<?php

declare(strict_types=1);

/*
 * @copyright Copyright (c) 2024 Michael Epstein <mepstein@live.cl>
 *
 * @author Michael Epstein <mepstein@live.cl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\UserISPConfigAPI;

use OC;
use OC\AllConfig;

use OCP\AppFramework\Db\TTransactional;
use OCP\Cache\CappedMemoryCache;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Server;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IGetHomeBackend;
use OCP\User\Backend\IGetRealUIDBackend;
use OCP\User\Backend\ISearchKnownUsersBackend;
use OCP\User\Backend\ISetDisplayNameBackend;
use OCP\User\Backend\ISetPasswordBackend;
use Psr\Log\LoggerInterface;

/**
 * Custom class for User Backend.
 * This class don't implement the ICreateUserBackend.
 */
class UserBackend extends ABackend implements
    ISetPasswordBackend,
    ISetDisplayNameBackend,
    IGetDisplayNameBackend,
    ICheckPasswordBackend,
    IGetHomeBackend,
    ICountUsersBackend,
    ISearchKnownUsersBackend,
    IGetRealUIDBackend
{
    use TTransactional;

    /**
     * Max length for UID row in the DB
     */
    const MAX_UID_LENGTH = 64;
    /**
     * Max length for Name row in the DB
     */
    const MAX_NAME_LENGTH = 64;

    /**
     * @var CappedMemoryCache
     */
    private $cache;
    /**
     * @var string
     */
    private $loginName;
    /**
     * @var null|bool
     */
    private $userData = null;
    /**
     * @var array
     */
    private $domainData;
    /**
     * @var array
     */
    private $serverData;
    /**
     * @var string
     */
    private $mailUser;
    /**
     * @var string
     */
    private $mailDomain;
    /**
     * @var string
     */
    private $uid;

    /**
     * @param SOAP $soap
     * @param IUserManager $userManager
     * @param IGroupManager $groupManager
     * @param GroupBackend $groupBackend
     * @param IEventDispatcher $eventDispatcher
     * @param IDBConnection $db
     * @param LoggerInterface $logger
     */
    public function __construct(
        private SOAP             $soap,
        private IUserManager     $userManager,
        private IGroupManager    $groupManager,
        private GroupBackend     $groupBackend,
        private IEventDispatcher $eventDispatcher,
        private IDBConnection    $db,
        private LoggerInterface  $logger
    )
    {
        $this->cache = new CappedMemoryCache();
    }

    /**
     * @inheritDoc
     */
    public function getBackendName(): string
    {
        return 'ISPConfig API';
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function userExists($uid): bool
    {
        $this->fixUID($uid);
        $this->loadUser($uid);

        return $this->cache[$uid] !== false;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function checkPassword(string $loginName, string $password): bool|string
    {
        // Load user
        if (!$this->setUserData($loginName)) {
            return false;
        }

        // Validate password
        if (!password_verify($password, $this->userData['password'])) {
            return false;
        }

        // Create a new user if don't exist
        $newUser = $this->createUser($this->uid, $this->userData['name']);

        // Process user data
        $user = $this->userManager->get($this->uid);

        if (!($user instanceof IUser)) {
            return false;
        }

        // Set system email for new users
        if ($newUser) {
            $user->setSystemEMailAddress($this->mailUser . '@' . $this->mailDomain);
        }

        // Quota (Defined by ISPConfig in MB)
        if (isset($this->userData['nc_quota']) && $this->userData['nc_quota'] !== '') {
            $userQuota = (int) $this->userData['nc_quota'];
        } elseif (isset($this->domainData['nc_quota']) && $this->domainData['nc_quota'] !== '') {
            $userQuota = (int) $this->domainData['nc_quota'];
        } else {
            $userQuota = 0;
        }

        // Set user quota
        $user->setQuota("$userQuota MB");

        // User "must" belong to this groups
        $mustGroups = [];
        // User can/can't be admin of this groups
        $adminGroups = [];

        // User group
        if (isset($this->userData['nc_group']) && $this->userData['nc_group'] !== '') {
            $userGroup = $this->groupBackend->parseGroup(
                $this->userData['mailuser_id'],
                GroupBackend::SECTION_USER,
                $this->userData['nc_group']

            );
            if ($userGroup) {
                $mustGroups[] = $userGroup;
                $adminGroups['nc_adm_user'] = $userGroup;
            }
        }

        // Domain Group
        if (isset($this->userData['nc_domain']) &&
            $this->userData['nc_domain'] == 'y' &&
            isset($this->domainData['nc_group']) &&
            $this->domainData['nc_group'] !== '') {
            $domainGroup = $this->groupBackend->parseGroup(
                $this->domainData['domain_id'],
                GroupBackend::SECTION_DOMAIN,
                $this->domainData['nc_group']
            );
            if ($domainGroup) {
                $mustGroups[] = $domainGroup;
                $adminGroups['nc_adm_domain'] = $domainGroup;
            }
        }

        // Server Group
        if (isset($this->userData['nc_server']) &&
            $this->userData['nc_server'] == 'y' &&
            isset($this->serverData['nc_group']) &&
            $this->serverData['nc_group'] !== '') {
            $serverGroup = $this->groupBackend->parseGroup(
                $this->userData['server_id'],
                GroupBackend::SECTION_SERVER,
                $this->serverData['nc_group']

            );
            if ($serverGroup) {
                $mustGroups[] = $serverGroup;
                $adminGroups['nc_adm_server'] = $serverGroup;
            }
        }

        // User "belong" to this groups
        $belongGroups = $this->groupBackend->getUserGroups($this->uid);
        // "Missing" user groups (Add user to this groups)
        $missingGroups = array_diff($mustGroups, $belongGroups);
        // User "must not" belong to this groups (Remove user from this groups)
        $extraGroups = array_diff($belongGroups, $mustGroups);

        // New groups for this user
        if ($missingGroups) {
            // Check if this groups exist
            $existing = $this->groupBackend->groupsExists($missingGroups);
            $absents = array_diff($missingGroups, $existing);

            // Crete new groups
            foreach ($absents as $absent) {
                $this->groupBackend->createGroup($absent);
            }

            // Add user to this groups?
            $add = isset($this->serverData['nc_add']) && $this->serverData['nc_add'] == 'y';
            if ($add) {
                foreach ($missingGroups as $missingGroup) {
                    $this->groupBackend->addToGroup($this->uid, $missingGroup);
                }
            }
        }

        // At this point we have all the needed groups created and in cache
        $this->groupBackend->updateDisplayName($mustGroups);

        // Get the sub admin manager
        $subAdminManager = $this->groupManager->getSubAdmin();

        // Make the user admin of his own groups
        foreach ($adminGroups as $key => $target) {
            if (isset($this->userData[$key])) {
                $group = $this->groupManager->get($target);

                if ($group instanceof IGroup) {
                    // We cannot be sub-admin twice
                    $isSubAdmin = $subAdminManager->isSubAdminOfGroup($user, $group);

                    if (!$isSubAdmin && $this->userData[$key] == 'y') {
                        $subAdminManager->createSubAdmin($user, $group);
                    } elseif ($isSubAdmin && $this->userData[$key] == 'n') {
                        $subAdminManager->deleteSubAdmin($user, $group);
                    }
                }
            }
        }

        // Remove user from groups and delete the group if empty
        if ($extraGroups) {
            $remove = isset($this->serverData['nc_remove']) && $this->serverData['nc_remove'] == 'y';

            if ($remove) {
                $delete = isset($this->serverData['nc_delete']) && $this->serverData['nc_delete'] == 'y';

                foreach ($extraGroups as $extraGroup) {
                    $this->groupBackend->removeFromGroup($this->uid, $extraGroup);

                    // Delete the groups
                    if ($delete) {
                        $remain = $this->groupBackend->countUsersInGroup($extraGroup);
                        if (!$remain) {
                            $this->groupBackend->deleteGroup($extraGroup);
                        }
                    }
                }
            }

        }

        return (string) $this->cache[$this->uid]['uid'];
    }

    /**
     * Create new user
     * WARNING! This backend don't implement the ICreateUserBackend characteristic.
     * We leave this here just for internal usage.
     *
     * @param string $uid
     * @param string $displayName
     *
     * @return bool
     * @throws \Throwable
     */
    private function createUser(string $uid, string $displayName): bool
    {
        if (!$this->userExists($uid)) {
            return $this->atomic(
                function () use ($uid, $displayName) {
                    // Compute the user Name
                    $displayName = trim($displayName);

                    if ($displayName === '') {
                        $displayName = $this->mailUser . '@' . $this->mailDomain;
                    }

                    $displayName = BackendHelper::computeName($displayName, self::MAX_NAME_LENGTH);

                    $qb = $this->db->getQueryBuilder();
                    $qb->insert('ispconfig_api_users')
                        ->values(
                            [
                                'uid' => $qb->createNamedParameter(mb_strtolower($uid)),
                                'displayname' => $qb->createNamedParameter($displayName),
                                'mailuser' => $qb->createNamedParameter(mb_strtolower($this->mailUser)),
                                'maildomain' => $qb->createNamedParameter(mb_strtolower($this->mailDomain)),
                            ]
                        );

                    $result = $qb->executeStatement();

                    // Clear cache
                    unset($this->cache[$uid]);

                    // Repopulate the cache
                    $this->loadUser($uid);

                    return (bool) $result;
                },
                $this->db
            );
        }

        return false;
    }

    /**
     * Query the API and set the user data
     *
     * @param string $loginName User email or login name
     * @param bool $close Close the SOAP connection
     *
     * @return bool
     */
    private function setUserData(string $loginName, bool $close = true): bool
    {
        if ($this->userData === null) {
            try {
                // Get user
                $userData = $this->soap->getMailUser($loginName);

                // Check if this user can login ('y' or 'n')
                if (!isset($userData['nc_enabled']) || $userData['nc_enabled'] != 'y') {
                    throw new Exception('User can not login.');
                }

                // Check if this server can login
                $serverData = $this->soap->getServerConf($userData['server_id']);

                if (!isset($serverData['nc_enabled']) || $serverData['nc_enabled'] != 'y') {
                    throw new Exception('Server can not login.');
                }

                // Split the email in user and domain
                $parts = BackendHelper::splitEmail($userData['email']);

                if (!$parts) {
                    throw new Exception('Invalid user email.');
                }

                $domainData = $this->soap->getMailDomain($parts['domain']);

                // Close the connection
                if ($close) {
                    $this->soap->close();
                }

                // Check if this domain can login
                if (!isset($domainData['nc_enabled']) || $domainData['nc_enabled'] != 'y') {
                    throw new Exception('Domain can not login.');
                }

                $this->loginName = $loginName;
                $this->userData = $userData;
                $this->serverData = $serverData;
                $this->domainData = $domainData;
                $this->mailUser = $parts['user'];
                $this->mailDomain = $parts['domain'];
                $this->uid = BackendHelper::computeUID(
                    $parts['user'] . '.' . $parts['domain'],
                    self::MAX_UID_LENGTH
                );
            } catch (Exception $e) {
                $this->soap->close();
                $this->userData = [];
                $this->logger->debug('User loading failed: ' . $e->getMessage(), ['app' => 'user_ispconfig_api']);
            }
        }

        return (bool) $this->userData;
    }

    /**
     * Load an user in the cache
     *
     * @param mixed $uid The user uid
     *
     * @return bool
     * @throws \Throwable
     */
    private function loadUser(mixed $uid): bool
    {
        if (!isset($this->cache[$uid])) {
            // Guests $uid could be NULL or ''
            if ($uid === '') {
                $this->cache[$uid] = false;
                return true;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select('uid', 'displayname', 'mailuser', 'maildomain')
                ->from('ispconfig_api_users')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter(mb_strtolower($uid))));

            $result = $qb->execute();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row !== false) {
                $this->cache[$uid] = [
                    'uid' => (string) $row['uid'],
                    'displayname' => (string) $row['displayname'],
                    'mailuser' => (string) $row['mailuser'],
                    'maildomain' => (string) $row['maildomain'],
                ];
            } else {
                $this->cache[$uid] = false;
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getDisplayName($uid): string
    {
        $uid = (string) $uid;
        $this->loadUser($uid);

        return empty($this->cache[$uid]['displayname']) ? $uid : $this->cache[$uid]['displayname'];
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function countUsers()
    {
        $query = $this->db->getQueryBuilder();
        $query->select($query->func()->count('uid'))
            ->from('ispconfig_api_users');
        $result = $query->executeQuery();

        return $result->fetchOne();
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getHome(string $uid): bool|string
    {
        if ($this->userExists($uid)) {
            return Server::get(AllConfig::class)
                    ->getSystemValueString('datadirectory', OC::$SERVERROOT . '/data')
                . '/' . $this->cache[$uid]['maildomain'] . '/' . $this->cache[$uid]['mailuser'];
        }

        return false;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function searchKnownUsersByDisplayName(string $searcher, string $pattern, ?int $limit = null, ?int $offset = null): array
    {
        $limit = $this->fixLimit($limit);
        $query = $this->db->getQueryBuilder();

        $query->select('u.uid', 'u.displayname')
            ->from('ispconfig_api_users', 'u')
            ->leftJoin('u', 'known_users', 'k', $query->expr()->andX(
                $query->expr()->eq('k.known_user', 'u.uid'),
                $query->expr()->eq('k.known_to', $query->createNamedParameter($searcher))
            ))
            ->where($query->expr()->eq('k.known_to', $query->createNamedParameter($searcher)))
            ->andWhere($query->expr()->orX(
                $query->expr()->iLike('u.uid', $query->createNamedParameter('%' . $this->db->escapeLikeParameter($pattern) . '%')),
                $query->expr()->iLike('u.displayname', $query->createNamedParameter('%' . $this->db->escapeLikeParameter($pattern) . '%'))
            ))
            ->orderBy('u.displayname', 'ASC')
            ->addOrderBy('u.uid', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $query->execute();
        $displayNames = [];
        while ($row = $result->fetch()) {
            $displayNames[(string) $row['uid']] = (string) $row['displayname'];
        }

        return $displayNames;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function setDisplayName(string $uid, string $displayName): bool
    {
        $response = false;
        $displayName = BackendHelper::computeName($displayName, self::MAX_NAME_LENGTH);

        if ($displayName !== '' && $this->userExists($uid)) {
            $email = $this->cache[$uid]['mailuser'] . '@' . $this->cache[$uid]['maildomain'];

            // Load user
            if ($this->setUserData($email, false)) {
                // API call is more likely to fail. Try this first.
                $response = $this->soap->updateMailUser(
                    $this->userData['sys_userid'],
                    $this->userData['mailuser_id'],
                    ['name' => $displayName]
                );
                $this->soap->close();

                if ($response) {
                    $query = $this->db->getQueryBuilder();
                    $query->update('ispconfig_api_users')
                        ->set('displayname', $query->createNamedParameter($displayName))
                        ->where($query->expr()->eq('uid', $query->createNamedParameter(mb_strtolower($uid))));
                    $query->execute();

                    $this->cache[$uid]['displayname'] = $displayName;
                }
            }
        }

        return $response;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function setPassword(string $uid, string $password): bool
    {
        $response = false;

        if ($this->userExists($uid)) {
            $this->eventDispatcher->dispatchTyped(new ValidatePasswordPolicyEvent($password));

            $email = $this->cache[$uid]['mailuser'] . '@' . $this->cache[$uid]['maildomain'];

            // Load user
            if ($this->setUserData($email, false)) {
                $response = $this->soap->updateMailUser(
                    $this->userData['sys_userid'],
                    $this->userData['mailuser_id'],
                    ['password' => $password]
                );
                $this->soap->close();
            }
        }

        return $response;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function deleteUser($uid): bool
    {
        // Delete user-group-relation
        $query = $this->db->getQueryBuilder();
        $query->delete('ispconfig_api_users')
            ->where($query->expr()->eq('uid', $query->createNamedParameter(mb_strtolower($uid))));
        $result = $query->execute();

        if (isset($this->cache[$uid])) {
            unset($this->cache[$uid]);
        }

        return (bool) $result;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getUsers($search = '', $limit = null, $offset = null): array
    {
        $limit = $this->fixLimit($limit);

        $users = $this->getDisplayNames($search, $limit, $offset);
        $userIds = array_map(
            function ($uid) {
                return (string) $uid;
            },
            array_keys($users)
        );
        sort($userIds, SORT_STRING | SORT_FLAG_CASE);

        return $userIds;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getDisplayNames($search = '', $limit = null, $offset = null): array
    {
        $limit = $this->fixLimit($limit);
        $query = $this->db->getQueryBuilder();

        $query->select('uid', 'displayname')
            ->from('ispconfig_api_users', 'u')
            ->leftJoin('u', 'preferences', 'p', $query->expr()->andX(
                $query->expr()->eq('userid', 'uid'),
                $query->expr()->eq('appid', $query->expr()->literal('settings')),
                $query->expr()->eq('configkey', $query->expr()->literal('email')))
            )
            // sqlite doesn't like re-using a single named parameter here
            ->where($query->expr()->iLike('uid', $query->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
            ->orWhere($query->expr()->iLike('displayname', $query->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
            ->orWhere($query->expr()->iLike('configvalue', $query->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
            ->orderBy($query->func()->lower('displayname'), 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $query->executeQuery();
        $displayNames = [];
        while ($row = $result->fetch()) {
            $displayNames[(string) $row['uid']] = (string) $row['displayname'];
        }

        return $displayNames;
    }

    /**
     * @inheritDoc
     */
    public function hasUserListings(): bool
    {
        return true;
    }

    /**
     * Returns the username for the given login name
     *
     * @param string $loginName
     *
     * @return string|false
     * @throws \Throwable
     */
    public function loginName2UserName(string $loginName): bool|string
    {
        if ($this->setUserData($loginName)) {
            $this->fixUID($loginName);

            if ($this->userExists($loginName)) {
                return $this->cache[$loginName]['uid'];
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getRealUID(string $uid): string
    {
        $this->fixUID($uid);

        if (!$this->userExists($uid)) {
            throw new \RuntimeException($uid . ' does not exist');
        }

        return $this->cache[$uid]['uid'];
    }

    /**
     * Get the real UID
     *
     * @param string $uid
     *
     * @return void
     */
    private function fixUID(string &$uid): void
    {
        if ($this->loginName && $uid === $this->loginName) {
            $uid = $this->uid;
        }
    }

    /**
     * Limit must be an integer
     *
     * @param mixed $limit
     *
     * @return int|null
     */
    private function fixLimit(mixed $limit): ?int
    {
        if (is_int($limit) && $limit >= 0) {
            return $limit;
        }

        return null;
    }
}

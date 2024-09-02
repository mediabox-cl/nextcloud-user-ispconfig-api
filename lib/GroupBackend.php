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

use OCP\AppFramework\Db\TTransactional;

use OC\User\LazyUser;
use OCP\Cache\CappedMemoryCache;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Group\Backend\ABackend;
use OCP\Group\Backend\IAddToGroupBackend;
use OCP\Group\Backend\IBatchMethodsBackend;
use OCP\Group\Backend\ICountDisabledInGroup;
use OCP\Group\Backend\ICountUsersBackend;
use OCP\Group\Backend\IDeleteGroupBackend;
use OCP\Group\Backend\IGetDisplayNameBackend;
use OCP\Group\Backend\IGroupDetailsBackend;
use OCP\Group\Backend\INamedBackend;
use OCP\Group\Backend\IRemoveFromGroupBackend;
use OCP\Group\Backend\ISearchableGroupBackend;
use OCP\Group\Backend\ISetDisplayNameBackend;
use OCP\IDBConnection;
use OCP\IUserManager;
use UnexpectedValueException;

/**
 * Custom class for Groups Backend.
 * This class don't implement the ICreateNamedGroupBackend.
 */
class GroupBackend extends ABackend implements
    IAddToGroupBackend,
    ICountDisabledInGroup,
    ICountUsersBackend,
    IDeleteGroupBackend,
    IGetDisplayNameBackend,
    IGroupDetailsBackend,
    IRemoveFromGroupBackend,
    ISearchableGroupBackend,
    ISetDisplayNameBackend,
    IBatchMethodsBackend,
    INamedBackend
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
     * Group from server
     */
    const SECTION_SERVER = 1;
    /**
     * Group from domain
     */
    const SECTION_DOMAIN = 2;
    /**
     * Group from user
     */
    const SECTION_USER = 3;

    /**
     * @var CappedMemoryCache
     */
    private $cache;
    /**
     * @var array
     */
    private $groups = [];

    /**
     * @param SOAP $soap
     * @param IDBConnection $db
     */
    public function __construct(
        private SOAP          $soap,
        private IDBConnection $db
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
     * Create group
     * WARNING! This backend don't implement the ICreateNamedGroupBackend characteristic.
     * We leave this here just for internal usage.
     *
     * @param string $gid Group ID
     *
     * @return string|null
     * @throws \Throwable
     */
    public function createGroup(string $gid): ?string
    {
        if (!isset($this->groups[$gid])) {
            return null;
        }

        if (!$this->groupExists($gid)) {
            return $this->atomic(
                function () use ($gid) {
                    $displayName = BackendHelper::computeName($this->groups[$gid]['name'], self::MAX_NAME_LENGTH);

                    $qb = $this->db->getQueryBuilder();
                    $qb->insert('ispconfig_api_groups')
                        ->values(
                            [
                                'gid' => $qb->createNamedParameter(mb_strtolower($gid)),
                                'rid' => $qb->createNamedParameter((int) $this->groups[$gid]['rid']),
                                'sid' => $qb->createNamedParameter((int) $this->groups[$gid]['sid']),
                                'displayname' => $qb->createNamedParameter($displayName)
                            ]
                        );

                    $result = $qb->executeStatement();

                    // Clear cache
                    unset($this->cache[$gid]);

                    // Repopulate the cache
                    $this->loadGroup($gid);

                    return $result ? $gid : null;
                },
                $this->db
            );
        }

        return null;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function inGroup($uid, $gid): bool
    {
        // check
        $qb = $this->db->getQueryBuilder();
        $cursor = $qb->select('uid')
            ->from('group_user')
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter(mb_strtolower($gid))))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter(mb_strtolower($uid))))
            ->execute();

        $result = $cursor->fetch();
        $cursor->closeCursor();

        return (bool) $result;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function addToGroup(string $uid, string $gid): bool
    {
        // No duplicate entries!
        if (!$this->inGroup($uid, $gid)) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('group_user')
                ->setValue('uid', $qb->createNamedParameter(mb_strtolower($uid)))
                ->setValue('gid', $qb->createNamedParameter(mb_strtolower($gid)))
                ->execute();
            return true;
        } else {
            return false;
        }
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function removeFromGroup(string $uid, string $gid): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('group_user')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter(mb_strtolower($uid))))
            ->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter(mb_strtolower($gid))))
            ->execute();

        return true;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getUserGroups($uid): array
    {
        //guests has empty or null $uid
        if ($uid === null || $uid === '') {
            return [];
        }

        // No magic!
        $qb = $this->db->getQueryBuilder();
        $cursor = $qb->select('gu.gid', 'g.rid', 'g.sid', 'g.displayname')
            ->from('group_user', 'gu')
            ->leftJoin('gu', 'ispconfig_api_groups', 'g', $qb->expr()->eq('gu.gid', 'g.gid'))
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter(mb_strtolower($uid))))
            ->execute();

        $groups = [];
        while ($row = $cursor->fetch()) {
            $groups[] = $row['gid'];
            $this->cache[$row['gid']] = [
                'gid' => (string) $row['gid'],
                'rid' => (int) $row['rid'],
                'sid' => (int) $row['sid'],
                'displayname' => (string) $row['displayname']
            ];
        }
        $cursor->closeCursor();

        return $groups;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getGroups(string $search = '', int $limit = -1, int $offset = 0): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('gid', 'rid', 'sid', 'displayname')
            ->from('ispconfig_api_groups')
            ->orderBy('gid', 'ASC');

        if ($search !== '') {
            $query->where($query->expr()->iLike('gid', $query->createNamedParameter(
                '%' . $this->db->escapeLikeParameter(mb_strtolower($search)) . '%'
            )));
            $query->orWhere($query->expr()->iLike('displayname', $query->createNamedParameter(
                '%' . $this->db->escapeLikeParameter($search) . '%'
            )));
        }

        if ($limit > 0) {
            $query->setMaxResults($limit);
        }
        if ($offset > 0) {
            $query->setFirstResult($offset);
        }
        $result = $query->execute();

        $groups = [];
        while ($row = $result->fetch()) {
            $this->cache[$row['gid']] = [
                'gid' => (string) $row['gid'],
                'rid' => (int) $row['rid'],
                'sid' => (int) $row['sid'],
                'displayname' => (string) $row['displayname']
            ];
            $groups[] = $row['gid'];
        }
        $result->closeCursor();

        return $groups;
    }

    /**
     * Load a groups in the cache
     *
     * @param mixed $gid the group gid
     *
     * @return void
     * @throws \Throwable
     */
    private function loadGroup(mixed $gid): void
    {
        if (!isset($this->cache[$gid])) {
            // Guests $gid could be NULL or ''
            if ($gid === '') {
                $this->cache[$gid] = false;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select('gid', 'rid', 'sid', 'displayname')
                ->from('ispconfig_api_groups')
                ->where($qb->expr()->eq('gid', $qb->createNamedParameter(mb_strtolower($gid))));

            $result = $qb->execute();
            $row = $result->fetch();
            $result->closeCursor();

            // "gid" is primary key, so there can only be a single result
            if ($row !== false) {
                $this->cache[$gid] = [
                    'gid' => (string) $row['gid'],
                    'rid' => (int) $row['rid'],
                    'sid' => (int) $row['sid'],
                    'displayname' => (string) $row['displayname']
                ];
            } else {
                $this->cache[$gid] = false;
            }
        }
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function groupExists($gid): bool
    {
        $this->loadGroup($gid);
        return $this->cache[$gid] !== false;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function groupsExists(array $gids): array
    {
        $notFoundGids = [];
        $existingGroups = [];

        // In case the data is already locally accessible, not need to do SQL query
        // or do a SQL query but with a smaller in clause
        foreach ($gids as $gid) {
            if (isset($this->cache[$gid])) {
                $existingGroups[] = mb_strtolower($gid);
            } else {
                $notFoundGids[] = mb_strtolower($gid);
            }
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('gid', 'rid', 'sid', 'displayname')
            ->from('ispconfig_api_groups')
            ->where($qb->expr()->in('gid', $qb->createParameter('ids')));

        foreach (array_chunk($notFoundGids, 1000) as $chunk) {
            $qb->setParameter('ids', $chunk, IQueryBuilder::PARAM_STR_ARRAY);
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $this->cache[(string) $row['gid']] = [
                    'gid' => (string) $row['gid'],
                    'rid' => (int) $row['rid'],
                    'sid' => (int) $row['sid'],
                    'displayname' => (string) $row['displayname']
                ];
                $existingGroups[] = (string) $row['gid'];
            }
            $result->closeCursor();
        }

        return $existingGroups;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0)
    {
        return array_values(array_map(fn($user) => $user->getUid(), $this->searchInGroup($gid, $search, $limit, $offset)));
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function countDisabledInGroup(string $gid): int
    {
        $query = $this->db->getQueryBuilder();
        $query->select($query->createFunction('COUNT(DISTINCT ' . $query->getColumnName('uid') . ')'))
            ->from('preferences', 'p')
            ->innerJoin('p', 'group_user', 'g', $query->expr()->eq('p.userid', 'g.uid'))
            ->where($query->expr()->eq('appid', $query->createNamedParameter('core')))
            ->andWhere($query->expr()->eq('configkey', $query->createNamedParameter('enabled')))
            ->andWhere($query->expr()->eq('configvalue', $query->createNamedParameter('false'), IQueryBuilder::PARAM_STR))
            ->andWhere($query->expr()->eq('gid', $query->createNamedParameter(mb_strtolower($gid)), IQueryBuilder::PARAM_STR));

        $result = $query->execute();
        $count = $result->fetchOne();
        $result->closeCursor();

        if ($count !== false) {
            $count = (int) $count;
        } else {
            $count = 0;
        }

        return $count;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function countUsersInGroup(string $gid, string $search = ''): int
    {
        $query = $this->db->getQueryBuilder();
        $query->select($query->func()->count('*', 'num_users'))
            ->from('group_user')
            ->where($query->expr()->eq('gid', $query->createNamedParameter(mb_strtolower($gid))));

        if ($search !== '') {
            $query->andWhere($query->expr()->like('uid', $query->createNamedParameter(
                '%' . $this->db->escapeLikeParameter($search) . '%'
            )));
        }

        $result = $query->execute();
        $count = $result->fetchOne();
        $result->closeCursor();

        if ($count !== false) {
            $count = (int) $count;
        } else {
            $count = 0;
        }

        return $count;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function deleteGroup(string $gid): bool
    {
        $gid = mb_strtolower($gid);

        // Delete the group
        $qb = $this->db->getQueryBuilder();
        $qb->delete('ispconfig_api_groups')
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
            ->execute();

        // Delete the group-user relation
        $qb = $this->db->getQueryBuilder();
        $qb->delete('group_user')
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
            ->execute();

        // Delete the group-groupadmin relation
        $qb = $this->db->getQueryBuilder();
        $qb->delete('group_admin')
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
            ->execute();

        // Delete from cache
        if (isset($this->cache[$gid])) {
            unset($this->cache[$gid]);
        }

        return true;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getDisplayName(string $gid): string
    {
        $this->loadGroup($gid);

        return empty($this->cache[$gid]) ? '' : $this->cache[$gid]['displayname'];
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getGroupDetails(string $gid): array
    {
        $displayName = $this->getDisplayName($gid);

        if ($displayName !== '') {
            return ['displayName' => $displayName];
        }

        return [];
    }

    /**
     * @inheritdoc
     * @throws \Throwable
     */
    public function getGroupsDetails(array $gids): array
    {
        $notFoundGids = [];
        $details = [];

        // In case the data is already locally accessible, not need to do SQL query
        // or do a SQL query but with a smaller in clause
        foreach ($gids as $gid) {
            if (isset($this->cache[$gid])) {
                $details[$gid] = ['displayName' => $this->cache[$gid]['displayname']];
            } else {
                $notFoundGids[] = mb_strtolower($gid);
            }
        }

        foreach (array_chunk($notFoundGids, 1000) as $chunk) {
            $query = $this->db->getQueryBuilder();
            $query->select('gid', 'rid', 'sid', 'displayname')
                ->from('ispconfig_api_groups')
                ->where($query->expr()->in('gid', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));

            $result = $query->executeQuery();
            while ($row = $result->fetch()) {
                $details[(string) $row['gid']] = ['displayName' => (string) $row['displayname']];
                $this->cache[(string) $row['gid']] = [
                    'gid' => (string) $row['gid'],
                    'rid' => (int) $row['rid'],
                    'sid' => (int) $row['sid'],
                    'displayname' => (string) $row['displayname']
                ];
            }
            $result->closeCursor();
        }

        return $details;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function searchInGroup(string $gid, string $search = '', int $limit = -1, int $offset = 0): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('g.uid', 'u.displayname');

        $query->from('group_user', 'g')
            ->where($query->expr()->eq('gid', $query->createNamedParameter(mb_strtolower($gid))))
            ->orderBy('g.uid', 'ASC');

        $query->leftJoin('g', 'users', 'u', $query->expr()->eq('g.uid', 'u.uid'));

        if ($search !== '') {
            $query->leftJoin('u', 'preferences', 'p', $query->expr()->andX(
                $query->expr()->eq('p.userid', 'u.uid'),
                $query->expr()->eq('p.appid', $query->expr()->literal('settings')),
                $query->expr()->eq('p.configkey', $query->expr()->literal('email'))
            ))
                // sqlite doesn't like re-using a single named parameter here
                ->andWhere(
                    $query->expr()->orX(
                        $query->expr()->ilike('g.uid', $query->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%')),
                        $query->expr()->ilike('u.displayname', $query->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%')),
                        $query->expr()->ilike('p.configvalue', $query->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%'))
                    )
                )
                ->orderBy('u.uid_lower', 'ASC');
        }

        if ($limit !== -1) {
            $query->setMaxResults($limit);
        }
        if ($offset !== 0) {
            $query->setFirstResult($offset);
        }

        $result = $query->executeQuery();

        $users = [];
        $userManager = \OCP\Server::get(IUserManager::class);
        while ($row = $result->fetch()) {
            $users[$row['uid']] = new LazyUser($row['uid'], $userManager, $row['displayname'] ?? null);
        }
        $result->closeCursor();

        return $users;
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function setDisplayName(string $gid, string $displayName): bool
    {
        $displayName = BackendHelper::computeName($displayName, self::MAX_NAME_LENGTH);

        if ($displayName !== '' && $this->groupExists($gid)) {
            $query = $this->db->getQueryBuilder();
            $query->update('ispconfig_api_groups')
                ->set('displayname', $query->createNamedParameter($displayName))
                ->where($query->expr()->eq('gid', $query->createNamedParameter(mb_strtolower($gid))));
            $query->execute();

            $this->cache[$gid]['displayname'] = $displayName;

            return true;
        }

        return false;
    }

    /**
     * Update the display name if changed in the API
     *
     * @param array $gids
     *
     * @return void
     * @throws \Throwable
     */
    public function updateDisplayName(array $gids): void
    {
        foreach ($gids as $gid) {
            if (isset($this->groups[$gid])) {
                $displayName = BackendHelper::computeName($this->groups[$gid]['name'], self::MAX_NAME_LENGTH);

                // $this->cache[$gid] can be false.
                if (isset($this->cache[$gid]) && $this->cache[$gid] && $this->cache[$gid]['displayname'] !== $displayName) {
                    $this->setDisplayName($gid, $displayName);
                }
            }
        }
    }

    /**
     * Parse group data
     *
     * @param mixed $rid Remote Server, Domain or User ID
     * @param int $sid Section from which this group comes from (Server, Domain or User)
     * @param string $name Group name
     *
     * @return bool|string
     */
    public function parseGroup(mixed $rid, int $sid, string $name): bool|string
    {
        $rid = (int) $rid;

        if ($rid < 1) {
            throw new UnexpectedValueException("Invalid ID '$rid' in section '$sid' in groups parsing function.");
        }

        $name = trim($name);

        if ($name !== '') {
            // We need this to avoid collision
            $gid = match ($sid) {
                self::SECTION_SERVER => "id$rid.server",
                self::SECTION_DOMAIN => "id$rid.domain",
                self::SECTION_USER => "id$rid.user",
                default => throw new UnexpectedValueException("Invalid section '$sid' in groups parsing function."),
            };

            $gid = BackendHelper::computeUID($gid, self::MAX_UID_LENGTH);

            $this->groups[$gid] = [
                'rid' => $rid,
                'sid' => $sid,
                'name' => $name
            ];

            return $gid;
        }

        return false;
    }
}
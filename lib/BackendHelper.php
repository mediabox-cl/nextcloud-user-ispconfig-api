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

use OCP\Server;

class BackendHelper
{
    /**
     * Compute/Truncate a name string
     *
     * @param mixed $str Item name
     * @param string $prefix
     * @param string $suffix Suffix to use in the name
     * @param int $length Max. allowed name length
     *
     * @return mixed
     */
    public static function computeName(mixed $str, int $length, string $prefix = '', string $suffix = ''): mixed
    {
        $str = trim($prefix . $str . $suffix);

        if ($str !== '') {
            $strLength = mb_strlen($str);

            if ($strLength > $length) {
                $str = mb_substr($str, 0, $length - 3) . '...';
            }
        }

        return $str;
    }

    /**
     * Compute and encode UID if greater than self::MAX_UID_LENGTH
     *
     * @param mixed $uid
     * @param int $length
     *
     * @return mixed
     */
    public static function computeUID(mixed $uid, int $length): mixed
    {
        $uid = trim($uid);

        if ($uid !== '') {
            $uid = mb_strlen($uid) > $length ? hash('sha256', $uid) : mb_strtolower($uid);
        }

        return $uid;
    }

    /**
     * Split an email
     *
     * @param string $email
     * @param bool $lower
     *
     * @return array
     */
    public static function splitEmail(string $email, bool $lower = true): array
    {
        $email = self::isEmail($email, $lower);

        if ($email) {
            $parts = explode('@', $email);

            if (count($parts) > 1) {
                $domain = array_pop($parts);

                if (filter_var($domain, FILTER_VALIDATE_DOMAIN)) {
                    $user = implode('@', $parts);
                    return [
                        'user' => $user,
                        'domain' => $domain
                    ];
                }
            }
        }

        return [];
    }

    /**
     * Check if string is a email
     *
     * @param string $email Email
     * @param bool $lower Convert to lowercase
     *
     * @return string|false
     */
    public static function isEmail(string $email, bool $lower = true): string|false
    {
        $email = trim($email);

        if ($lower) {
            $email = mb_strtolower($email);
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Listens to a hook thrown by server2server sharing and replaces the given
     * login name by a username, if it matches an API user.
     *
     * @param $param
     *
     * @return void
     * @throws \Throwable
     */
    public static function preLoginNameUsedAsUserName($param): void
    {
        if (!isset($param['uid'])) {
            throw new \Exception('key uid is expected to be set in $param');
        }

        $userBackend = Server::get(UserBackend::class);
        $uid = $userBackend->loginName2UserName($param['uid']);
        if ($uid !== false) {
            $param['uid'] = $uid;
        }
    }
}
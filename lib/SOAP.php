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

use OCP\IConfig;
use Psr\Log\LoggerInterface;
use SoapClient;
use SoapFault;
use UnexpectedValueException;

/**
 * SOAP
 */
class SOAP
{
    /**
     * @var mixed API Configuration
     */
    private $api;
    /**
     * @var SoapClient SOAP Connection
     */
    private $soap = null;
    /**
     * @var boolean|string false or SOAP Session ID
     */
    private $session = false;
    /**
     * @var array
     */
    private $cache = [
        'client' => [],
        'user' => [],
        'domain' => [],
        'server' => []
    ];

    /**
     * @param LoggerInterface $logger
     * @param IConfig $config
     */
    public function __construct(
        private LoggerInterface $logger,
        private IConfig         $config
    )
    {
        $this->api = $this->config->getSystemValue('user_ispconfig_api');

        if (empty($this->api)) {
            throw new UnexpectedValueException('The Nextcloud '
                . 'configuration (config/config.php) does not contain the key '
                . '"user_ispconfig_api" which should contain the configuration '
                . 'for this Backend App.');
        }
    }

    /**
     * Connect to the soap api
     *
     * @throws SoapFault
     */
    private function connect(): void
    {
        if (!$this->session) {
            $location = $this->getConfig('location');
            $uri = $this->getConfig('uri');
            $user = $this->getConfig('user');
            $password = $this->getConfig('password');

            $this->soap = new SoapClient(
                null,
                array(
                    'location' => $location,
                    'uri' => $uri,
                    'trace' => 1,
                    'exceptions' => 1,
                    'stream_context' => stream_context_create(
                        array(
                            'ssl' => array(
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                                'allow_self_signed' => true
                            )
                        )
                    )
                )
            );

            $this->session = $this->soap->login($user, $password);
        }
    }

    /**
     * Disconnect from API
     */
    public function close(): void
    {
        if ($this->session) {
            $this->soap->logout($this->session);
            $this->session = false;
        }
    }

    /**
     * Send call to API
     *
     * @return mixed
     */
    public function run(): mixed
    {
        $args = func_get_args();

        try {
            if (empty($args)) {
                throw new SoapFault('invalid_call', 'Invalid function call. No function name specified.');
            }

            $this->connect();

            $function = array_shift($args);

            array_unshift($args, $this->session);

            return call_user_func_array([$this->soap, $function], $args);
        } catch (SoapFault $e) {
            $this->close();
            $this->fault($e);
            return false;
        }
    }

    /**
     * Get server config from API (Only the Nextcloud plugin config)
     *
     * @param mixed $server_id User server ID
     *
     * @return array
     */
    public function getServerConf(mixed $server_id): array
    {
        if (!array_key_exists($server_id, $this->cache['server'])) {
            $serverId = (int) $server_id;
            // Limit the section to 'nextcloud'
            $config = $this->run('server_get', $serverId, 'nextcloud');

            $this->cache['server'][$server_id] = is_array($config) ? $config : [];
        }

        return $this->cache['server'][$server_id];
    }

    /**
     * Get client ID from API
     *
     * @param mixed $sys_userid System user ID (sys_userid)
     *
     * @return int
     */
    public function getClientId(mixed $sys_userid): int
    {
        if (!array_key_exists($sys_userid, $this->cache['client'])) {
            $sysUserid = (int) $sys_userid;
            $response = $this->run('client_get_id', $sysUserid);

            $this->cache['client'][$sys_userid] = is_numeric($response) ? (int) $response : 0;
        }

        return $this->cache['client'][$sys_userid];
    }

    /**
     * Get the mail user from API
     *
     * @param mixed $user Email or login name
     *
     * @return array
     */
    public function getMailUser(mixed $user): array
    {
        if (!array_key_exists($user, $this->cache['user'])) {
            $mailUser = mb_strtolower(trim($user));
            $return = [];

            $filtered = filter_var($mailUser, FILTER_VALIDATE_EMAIL);

            if ($filtered) {
                $field = 'email';
                $mailUser = $filtered;
            } else {
                $field = 'login';
            }

            $response = $this->run('mail_user_get', [$field => $mailUser]);

            if (is_array($response) && isset($response[0])) {
                $return = $response[0];
            }

            $this->cache['user'][$user] = $return;
        }

        return $this->cache['user'][$user];
    }

    /**
     * Get domain from API
     *
     * @param mixed $domain Domain to search for
     *
     * @return array
     */
    public function getMailDomain(mixed $domain): array
    {
        if (!array_key_exists($domain, $this->cache['domain'])) {
            $mailDomain = mb_strtolower(trim($domain));
            $return = [];

            if (filter_var($mailDomain, FILTER_VALIDATE_DOMAIN)) {
                $response = $this->run('mail_domain_get', ['domain' => $mailDomain]);

                if (is_array($response) && isset($response[0])) {
                    $return = $response[0];
                }
            }

            $this->cache['domain'][$domain] = $return;
        }

        return $this->cache['domain'][$domain];
    }

    /**
     * Update mail user from API
     *
     * @param mixed $sysUserid System User ID (sys_userid)
     * @param mixed $mailUserid Mail user ID (mailuser_id)
     * @param array $data Data to be updated
     *
     * @return bool True if the record was modified, False otherwise
     */
    public function updateMailUser(mixed $sysUserid, mixed $mailUserid, array $data): bool
    {
        // We need to get the client ID from the sys_userid
        $clientId = $this->getClientId($sysUserid);

        if ($clientId) {
            return (bool) $this->run('mail_user_update', $clientId, (int) $mailUserid, $data);
        }

        return false;
    }

    /**
     * Tries to read a config value and throws an exception if it is not set.
     * This is used for config keys that are mandatory.
     *
     * @param $key string Key name of configuration parameter
     *
     * @return string The value of the configuration parameter.
     * @throws UnexpectedValueException
     */
    private function getConfig(string $key): string
    {
        if (empty($this->api[$key])) {
            throw new UnexpectedValueException(
                "The config key $key is not set. Add it to config/config.php as a sub-key of 'user_ispconfig_api'."
            );
        }

        return $this->api[$key];
    }

    /**
     * Log SoapFault errors
     *
     * @param SoapFault $e Error object
     */
    private function fault(SoapFault $e): void
    {
        $code = $e->getCode();
        switch ($code) {
            case 'client_login_failed':
            case 'login_failed':
                $this->logger->error('SOAP Request failed: Invalid credentials of ISPConfig remote user', ['app' => 'user_ispconfig_api']);
                break;
            case 'permission_denied':
                $this->logger->error('SOAP Request failed: Ensure ISPConfig remote user has the following permissions: Customer Functions, Server Functions, E-Mail User Functions', ['app' => 'user_ispconfig_api']);
                break;
            default:
                $this->logger->debug('SOAP Request failed: [' . $code . '] ' . $e->getMessage(), ['app' => 'user_ispconfig_api']);
                break;
        }
    }
}

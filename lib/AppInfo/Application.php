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

namespace OCA\UserISPConfigAPI\AppInfo;

use OC_Hook;
use OCA\UserISPConfigAPI\GroupBackend;
use OCA\UserISPConfigAPI\UserBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IGroupManager;
use OCP\IUserManager;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'user_ispconfig_api';

    public function __construct(array $urlParams = array())
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    /**
     * @param IBootContext $context
     *
     * @return void
     * @throws \Throwable
     */
    public function boot(IBootContext $context): void
    {
        $context->injectFn(function (
            IUserManager  $userManager,
            UserBackend   $userBackend,
            IGroupManager $groupManager,
            GroupBackend  $groupBackend
        ) {
            $userManager->registerBackend($userBackend);
            $groupManager->addBackend($groupBackend);
        });

        OC_Hook::connect(
            '\OCA\Files_Sharing\API\Server2Server',
            'preLoginNameUsedAsUserName',
            '\OCA\UserISPConfigAPI\BackendHelper',
            'preLoginNameUsedAsUserName'
        );
    }

    /**
     * @inheritDoc
     */
    public function register(IRegistrationContext $context): void
    {
    }
}

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

namespace OCA\UserISPConfigAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step
 */
class Version29000Date20240813041936 extends SimpleMigrationStep
{
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
    }

    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     *
     * @return null|ISchemaWrapper
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('ispconfig_api_users')) {
            $table = $schema->createTable('ispconfig_api_users');

            $table->addColumn('uid', Types::STRING, [
                'notnull' => true,
                'length' => 64
            ]);

            $table->addColumn('displayname', Types::STRING, [
                'notnull' => true,
                'length' => 255
            ]);

            $table->addColumn('mailuser', Types::STRING, [
                'notnull' => true,
                'length' => 255
            ]);

            $table->addColumn('maildomain', Types::STRING, [
                'notnull' => true,
                'length' => 255
            ]);

            $table->setPrimaryKey(['uid'], 'uid_user');
            $table->addIndex(['mailuser'], 'mail_user');
            $table->addIndex(['maildomain'], 'mail_domain');
        }

        if (!$schema->hasTable('ispconfig_api_groups')) {
            $table = $schema->createTable('ispconfig_api_groups');

            $table->addColumn('gid', Types::STRING, [
                'notnull' => true,
                'length' => 64
            ]);

            $table->addColumn('rid', Types::BIGINT, [
                'notnull' => true
            ]);

            $table->addColumn('sid', Types::SMALLINT, [
                'notnull' => true,
                'length' => 1
            ]);

            $table->addColumn('displayname', Types::STRING, [
                'notnull' => true,
                'length' => 255
            ]);

            $table->setPrimaryKey(['gid'], 'gid_group');
            $table->addIndex(['rid'], 'rid_group');
            $table->addIndex(['sid'], 'sid_group');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
    }
}

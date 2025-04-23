<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserISPConfigAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step
 */
class Version29000Date20250421052838 extends SimpleMigrationStep
{
    public function __construct(
        private IDBConnection $db
    )
    {
    }

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

        if (!$schema->hasTable('ispconfig_api_group_user')) {
            $table = $schema->createTable('ispconfig_api_group_user');
            $table->addColumn('gid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'default' => '',
            ]);
            $table->addColumn('uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'default' => '',
            ]);
            $table->setPrimaryKey(['gid', 'uid'], 'gid_uid_group');
            $table->addIndex(['uid'], 'gu_uid_group');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     *
     * @throws \OCP\DB\Exception
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $query = $this->db->getQueryBuilder();
        $cursor = $query->select('gu.gid', 'gu.uid')
            ->from('group_user', 'gu')
            ->innerJoin('gu', 'ispconfig_api_groups', 'g', $query->expr()->eq('gu.gid', 'g.gid'))
            ->executeQuery();

        while ($row = $cursor->fetch()) {
            $query->insert('ispconfig_api_group_user')
                ->setValue('gid', $query->createNamedParameter($row['gid']))
                ->setValue('uid', $query->createNamedParameter($row['uid']))
                ->executeStatement();

            $query->delete('group_user')
                ->where($query->expr()->eq('gid', $query->createNamedParameter($row['gid'])))
                ->andWhere($query->expr()->eq('uid', $query->createNamedParameter($row['uid'])))
                ->executeStatement();
        }
        $cursor->closeCursor();
    }
}

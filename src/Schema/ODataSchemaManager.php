<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Schema;

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\View;

class ODataSchemaManager extends AbstractSchemaManager
{
    protected function selectTableNames(string $databaseName): Result
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function _getPortableTableDefinition(array $table): string
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function _getPortableViewDefinition(array $view): View
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function _getPortableTableForeignKeyDefinition(array $tableForeignKey): ForeignKeyConstraint
    {
        throw NotSupported::new(__METHOD__);
    }
}

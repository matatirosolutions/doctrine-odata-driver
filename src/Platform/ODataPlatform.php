<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\TransactionIsolationLevel;
use Matatirosoln\DoctrineOdataDriver\Schema\ODataSchemaManager;

class ODataPlatform extends AbstractPlatform
{
    public function __construct()
    {
        // OData field names are case-sensitive — no identifier folding should be applied.
        parent::__construct(UnquotedIdentifierFolding::NONE);
    }

    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'BOOLEAN';
    }

    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return 'INT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return 'BIGINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return 'SMALLINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        return '';
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            // Standard SQL type names
            'boolean'   => 'boolean',
            'integer'   => 'integer',
            'bigint'    => 'bigint',
            'smallint'  => 'smallint',
            'decimal'   => 'decimal',
            'float'     => 'float',
            'string'    => 'string',
            'text'      => 'text',
            'date'      => 'date',
            'time'      => 'time',
            'datetime'  => 'datetime',
            'blob'      => 'blob',
            'guid'      => 'guid',

            // OData EDM primitive types — used when DBAL's type system resolves
            // types from schema introspection via $metadata.
            'Edm.String'         => 'string',
            'Edm.Int16'          => 'smallint',
            'Edm.Int32'          => 'integer',
            'Edm.Int64'          => 'bigint',
            'Edm.Decimal'        => 'decimal',
            'Edm.Double'         => 'float',
            'Edm.Single'         => 'float',
            'Edm.Boolean'        => 'boolean',
            'Edm.Date'           => 'date',
            'Edm.DateTimeOffset' => 'datetime',
            'Edm.TimeOfDay'      => 'time',
            'Edm.Binary'         => 'blob',
            'Edm.Stream'         => 'blob',
            'Edm.Guid'           => 'guid',
        ];
    }

    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'TEXT';
    }

    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return 'BLOB';
    }

    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        throw NotSupported::new(__METHOD__);
    }

    public function getDateDiffExpression(string $date1, string $date2): string
    {
        throw NotSupported::new(__METHOD__);
    }

    protected function getDateArithmeticIntervalExpression(
        string $date,
        string $operator,
        string $interval,
        DateIntervalUnit $unit,
    ): string {
        throw NotSupported::new(__METHOD__);
    }

    public function getCurrentDatabaseExpression(): string
    {
        throw NotSupported::new(__METHOD__);
    }

    public function getAlterTableSQL(TableDiff $diff): array
    {
        throw NotSupported::new(__METHOD__);
    }

    /**
     * Required by AbstractPlatform (abstract method).
     *
     * This method is marked @internal by DBAL and should only be called from
     * within AbstractSchemaManager. ODataSchemaManager::listViews() returns []
     * directly, so this method is never reached in practice.
     */
    public function getListViewsSQL(string $database): string
    {
        return 'SELECT NULL WHERE 1=0';
    }

    public function getSetTransactionIsolationSQL(TransactionIsolationLevel $level): string
    {
        throw NotSupported::new(__METHOD__);
    }

    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'DATETIME';
    }

    public function getDateTypeDeclarationSQL(array $column): string
    {
        return 'DATE';
    }

    public function getTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIME';
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        /** @phpstan-ignore new.deprecated */
        return new ODataKeywords();
    }

    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        return new ODataSchemaManager($connection, $this);
    }
}

<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Schema;

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;

class ODataSchemaManager extends AbstractSchemaManager
{
    /**
     * Maps OData EDM primitive types to Doctrine DBAL type names.
     * Any type not listed here falls back to Types::STRING.
     */
    private const array EDM_TYPE_MAP = [
        'Edm.String'         => Types::STRING,
        'Edm.Int16'          => Types::SMALLINT,
        'Edm.Int32'          => Types::INTEGER,
        'Edm.Int64'          => Types::BIGINT,
        'Edm.Decimal'        => Types::DECIMAL,
        'Edm.Double'         => Types::FLOAT,
        'Edm.Single'         => Types::FLOAT,
        'Edm.Boolean'        => Types::BOOLEAN,
        'Edm.Date'           => Types::DATE_MUTABLE,
        'Edm.DateTimeOffset' => Types::DATETIME_MUTABLE,
        'Edm.TimeOfDay'      => Types::TIME_MUTABLE,
        'Edm.Binary'         => Types::BLOB,
        'Edm.Stream'         => Types::BLOB,
        'Edm.Guid'           => Types::GUID,
    ];

    // -------------------------------------------------------------------------
    // Schema introspection — powered by OData $metadata
    // -------------------------------------------------------------------------

    /**
     * Returns all entity-set names exposed by the OData endpoint.
     *
     * @return list<string>
     */
    public function listTableNames(): array
    {
        return array_keys($this->odataMetadata());
    }

    /**
     * Returns the columns for the given entity set, mapped from OData EDM types
     * to Doctrine DBAL types.
     *
     * @return array<string, Column>
     */
    public function listTableColumns(string $table): array
    {
        $metadata = $this->odataMetadata();

        if (!isset($metadata[$table])) {
            return [];
        }

        $columns = [];
        foreach ($metadata[$table]['properties'] as $name => $info) {
            $dbalTypeName = self::EDM_TYPE_MAP[$info['type']] ?? Types::STRING;
            $type         = Type::getType($dbalTypeName);

            $columns[strtolower($name)] = new Column($name, $type, [
                'notnull' => !$info['nullable'],
            ]);
        }

        return $columns;
    }

    /**
     * OData exposes entity sets, not views. Always returns empty.
     *
     * Overridden here rather than via the @internal getListViewsSQL() method
     * on AbstractPlatform, which should not be extended by drivers.
     *
     * @return array<string, \Doctrine\DBAL\Schema\View>
     */
    public function listViews(): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // DDL write operations — not supported via OData
    //
    // Schema modifications are an OData-server-specific extension (not part of
    // the OData v4 spec). Override all inherited write methods to fail loudly
    // rather than attempting to execute SQL that the OData driver cannot handle.
    // -------------------------------------------------------------------------

    public function createDatabase(string $database): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function dropDatabase(string $database): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function createTable(Table $table): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function dropTable(string $name): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function renameTable(string $name, string $newName): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function alterTable(\Doctrine\DBAL\Schema\TableDiff $tableDiff): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function createIndex(Index $index, string $table): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function dropIndex(string $index, string $table): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function createForeignKey(ForeignKeyConstraint $foreignKey, string $table): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function dropForeignKey(string $name, string $table): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function createUniqueConstraint(UniqueConstraint $uniqueConstraint, string $tableName): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function dropUniqueConstraint(string $name, string $tableName): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function createView(View $view): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function dropView(string $name): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function createSequence(Sequence $sequence): void
    {
        throw NotSupported::new(__METHOD__);
    }

    public function dropSequence(string $name): void
    {
        throw NotSupported::new(__METHOD__);
    }

    // -------------------------------------------------------------------------
    // Abstract method stubs — required by AbstractSchemaManager but bypassed
    // by the overrides above.
    // -------------------------------------------------------------------------

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
        return [];
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

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieves the cached OData $metadata via the native driver connection.
     *
     * @return array<string, array{pk: string, properties: array<string, array{type: string, nullable: bool}>}>
     */
    private function odataMetadata(): array
    {
        /** @var ODataConnection $odataConnection */
        $odataConnection = $this->_conn->getNativeConnection();
        return $odataConnection->getMetadata();
    }
}

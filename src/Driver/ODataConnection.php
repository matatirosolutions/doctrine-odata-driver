<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Driver;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;

class ODataConnection implements ConnectionInterface
{
    /**
     * Cached metadata from the OData $metadata endpoint.
     * Null until first accessed; fetched lazily and held for the lifetime
     * of the connection (i.e. the request in a typical Symfony app).
     *
     * @var array{entities: array<string, array{pk: string, properties: array<string, array{type: string, nullable: bool}>>}, valueLists: list<string>}|null
     */
    private ?array $parsedMetadata = null;

    public function __construct(
        private readonly ODataClient $client,
        private readonly bool $quoteGuids = false,
    ) {
    }

    public function prepare(string $sql): StatementInterface
    {
        return new ODataStatement($sql, $this->client, $this->quoteGuids, $this->getMetadata());
    }

    public function query(string $sql): ResultInterface
    {
        return $this->prepare($sql)->execute();
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function exec(string $sql): int
    {
        throw new ODataDriverException('exec() is not supported for OData connections.');
    }

    public function lastInsertId(): int|string
    {
        throw new ODataDriverException('lastInsertId() is not supported.');
    }

    public function beginTransaction(): void
    {
        // OData has no transaction concept — each HTTP request is implicitly
        // committed. Doctrine calls beginTransaction() / commit() around every
        // flush(); we silently accept them so that normal UoW usage works.
    }

    public function commit(): void
    {
        // No-op: see beginTransaction().
    }

    public function rollBack(): void
    {
        // OData operations cannot be rolled back. Doctrine calls this on
        // exception paths, so we swallow it rather than masking the original
        // error with a secondary exception.
    }

    public function getServerVersion(): string
    {
        return $this->client->getServerVersion();
    }

    /**
     * Returns this connection instance as the "native" connection.
     *
     * The ODataSchemaManager accesses entity-set metadata via this method,
     * which is the standard DBAL pattern for driver-level introspection.
     */
    public function getNativeConnection(): static
    {
        return $this;
    }

    /** Direct access to the HTTP client for callers that need it. */
    public function getClient(): ODataClient
    {
        return $this->client;
    }

    /**
     * Returns entity-set metadata for all entity sets, fetching and caching on
     * first call. This is the interface used by ODataStatement and
     * ODataSchemaManager — the return shape is unchanged from before the
     * addition of value-list support.
     *
     * @return array<string, array{pk: string, properties: array<string, array{type: string, nullable: bool}>}>
     */
    public function getMetadata(): array
    {
        return $this->parsed()['entities'];
    }

    /**
     * Returns the names of all value lists defined in the FileMaker database,
     * as discovered from the OData $metadata EnumType elements.
     *
     * This is names only — the actual entries for each list must be fetched
     * via the FileMaker_ValueList_{name} entity-set endpoints (see ValueListService).
     *
     * @return list<string>
     */
    public function getValueLists(): array
    {
        return $this->parsed()['valueLists'];
    }

    /**
     * Lazily fetches and caches the full parsed metadata from the OData server.
     *
     * @return array{entities: array<string, array{pk: string, properties: array<string, array{type: string, nullable: bool}>>}, valueLists: list<string>}
     */
    private function parsed(): array
    {
        return $this->parsedMetadata ??= $this->client->fetchMetadata();
    }
}

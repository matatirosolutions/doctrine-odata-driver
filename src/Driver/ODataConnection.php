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
    public function __construct(private readonly ODataClient $client)
    {
    }

    public function prepare(string $sql): StatementInterface
    {
        return new ODataStatement($sql, $this->client);
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
        throw new ODataDriverException('exec() is not supported for read-only OData connections.');
    }

    public function lastInsertId(): int|string
    {
        throw new ODataDriverException('lastInsertId() is not supported.');
    }

    public function beginTransaction(): void
    {
        throw new ODataDriverException('Transactions are not supported by OData.');
    }

    public function commit(): void
    {
        throw new ODataDriverException('Transactions are not supported by OData.');
    }

    public function rollBack(): void
    {
        throw new ODataDriverException('Transactions are not supported by OData.');
    }

    public function getServerVersion(): string
    {
        return $this->client->getServerVersion();
    }

    public function getNativeConnection(): ODataClient
    {
        return $this->client;
    }
}

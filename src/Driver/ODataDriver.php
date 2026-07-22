<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Driver;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;
use Matatirosoln\DoctrineOdataDriver\Platform\ODataPlatform;
use Psr\SimpleCache\CacheInterface;

class ODataDriver implements DriverInterface
{
    public function connect(array $params): ODataConnection
    {
        // driverOptions (set via doctrine.yaml `options:`) are merged for convenience
        $parameters = array_merge($params['driverOptions'] ?? [], $params);

        $metadataCache = $parameters['metadata_cache'] ?? null;

        $client = new ODataClient(
            host: $parameters['host'] ?? throw new \InvalidArgumentException('Missing "host" connection param.'),
            user: $parameters['user'] ?? $parameters['username'] ?? '',
            password: $parameters['password'] ?? '',
            dbname: $parameters['dbname'] ?? throw new \InvalidArgumentException('Missing "dbname" connection param.'),
            port: (int) ($parameters['port'] ?? 443),
            urlPrefix: $parameters['url_prefix'] ?? '/fmi/odata/v4',
            ssl: (bool) ($parameters['ssl'] ?? true),
            metadataCache: $metadataCache instanceof CacheInterface ? $metadataCache : null,
            metadataTtl: (int) ($parameters['metadata_ttl'] ?? 0),
            timeout: (int) ($parameters['timeout'] ?? 30),
        );

        return new ODataConnection($client, quoteGuids: (bool) ($parameters['quote_guids'] ?? false));
    }

    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return new ODataPlatform();
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return new ODataExceptionConverter();
    }
}

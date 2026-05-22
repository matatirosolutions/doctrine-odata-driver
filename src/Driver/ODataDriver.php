<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Driver;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;
use Matatirosoln\DoctrineOdataDriver\Platform\ODataPlatform;

class ODataDriver implements DriverInterface
{
    public function connect(array $params): ODataConnection
    {
        $client = new ODataClient(
            host: $params['host'] ?? throw new \InvalidArgumentException('Missing "host" connection param.'),
            user: $params['user'] ?? $params['username'] ?? '',
            password: $params['password'] ?? '',
            dbname: $params['dbname'] ?? throw new \InvalidArgumentException('Missing "dbname" connection param.'),
            port: (int) ($params['port'] ?? 443),
            urlPrefix: $params['url_prefix'] ?? '/fmi/odata/v4',
            ssl: (bool) ($params['ssl'] ?? true),
        );

        return new ODataConnection($client);
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

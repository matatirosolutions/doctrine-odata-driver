<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Driver;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DriverException as DBALDriverException;
use Doctrine\DBAL\Query;

class ODataExceptionConverter implements ExceptionConverter
{
    public function convert(DriverException $exception, ?Query $query): DBALDriverException
    {
        return new DBALDriverException($exception, $query);
    }
}

<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Exception;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Exception;

class ODataDriverException extends Exception implements DriverExceptionInterface
{
    public function __construct(string $message, private readonly int $odbcCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getSQLState(): ?string
    {
        return null;
    }

    public function getErrorCode(): int
    {
        return $this->odbcCode;
    }
}

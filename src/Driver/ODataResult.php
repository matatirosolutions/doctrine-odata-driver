<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Driver;

use Doctrine\DBAL\Driver\Result as ResultInterface;

class ODataResult implements ResultInterface
{
    private int $position = 0;

    /** @var list<array<string, mixed>> */
    private array $rows;

    /** @var list<string> */
    private array $columns;

    /** @param array<string, mixed> $odataResponse */
    public function __construct(array $odataResponse)
    {
        $this->rows = $odataResponse['value'] ?? [];
        $this->columns = !empty($this->rows) ? array_keys($this->rows[0]) : [];
    }

    public function fetchNumeric(): array|false
    {
        if ($this->position >= count($this->rows)) {
            return false;
        }

        return array_values($this->rows[$this->position++]);
    }

    public function fetchAssociative(): array|false
    {
        if ($this->position >= count($this->rows)) {
            return false;
        }

        return $this->rows[$this->position++];
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();
        if ($row === false) {
            return false;
        }

        return $row[0] ?? false;
    }

    public function fetchAllNumeric(): array
    {
        return array_map(static fn(array $row) => array_values($row), $this->rows);
    }

    public function fetchAllAssociative(): array
    {
        return $this->rows;
    }

    public function fetchFirstColumn(): array
    {
        return array_map(static fn(array $row) => array_values($row)[0] ?? null, $this->rows);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function free(): void
    {
        $this->rows    = [];
        $this->columns = [];
        $this->position = 0;
    }
}

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

    /**
     * @param array<string, mixed>                      $odataResponse
     * @param list<array{field: string, alias: string}> $columns
     *        Ordered column map from the SQL SELECT clause. When provided,
     *        each row is reindexed so that:
     *          - keys  = SQL aliases (e.g. "id_1", "Name_2") that Doctrine expects
     *          - order = matches the SQL SELECT position for numeric/positional fetch
     *        Missing OData fields are returned as null rather than causing errors.
     *        When empty (SELECT *, aggregates, …) the raw OData fields are kept.
     */
    public function __construct(array $odataResponse, array $columns = [])
    {
        $rawRows = array_map(
            static fn(array $row) => array_filter(
                $row,
                static fn(string $key) => !str_starts_with($key, '@'),
                ARRAY_FILTER_USE_KEY,
            ),
            $odataResponse['value'] ?? [],
        );

        if (empty($columns)) {
            $this->rows = $rawRows;
        } else {
            $this->rows = array_map(static function (array $row) use ($columns): array {
                $mapped = [];
                foreach ($columns as $col) {
                    // Key = SQL alias Doctrine expects; value from the OData field name
                    $mapped[$col['alias']] = $row[$col['field']] ?? null;
                }
                return $mapped;
            }, $rawRows);
        }

        $first = reset($this->rows);
        $this->columns = $first !== false ? array_keys($first) : [];
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

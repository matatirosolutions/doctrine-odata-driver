<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Driver;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;
use Matatirosoln\SqlToOdata\Exception\ConversionException;
use Matatirosoln\SqlToOdata\Query\DeleteQuery;
use Matatirosoln\SqlToOdata\Query\InsertQuery;
use Matatirosoln\SqlToOdata\Query\SelectQuery;
use Matatirosoln\SqlToOdata\Query\UpdateQuery;
use Matatirosoln\SqlToOdata\SqlToOdata;

class ODataStatement implements StatementInterface
{
    /** @var array<int|string, array{value: mixed, type: ParameterType}> */
    private array $boundParams = [];

    public function __construct(
        private readonly string $sql,
        private readonly ODataClient $client,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->boundParams[$param] = ['value' => $value, 'type' => $type];
    }

    public function execute(): ResultInterface
    {
        $sql = $this->substituteParams($this->sql);

        try {
            $query = new SqlToOdata()->parse($sql);
        } catch (ConversionException $e) {
            throw new ODataDriverException($e->getMessage(), 0, $e);
        }

        if ($query instanceof SelectQuery) {
            return new ODataResult(
                $this->client->get($query->entitySet, $query->queryString),
            );
        }

        if ($query instanceof InsertQuery) {
            // OData has no bulk-insert endpoint; POST each row individually
            // and collect the created records into a value array.
            $created = array_map(
                fn(array $row) => $this->client->post($query->entitySet, $row),
                $query->rows,
            );
            return new ODataResult(['value' => $created]);
        }

        if ($query instanceof UpdateQuery) {
            return new ODataResult(
                $this->wrapSingle(
                    $this->client->patch($query->entitySet, $query->filter, $query->body),
                ),
            );
        }

        if ($query instanceof DeleteQuery) {
            $this->client->delete($query->entitySet, $query->filter);
            return new ODataResult(['value' => []]);
        }

        throw new ODataDriverException("Unsupported query type: " . get_class($query));
    }

    /**
     * Wraps a single-record response (no "value" key) into the standard
     * {"value": [...]} envelope that ODataResult expects.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function wrapSingle(array $response): array
    {
        if (array_key_exists('value', $response)) {
            return $response;
        }

        return $response !== [] ? ['value' => [$response]] : ['value' => []];
    }

    private function substituteParams(string $sql): string
    {
        if (empty($this->boundParams)) {
            return $sql;
        }

        // Named params (:name)
        foreach ($this->boundParams as $key => $binding) {
            if (is_string($key)) {
                $placeholder = ':' . ltrim($key, ':');
                $sql = str_replace($placeholder, $this->formatValue($binding['value'], $binding['type']), $sql);
            }
        }

        // Positional params (?)
        $positional = array_filter(
            $this->boundParams,
            static fn(int|string $k) => is_int($k),
            ARRAY_FILTER_USE_KEY,
        );

        if (!empty($positional)) {
            ksort($positional);
            foreach ($positional as $binding) {
                $formatted = $this->formatValue($binding['value'], $binding['type']);
                $sql = preg_replace('/\?/', $formatted, $sql, 1);
            }
        }

        return $sql;
    }

    private function formatValue(mixed $value, ParameterType $type): string
    {
        return match ($type) {
            ParameterType::NULL    => 'null',
            ParameterType::INTEGER => (string) (int) $value,
            ParameterType::BOOLEAN => $value ? 'true' : 'false',
            default                => "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }
}

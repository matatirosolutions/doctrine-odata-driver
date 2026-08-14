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
use Matatirosoln\SqlToOdata\Support\KeyValue;
use Matatirosoln\SqlToOdata\Support\WhereKeyExtractor;
use Matatirosoln\SqlToOdata\SqlToOdata;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;

class ODataStatement implements StatementInterface
{
    /** @var array<int|string, array{value: mixed, type: ParameterType}> */
    private array $boundParams = [];

    /**
     * @param array<string, array{pk: string, properties: array<string, array{type: string, nullable: bool}>}> $metadata
     *        Entity-set metadata from the OData $metadata endpoint, as parsed
     *        and cached by ODataConnection. Used to resolve primary-key field
     *        names without PHP reflection.
     */
    public function __construct(
        private readonly string $sql,
        private readonly ODataClient $client,
        private readonly bool $quoteGuids = false,
        private readonly array $metadata = [],
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
            $query = (new SqlToOdata(quoteGuids: $this->quoteGuids))->parse($sql);
        } catch (ConversionException $e) {
            throw new ODataDriverException($e->getMessage(), 0, $e);
        }

        if ($query instanceof SelectQuery) {
            $keyValue    = $this->resolveKeyValue($sql, $query->entitySet);
            $queryString = $keyValue !== null
                ? $this->stripFilter($query->queryString)
                : $query->queryString;
            return new ODataResult(
                $this->client->get($query->entitySet, $queryString, $keyValue),
                $query->columns,
            );
        }

        if ($query instanceof InsertQuery) {
            $created = array_map(
                fn(array $row) => $this->client->post($query->entitySet, $row),
                $query->rows,
            );
            return new ODataResult(['value' => $created]);
        }

        if ($query instanceof UpdateQuery) {
            $keyValue = $this->resolveKeyValue($sql, $query->entitySet);
            return new ODataResult(
                $this->wrapSingle(
                    $this->client->patch($query->entitySet, $query->body, $keyValue, $query->filter),
                ),
            );
        }

        if ($query instanceof DeleteQuery) {
            $keyValue = $this->resolveKeyValue($sql, $query->entitySet);
            $this->client->delete($query->entitySet, $keyValue, $query->filter);
            return new ODataResult(['value' => []]);
        }

        throw new ODataDriverException("Unsupported query type: " . get_class($query));
    }

    /**
     * Inspects the raw SQL WHERE clause and, when the entity's primary-key field
     * is matched by a single equality condition, returns a KeyValue for use as an
     * OData key-path URL segment (/Entity('uuid') or /Entity(42)).
     * Returns null for any other WHERE pattern or when the PK cannot be resolved.
     */
    private function resolveKeyValue(string $sql, string $entitySet): ?KeyValue
    {
        $pkField = $this->detectPrimaryKeyField($entitySet);
        if ($pkField === null) {
            return null;
        }

        $parser    = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        [$where, $aliases] = match (true) {
            $statement instanceof SelectStatement => [
                $statement->where ?? [],
                array_filter(array_column($statement->from, 'alias')),
            ],
            $statement instanceof UpdateStatement => [$statement->where ?? [], []],
            $statement instanceof DeleteStatement => [$statement->where ?? [], []],
            default                               => [[], []],
        };

        if (empty($where)) {
            return null;
        }

        return WhereKeyExtractor::extract($where, array_values(array_filter($aliases)), $pkField);
    }

    /**
     * Returns the primary-key field name for the given entity set by looking it
     * up in the OData $metadata that was fetched and cached by ODataConnection.
     *
     * Returns null when the entity set is not in the metadata (in which case
     * key-path detection is skipped and a $filter query is used instead).
     */
    private function detectPrimaryKeyField(string $entitySet): ?string
    {
        $pk = $this->metadata[$entitySet]['pk'] ?? null;
        return ($pk !== null && $pk !== '') ? $pk : null;
    }

    /**
     * Removes the $filter parameter from an OData query string.
     *
     * Used when the driver promotes a WHERE clause to an OData key-path URL
     * (/Entity('key')): the $filter produced by sql-to-odata must be dropped
     * since the key-path already encodes the lookup condition.
     */
    private function stripFilter(string $queryString): string
    {
        if ($queryString === '' || $queryString === '?') {
            return $queryString;
        }

        $raw    = ltrim($queryString, '?');
        $params = array_filter(
            explode('&', $raw),
            static fn(string $p) => !str_starts_with($p, '$filter='),
        );

        return '?' . implode('&', $params);
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
                $sql         = str_replace($placeholder, $this->formatValue($binding['value'], $binding['type']), $sql);
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
                $sql       = preg_replace('/\?/', $formatted, $sql, 1);
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
            default                => is_int($value) || is_float($value)
                ? (string) $value
                : "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }
}

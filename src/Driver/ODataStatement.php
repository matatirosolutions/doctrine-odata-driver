<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Driver;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;
use Matatirosoln\SqlToOdata\Exception\ConversionException;
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
            $parsed = (new SqlToOdata())->parse($sql);
        } catch (ConversionException $e) {
            throw new ODataDriverException($e->getMessage(), 0, $e);
        }

        $data = $this->client->get($parsed->entitySet, $parsed->queryString);

        return new ODataResult($data);
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

<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Driver;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;
use Matatirosoln\SqlToOdata\SqlToOdata;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

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

        $parser    = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            throw new ODataDriverException('Only SELECT statements are supported at this time.');
        }

        $entitySet = $this->extractEntitySet($statement);
        $converter = new SqlToOdata();
        $queryString = $converter->convert($sql);

        $data = $this->client->get($entitySet, $queryString);

        return new ODataResult($data);
    }

    private function extractEntitySet(SelectStatement $statement): string
    {
        $table = $statement->from[0]->table ?? null;

        if ($table === null || $table === '') {
            throw new ODataDriverException('Could not determine table name from SQL.');
        }

        // Strip ANSI/MySQL quoting that the ORM may add
        return trim($table, '`"\'');
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
                // Replace only the first remaining `?`
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

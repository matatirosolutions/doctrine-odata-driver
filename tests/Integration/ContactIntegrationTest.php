<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Tests\Integration;

use Doctrine\DBAL\ParameterType;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataDriver;
use PHPUnit\Framework\TestCase;

class ContactIntegrationTest extends TestCase
{
    private ODataConnection $connection;

    protected function setUp(): void
    {
        if (!getenv('ODATA_INTEGRATION')) {
            $this->markTestSkipped(
                'Integration tests are disabled. Copy phpunit.integration.xml.example to ' .
                'phpunit.integration.xml, fill in your server details, and run: ' .
                './vendor/bin/phpunit -c phpunit.integration.xml',
            );
        }

        $missing = array_filter(
            ['ODATA_HOST', 'ODATA_USER', 'ODATA_PASSWORD', 'ODATA_DBNAME'],
            static fn(string $var) => getenv($var) === false || getenv($var) === '',
        );

        if (!empty($missing)) {
            $this->markTestSkipped(
                'Missing required environment variables: ' . implode(', ', $missing) . '. ' .
                'Check your phpunit.integration.xml.',
            );
        }

        $this->connection = (new ODataDriver())->connect([
            'host'     => getenv('ODATA_HOST'),
            'user'     => getenv('ODATA_USER'),
            'password' => getenv('ODATA_PASSWORD'),
            'dbname'   => getenv('ODATA_DBNAME'),
        ]);
    }

    public function testFetchAllContactsReturnsThreeRows(): void
    {
        $result = $this->connection->query('SELECT * FROM Contact');

        self::assertCount(3, $result->fetchAllAssociative());
    }

    public function testRowsDoNotContainOdataAnnotations(): void
    {
        $result = $this->connection->query('SELECT * FROM Contact');
        $rows   = $result->fetchAllAssociative();

        foreach (array_keys($rows[0]) as $key) {
            self::assertFalse(
                str_starts_with($key, '@'),
                "Row contains OData annotation key: {$key}",
            );
        }
    }

    public function testFilterByCityStringLiteral(): void
    {
        $result = $this->connection->query("SELECT * FROM Contact WHERE City = 'Auckland'");
        $rows   = $result->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]['Name']);
    }

    public function testFilterByCityBoundParameter(): void
    {
        $stmt = $this->connection->prepare('SELECT * FROM Contact WHERE City = ?');
        $stmt->bindValue(1, 'Wellington', ParameterType::STRING);
        $rows = $stmt->execute()->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('Bob', $rows[0]['Name']);
    }

    public function testSelectSpecificColumns(): void
    {
        $result = $this->connection->query('SELECT Name, City FROM Contact');
        $rows   = $result->fetchAllAssociative();

        self::assertCount(3, $rows);
        self::assertArrayHasKey('Name', $rows[0]);
        self::assertArrayHasKey('City', $rows[0]);
        self::assertArrayNotHasKey('id', $rows[0]);
    }

    public function testOrderByNameAscending(): void
    {
        $rows = $this->connection
            ->query('SELECT Name, City FROM Contact ORDER BY Name ASC')
            ->fetchAllAssociative();

        self::assertSame(['Alice', 'Bob', 'Jane'], array_column($rows, 'Name'));
    }

    public function testTopLimitsResults(): void
    {
        $result = $this->connection->query('SELECT * FROM Contact ORDER BY Name ASC LIMIT 2');

        self::assertCount(2, $result->fetchAllAssociative());
    }

    public function testFetchAssociativeIteratesRows(): void
    {
        $result = $this->connection->query('SELECT Name, City FROM Contact ORDER BY Name ASC');

        $first = $result->fetchAssociative();
        self::assertIsArray($first);
        self::assertSame('Alice', $first['Name']);

        $second = $result->fetchAssociative();
        self::assertIsArray($second);
        self::assertSame('Bob', $second['Name']);
    }

    public function testRowCount(): void
    {
        $result = $this->connection->query('SELECT * FROM Contact');

        self::assertSame(3, $result->rowCount());
    }
}

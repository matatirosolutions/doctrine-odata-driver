<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Tests\Integration;

use Doctrine\DBAL\ParameterType;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataDriver;
use PHPUnit\Framework\TestCase;

class WriteIntegrationTest extends TestCase
{
    private ODataConnection $connection;

    // Unique marker so tearDown can clean up regardless of what the test did
    private const TEST_CITY = 'IntegrationTestCity';

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

        $this->connection = new ODataDriver()->connect([
            'host'     => getenv('ODATA_HOST'),
            'user'     => getenv('ODATA_USER'),
            'password' => getenv('ODATA_PASSWORD'),
            'dbname'   => getenv('ODATA_DBNAME'),
        ]);
    }

    protected function tearDown(): void
    {
        // Always attempt to remove any records created during the test run.
        // This runs even if the test fails, keeping the database clean.
        if (isset($this->connection)) {
            $stmt = $this->connection->prepare('DELETE FROM Contact WHERE City = ?');
            $stmt->bindValue(1, self::TEST_CITY, ParameterType::STRING);
            $stmt->execute();
        }
    }

    public function testInsertCreatesRecord(): void
    {
        $stmt = $this->connection->prepare('INSERT INTO Contact (Name, City) VALUES (?, ?)');
        $stmt->bindValue(1, 'TestInsert', ParameterType::STRING);
        $stmt->bindValue(2, self::TEST_CITY, ParameterType::STRING);
        $result = $stmt->execute();

        self::assertSame(1, $result->rowCount());

        $rows = $this->connection
            ->query("SELECT * FROM Contact WHERE City = '" . self::TEST_CITY . "'")
            ->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('TestInsert', $rows[0]['Name']);
    }

    public function testUpdateChangesField(): void
    {
        // Insert a record to update
        $insert = $this->connection->prepare('INSERT INTO Contact (Name, City) VALUES (?, ?)');
        $insert->bindValue(1, 'TestUpdate', ParameterType::STRING);
        $insert->bindValue(2, self::TEST_CITY, ParameterType::STRING);
        $insert->execute();

        // Update the name
        $update = $this->connection->prepare('UPDATE Contact SET Name = ? WHERE City = ?');
        $update->bindValue(1, 'TestUpdated', ParameterType::STRING);
        $update->bindValue(2, self::TEST_CITY, ParameterType::STRING);
        $update->execute();

        $rows = $this->connection
            ->query("SELECT * FROM Contact WHERE City = '" . self::TEST_CITY . "'")
            ->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('TestUpdated', $rows[0]['Name']);
    }

    public function testDeleteRemovesRecord(): void
    {
        // Insert a record to delete
        $insert = $this->connection->prepare('INSERT INTO Contact (Name, City) VALUES (?, ?)');
        $insert->bindValue(1, 'TestDelete', ParameterType::STRING);
        $insert->bindValue(2, self::TEST_CITY, ParameterType::STRING);
        $insert->execute();

        // Confirm it exists
        $before = $this->connection
            ->query("SELECT * FROM Contact WHERE City = '" . self::TEST_CITY . "'")
            ->fetchAllAssociative();
        self::assertCount(1, $before);

        // Delete it
        $delete = $this->connection->prepare('DELETE FROM Contact WHERE City = ?');
        $delete->bindValue(1, self::TEST_CITY, ParameterType::STRING);
        $delete->execute();

        // Confirm it's gone
        $after = $this->connection
            ->query("SELECT * FROM Contact WHERE City = '" . self::TEST_CITY . "'")
            ->fetchAllAssociative();
        self::assertCount(0, $after);
    }
}

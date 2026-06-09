<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Tests\Driver;

use Doctrine\DBAL\ParameterType;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataStatement;
use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ODataStatementTest extends TestCase
{
    private ODataClient&MockObject $client;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ODataClient::class);
    }

    // --- SELECT ---

    public function testSimpleSelectReturnsRows(): void
    {
        $this->client
            ->expects($this->once())
            ->method('get')
            ->with('Contacts', $this->stringContains('?'))
            ->willReturn(['value' => [['id' => 1, 'Name' => 'Alice']]]);

        $result = (new ODataStatement('SELECT id, Name FROM Contacts', $this->client))->execute();

        self::assertSame([['id' => 1, 'Name' => 'Alice']], $result->fetchAllAssociative());
    }

    public function testSelectWithWhereClause(): void
    {
        $this->client->method('get')
            ->with('Contacts', $this->stringContains('$filter'))
            ->willReturn(['value' => []]);

        (new ODataStatement("SELECT * FROM Contacts WHERE City = 'Auckland'", $this->client))->execute();
    }

    public function testPositionalParameterSubstitution(): void
    {
        $this->client->method('get')
            ->with('Contacts', $this->stringContains("'Auckland'"))
            ->willReturn(['value' => []]);

        $stmt = new ODataStatement('SELECT * FROM Contacts WHERE City = ?', $this->client);
        $stmt->bindValue(1, 'Auckland', ParameterType::STRING);
        $stmt->execute();
    }

    public function testIntegerParameterNotQuoted(): void
    {
        $this->client->method('get')
            ->with('Contacts', $this->stringContains('42'))
            ->willReturn(['value' => []]);

        $stmt = new ODataStatement('SELECT * FROM Contacts WHERE id = ?', $this->client);
        $stmt->bindValue(1, 42, ParameterType::INTEGER);
        $stmt->execute();
    }

    // --- INSERT ---

    public function testInsertCallsPostWithCorrectEntitySetAndBody(): void
    {
        $this->client
            ->expects($this->once())
            ->method('post')
            ->with('Contact', ['Name' => 'Alice', 'City' => 'Auckland'])
            ->willReturn(['Name' => 'Alice', 'City' => 'Auckland']);

        $stmt = new ODataStatement(
            "INSERT INTO Contact (Name, City) VALUES ('Alice', 'Auckland')",
            $this->client,
        );
        $stmt->execute();
    }

    public function testInsertResultContainsCreatedRow(): void
    {
        $this->client->method('post')
            ->willReturn(['Name' => 'Alice', 'City' => 'Auckland']);

        $result = (new ODataStatement(
            "INSERT INTO Contact (Name, City) VALUES ('Alice', 'Auckland')",
            $this->client,
        ))->execute();

        self::assertSame(1, $result->rowCount());
        self::assertSame('Alice', $result->fetchAssociative()['Name']);
    }

    public function testMultipleInsertRowsCallsPostForEachRow(): void
    {
        $this->client
            ->expects($this->exactly(2))
            ->method('post')
            ->willReturn([]);

        (new ODataStatement(
            "INSERT INTO Contact (Name, City) VALUES ('Alice', 'Auckland'), ('Bob', 'Wellington')",
            $this->client,
        ))->execute();
    }

    // --- UPDATE ---

    public function testUpdateCallsPatchWithFilterAndBody(): void
    {
        $this->client
            ->expects($this->once())
            ->method('patch')
            ->with('Contact', ['City' => 'Wellington'], null, "City eq 'Auckland'")
            ->willReturn(['Name' => 'Alice', 'City' => 'Wellington']);

        (new ODataStatement(
            "UPDATE Contact SET City = 'Wellington' WHERE City = 'Auckland'",
            $this->client,
        ))->execute();
    }

    public function testUpdateResultContainsUpdatedRow(): void
    {
        $this->client->method('patch')
            ->willReturn(['Name' => 'Alice', 'City' => 'Wellington']);

        $result = (new ODataStatement(
            "UPDATE Contact SET City = 'Wellington' WHERE City = 'Auckland'",
            $this->client,
        ))->execute();

        self::assertSame(1, $result->rowCount());
        self::assertSame('Wellington', $result->fetchAssociative()['City']);
    }

    public function testUpdateWithoutWhereThrows(): void
    {
        $this->expectException(ODataDriverException::class);

        (new ODataStatement('UPDATE Contact SET City = ?', $this->client))->execute();
    }

    // --- DELETE ---

    public function testDeleteCallsDeleteWithFilter(): void
    {
        $this->client
            ->expects($this->once())
            ->method('delete')
            ->with('Contact', null, "City eq 'Auckland'");

        (new ODataStatement(
            "DELETE FROM Contact WHERE City = 'Auckland'",
            $this->client,
        ))->execute();
    }

    public function testDeleteReturnsEmptyResult(): void
    {
        $this->client->method('delete');

        $result = (new ODataStatement(
            "DELETE FROM Contact WHERE City = 'Auckland'",
            $this->client,
        ))->execute();

        self::assertSame(0, $result->rowCount());
    }

    public function testDeleteWithoutWhereThrows(): void
    {
        $this->expectException(ODataDriverException::class);

        (new ODataStatement('DELETE FROM Contact', $this->client))->execute();
    }
}

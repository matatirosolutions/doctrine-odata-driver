<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Tests\Driver;

use Doctrine\DBAL\ParameterType;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataStatement;
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

    public function testSimpleSelectReturnsRows(): void
    {
        $this->client
            ->expects($this->once())
            ->method('get')
            ->with('Contacts', $this->stringContains('?'))
            ->willReturn(['value' => [['id' => 1, 'Name' => 'Alice']]]);

        $stmt   = new ODataStatement('SELECT id, Name FROM Contacts', $this->client);
        $result = $stmt->execute();

        self::assertSame([['id' => 1, 'Name' => 'Alice']], $result->fetchAllAssociative());
    }

    public function testSelectWithWhereClause(): void
    {
        $this->client
            ->expects($this->once())
            ->method('get')
            ->with('Contacts', $this->stringContains('$filter'))
            ->willReturn(['value' => []]);

        $stmt = new ODataStatement("SELECT * FROM Contacts WHERE City = 'Auckland'", $this->client);
        $stmt->execute();
    }

    public function testPositionalParameterSubstitution(): void
    {
        $this->client
            ->expects($this->once())
            ->method('get')
            ->with('Contacts', $this->stringContains("'Auckland'"))
            ->willReturn(['value' => []]);

        $stmt = new ODataStatement('SELECT * FROM Contacts WHERE City = ?', $this->client);
        $stmt->bindValue(1, 'Auckland', ParameterType::STRING);
        $stmt->execute();
    }

    public function testIntegerParameterNotQuoted(): void
    {
        $this->client
            ->expects($this->once())
            ->method('get')
            ->with('Contacts', $this->stringContains('42'))
            ->willReturn(['value' => []]);

        $stmt = new ODataStatement('SELECT * FROM Contacts WHERE id = ?', $this->client);
        $stmt->bindValue(1, 42, ParameterType::INTEGER);
        $stmt->execute();
    }

    public function testNonSelectThrows(): void
    {
        $this->expectException(\Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException::class);
        $stmt = new ODataStatement('UPDATE Contacts SET Name = ?', $this->client);
        $stmt->execute();
    }
}

<?php

namespace Symfony\AI\Store\Tests\Bridge\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\MySQLSchemaManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\VectorType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Bridge\Doctrine\Store;

#[CoversClass(Store::class)]
final class StoreTest extends TestCase
{
    private Connection&MockObject $connection;
    private Store $store;
    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->store = new Store(
            $this->connection,
            'table_name',
            'index_name',
            'vector_field',
        );
    }

    public function testSetup(): void
    {
        $schemaManager = $this->createMock(MySQLSchemaManager::class);
        $this->connection->expects($this->once())
            ->method('createSchemaManager')
            ->willReturn($schemaManager)
        ;

        $schemaManager->expects($this->once())
            ->method('createTable')
            ->with($this->callback(function (Table $table): true {
                self::assertSame('table_name', $table->getObjectName()->toString());
                $columns = $table->getColumns();
                self::assertCount(3, $columns);
                self::assertSame('id', $columns[0]->getObjectName()->toString());
                self::assertInstanceOf(BinaryType::class, $columns[0]->getType());
                self::assertSame(16, $columns[0]->getLength());
                self::assertSame('metadata', $columns[1]->getObjectName()->toString());
                self::assertInstanceOf(JsonType::class, $columns[1]->getType());
                self::assertSame('vector_field', $columns[2]->getObjectName()->toString());
                self::assertInstanceOf(VectorType::class, $columns[2]->getType());
                self::assertSame(2048, $columns[2]->getLength());

                $index = $table->getIndex('index_name');
                self::assertSame(IndexType::VECTOR, $index->getType());

                $columns = $table->getPrimaryKeyConstraint()->getColumnNames();
                self::assertCount(1, $columns);
                self::assertSame('id', $columns[0]->toString());;

                return true;
            }))
        ;

        $this->store->setup(['dimensions' => 2048]);
    }

    public function testDrop(): void
    {
        $schemaManager = $this->createMock(MySQLSchemaManager::class);
        $this->connection->expects($this->once())
            ->method('createSchemaManager')
            ->willReturn($schemaManager)
        ;
        $schemaManager->expects($this->once())
            ->method('dropTable')
            ->with('table_name')
        ;
        $this->store->drop();
    }
}

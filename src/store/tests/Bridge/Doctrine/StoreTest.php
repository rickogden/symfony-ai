<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Tests\Bridge\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MariaDB110700Platform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\MySQLSchemaManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\VectorType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\Doctrine\Store;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Store::class)]
final class StoreTest extends TestCase
{
    private Connection&MockObject $connection;
    private Store $store;

    protected function setUp(): void
    {
        if (!class_exists('Doctrine\DBAL\Connection')) {
            $this->markTestSkipped('Doctrine DBAL is not installed.');
        }

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

        $this->connection->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn(new MariaDB110700Platform())
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
                self::assertSame('id', $columns[0]->toString());

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

    public function testAdd(): void
    {
        $document1 = new VectorDocument(
            Uuid::v4(),
            new Vector([0.1, 0.2, 0.3]),
            new Metadata(['title' => 'Document 1', 'content' => 'First document content']),
        );
        $document2 = new VectorDocument(
            Uuid::v4(),
            new Vector([0.4, 0.5]),
            new Metadata(['title' => 'Document 2', 'content' => 'Second document content']),
        );
        $this->connection->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn(new QueryBuilder($this->connection))
        ;
        $paramArray = [];
        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params, array $types) use (&$paramArray): int {
                self::assertSame(
                    'INSERT INTO table_name (id, metadata, vector_field) VALUES(:id, :metadata, :vector)',
                    $sql,
                );
                $paramArray[] = $params;
                self::assertSame(['id' => ParameterType::BINARY, 'metadata' => 'json', 'vector' => 'vector'], $types);

                return 1;
            })
        ;

        $this->store->add($document1, $document2);
        $this->assertCount(2, $paramArray);
        $this->assertCount(3, $paramArray[0]);
        $this->assertCount(3, $paramArray[1]);
        $this->assertSame($document1->id->toBinary(), $paramArray[0]['id']);
        $this->assertSame($document1->metadata->getArrayCopy(), $paramArray[0]['metadata']);
        $this->assertSame($document1->vector->getData(), $paramArray[0]['vector']);
        $this->assertSame($document2->id->toBinary(), $paramArray[1]['id']);
        $this->assertSame($document2->metadata->getArrayCopy(), $paramArray[1]['metadata']);
        $this->assertSame($document2->vector->getData(), $paramArray[1]['vector']);
    }
}

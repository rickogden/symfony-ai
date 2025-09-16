<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MariaDB110700Platform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Types\VectorType;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;

final readonly class Store implements StoreInterface, ManagedStoreInterface
{
    private const FIELD_ID = 'id';
    private const FIELD_METADATA = 'metadata';

    private const OPTIONS_DIMENSIONS = 'dimensions';

    public function __construct(
        private Connection $connection,
        private string $tableName,
        private string $indexName,
        private string $vectorFieldName,
    ) {
    }

    /**
     * @throws Exception
     */
    public function setup(array $options = []): void
    {
        $dimensions = $options[self::OPTIONS_DIMENSIONS] ?? 1536;
        if (!is_int($dimensions)) {
            throw new InvalidArgumentException('The "dimensions" option must be an integer.');
        }

        $indexes = [];

        if ($this->connection->getDatabasePlatform() instanceof MariaDB110700Platform) {
            $indexes[] = Index::editor()
                ->setUnquotedName($this->indexName)
                ->setUnquotedColumnNames($this->vectorFieldName)
                ->setType(Index\IndexType::VECTOR)
                ->create()
            ;
        }

        $table = new Table(
            $this->tableName,
            [
                Column::editor()
                    ->setUnquotedName(self::FIELD_ID)
                    ->setType(new BinaryType())
                    ->setLength(16)
                    ->create(),
                Column::editor()
                    ->setUnquotedName(self::FIELD_METADATA)
                    ->setType(new JsonType())
                    ->create(),
                Column::editor()
                    ->setUnquotedName($this->vectorFieldName)
                    ->setType(new VectorType())
                    ->setLength($dimensions)
                    ->create(),
            ],
            $indexes,
            [],
            [],
            [],
            null,
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames(self::FIELD_ID)
                ->create(),
        );
        $this->connection->createSchemaManager()->createTable($table);
    }

    /**
     * @throws Exception
     */
    public function add(VectorDocument ...$documents): void
    {
        $q = $this->connection->createQueryBuilder()
            ->insert($this->tableName)
            ->setValue(self::FIELD_ID, ':id')
            ->setValue(self::FIELD_METADATA, ':metadata')
            ->setValue($this->vectorFieldName, ':vector')
        ;

        foreach ($documents as $document) {
            $q->setParameter('id', $document->id->toBinary(), ParameterType::BINARY)
                ->setParameter('metadata', $document->metadata->getArrayCopy(), Types::JSON)
                ->setParameter('vector', $document->vector->getData(), Types::VECTOR)
            ;
            $q->executeStatement();
        }
    }

    public function query(Vector $vector, array $options = []): array
    {
        // TODO: Implement query() method.
    }

    /**
     * @throws Exception
     */
    public function drop(): void
    {
        $this->connection->createSchemaManager()->dropTable($this->tableName);
    }
}

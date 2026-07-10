<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Test\Unit;

use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\SnapshotBuilder;

/**
 * Pins the fix for the "half-built snapshot" defect: a snapshot mutation that
 * fails partway must NOT leave an existing-but-incomplete table behind, because
 * buildMissingTables() trusts existence as completeness and later partial
 * reindexes would silently drop rows from the index. withBuildLock() must drop
 * every snapshot table on any failure, then re-throw the original error.
 */
class SnapshotBuilderCleanupTest extends TestCase
{
    public function testFailedWorkDropsAllSnapshotTablesAndRethrows(): void
    {
        $droppedTables = [];

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn ($sql) => str_contains((string) $sql, 'GET_LOCK')
                || str_contains((string) $sql, 'RELEASE_LOCK') ? '1' : null
        );
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('query')->willReturnCallback(
            function ($sql) use (&$droppedTables) {
                if (preg_match('/DROP TABLE IF EXISTS (\S+)/', (string) $sql, $m)) {
                    $droppedTables[] = $m[1];
                }
                return $this->createStub(\Zend_Db_Statement_Interface::class);
            }
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $builder = new SnapshotBuilder(
            $resource,
            $this->createMock(CatalogConfig::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(MetadataPool::class),
        );

        $withBuildLock = new ReflectionMethod($builder, 'withBuildLock');
        $withBuildLock->setAccessible(true);

        $caught = null;
        try {
            $withBuildLock->invoke($builder, 5, static function (): void {
                throw new \RuntimeException('chunk insert deadlocked');
            });
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'the original build error must propagate to the caller');
        self::assertSame('chunk insert deadlocked', $caught->getMessage());
        self::assertCount(
            4,
            $droppedTables,
            'all four snapshot tables must be dropped after a failed build so no '
            . 'half-built table survives to be trusted by a later partial reindex',
        );
    }
}

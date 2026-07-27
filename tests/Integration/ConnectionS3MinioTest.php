<?php

declare(strict_types=1);

namespace S3Storage\Tests\Integration;

use Atro\Entities\Connection;
use Atro\Services\Connection as ConnectionService;
use PHPUnit\Framework\TestCase;
use S3Storage\Core\ConnectionType\ConnectionS3;
use S3Storage\Tests\Support\ContainerBuilder;

/**
 * Exercises the real "Test Connection" path (Atro\Services\Connection::testConnection() ->
 * ConnectionS3::testConnection() -> a genuine bucketExists() HeadBucket call) against a real
 * MinIO server, since constructing an S3Client alone performs no network I/O and would give a
 * false sense of verification if only unit-tested against mocks.
 */
final class ConnectionS3MinioTest extends TestCase
{
    private function endpoint(): string
    {
        return getenv('S3_STORAGE_TEST_ENDPOINT') ?: 'http://127.0.0.1:19000';
    }

    private function bucket(): string
    {
        return getenv('S3_STORAGE_TEST_BUCKET') ?: 'test-bucket';
    }

    private function newConnectionType(): ConnectionS3
    {
        $connectionService = $this->createMock(ConnectionService::class);
        $connectionService->method('decryptPassword')->willReturn(getenv('S3_STORAGE_TEST_SECRET_KEY') ?: 'testsecret');

        $serviceFactory = $this->createMock(\Espo\Core\ServiceFactory::class);
        $serviceFactory->method('create')->willReturn($connectionService);

        $container = ContainerBuilder::build(['serviceFactory' => $serviceFactory]);

        return new ConnectionS3($container);
    }

    private function connectionEntity(array $overrides = []): Connection
    {
        $values = array_merge([
            'name' => 'minio-test-connection',
            's3Region' => 'us-east-1',
            's3Bucket' => $this->bucket(),
            's3AccessKeyId' => getenv('S3_STORAGE_TEST_ACCESS_KEY') ?: 'testkey',
            's3SecretAccessKey' => 'encrypted-placeholder',
            's3Endpoint' => $this->endpoint(),
            's3ForcePathStyle' => true,
            's3VerifyTls' => false,
        ], $overrides);

        $connection = $this->createMock(Connection::class);
        $connection->method('get')->willReturnCallback(fn (string $key) => $values[$key] ?? null);

        return $connection;
    }

    public function testTestConnectionSucceedsAgainstRealBucket(): void
    {
        $connectionType = $this->newConnectionType();

        $this->assertTrue($connectionType->testConnection($this->connectionEntity()));
    }

    public function testTestConnectionThrowsForNonexistentBucket(): void
    {
        $connectionType = $this->newConnectionType();

        $this->expectException(\Atro\Core\Exceptions\BadRequest::class);
        $connectionType->testConnection($this->connectionEntity([
            's3Bucket' => 'this-bucket-does-not-exist-' . bin2hex(random_bytes(4)),
        ]));
    }
}

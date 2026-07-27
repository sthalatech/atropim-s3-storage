<?php

declare(strict_types=1);

namespace S3Storage\Tests\Unit;

use Atro\Entities\Connection;
use Atro\Services\Connection as ConnectionService;
use PHPUnit\Framework\TestCase;
use S3Storage\Core\ConnectionType\ConnectionS3;
use S3Storage\Tests\Support\ContainerBuilder;

final class ConnectionS3Test extends TestCase
{
    private function newConnectionType(): ConnectionS3
    {
        $connectionService = $this->createMock(ConnectionService::class);
        $connectionService->method('decryptPassword')->willReturn('decrypted-secret');

        $serviceFactory = $this->createMock(\Espo\Core\ServiceFactory::class);
        $serviceFactory->method('create')->willReturn($connectionService);

        $container = ContainerBuilder::build(['serviceFactory' => $serviceFactory]);

        return new ConnectionS3($container);
    }

    private function connectionEntity(array $overrides = []): Connection
    {
        $values = array_merge([
            'name' => 's3-connection',
            's3Region' => 'us-east-1',
            's3Bucket' => 'nonprod-isha-pim-media',
            's3AccessKeyId' => '',
            's3SecretAccessKey' => '',
            's3Endpoint' => '',
            's3ForcePathStyle' => false,
            's3VerifyTls' => true,
        ], $overrides);

        $connection = $this->createMock(Connection::class);
        $connection->method('get')->willReturnCallback(fn (string $key) => $values[$key] ?? null);

        return $connection;
    }

    // connect() performs no network I/O (S3Client is a lazy HTTP client), so this is safely
    // unit-testable; testConnection()'s real bucketExists() check is covered by the MinIO
    // integration suite instead, same as the rest of this addon's network-touching behavior.

    public function testConnectReturnsS3ClientForIamRoleConnection(): void
    {
        $connectionType = $this->newConnectionType();

        $client = $connectionType->connect($this->connectionEntity());

        $this->assertInstanceOf(\AsyncAws\S3\S3Client::class, $client);
    }

    public function testConnectReturnsS3ClientForStaticKeyConnection(): void
    {
        $connectionType = $this->newConnectionType();

        $client = $connectionType->connect($this->connectionEntity([
            's3AccessKeyId' => 'AKIAEXAMPLE',
            's3SecretAccessKey' => 'encrypted-blob',
        ]));

        $this->assertInstanceOf(\AsyncAws\S3\S3Client::class, $client);
    }

    public function testTestConnectionThrowsWhenBucketNotConfigured(): void
    {
        $connectionType = $this->newConnectionType();

        $this->expectException(\Atro\Core\Exceptions\BadRequest::class);
        $connectionType->testConnection($this->connectionEntity(['s3Bucket' => '']));
    }
}

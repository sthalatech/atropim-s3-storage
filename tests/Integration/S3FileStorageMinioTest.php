<?php

declare(strict_types=1);

namespace S3Storage\Tests\Integration;

use AsyncAws\S3\S3Client;
use Atro\Entities\Connection;
use Atro\Entities\File;
use Atro\Entities\Storage;
use Atro\Services\Connection as ConnectionService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use S3Storage\Core\FileStorage\S3FileStorage;
use S3Storage\Tests\Support\ContainerBuilder;

/**
 * Exercises S3FileStorage's real public interface methods against a real
 * S3-compatible server (MinIO), with File/Storage/Connection as mocked entities
 * (their own get/set machinery is core's already-tested ORM, not this addon's
 * concern) so this test is isolated from needing a full AtroCore app/DB bootstrap
 * while still validating genuine wire-level S3 behavior — not a parallel
 * hand-written script exercising a different code path than what ships.
 */
final class S3FileStorageMinioTest extends TestCase
{
    private function endpoint(): string
    {
        return getenv('S3_STORAGE_TEST_ENDPOINT') ?: 'http://127.0.0.1:19000';
    }

    private function bucket(): string
    {
        return getenv('S3_STORAGE_TEST_BUCKET') ?: 'test-bucket';
    }

    private function buildStorageAndClient(string $prefix = 'integration-tests'): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('get')->willReturnMap([
            ['type', [], 's3'],
            ['name', [], 'test-connection'],
            ['s3Region', [], 'us-east-1'],
            ['s3Bucket', [], $this->bucket()],
            ['s3AccessKeyId', [], getenv('S3_STORAGE_TEST_ACCESS_KEY') ?: 'testkey'],
            ['s3SecretAccessKey', [], 'encrypted-placeholder'],
            ['s3Endpoint', [], $this->endpoint()],
            ['s3ForcePathStyle', [], true],
            ['s3VerifyTls', [], false],
        ]);

        // decryptPassword() round-trips through core's already-tested Encrypter — not
        // this addon's concern to re-verify; the mock returns the real test secret
        // regardless of what "encrypted" value it's handed.
        $connectionService = $this->createMock(ConnectionService::class);
        $connectionService->method('decryptPassword')->willReturn(getenv('S3_STORAGE_TEST_SECRET_KEY') ?: 'testsecret');

        $serviceFactory = $this->createMock(\Espo\Core\ServiceFactory::class);
        $serviceFactory->method('create')->willReturn($connectionService);

        $connectionRepository = $this->getMockBuilder(\stdClass::class)->addMethods(['get'])->getMock();
        $connectionRepository->method('get')->willReturn($connection);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRepository')->willReturn($connectionRepository);

        $storage = $this->createMock(Storage::class);
        $storage->method('get')->willReturnMap([
            ['id', [], 'test-storage-id'],
            ['name', [], 'test-storage'],
            ['connectionId', [], 'test-connection-id'],
            ['s3KeyPrefix', [], $prefix],
        ]);

        // Atro\Core\Utils\Config's real parent class references a global VENDOR_PATH
        // constant only defined during full app bootstrap — createMock() would trigger
        // loading it and fatal outside that context, so use a plain duck-typed double
        // instead (none of the methods under test here actually call getConfig()).
        $config = $this->getMockBuilder(\stdClass::class)->addMethods(['getSiteUrl', 'get'])->getMock();

        $container = ContainerBuilder::build([
            'entityManager' => $entityManager,
            'serviceFactory' => $serviceFactory,
            'config' => $config,
            'connection' => $this->realDbalConnection(),
            'log' => new class {
                public function warning($m)
                {
                }
            },
        ]);

        $rawClient = new S3Client([
            'endpoint' => $this->endpoint(),
            'region' => 'us-east-1',
            'accessKeyId' => getenv('S3_STORAGE_TEST_ACCESS_KEY') ?: 'testkey',
            'accessKeySecret' => getenv('S3_STORAGE_TEST_SECRET_KEY') ?: 'testsecret',
            'pathStyleEndpoint' => true,
        ]);

        return [new S3FileStorage($container), $storage, $rawClient, $prefix];
    }

    private function realDbalConnection(): \Doctrine\DBAL\Connection
    {
        // reupload()'s advisory lock needs a real DBAL Connection (Doctrine\DBAL\Connection
        // is not final and mockable, but the lock semantics being tested are only
        // meaningful against a real database backend — reuses the same Postgres instance
        // this addon's AdvisoryLock behavior was already validated against directly).
        return \Doctrine\DBAL\DriverManager::getConnection([
            'driverClass' => \Atro\Core\Utils\Database\DBAL\Driver\PDO\PgSQL\Driver::class,
            'host' => getenv('S3_STORAGE_TEST_DB_HOST') ?: 'db',
            'dbname' => getenv('S3_STORAGE_TEST_DB_NAME') ?: 'atrocore',
            'user' => getenv('S3_STORAGE_TEST_DB_USER') ?: 'atropim',
            'password' => getenv('S3_STORAGE_TEST_DB_PASSWORD') ?: 'atropim_pg_2026',
        ]);
    }

    private function mockFile(Storage $storage, string $id, string $base64Contents): File
    {
        $file = $this->createMock(File::class);
        $file->method('getStorage')->willReturn($storage);
        $file->method('get')->willReturnMap([
            ['id', [], $id],
            ['mimeType', [], null],
            ['storageId', [], 'test-storage-id'],
        ]);
        $file->_input = (object)['fileContents' => $base64Contents];

        return $file;
    }

    public function testCreateFileThenGetContentsRoundTrips(): void
    {
        [$s3, $storage] = $this->buildStorageAndClient();
        $bytes = random_bytes(1024 * 10);
        $fileId = 'it-create-' . bin2hex(random_bytes(4));
        $file = $this->mockFile($storage, $fileId, 'data:application/octet-stream;base64,' . base64_encode($bytes));

        $this->assertTrue($s3->createFile($file));
        $this->assertSame($bytes, $s3->getContents($file));

        $s3->deleteFilePermanently($file);
    }

    public function testReuploadReplacesContentAndCleansUpTempKey(): void
    {
        [$s3, $storage, $rawClient, $prefix] = $this->buildStorageAndClient();
        $fileId = 'it-reupload-' . bin2hex(random_bytes(4));

        $original = random_bytes(1024 * 5);
        $file = $this->mockFile($storage, $fileId, 'data:application/octet-stream;base64,' . base64_encode($original));
        $this->assertTrue($s3->createFile($file));
        $this->assertSame($original, $s3->getContents($file));

        $replacement = random_bytes(1024 * 8);
        $file2 = $this->mockFile($storage, $fileId, 'data:application/octet-stream;base64,' . base64_encode($replacement));
        $this->assertTrue($s3->reupload($file2));

        $this->assertSame($replacement, $s3->getContents($file), 'reupload must replace content at the same key');

        // The temp key used during the safe copy-verify-swap must not survive.
        $listed = $rawClient->listObjectsV2(['Bucket' => $this->bucket(), 'Prefix' => "$prefix/$fileId.uploading."]);
        $this->assertCount(0, iterator_to_array($listed->getContents()), 'temp upload key must be cleaned up after reupload');

        $s3->deleteFilePermanently($file);
    }

    public function testDeleteFilePermanentlyRemovesObject(): void
    {
        [$s3, $storage] = $this->buildStorageAndClient();
        $fileId = 'it-delete-' . bin2hex(random_bytes(4));
        $file = $this->mockFile($storage, $fileId, 'data:application/octet-stream;base64,' . base64_encode('bye'));

        $this->assertTrue($s3->createFile($file));
        $this->assertTrue($s3->deleteFilePermanently($file));

        $this->expectException(\Throwable::class);
        $s3->getContents($file);
    }

    public function testIsAvailableTrueForRealBucket(): void
    {
        [$s3, $storage] = $this->buildStorageAndClient();
        $this->assertTrue($s3->isAvailable($storage));
    }

    public function testMultipartUploadOverThresholdRoundTrips(): void
    {
        [$s3, $storage] = $this->buildStorageAndClient();
        $fileId = 'it-multipart-' . bin2hex(random_bytes(4));

        // Force the multipart path without needing a genuinely 100MB+ fixture: use
        // reflection to invoke putMultipartFromBytes directly with a modest payload
        // that spans multiple parts, sized well above the part size, not the full
        // production threshold — the threshold constant itself is asserted separately.
        $bytes = random_bytes(20 * 1024 * 1024); // 20MB — spans two 16MB parts.
        $key = 'integration-tests/' . $fileId;

        $r = new \ReflectionMethod($s3, 'putMultipartFromBytes');
        $r->setAccessible(true);
        $r->invoke($s3, $storage, $key, $bytes, null);

        $file = $this->mockFile($storage, $fileId, '');
        $this->assertSame($bytes, $s3->getContents($file));

        $s3->deleteFilePermanently($file);
    }
}

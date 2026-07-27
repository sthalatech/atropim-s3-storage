<?php

declare(strict_types=1);

namespace S3Storage\Tests\Unit;

use Atro\Entities\Connection;
use Atro\Entities\Storage;
use Atro\Services\Connection as ConnectionService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use S3Storage\Core\Utils\S3ClientFactory;
use S3Storage\Tests\Support\ContainerBuilder;

final class S3ClientFactoryTest extends TestCase
{
    private function invokePrivate(object $object, string $method, array $args = [])
    {
        $r = new \ReflectionMethod($object, $method);
        $r->setAccessible(true);

        return $r->invokeArgs($object, $args);
    }

    private function newFactory(ConnectionService $connectionService = null): S3ClientFactory
    {
        $connectionService = $connectionService ?? $this->createMock(ConnectionService::class);

        $serviceFactory = $this->createMock(\Espo\Core\ServiceFactory::class);
        $serviceFactory->method('create')->willReturn($connectionService);

        $container = ContainerBuilder::build(['serviceFactory' => $serviceFactory]);

        return new S3ClientFactory($container);
    }

    private function storageLinkedTo(): Storage
    {
        $storage = $this->createMock(Storage::class);
        $storage->method('get')->willReturnMap([
            ['connectionId', [], 'connection-id'],
        ]);

        return $storage;
    }

    private function newFactoryWithConnection(Connection $connection, string $decryptedSecret = 'decrypted-secret'): S3ClientFactory
    {
        $connectionRepository = $this->getMockBuilder(\stdClass::class)->addMethods(['get'])->getMock();
        $connectionRepository->method('get')->willReturn($connection);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRepository')->willReturn($connectionRepository);

        $connectionService = $this->createMock(ConnectionService::class);
        $connectionService->method('decryptPassword')->willReturn($decryptedSecret);

        $serviceFactory = $this->createMock(\Espo\Core\ServiceFactory::class);
        $serviceFactory->method('create')->willReturn($connectionService);

        $container = ContainerBuilder::build([
            'entityManager' => $entityManager,
            'serviceFactory' => $serviceFactory,
        ]);

        return new S3ClientFactory($container);
    }

    // -----------------------------------------------------------------
    // buildClientConfig — the credential-omission behavior IAM-role fallback relies on
    // -----------------------------------------------------------------

    public function testBuildClientConfigIncludesStaticCredentialsWhenAccessKeyIdPresent(): void
    {
        $factory = $this->newFactory();

        $config = $this->invokePrivate($factory, 'buildClientConfig', [
            'us-east-1', 'AKIAEXAMPLE', 'secret-value', '', false,
        ]);

        $this->assertSame('AKIAEXAMPLE', $config['accessKeyId']);
        $this->assertSame('secret-value', $config['accessKeySecret']);
    }

    public function testBuildClientConfigOmitsCredentialKeysEntirelyWhenAccessKeyIdBlank(): void
    {
        // This is the load-bearing behavior for IAM-role support: async-aws/core's default
        // credential provider chain (env vars -> shared ini file -> ECS container creds ->
        // EC2 instance-profile role via IMDS) only kicks in when 'accessKeyId'/'accessKeySecret'
        // are absent from the config array entirely — passing empty strings would instead be
        // treated as explicit (but blank) static credentials and would NOT fall back.
        $factory = $this->newFactory();

        $config = $this->invokePrivate($factory, 'buildClientConfig', [
            'us-east-1', '', '', '', false,
        ]);

        $this->assertArrayNotHasKey('accessKeyId', $config);
        $this->assertArrayNotHasKey('accessKeySecret', $config);
        $this->assertSame('us-east-1', $config['region']);
    }

    public function testBuildClientConfigIncludesEndpointAndPathStyleWhenSet(): void
    {
        $factory = $this->newFactory();

        $config = $this->invokePrivate($factory, 'buildClientConfig', [
            'us-east-1', '', '', 'https://minio.example.com', true,
        ]);

        $this->assertSame('https://minio.example.com', $config['endpoint']);
        $this->assertTrue($config['pathStyleEndpoint']);
    }

    public function testBuildClientConfigOmitsEndpointAndPathStyleWhenNotSet(): void
    {
        $factory = $this->newFactory();

        $config = $this->invokePrivate($factory, 'buildClientConfig', [
            'us-east-1', '', '', '', false,
        ]);

        $this->assertArrayNotHasKey('endpoint', $config);
        $this->assertArrayNotHasKey('pathStyleEndpoint', $config);
    }

    // -----------------------------------------------------------------
    // create() — end-to-end via a mocked Connection/Storage/container
    // -----------------------------------------------------------------

    public function testCreateSucceedsWithBothCredentialFieldsBlankIamRolePath(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('get')->willReturnMap([
            ['type', [], 's3'],
            ['name', [], 'iam-role-connection'],
            ['s3Region', [], 'us-east-1'],
            ['s3Bucket', [], 'nonprod-isha-pim-media'],
            ['s3AccessKeyId', [], ''],
            ['s3SecretAccessKey', [], ''],
            ['s3Endpoint', [], ''],
            ['s3ForcePathStyle', [], false],
            ['s3VerifyTls', [], true],
        ]);

        $factory = $this->newFactoryWithConnection($connection);
        $client = $factory->create($this->storageLinkedTo());

        $this->assertInstanceOf(\AsyncAws\S3\S3Client::class, $client);
    }

    public function testCreateSucceedsWithBothCredentialFieldsSetStaticKeyPath(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('get')->willReturnMap([
            ['type', [], 's3'],
            ['name', [], 'static-key-connection'],
            ['s3Region', [], 'us-east-1'],
            ['s3Bucket', [], 'nonprod-isha-pim-media'],
            ['s3AccessKeyId', [], 'AKIAEXAMPLE'],
            ['s3SecretAccessKey', [], 'encrypted-blob'],
            ['s3Endpoint', [], ''],
            ['s3ForcePathStyle', [], false],
            ['s3VerifyTls', [], true],
        ]);

        $factory = $this->newFactoryWithConnection($connection);
        $client = $factory->create($this->storageLinkedTo());

        $this->assertInstanceOf(\AsyncAws\S3\S3Client::class, $client);
    }

    public function testCreateThrowsWhenSecretSetWithoutAccessKeyId(): void
    {
        // Guards against a partial-fill footgun: a Secret Access Key left over (e.g. from
        // switching a connection from static keys to IAM-role auth but only clearing one
        // field) must fail loudly rather than silently behaving unexpectedly either way.
        $connection = $this->createMock(Connection::class);
        $connection->method('get')->willReturnMap([
            ['type', [], 's3'],
            ['name', [], 'partial-connection'],
            ['s3Region', [], 'us-east-1'],
            ['s3Bucket', [], 'nonprod-isha-pim-media'],
            ['s3AccessKeyId', [], ''],
            ['s3SecretAccessKey', [], 'leftover-secret'],
            ['s3Endpoint', [], ''],
            ['s3ForcePathStyle', [], false],
            ['s3VerifyTls', [], true],
        ]);

        // decryptPassword() genuinely returns a non-empty secret here — this is the real
        // misconfiguration case (a real secret left behind with no access key), as opposed
        // to the regression case below (an encrypted-but-blank secret, which must NOT throw).
        $factory = $this->newFactoryWithConnection($connection, 'a-real-leftover-secret');

        $this->expectException(\Atro\Core\Exceptions\Error::class);
        $factory->create($this->storageLinkedTo());
    }

    public function testCreateSucceedsWhenSecretFieldIsEncryptedBlank(): void
    {
        // Regression test: Atro\Services\Connection::encryptPasswordFields() encrypts every
        // password-type field on save, including blank ones — so a Secret Access Key the user
        // correctly left empty for IAM-role auth is persisted as encrypt(''), a non-empty
        // ciphertext, not '' or null. The guard must check the DECRYPTED value, not the raw
        // stored ciphertext, or every previously-saved IAM-role connection would be falsely
        // rejected as "Secret Access Key is set without an Access Key ID".
        $connection = $this->createMock(Connection::class);
        $connection->method('get')->willReturnMap([
            ['type', [], 's3'],
            ['name', [], 'iam-role-connection'],
            ['s3Region', [], 'us-east-1'],
            ['s3Bucket', [], 'nonprod-isha-pim-media'],
            ['s3AccessKeyId', [], ''],
            ['s3SecretAccessKey', [], 'encrypted-blank-ciphertext'],
            ['s3Endpoint', [], ''],
            ['s3ForcePathStyle', [], false],
            ['s3VerifyTls', [], true],
        ]);

        // decryptPassword() of the encrypted-blank ciphertext genuinely returns ''.
        $factory = $this->newFactoryWithConnection($connection, '');

        $client = $factory->create($this->storageLinkedTo());

        $this->assertInstanceOf(\AsyncAws\S3\S3Client::class, $client);
    }
}

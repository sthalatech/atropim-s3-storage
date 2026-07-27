<?php
/**
 * AtroPIM S3 Storage Addon
 *
 * This source file is available under GNU General Public License version 3 (GPLv3).
 * Full copyright and license information is available in LICENSE.txt, located in the root directory.
 *
 * @copyright  Copyright (c) Sthala Technologies
 * @license    GPLv3 (https://www.gnu.org/licenses/)
 */

declare(strict_types=1);

namespace S3Storage\Core\Utils;

use AsyncAws\S3\S3Client;
use Atro\Core\Container;
use Atro\Core\Exceptions\Error;
use Atro\Entities\Connection as ConnectionEntity;
use Atro\Entities\Storage as StorageEntity;

/**
 * Builds an async-aws S3Client from a Storage entity's linked Connection (type=s3) record,
 * decrypting the secret key via the existing, already-audited Connection service/Encrypter
 * rather than rolling any bespoke crypto.
 */
final class S3ClientFactory
{
    public function __construct(private readonly Container $container)
    {
    }

    public function create(StorageEntity $storage): S3Client
    {
        return $this->createFromConnection($this->getConnection($storage));
    }

    /**
     * Builds a client directly from a Connection record, without requiring a Storage that
     * links to it — used by the "Test Connection" action (ConnectionType\ConnectionS3), which
     * only ever has the Connection entity itself, never a Storage.
     */
    public function createFromConnection(ConnectionEntity $connection): S3Client
    {
        $accessKeyId = trim((string)$connection->get('s3AccessKeyId'));
        $hasSecret = trim((string)$connection->get('s3SecretAccessKey')) !== '';

        if ($accessKeyId === '' && $hasSecret) {
            throw new Error(
                "S3 connection '{$connection->get('name')}': Secret Access Key is set without an " .
                "Access Key ID. Provide both, or leave both blank to authenticate via the server's " .
                "own IAM role instead of static keys."
            );
        }

        $accessKeySecret = $accessKeyId !== '' ? $this->decryptSecret((string)$connection->get('s3SecretAccessKey')) : '';

        $endpoint = trim((string)$connection->get('s3Endpoint'));
        $verifyTls = $connection->get('s3VerifyTls');
        $verifyTls = $verifyTls === null ? true : (bool)$verifyTls;

        if ($endpoint !== '' && $verifyTls && !str_starts_with(strtolower($endpoint), 'https://')) {
            throw new Error(
                "S3 connection '{$connection->get('name')}': endpoint must use https:// unless " .
                "'Verify TLS' is explicitly disabled (intended for local development against MinIO only)."
            );
        }

        $config = $this->buildClientConfig(
            (string)$connection->get('s3Region'),
            $accessKeyId,
            $accessKeySecret,
            $endpoint,
            !empty($connection->get('s3ForcePathStyle'))
        );

        return new S3Client($config);
    }

    /**
     * Static keys are only included in the config when an Access Key ID is actually configured.
     * Omitting both credential keys entirely (rather than passing empty strings) is what lets
     * async-aws/core's own default credential provider chain take over — environment variables,
     * the shared ~/.aws/credentials file, ECS container credentials, and finally the EC2 instance's
     * attached IAM role via IMDS — exactly like the AWS SDK's own behavior when no explicit
     * credentials are passed to its S3 client constructor. Passing empty-string credentials instead
     * would be treated as explicit (but blank) static credentials and would NOT fall back to the
     * instance role.
     */
    private function buildClientConfig(
        string $region,
        string $accessKeyId,
        string $accessKeySecret,
        string $endpoint,
        bool $forcePathStyle
    ): array {
        $config = ['region' => $region];

        if ($accessKeyId !== '') {
            $config['accessKeyId'] = $accessKeyId;
            $config['accessKeySecret'] = $accessKeySecret;
        }

        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
        }

        if ($forcePathStyle) {
            $config['pathStyleEndpoint'] = true;
        }

        return $config;
    }

    public function getConnection(StorageEntity $storage): ConnectionEntity
    {
        $connectionId = (string)$storage->get('connectionId');
        if ($connectionId === '') {
            throw new Error("Storage '{$storage->get('name')}' has no S3 connection configured.");
        }

        /** @var ConnectionEntity|null $connection */
        $connection = $this->container->get('entityManager')->getRepository('Connection')->get($connectionId);
        if (empty($connection) || $connection->get('type') !== 's3') {
            throw new Error("Storage '{$storage->get('name')}' is not linked to a valid S3 connection.");
        }

        return $connection;
    }

    public function getBucket(StorageEntity $storage): string
    {
        return (string)$this->getConnection($storage)->get('s3Bucket');
    }

    private function decryptSecret(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        /** @var \Atro\Services\Connection $connectionService */
        $connectionService = $this->container->get('serviceFactory')->create('Connection');
        $decrypted = $connectionService->decryptPassword($encrypted);

        return is_string($decrypted) ? $decrypted : '';
    }
}

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

namespace S3Storage\Core\ConnectionType;

use AsyncAws\S3\S3Client;
use Atro\ConnectionType\AbstractConnection;
use Atro\ConnectionType\ConnectionInterface;
use Atro\ConnectionType\TestConnectionInterface;
use Atro\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use S3Storage\Core\Utils\S3ClientFactory;

/**
 * Registers the "s3" Connection type (see Resources/metadata/app/connectionTypes.json) so the
 * Connection entity's generic, built-in "Test Connection" button/action
 * (client/src/views/connection/record/detail.js, Atro\Services\Connection::testConnection())
 * works for S3 connections without any addon-specific frontend code.
 *
 * connect() alone would NOT be a real connectivity test here — unlike a PDO or FTP connection,
 * constructing an S3Client performs no network I/O; it's a lazy HTTP client. TestConnectionInterface
 * is implemented specifically to perform a real HeadBucket call, mirroring the same
 * bucketExists()->resolve() pattern S3FileStorage::isAvailable() already uses.
 */
class ConnectionS3 extends AbstractConnection implements ConnectionInterface, TestConnectionInterface
{
    public function connect(Entity $connection): S3Client
    {
        return (new S3ClientFactory($this->container))->createFromConnection($connection);
    }

    public function testConnection(Entity $connection): bool
    {
        $bucket = trim((string)$connection->get('s3Bucket'));

        try {
            if ($bucket === '') {
                throw new \RuntimeException('Bucket is not configured.');
            }

            $exists = $this->connect($connection)->bucketExists(['Bucket' => $bucket])->resolve(3.0);

            if (!$exists) {
                throw new \RuntimeException("bucket '{$bucket}' was not found or is not accessible with the configured credentials/role");
            }
        } catch (\Throwable $e) {
            throw new BadRequest(sprintf($this->exception('connectionFailed'), $e->getMessage()));
        }

        return true;
    }
}

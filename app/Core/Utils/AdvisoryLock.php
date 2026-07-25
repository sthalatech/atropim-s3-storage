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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Non-blocking, DB-backed advisory lock scoped to a single File id.
 *
 * Deliberately non-blocking ("try" semantics only): a caller that cannot acquire
 * the lock is expected to fail fast rather than wait, since this is used to guard
 * a synchronous HTTP request path (see S3FileStorage::reupload()).
 *
 * The caller MUST reuse a single Connection instance for the whole
 * acquire/release pair (never re-resolve it from the container mid-sequence) —
 * MySQL's GET_LOCK()/RELEASE_LOCK() and Postgres's pg_advisory_lock()/
 * pg_advisory_unlock() are both scoped to the *session* that acquired them, so
 * releasing from a different connection is a silent no-op, not an error.
 */
final class AdvisoryLock
{
    private const NAMESPACE = 'atropim_s3_storage';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Attempt to acquire the lock immediately; returns false rather than blocking
     * if it is already held elsewhere.
     */
    public function tryLock(string $id): bool
    {
        if ($this->isPostgres()) {
            $result = $this->connection->fetchOne('SELECT pg_try_advisory_lock(?)', [$this->pgKey($id)]);

            return $result === true || $result === 't' || (string)$result === '1';
        }

        $result = $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$this->mysqlKey($id)]);

        return (string)$result === '1';
    }

    /**
     * Release a lock previously acquired via tryLock() on the SAME Connection instance.
     */
    public function unlock(string $id): void
    {
        if ($this->isPostgres()) {
            $this->connection->executeStatement('SELECT pg_advisory_unlock(?)', [$this->pgKey($id)]);

            return;
        }

        $this->connection->executeStatement('SELECT RELEASE_LOCK(?)', [$this->mysqlKey($id)]);
    }

    private function isPostgres(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function mysqlKey(string $id): string
    {
        // MySQL lock names are limited to 64 chars on older versions; keep well under that.
        return self::NAMESPACE . ':' . $id;
    }

    /**
     * pg_advisory_lock() takes a signed bigint. A 32-bit CRC of the (namespaced) id is
     * deterministic and fits comfortably; a hash collision between two unrelated File
     * ids only costs a rare, harmless extra round of serialization, never a correctness bug.
     */
    private function pgKey(string $id): int
    {
        return crc32(self::NAMESPACE . ':' . $id);
    }
}

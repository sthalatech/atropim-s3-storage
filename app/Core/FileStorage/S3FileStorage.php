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

namespace S3Storage\Core\FileStorage;

use AsyncAws\S3\Enum\ChecksumAlgorithm;
use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\S3Client;
use Atro\Core\Container;
use Atro\Core\Exceptions\BadRequest;
use Atro\Core\Exceptions\Conflict;
use Atro\Core\Exceptions\Error;
use Atro\Core\FileStorage\FileStorageInterface;
use Atro\Core\FileStorage\LocalFileStorageInterface;
use Atro\Core\Utils\Config;
use Atro\Core\Utils\FileManager;
use Atro\Core\Utils\Thumbnail;
use Atro\Entities\File;
use Atro\Entities\Folder;
use Atro\Entities\Storage;
use Atro\EntryPoints\Image;
use Espo\ORM\EntityManager;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use Psr\Http\Message\StreamInterface;
use S3Storage\Core\Utils\AdvisoryLock;
use S3Storage\Core\Utils\S3ClientFactory;

/**
 * S3-compatible object storage backend for AtroCore's File entity.
 *
 * Key design decisions (see PLAN.md for the full rationale):
 *  - Objects are keyed by the File entity's immutable UUID ({prefix}/{id}), never by
 *    name/folder path. This means rename/move/folder operations are pure DB metadata
 *    changes with zero S3 side effects — nothing to get wrong, nothing to corrupt.
 *  - Every write is checksum-verified (SHA-256 where the backend supports the S3
 *    checksum-trailer feature, ETag/MD5 fallback otherwise) with bounded retries.
 *  - Overwrites (reupload) never touch the live key directly: content lands at a temp
 *    key, is verified, then server-side copied onto the real key — a failed or racing
 *    overwrite can never destroy the original.
 *  - Concurrent reuploads of the same File are serialized via a non-blocking DB advisory
 *    lock (core provides no concurrency control here at all, for any backend).
 *  - Implements LocalFileStorageInterface: some core code (EntryPoints/Thumbnail.php)
 *    calls File::getFilePath(), which for a non-LocalFileStorageInterface backend falls
 *    back to getUrl() — an app-internal URL, not a real filesystem path — and silently
 *    breaks thumbnail/preview generation for anything other than local storage. We
 *    implement getLocalPath() to download to a real temp file instead, fixing this
 *    without touching core. (Repositories\File::addDimensions() already has its own
 *    correct fallback for non-local storage and needs no help; this only covers the
 *    gap in the Thumbnail entry point specifically.)
 */
class S3FileStorage implements FileStorageInterface, LocalFileStorageInterface
{
    /** Single PutObject beyond this size is sliced into a real multipart upload. */
    private const MULTIPART_THRESHOLD_BYTES = 100 * 1024 * 1024;
    private const MULTIPART_PART_SIZE_BYTES = 16 * 1024 * 1024;
    private const MAX_RETRIES = 3;

    /** See PLAN.md "In-memory size ceiling" — calibrated against the base64/json_decode/decode buffering chain. */
    private const MEMORY_BASELINE_BYTES = 60 * 1024 * 1024;
    private const MEMORY_MULTIPLIER = 3.7;

    private const SCAN_BATCH_SIZE = 500;
    private const SCAN_TIME_BUDGET_SECONDS = 240;

    /** Local, disk-persisted (NOT memoryStorage — that cache is per-request only and
     *  would not survive between the separate HTTP requests a chunked upload spans). */
    private const MULTIPART_ABANDON_SECONDS = 86400;

    /** getLocalPath() downloads a fresh temp copy per call (never reused/cached across
     *  calls, so a reupload can never be served from a stale temp copy) — this just
     *  bounds how long an unclaimed temp file can sit before deleteCache() sweeps it. */
    private const LOCAL_PATH_TMP_ABANDON_SECONDS = 3600;

    protected Container $container;
    private ?S3ClientFactory $s3ClientFactory = null;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function scan(Storage $storage): void
    {
        $startedAt = microtime(true);
        $client = $this->getS3Client($storage);
        $bucket = $this->getS3ClientFactory()->getBucket($storage);
        $lock = new AdvisoryLock($this->getDbalConnection());

        /** @var \Atro\Repositories\File $fileRepo */
        $fileRepo = $this->getEntityManager()->getRepository('File');
        $cursor = (string)$storage->get('s3ScanCursor');

        $where = ['storageId' => $storage->get('id')];
        if ($cursor !== '') {
            $where['id>'] = $cursor;
        }

        $files = $fileRepo->where($where)->order('id')->limit(0, self::SCAN_BATCH_SIZE)->find();

        if (count($files) === 0) {
            // Reached the end of the list (or the storage has no files) — wrap around for next cycle.
            $this->saveScanCursor($storage, '');

            return;
        }

        $lastId = $cursor;
        foreach ($files as $file) {
            if ((microtime(true) - $startedAt) > self::SCAN_TIME_BUDGET_SECONDS) {
                break;
            }

            $fileId = (string)$file->get('id');
            $lastId = $fileId;

            if (!$lock->tryLock($fileId)) {
                // A reupload is actively in progress on this file right now — the temp/real
                // key state is transitionally inconsistent by design; skip rather than
                // flag false-positive drift. It will be re-checked on a later cycle.
                continue;
            }
            $lock->unlock($fileId);

            $key = $this->buildKey($storage, $fileId);

            try {
                $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
            } catch (\Throwable $e) {
                $this->logDrift($storage, $file, "missing or unreachable object at '$key': " . $e->getMessage());
                continue;
            }

            if (!$this->headMatchesStoredHash($head, (string)$file->get('hash'))) {
                $this->logDrift($storage, $file, "stored hash does not match the object currently in S3 at '$key'.");
            }
        }

        $this->saveScanCursor($storage, $lastId);
    }

    public function createFile(File $file): bool
    {
        $storage = $file->getStorage();
        $input = $file->_input ?? new \stdClass();

        if (property_exists($input, 'allChunks')) {
            return $this->completeMultipartFromChunks($file, $storage, $input);
        }

        if (!property_exists($input, 'fileContents')) {
            throw new Error("S3Storage: unsupported upload input for file '{$file->get('name')}' — only direct content or chunked uploads are supported.");
        }

        $base64 = $this->extractBase64Payload((string)$input->fileContents);
        $this->assertWithinMemoryCeiling(strlen($base64));

        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new Error("S3Storage: could not decode uploaded content for file '{$file->get('name')}'.");
        }

        $key = $this->buildKey($storage, (string)$file->get('id'));

        if (strlen($bytes) > self::MULTIPART_THRESHOLD_BYTES) {
            $this->putMultipartFromBytes($storage, $key, $bytes, $file);
        } else {
            $this->putObjectWithVerification($storage, $key, $bytes, $file);
        }

        $this->setFileAttributesFromBytes($file, $bytes);

        return true;
    }

    public function createFolder(Folder $folder): bool
    {
        // Folders are purely virtual (DB-only, prefix-free) for S3 storage — files are
        // keyed by id, not by folder path, so there is nothing to create in the bucket.
        return true;
    }

    public function createChunk(\stdClass $input, Storage $storage): array
    {
        $hash = (string)($input->fileUniqueHash ?? '');
        if ($hash === '') {
            throw new Error('S3Storage: chunked upload is missing fileUniqueHash.');
        }

        $piece = $this->extractBase64Payload((string)($input->piece ?? ''));
        $bytes = base64_decode($piece, true);
        if ($bytes === false) {
            throw new Error("S3Storage: could not decode chunk for upload '$hash'.");
        }

        $client = $this->getS3Client($storage);
        $bucket = $this->getS3ClientFactory()->getBucket($storage);

        $state = $this->loadMultipartState($hash);
        if ($state === null) {
            // First chunk of this upload session: start a real S3 multipart upload under a
            // temporary hash-keyed name (the eventual File id isn't known yet). createFile()
            // copy-verify-swaps this onto the final {prefix}/{id} key once chunking completes —
            // the same safe pattern reupload() uses, not a special case.
            $tempKey = $this->keyPrefix($storage) !== '' ? $this->keyPrefix($storage) . '/.chunked-upload.' . $hash : '.chunked-upload.' . $hash;
            $create = $client->createMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $tempKey,
                'ChecksumAlgorithm' => ChecksumAlgorithm::SHA256,
            ]);
            $state = ['uploadId' => $create->getUploadId(), 'key' => $tempKey, 'parts' => []];
        }

        $partNumber = count($state['parts']) + 1;
        $part = $this->uploadPartWithVerification($client, $bucket, $state['key'], $state['uploadId'], $partNumber, $bytes);
        $state['parts'][] = $part;

        $this->saveMultipartState($hash, $state);

        return array_column($state['parts'], 'PartNumber');
    }

    public function deleteCache(Storage $storage): void
    {
        // Opportunistic cleanup of abandoned chunked-upload multipart sessions: anything
        // whose local state file is older than the abandon threshold gets aborted server-side.
        // This is a best-effort backstop alongside (not a replacement for) the bucket
        // lifecycle rule documented in the README, which covers the case where the PHP
        // process dies before ever running this.
        $dir = $this->multipartStateDir();
        if (!is_dir($dir)) {
            return;
        }

        $cutoff = time() - self::MULTIPART_ABANDON_SECONDS;
        $client = null;
        $bucket = null;

        foreach ((scandir($dir) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.json')) {
                continue;
            }

            $path = $dir . '/' . $entry;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime >= $cutoff) {
                continue;
            }

            $state = json_decode((string)@file_get_contents($path), true);
            if (is_array($state) && !empty($state['uploadId']) && !empty($state['key'])) {
                $client ??= $this->getS3Client($storage);
                $bucket ??= $this->getS3ClientFactory()->getBucket($storage);
                $this->abortMultipartQuietly($client, $bucket, (string)$state['key'], (string)$state['uploadId']);
            }

            @unlink($path);
        }

        $this->sweepLocalPathTmpDir();
    }

    private function sweepLocalPathTmpDir(): void
    {
        $dir = $this->localPathTmpDir();
        if (!is_dir($dir)) {
            return;
        }

        $cutoff = time() - self::LOCAL_PATH_TMP_ABANDON_SECONDS;
        foreach ((scandir($dir) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }

            @unlink($path);
        }
    }

    public function renameFile(File $file): bool
    {
        // No-op by design: the object key is the File's immutable id, never its name.
        return true;
    }

    public function moveFile(File $file): bool
    {
        // No-op by design: the object key is the File's immutable id, never its folder path.
        return true;
    }

    public function renameFolder(Folder $folder): bool
    {
        return true;
    }

    public function moveFolder(string $entityId, string $wasParentId, string $becameParentId): bool
    {
        return true;
    }

    public function reupload(File $file): bool
    {
        $storage = $file->getStorage();
        $fileId = (string)$file->get('id');

        $lock = new AdvisoryLock($this->getDbalConnection());
        if (!$lock->tryLock($fileId)) {
            throw new Conflict('This file is currently being updated by another process. Please try again in a few seconds.');
        }

        try {
            $input = $file->_input ?? new \stdClass();
            if (!property_exists($input, 'fileContents')) {
                throw new Error("S3Storage: reupload requires direct file content for file '{$file->get('name')}'.");
            }

            $base64 = $this->extractBase64Payload((string)$input->fileContents);
            $this->assertWithinMemoryCeiling(strlen($base64));

            $bytes = base64_decode($base64, true);
            if ($bytes === false) {
                throw new Error("S3Storage: could not decode uploaded content for file '{$file->get('name')}'.");
            }

            $finalKey = $this->buildKey($storage, $fileId);
            $tempKey = $this->buildTempKey($storage, $fileId);

            if (strlen($bytes) > self::MULTIPART_THRESHOLD_BYTES) {
                $this->putMultipartFromBytes($storage, $tempKey, $bytes, $file);
            } else {
                $this->putObjectWithVerification($storage, $tempKey, $bytes, $file);
            }

            // The temp object is now verified good. Swap it onto the real key; the original
            // is never touched until this copy is itself verified.
            $this->copyWithVerification($storage, $tempKey, $finalKey);
            $this->deleteObjectQuietly($this->getS3Client($storage), $this->getS3ClientFactory()->getBucket($storage), $tempKey);

            $this->setFileAttributesFromBytes($file, $bytes);

            return true;
        } finally {
            $lock->unlock($fileId);
        }
    }

    public function deleteFilePermanently(File $file): bool
    {
        $storage = $file->getStorage();
        $client = $this->getS3Client($storage);
        $bucket = $this->getS3ClientFactory()->getBucket($storage);
        $key = $this->buildKey($storage, (string)$file->get('id'));

        try {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (\Throwable $e) {
            throw new Error("S3Storage: failed to delete '$key': " . $e->getMessage());
        }

        return true;
    }

    public function deleteFolderPermanently(Folder $folder): bool
    {
        return true;
    }

    public function getStream(File $file): StreamInterface
    {
        $storage = $file->getStorage();
        $client = $this->getS3Client($storage);
        $bucket = $this->getS3ClientFactory()->getBucket($storage);
        $key = $this->buildKey($storage, (string)$file->get('id'));

        $body = $client->getObject(['Bucket' => $bucket, 'Key' => $key])->getBody();

        // getContentAsResource() materializes the body into a real (rewindable) PHP
        // resource before returning — deliberately, not an oversight: core's own
        // File::findOrCreateLocalFilePath() calls rewind() on whatever getStream()
        // returns, so a forward-only chunked-pull stream would break that code path.
        return Psr7Utils::streamFor($body->getContentAsResource());
    }

    public function getUrl(File $file): string
    {
        $url = $this->getConfig()->getSiteUrl() . DIRECTORY_SEPARATOR;
        if (in_array($file->get('mimeType'), Image::TYPES, true)) {
            $url .= 'images' . DIRECTORY_SEPARATOR . $file->get('id') . '.' . $file->get('extension');
        } else {
            $url .= 'downloads' . DIRECTORY_SEPARATOR . $file->get('id') . '.' . $file->get('extension');
        }

        return $url;
    }

    public function getThumbnail(File $file, string $size): ?string
    {
        // Thumbnails are a local cache layer on top of any storage backend (core's own
        // Thumbnail utility always reads/writes local disk) — reuse it as-is rather than
        // inventing S3-backed thumbnail storage. getContents() below supplies the source
        // bytes on a cache miss.
        $thumbnailCreator = $this->getThumbnailUtil();

        if ($thumbnailCreator->hasThumbnail($file, $size)) {
            return $thumbnailCreator->preparePath($file, $size);
        }

        return $thumbnailCreator->getPath($file, $size);
    }

    public function getThumbnailPdfImageCachePath(File $file): ?string
    {
        $dir = rtrim((string)($this->getConfig()->get('uploadRootPath') ?: 'data/upload'), '/')
            . '/.s3-storage-pdf-cache/' . $file->get('storageId');

        $this->getFileManagerUtil()->mkdir($dir, 0777, true);

        return $dir . '/' . $file->get('id');
    }

    public function getContents(File $file): string
    {
        $storage = $file->getStorage();
        $client = $this->getS3Client($storage);
        $bucket = $this->getS3ClientFactory()->getBucket($storage);
        $key = $this->buildKey($storage, (string)$file->get('id'));

        return $client->getObject(['Bucket' => $bucket, 'Key' => $key])->getBody()->getContentAsString();
    }

    /**
     * Downloads a fresh temp copy of the object for code that needs a real local path
     * (currently: EntryPoints/Thumbnail.php via Repositories\File::getFilePath()). Always
     * re-downloads rather than reusing any previous copy, so a reupload can never result
     * in stale content being read here. The $fetched flag is meaningful for LocalStorage's
     * rename-in-progress old/new path distinction, which doesn't apply to us (our S3 key
     * is the immutable File id, never derived from name/path), so it's ignored.
     */
    public function getLocalPath(File $file, bool $fetched = false): string
    {
        $dir = $this->localPathTmpDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $extension = (string)$file->get('extension');
        $suffix = $extension !== '' ? '.' . $extension : '';
        $path = $dir . '/' . $file->get('id') . '.' . bin2hex(random_bytes(8)) . $suffix;

        file_put_contents($path, $this->getContents($file));

        return $path;
    }

    public function isAvailable(Storage $storage): bool
    {
        try {
            $client = $this->getS3Client($storage);
            $bucket = $this->getS3ClientFactory()->getBucket($storage);

            // async-aws/s3 has no headBucket() call directly — bucketExists() returns a
            // Waiter wrapping the same HeadBucket request; resolve() with a short timeout
            // gives a one-shot true/false rather than the waiter's default long poll.
            return $client->bucketExists(['Bucket' => $bucket])->resolve(3.0);
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Upload / integrity helpers
    // ---------------------------------------------------------------------

    private function assertWithinMemoryCeiling(int $base64Length): void
    {
        $estimatedDecodedBytes = (int)floor($base64Length * 3 / 4);
        $limit = $this->computeMemoryCeilingBytes();

        if ($estimatedDecodedBytes > $limit) {
            throw new BadRequest(sprintf(
                "File is too large to upload via the standard upload path under the current PHP " .
                "memory_limit (%s). Estimated size ~%dMB exceeds the computed safe ceiling of ~%dMB. " .
                "Increase memory_limit, or use the chunked-upload path instead (true S3 multipart, " .
                "never fully buffered in memory).",
                (string)ini_get('memory_limit'),
                (int)round($estimatedDecodedBytes / 1024 / 1024),
                (int)round($limit / 1024 / 1024)
            ));
        }
    }

    private function computeMemoryCeilingBytes(): int
    {
        $memoryLimitBytes = $this->parseMemoryLimit((string)ini_get('memory_limit'));
        if ($memoryLimitBytes <= 0) {
            // -1 (or unset/0) means "unlimited" in PHP's own convention — no ceiling to enforce.
            return PHP_INT_MAX;
        }

        $available = $memoryLimitBytes - self::MEMORY_BASELINE_BYTES;

        return $available > 0 ? (int)floor($available / self::MEMORY_MULTIPLIER) : 0;
    }

    private function parseMemoryLimit(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int)$value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int)$value,
        };
    }

    private function extractBase64Payload(string $fileContents): string
    {
        $parts = explode(',', $fileContents, 2);

        return count($parts) > 1 ? $parts[1] : $parts[0];
    }

    private function setFileAttributesFromBytes(File $file, string $bytes): void
    {
        $mimeType = $file->get('mimeType');
        if (empty($mimeType)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = ($finfo !== false ? finfo_buffer($finfo, $bytes) : false) ?: 'application/octet-stream';
            if ($finfo !== false) {
                finfo_close($finfo);
            }
        }

        $file->set('mimeType', $mimeType);
        $file->set('fileSize', strlen($bytes));
        $file->set('fileMtime', gmdate('Y-m-d H:i:s'));
        // Real MD5 hex, matching core's own convention exactly — we already hold the full
        // bytes in memory for the non-chunked/non-multipart path, so this costs nothing extra.
        $file->set('hash', md5($bytes));
    }

    private function computeChecksumSha256(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }

    private function backoff(int $attempt): void
    {
        if ($attempt >= self::MAX_RETRIES) {
            return;
        }

        $baseMs = 200 * (2 ** ($attempt - 1));
        $jitterMs = random_int(0, $baseMs);
        usleep(($baseMs + $jitterMs) * 1000);
    }

    private function putObjectWithVerification(Storage $storage, string $key, string $bytes, ?File $file = null): void
    {
        $client = $this->getS3Client($storage);
        $bucket = $this->getS3ClientFactory()->getBucket($storage);
        $expectedChecksum = $this->computeChecksumSha256($bytes);

        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $result = $client->putObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'Body' => $bytes,
                    'ChecksumAlgorithm' => ChecksumAlgorithm::SHA256,
                    // ChecksumAlgorithm alone only names the algorithm; the SDK does not
                    // auto-compute a trailer for a plain string body — the checksum VALUE
                    // must be supplied explicitly so S3 can validate what we intended to send
                    // against what it actually received (verified against real MinIO: without
                    // this, PutObject silently returns no checksum and UploadPart hard-errors).
                    'ChecksumSHA256' => $expectedChecksum,
                    'ContentType' => ($file?->get('mimeType')) ?: 'application/octet-stream',
                ]);

                $actualChecksum = $result->getChecksumSha256();
                if ($actualChecksum !== null) {
                    if ($actualChecksum !== $expectedChecksum) {
                        throw new Error("checksum mismatch after upload to '$key' (attempt $attempt)");
                    }
                } else {
                    // Backend without checksum-trailer support: fall back to ETag/MD5 comparison.
                    $etag = trim((string)$result->getETag(), '"');
                    if ($etag !== md5($bytes)) {
                        throw new Error("ETag mismatch after upload to '$key' (attempt $attempt)");
                    }
                }

                return;
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->backoff($attempt);
            }
        }

        // Exhausted retries: never leave a possibly-corrupt object behind under a key
        // anything might read as "the real file" — best-effort cleanup, then throw.
        $this->deleteObjectQuietly($client, $bucket, $key);

        throw new Error(
            "S3Storage: failed to upload '$key' after " . self::MAX_RETRIES . " attempts: " .
            $lastException->getMessage()
        );
    }

    private function putMultipartFromBytes(Storage $storage, string $key, string $bytes, ?File $file = null): void
    {
        $client = $this->getS3Client($storage);
        $bucket = $this->getS3ClientFactory()->getBucket($storage);

        $create = $client->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'ChecksumAlgorithm' => ChecksumAlgorithm::SHA256,
            'ContentType' => ($file?->get('mimeType')) ?: 'application/octet-stream',
        ]);
        $uploadId = $create->getUploadId();

        try {
            $parts = [];
            $length = strlen($bytes);
            $partNumber = 1;
            for ($offset = 0; $offset < $length; $offset += self::MULTIPART_PART_SIZE_BYTES) {
                $chunk = substr($bytes, $offset, self::MULTIPART_PART_SIZE_BYTES);
                $parts[] = $this->uploadPartWithVerification($client, $bucket, $key, $uploadId, $partNumber, $chunk);
                $partNumber++;
            }

            $client->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts],
            ]);
        } catch (\Throwable $e) {
            $this->abortMultipartQuietly($client, $bucket, $key, $uploadId);
            throw new Error("S3Storage: multipart upload of '$key' failed and was aborted: " . $e->getMessage());
        }
    }

    private function uploadPartWithVerification(S3Client $client, string $bucket, string $key, string $uploadId, int $partNumber, string $chunk): array
    {
        $expectedChecksum = $this->computeChecksumSha256($chunk);

        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $result = $client->uploadPart([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                    'PartNumber' => $partNumber,
                    'Body' => $chunk,
                    'ChecksumAlgorithm' => ChecksumAlgorithm::SHA256,
                    'ChecksumSHA256' => $expectedChecksum,
                ]);

                $actual = $result->getChecksumSha256();
                if ($actual !== null && $actual !== $expectedChecksum) {
                    throw new Error("checksum mismatch on part $partNumber (attempt $attempt)");
                }

                return [
                    'PartNumber' => $partNumber,
                    'ETag' => $result->getETag(),
                    'ChecksumSHA256' => $actual,
                ];
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->backoff($attempt);
            }
        }

        throw new Error("S3Storage: failed to upload part $partNumber of '$key': " . $lastException->getMessage());
    }

    private function abortMultipartQuietly(S3Client $client, string $bucket, string $key, string $uploadId): void
    {
        try {
            $client->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
        } catch (\Throwable) {
            // Best-effort only; the bucket's AbortIncompleteMultipartUpload lifecycle rule
            // (see README) is the backstop for cases where even this cleanup call fails.
        }
    }

    private function deleteObjectQuietly(S3Client $client, string $bucket, string $key): void
    {
        try {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (\Throwable) {
        }
    }

    private function copyWithVerification(Storage $storage, string $sourceKey, string $destKey): void
    {
        $client = $this->getS3Client($storage);
        $bucket = $this->getS3ClientFactory()->getBucket($storage);

        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $client->copyObject([
                    'Bucket' => $bucket,
                    'CopySource' => rawurlencode($bucket . '/' . $sourceKey),
                    'Key' => $destKey,
                    'ChecksumAlgorithm' => ChecksumAlgorithm::SHA256,
                ]);

                $sourceHead = $client->headObject(['Bucket' => $bucket, 'Key' => $sourceKey]);
                $destHead = $client->headObject(['Bucket' => $bucket, 'Key' => $destKey]);

                if ($this->headSignature($sourceHead) !== $this->headSignature($destHead)) {
                    throw new Error("copy verification failed from '$sourceKey' to '$destKey' (attempt $attempt)");
                }

                return;
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->backoff($attempt);
            }
        }

        throw new Error(
            "S3Storage: failed to copy '$sourceKey' to '$destKey' after " . self::MAX_RETRIES . " attempts: " .
            $lastException->getMessage()
        );
    }

    private function headSignature(HeadObjectOutput $head): string
    {
        $checksum = $head->getChecksumSha256();
        if ($checksum !== null) {
            return 'sha256:' . $checksum;
        }

        return 'etag:' . trim((string)$head->getETag(), '"') . ':' . $head->getContentLength();
    }

    private function headMatchesStoredHash(HeadObjectOutput $head, string $storedHash): bool
    {
        if ($storedHash === '') {
            return true; // nothing to compare against
        }

        $checksumBase64 = $head->getChecksumSha256();
        if ($checksumBase64 !== null) {
            $decoded = base64_decode($checksumBase64, true);
            if ($decoded !== false && bin2hex($decoded) === strtolower($storedHash)) {
                return true;
            }
        }

        $etag = trim((string)$head->getETag(), '"');
        if (!str_contains($etag, '-') && strtolower($etag) === strtolower($storedHash)) {
            return true; // non-multipart object: ETag is a plain MD5 hex digest
        }

        return false;
    }

    private function logDrift(Storage $storage, File $file, string $message): void
    {
        try {
            $this->container->get('log')->warning(
                "S3Storage scan: integrity drift on File '{$file->get('id')}' (Storage '{$storage->get('id')}'): $message"
            );
        } catch (\Throwable) {
        }
    }

    private function saveScanCursor(Storage $storage, string $cursor): void
    {
        $storage->set('s3ScanCursor', $cursor);
        $this->getEntityManager()->saveEntity($storage, ['skipValidation' => true, 'silent' => true]);
    }

    // ---------------------------------------------------------------------
    // Chunked-upload (multipart) bookkeeping — persisted to local disk because
    // memoryStorage is per-request only and would not survive between the
    // separate HTTP requests a chunked upload spans. Only this small JSON
    // bookkeeping record touches local disk; file bytes never do.
    // ---------------------------------------------------------------------

    private function completeMultipartFromChunks(File $file, Storage $storage, \stdClass $input): bool
    {
        $hash = (string)($input->fileUniqueHash ?? '');
        if ($hash === '') {
            throw new Error("S3Storage: chunked upload finalize called without fileUniqueHash for file '{$file->get('name')}'.");
        }

        $state = $this->loadMultipartState($hash);
        if ($state === null) {
            throw new Error("S3Storage: no in-progress chunked upload found for '$hash' (it may have expired).");
        }

        $client = $this->getS3Client($storage);
        $bucket = $this->getS3ClientFactory()->getBucket($storage);

        try {
            $client->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $state['key'],
                'UploadId' => $state['uploadId'],
                'MultipartUpload' => ['Parts' => $state['parts']],
            ]);
        } catch (\Throwable $e) {
            $this->abortMultipartQuietly($client, $bucket, (string)$state['key'], (string)$state['uploadId']);
            $this->clearMultipartState($hash);
            throw new Error("S3Storage: failed to finalize chunked upload for '{$file->get('name')}': " . $e->getMessage());
        }

        $finalKey = $this->buildKey($storage, (string)$file->get('id'));

        try {
            $this->copyWithVerification($storage, (string)$state['key'], $finalKey);
        } finally {
            $this->deleteObjectQuietly($client, $bucket, (string)$state['key']);
            $this->clearMultipartState($hash);
        }

        $head = $client->headObject(['Bucket' => $bucket, 'Key' => $finalKey]);
        $file->set('mimeType', $file->get('mimeType') ?: ($head->getContentType() ?: 'application/octet-stream'));
        $file->set('fileSize', $head->getContentLength());
        $file->set('fileMtime', gmdate('Y-m-d H:i:s'));

        // Multipart-assembled files can't cheaply produce a whole-object MD5 without
        // re-downloading (S3's multipart ETag is not a plain MD5) — documented limitation:
        // these records store the SHA-256 checksum (hex) instead of MD5 in `hash`.
        // scan()'s drift check (headMatchesStoredHash) understands both representations.
        $checksum = $head->getChecksumSha256();
        $file->set('hash', $checksum !== null ? bin2hex((string)base64_decode($checksum, true)) : md5((string)$head->getETag()));

        return true;
    }

    private function multipartStateDir(): string
    {
        return rtrim((string)($this->getConfig()->get('uploadRootPath') ?: 'data/upload'), '/') . '/.s3-storage-multipart';
    }

    private function multipartStatePath(string $hash): string
    {
        return $this->multipartStateDir() . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $hash) . '.json';
    }

    private function localPathTmpDir(): string
    {
        return rtrim((string)($this->getConfig()->get('uploadRootPath') ?: 'data/upload'), '/') . '/.s3-storage-localpath-tmp';
    }

    private function loadMultipartState(string $hash): ?array
    {
        $path = $this->multipartStatePath($hash);
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private function saveMultipartState(string $hash, array $state): void
    {
        $dir = $this->multipartStateDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // Atomic write (temp file + rename) so a concurrent reader never observes a
        // partially-written state file.
        $path = $this->multipartStatePath($hash);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($state));
        rename($tmp, $path);
    }

    private function clearMultipartState(string $hash): void
    {
        $path = $this->multipartStatePath($hash);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ---------------------------------------------------------------------
    // Key building & service accessors
    // ---------------------------------------------------------------------

    private function keyPrefix(Storage $storage): string
    {
        return trim((string)$storage->get('s3KeyPrefix'), '/');
    }

    private function buildKey(Storage $storage, string $id): string
    {
        $prefix = $this->keyPrefix($storage);

        return $prefix !== '' ? $prefix . '/' . $id : $id;
    }

    private function buildTempKey(Storage $storage, string $id): string
    {
        return $this->buildKey($storage, $id) . '.uploading.' . bin2hex(random_bytes(8));
    }

    private function getS3Client(Storage $storage): S3Client
    {
        return $this->getS3ClientFactory()->create($storage);
    }

    private function getS3ClientFactory(): S3ClientFactory
    {
        if ($this->s3ClientFactory === null) {
            $this->s3ClientFactory = new S3ClientFactory($this->container);
        }

        return $this->s3ClientFactory;
    }

    private function getDbalConnection(): \Doctrine\DBAL\Connection
    {
        return $this->container->get('connection');
    }

    private function getConfig(): Config
    {
        return $this->container->get('config');
    }

    private function getEntityManager(): EntityManager
    {
        return $this->container->get('entityManager');
    }

    private function getFileManagerUtil(): FileManager
    {
        return $this->container->get('fileManager');
    }

    private function getThumbnailUtil(): Thumbnail
    {
        return $this->container->get(Thumbnail::class);
    }
}

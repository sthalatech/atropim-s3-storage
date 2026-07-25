<?php

declare(strict_types=1);

namespace S3Storage\Tests\Unit;

use AsyncAws\S3\Result\HeadObjectOutput;
use Atro\Entities\Storage;
use PHPUnit\Framework\TestCase;
use S3Storage\Core\FileStorage\S3FileStorage;
use S3Storage\Tests\Support\ContainerBuilder;

final class S3FileStorageTest extends TestCase
{
    private function newStorage(): S3FileStorage
    {
        return new S3FileStorage(ContainerBuilder::build([]));
    }

    private function invokePrivate(object $object, string $method, array $args = [])
    {
        $r = new \ReflectionMethod($object, $method);
        $r->setAccessible(true);

        return $r->invokeArgs($object, $args);
    }

    // -----------------------------------------------------------------
    // Memory ceiling
    // -----------------------------------------------------------------

    public function testParseMemoryLimitHandlesUnits(): void
    {
        $s = $this->newStorage();

        $this->assertSame(256 * 1024 * 1024, $this->invokePrivate($s, 'parseMemoryLimit', ['256M']));
        $this->assertSame(1 * 1024 * 1024 * 1024, $this->invokePrivate($s, 'parseMemoryLimit', ['1G']));
        $this->assertSame(2048, $this->invokePrivate($s, 'parseMemoryLimit', ['2048']));
        $this->assertSame(-1, $this->invokePrivate($s, 'parseMemoryLimit', ['-1']));
    }

    public function testMemoryCeilingReferencePoints(): void
    {
        $s = $this->newStorage();

        ini_set('memory_limit', '256M');
        $ceiling256 = $this->invokePrivate($s, 'computeMemoryCeilingBytes', []);
        $ceilingMb256 = (int) round($ceiling256 / 1024 / 1024);

        ini_set('memory_limit', '512M');
        $ceiling512 = $this->invokePrivate($s, 'computeMemoryCeilingBytes', []);
        $ceilingMb512 = (int) round($ceiling512 / 1024 / 1024);

        // PLAN.md reference points: ~45MB @ 256MB, ~100MB @ 512MB.
        $this->assertGreaterThanOrEqual(40, $ceilingMb256);
        $this->assertLessThanOrEqual(55, $ceilingMb256);
        $this->assertGreaterThanOrEqual(95, $ceilingMb512);
        $this->assertLessThanOrEqual(130, $ceilingMb512);
    }

    public function testAssertWithinMemoryCeilingRejectsOversizedPayload(): void
    {
        ini_set('memory_limit', '256M');
        $s = $this->newStorage();

        $tooLargeBase64Length = 200 * 1024 * 1024; // ~200MB of base64 text — decodes to ~150MB, over the ~45MB ceiling.

        $this->expectException(\Atro\Core\Exceptions\BadRequest::class);
        $this->invokePrivate($s, 'assertWithinMemoryCeiling', [$tooLargeBase64Length]);
    }

    public function testAssertWithinMemoryCeilingAllowsSmallPayload(): void
    {
        ini_set('memory_limit', '256M');
        $s = $this->newStorage();

        // 1MB of base64 text decodes to well under the ceiling — must not throw.
        $this->invokePrivate($s, 'assertWithinMemoryCeiling', [1024 * 1024]);
        $this->addToAssertionCount(1);
    }

    public function testUnlimitedMemoryLimitNeverRejects(): void
    {
        ini_set('memory_limit', '-1');
        $s = $this->newStorage();

        $this->invokePrivate($s, 'assertWithinMemoryCeiling', [500 * 1024 * 1024]);
        $this->addToAssertionCount(1);
    }

    // -----------------------------------------------------------------
    // Checksum helpers
    // -----------------------------------------------------------------

    public function testComputeChecksumSha256MatchesKnownVector(): void
    {
        $s = $this->newStorage();
        $checksum = $this->invokePrivate($s, 'computeChecksumSha256', ['hello world']);

        $this->assertSame(base64_encode(hash('sha256', 'hello world', true)), $checksum);
    }

    public function testHeadMatchesStoredHashViaChecksum(): void
    {
        $s = $this->newStorage();
        $bytes = 'some file content';
        $sha256Base64 = base64_encode(hash('sha256', $bytes, true));
        $expectedHexHash = bin2hex(hash('sha256', $bytes, true));

        $head = $this->createMock(HeadObjectOutput::class);
        $head->method('getChecksumSha256')->willReturn($sha256Base64);

        $this->assertTrue($this->invokePrivate($s, 'headMatchesStoredHash', [$head, $expectedHexHash]));
        $this->assertFalse($this->invokePrivate($s, 'headMatchesStoredHash', [$head, 'deadbeef']));
    }

    public function testHeadMatchesStoredHashFallsBackToETagWhenChecksumMissing(): void
    {
        // Verified real-world behavior: MinIO does not return a checksum on HeadObject
        // even for objects stored with one — this fallback is load-bearing, not academic.
        $s = $this->newStorage();
        $bytes = 'some file content';
        $md5Hex = md5($bytes);

        $head = $this->createMock(HeadObjectOutput::class);
        $head->method('getChecksumSha256')->willReturn(null);
        $head->method('getETag')->willReturn('"' . $md5Hex . '"');

        $this->assertTrue($this->invokePrivate($s, 'headMatchesStoredHash', [$head, $md5Hex]));
    }

    public function testHeadMatchesStoredHashIgnoresMultipartEtag(): void
    {
        // A multipart ETag (e.g. "abc123-2") is NOT a plain MD5 — must not be treated as one.
        $s = $this->newStorage();

        $head = $this->createMock(HeadObjectOutput::class);
        $head->method('getChecksumSha256')->willReturn(null);
        $head->method('getETag')->willReturn('"abc123def456-2"');

        $this->assertFalse($this->invokePrivate($s, 'headMatchesStoredHash', [$head, 'abc123def456-2']));
    }

    public function testHeadMatchesStoredHashSkipsComparisonWhenNoStoredHash(): void
    {
        $s = $this->newStorage();
        $head = $this->createMock(HeadObjectOutput::class);

        $this->assertTrue($this->invokePrivate($s, 'headMatchesStoredHash', [$head, '']));
    }

    // -----------------------------------------------------------------
    // Key building — the "key = file id" design decision
    // -----------------------------------------------------------------

    public function testBuildKeyWithoutPrefix(): void
    {
        $s = $this->newStorage();
        $storage = $this->createMock(Storage::class);
        $storage->method('get')->willReturnMap([['s3KeyPrefix', [], null]]);

        $this->assertSame('file-id-123', $this->invokePrivate($s, 'buildKey', [$storage, 'file-id-123']));
    }

    public function testBuildKeyWithPrefixTrimsSlashes(): void
    {
        $s = $this->newStorage();
        $storage = $this->createMock(Storage::class);
        $storage->method('get')->willReturnMap([['s3KeyPrefix', [], '/atropim/prod/']]);

        $this->assertSame('atropim/prod/file-id-123', $this->invokePrivate($s, 'buildKey', [$storage, 'file-id-123']));
    }

    public function testBuildTempKeyIsDistinctEachCall(): void
    {
        $s = $this->newStorage();
        $storage = $this->createMock(Storage::class);
        $storage->method('get')->willReturnMap([['s3KeyPrefix', [], null]]);

        $a = $this->invokePrivate($s, 'buildTempKey', [$storage, 'file-id-123']);
        $b = $this->invokePrivate($s, 'buildTempKey', [$storage, 'file-id-123']);

        $this->assertNotSame($a, $b);
        $this->assertStringStartsWith('file-id-123.uploading.', $a);
    }

    // -----------------------------------------------------------------
    // Folder/rename/move no-ops — the "key never changes on rename/move" decision
    // -----------------------------------------------------------------

    public function testFolderAndRenameOperationsAreNoOps(): void
    {
        $s = $this->newStorage();

        $this->assertTrue($s->renameFile($this->createMock(\Atro\Entities\File::class)));
        $this->assertTrue($s->moveFile($this->createMock(\Atro\Entities\File::class)));
        $this->assertTrue($s->renameFolder($this->createMock(\Atro\Entities\Folder::class)));
        $this->assertTrue($s->moveFolder('a', 'b', 'c'));
        $this->assertTrue($s->createFolder($this->createMock(\Atro\Entities\Folder::class)));
        $this->assertTrue($s->deleteFolderPermanently($this->createMock(\Atro\Entities\Folder::class)));
    }
}

# AtroPIM S3 Storage Addon — Implementation Plan

## Context

`sthalatech/atropim-s3-storage` is currently an empty GitHub repo. The goal is a production-grade,
open-source AtroCore/AtroPIM addon that lets an install store file/media bytes in S3-compatible
object storage instead of (or alongside) local disk, with zero visible change to end users, and
with hardening against data loss/corruption on write — since this will be a public release, silent
data-integrity bugs are the top risk to design out.

All architectural claims below come from directly reading AtroCore's own public source
(`github.com/atrocore/atrocore`, `atrocore/import`, `atrocore/example-module`, `atrocore/atropim`),
not guesses — the codebase already ships a multi-backend storage abstraction and a `Connection`
entity with battle-tested secret encryption, so the addon plugs into existing machinery rather than
inventing a parallel one.

Locked-in decisions from user Q&A:
- **Serving**: proxy-through-app (already the only download path in core — streams in 4KB chunks).
- **SDK**: `async-aws/s3` (lightweight PSR-7 client), not the full AWS SDK.
- **Migration**: none in v1 — new/changed files go to S3 going forward; no bulk-migrate tool.
- **License**: GPL-3.0-only (matches core and `atrocore/import`; avoids derivative-work risk since
  the addon extends core's GPL-licensed `AbstractModule`).
- **Integrity**: checksum-verify every write, retry with backoff on mismatch, never silently accept
  a corrupted upload.
- **Secrets**: encrypted at rest, reusing AtroCore's existing `Encrypter`/`Connection` machinery
  rather than rolling bespoke crypto.
- **Backends**: AWS S3 is the only backend that must work for v1; generic S3-API compatibility
  (MinIO, R2, Wasabi) via endpoint override + path-style toggle is a "nice to have, don't break it"
  rather than a tested guarantee.
- **Config surface**: AtroCore's native `Storage` + `Connection` entities (Admin panel), not a
  bespoke Settings page — matches existing architecture, gets multi-bucket support and encryption
  for free.

## Architecture grounding (from source research)

- **Storage abstraction**: `Atro\Core\FileStorage\FileStorageInterface` — methods `scan()`,
  `createFile(File)`, `createFolder(Folder)`, `createChunk(stdClass,Storage):array`, `deleteCache()`,
  `renameFile/moveFile/renameFolder/moveFolder`, `reupload()`, `deleteFilePermanently()`,
  `getStream(File):StreamInterface`, `getUrl(File):string`, `getThumbnail(File,size):?string`,
  `getContents(File):string`, `isAvailable(Storage):bool`.
- **Backend selection**: `Atro\Repositories\Storage::getFileStorage()` does
  `$container->get($storage->get('type') . 'Storage')`. A module registers its implementation by
  calling `$this->getContainer()->setClassAlias('s3Storage', S3Storage::class)` inside its
  `Module::onLoad()` (called for every module at boot, `Application.php`).
- **Landmine in core's own `LocalStorage::createFile()`**: writes via raw `file_put_contents`/
  `fopen`+`fwrite` with **no post-write verification** — just a truthy check. This is the exact gap
  the addon must not replicate.
- **Downloads already stream**: `EntryPoints/Download.php::downloadByFileStream()` reads
  `getStream()` and streams in 4096-byte chunks with `connection_aborted()` checks — proxy-through
  serving requires zero changes there; `S3Storage::getStream()` just needs to hand back a PSR-7
  stream over the S3 object body.
- **`getUrl()` must stay app-internal** — `LocalStorage::getUrl()` returns an app-internal
  `/downloads/{id}.{ext}` or `/images/{id}.{ext}` URL, never a raw path. Design consequence spec'd
  under S3Storage hardening design below.
- **Thumbnails are a local cache layer, storage-agnostic by design**: `Core\Utils\Thumbnail` always
  reads/writes `public/{thumbnailsPath}` on local disk regardless of backend.
  `S3Storage::getThumbnail()` should pull original bytes via its own `getContents()` and let the
  existing `Thumbnail` utility render/cache locally — don't invent S3-backed thumbnail storage.
- **`scan()` is dispatched per-`Storage` by type** from a cron-driven `ScanStorage` job,
  independently guarded — nothing else in core depends on it doing real work to keep functioning.
  Design consequence (upgrading it from a safe no-op into real bounded reconciliation) spec'd below.
- **Postgres is a first-class supported platform, confirmed from source, not MySQL-only**:
  `app/Atro/Core/Utils/Database/DBAL/Platforms/PostgreSQLPlatform.php` exists alongside
  `MySQLPlatform.php`/`MariaDBPlatform.php`, each with their own PDO driver. The advisory-lock design
  below keeps both `GET_LOCK`/`pg_advisory_lock` code paths — dropping either would break real
  deployments (the reference `atropim` VM itself runs Postgres 15).
- **`PDO::ATTR_PERSISTENT` is off by default and MySQL-only**: `MySQL/Driver.php:54` only sets it
  `if (!empty($params['persistent']))` — an explicit opt-in with no default config enabling it
  anywhere in the repo — and `PgSQL/Driver.php` doesn't implement the option at all. So under
  standard configuration, each HTTP request gets exactly one non-persistent PDO connection for its
  lifetime, which the locking design below relies on.
- **Upload data actually arrives fully in memory as base64, not as a seekable stream/tmp file** —
  traced the real path: client-side `client/src/views/file/fields/upload.js` does
  `FileReader.readAsDataURL(file)` and POSTs JSON with `fileContents` as a base64 data-URI string
  (large files instead go through `createChunk` with base64 `piece` fields). Server-side,
  `Atro\Repositories\File::beforeSave()` calls `createFile($entity)`/`reupload($entity)`, and
  `LocalStorage::createFile()` reads `$file->_input->fileContents` and `base64_decode()`s it
  directly — never a `$_FILES[...]['tmp_name']` path, never `php://input`. **This actually
  simplifies the retry design**: since the full decoded byte string is already resident in PHP
  memory by the time `createFile()` runs, a checksum-mismatch retry just resends the same in-memory
  string to `PutObject` — no stream-rewind problem exists for the non-chunked path. It also matches
  what `async-aws/s3` requires: its `ResourceStream` explicitly rejects non-seekable resources (SigV4
  signing needs to hash the full payload upfront), so passing the already-fully-available string
  body is both the natural fit and the only thing the SDK would accept for a one-shot stream anyway.
- **No concurrency control exists today on reupload, in core, for any backend** — confirmed:
  `Atro\Repositories\File::save()` wraps the save in a plain PDO transaction (atomicity/rollback
  only, no `SELECT ... FOR UPDATE`), `LocalStorage::reupload()` is an unguarded
  delete-then-recreate with no `flock()`/advisory lock anywhere in the codebase, and
  `Atro\Services\File::createFileEntity()` explicitly sets `_skipIsEntityUpdated = true` before
  calling `parent::updateEntity()`, disabling the one staleness check that might have caught a race.
  Two concurrent reuploads of the same `File` today can interleave with no mutual exclusion at all.
  This is a pre-existing gap in core, not something the addon introduces — but since S3's
  copy-verify-delete safety pattern (below) only protects against a *failed* write, not a *racing*
  one, v1 adds its own advisory locking around the reupload critical section (below), which is
  strictly more protection than core's local-disk path has today.
- **Secret encryption is already handled — for free**: `Atro\Services\Connection::encryptPasswordFields()`
  loops over **every** field on the `Connection` entity typed `"password"` in metadata and encrypts
  it via `Atro\Core\Utils\Encrypter` (AES-256-CBC, key from `passwordSalt`), regardless of which
  `type` value the record has. Because the addon's S3 config lives as a new `type` value on the
  *existing* `Connection` entity (not a new entity), this loop already covers our new
  `s3SecretKey` field the moment it's declared `"type": "password"` in metadata — no new crypto code
  needed. Field show/hide by `type` is handled generically by the client's `conditionalProperties`
  engine (`client/src/views/fields/base.js`) — no custom JS required for that part.
- **Metadata merge is REPLACE by default, not UNION** — `Atro\Core\Utils\Util::merge()`: a plain
  array override (e.g. `fields.type.options: ["s3"]`) wholesale-replaces core's existing options
  list. To extend instead of clobber, use the documented `__APPEND__` sigil:
  `"options": ["__APPEND__", "s3"]`. Confirmed as a live convention via `atropim`'s and `import`'s own
  `adminPanel.json` overrides (`"itemList": ["__APPEND__", {...}]`). **This must be used for both
  `Connection.json`'s and `Storage.json`'s `type.options` overrides** or the addon will silently
  break every other connection/storage type on install.
- **Module packaging**: plain Composer package, no custom `type`, no installer plugin. Discovery
  scans `composer.lock` entries for `extra.atroId`; the module class `\{atroId}\Module` is expected
  to autoload via the package's own PSR-4 block. Business-logic PHP runs straight from `vendor/` —
  only `Migrations/*`, an `Event.php` hook, and `client/` frontend assets get physically copied out
  to `data/migrations/`/`public/client/` on `composer.phar update`. Reference shape
  (`atrocore/import`'s real, shipped `composer.json`):
  ```json
  {
    "name": "atrocore/import",
    "require": { "atrocore/core": "~2.3.11" },
    "autoload": { "psr-4": { "Import\\": "app/" } },
    "extra": {
      "atroId": "Import",
      "name": { "default": "Import" },
      "description": { "default": "..." }
    }
  }
  ```
- **CI precedent**: the only real upstream CI is Semgrep SAST on merge requests
  (`import/.gitlab-ci.yml`, `p/php` + `p/javascript` rulesets) — no PHPUnit/PHPStan precedent
  upstream. Given the "robust, secure, hardened" requirement, the addon will exceed that baseline
  (PHPUnit + PHPStan + Semgrep), documented as a deliberate deviation from the minimal upstream norm.

## Repo layout

```
atropim-s3-storage/
├── composer.json                 # require atrocore/core, async-aws/s3 (^3.4 — see SDK note below); extra.atroId=S3Storage
├── LICENSE.txt                    # GPL-3.0-only (mirrors core's)
├── README.md                      # install, config, IAM policy, backend notes, limitations
├── .github/workflows/ci.yml       # semgrep + phpunit + phpstan
├── docker-compose.test.yml        # MinIO fixture for integration tests
├── app/
│   ├── Module.php                              # extends Atro\Core\ModuleManager\AbstractModule
│   ├── Core/FileStorage/S3Storage.php           # implements FileStorageInterface (the core class)
│   ├── Core/Utils/S3ClientFactory.php           # builds async-aws S3Client from a Connection record
│   └── Resources/
│       ├── metadata/entityDefs/
│       │   ├── Connection.json                  # __APPEND__ "s3" + conditional s3* fields
│       │   └── Storage.json                      # __APPEND__ "s3" + conditional connection/prefix fields
│       └── i18n/en_US/{Connection,Storage}.json   # field labels
├── client/modules/atropim-s3-storage/            # only if a "Test S3 Connection" button is added
├── tests/
│   ├── Unit/S3StorageTest.php                    # mocked async-aws responses
│   └── Integration/S3StorageMinioTest.php        # real MinIO round-trip via docker-compose
```

## New Connection fields (type = "s3")

On `Connection.json`, append `"s3"` to `fields.type.options` via `__APPEND__`, then add, each gated
by `conditionalProperties.visible.conditionGroup: [{"type":"in","attribute":"type","value":["s3"]}]`:

- `s3Region` (varchar, required)
- `s3Bucket` (varchar, required)
- `s3AccessKeyId` (varchar, required) — not secret by AWS's own model, stored plain like other
  non-password Connection fields (e.g. `user` on ftp/smtp types).
- `s3SecretAccessKey` (**type: "password"**, required) — encrypted automatically via the existing
  `encryptPasswordFields()` loop described above.
- `s3Endpoint` (varchar, optional) — override for S3-compatible non-AWS backends; blank = default
  AWS endpoint resolution from region.
- `s3ForcePathStyle` (bool, default false) — needed for MinIO-style path addressing.
- `s3VerifyTls` (bool, default true) — enforce TLS; disabling requires an explicit opt-in, logged
  loudly, intended only for local dev against MinIO over plain HTTP.

On `Storage.json`, append `"s3"` to `fields.type.options` via `__APPEND__`, then add:
- `connection` (link to `Connection`, required/visible when `type == "s3"`) — mirrors the existing
  `folder` link's conditional pattern already in the file.
- `s3KeyPrefix` (varchar, optional) — key prefix within the bucket, the S3 analogue of the existing
  `path` field for `local` storages (kept as a separate field rather than overloading `path`, to
  avoid confusing local-disk-path semantics with an S3 key prefix).
- `s3ScanCursor` (varchar, hidden from the edit layout, internal use only) — last-processed `File`
  id for the `scan()` resume cursor described below; not user-facing config, just addon-internal
  state stored on the existing entity rather than a new DB table.

## S3Storage — hardening design (the core of the "no corruption/no data loss" requirement)

- **SDK checksum support, confirmed empirically** (read `async-aws/s3` source directly, not just
  docs): `PutObjectRequest`/`CopyObjectRequest` accept a real `ChecksumAlgorithm` parameter (`SHA256`
  among others), and `PutObjectOutput`/`HeadObjectOutput`/`GetObjectOutput`/`CopyObjectResult` all
  expose matching `getChecksumSha256()`-style accessors populated from genuine `x-amz-checksum-*`
  response headers — this is real server-verified integrity checking, not just an ETag/MD5 guess.
  Checksum support landed in `async-aws/s3` `1.11.0`; pin `^3.4` for full algorithm coverage.
  Confirmed constraint: the SDK's `ResourceStream` throws on a non-seekable resource because SigV4
  signing hashes the full body upfront — consistent with (and satisfied by) the in-memory-string
  upload flow described above.
- **Client construction**: `S3ClientFactory` reads the `Storage`'s linked `Connection`, decrypts
  `s3SecretAccessKey` via the existing `Connection` service's `decryptPassword()`, builds an
  `AsyncAws\S3\S3Client` with region/endpoint/path-style/TLS options. Secret values are never logged
  and never interpolated into exception messages.
- **In-memory size ceiling — concrete, enforced, not just documented**: the upload path buffers the
  payload ~3.7× (raw POST body 1.33× + `json_decode`'s copy of the base64 string 1.33× +
  `base64_decode()` output 1.0×) on top of AtroCore's own per-request baseline (~60MB for a
  metadata/ORM-heavy PIM request). Solving `(memory_limit − 60MB) / 3.7` gives concrete reference
  points: **~45MB max file at a 256MB `memory_limit`**, **~100MB max file at 512MB** — both under the
  100MB multipart-slice threshold below, meaning a large non-chunked upload can hit a PHP OOM fatal
  *before* slicing logic ever runs. `createFile()` therefore computes
  `strlen($base64) * 3/4` (no decode needed for the estimate) against a limit computed at runtime
  from `ini_get('memory_limit')` via the same formula, and **rejects before calling
  `base64_decode()`** if it's over, with a clear, catchable error rather than risking an OOM crash.
  This can't undo memory the framework already spent buffering the POST body/JSON before our code
  runs, but it stops the addon from adding the final decode+upload allocation on top of an already
  tight request. README documents both reference numbers and recommends the chunked-upload UI path
  (true S3 multipart, never fully buffered) for installs expecting routinely large media.
- **`createFile()` (new upload, non-chunked path)**:
  1. `base64_decode()` the `_input->fileContents` payload once (as core itself already does), compute
     a SHA-256 checksum over the resulting bytes.
  2. `PutObject` with `ChecksumAlgorithm: SHA256`, passing the byte string directly as the body — S3
     validates server-side and returns `ChecksumSha256` in the response; compare against the locally
     computed value. Non-AWS backends without checksum-trailer support fall back to comparing the
     returned `ETag` against a computed MD5 (valid only for single-part puts) — documented as a known
     limitation for non-AWS backends.
  3. On mismatch or transient/5xx error: retry with exponential backoff + jitter (bounded attempts),
     resending the same in-memory bytes — no rewind concern since nothing was ever streamed.
  4. If retries are exhausted: throw — never mark the `File` entity as stored; let the existing save
     transaction fail naturally rather than leaving an orphaned DB record with no real object.
  5. **Multipart threshold**: single `PutObject` has a hard 5GB limit and gets progressively riskier
     as a single all-or-nothing transfer well before that. If the decoded byte length exceeds
     **100MB** (matches AWS's own general multipart guidance), automatically slice the in-memory
     bytes into ~16MB parts and perform a real multipart upload (`CreateMultipartUpload`/`UploadPart`
     per slice, each part's returned checksum verified individually/`CompleteMultipartUpload` only
     once every part is confirmed). In practice this rarely triggers via the non-chunked path, since
     PHP's own `upload_max_filesize`/`post_max_size`/`memory_limit` already constrain how large a
     file can reach `createFile()` this way — the README documents that installs expecting routinely
     large media should raise those PHP limits deliberately, or rely on the chunked-upload UI path
     below, and states the effective max single-shot size given typical PHP defaults.
- **`createChunk(stdClass $data, Storage $storage): array`**: maps AtroCore's client-driven chunked
  upload directly onto **real S3 multipart upload parts**, rather than assembling chunks into a local
  temp file first (which is what `LocalStorage` does via its `.chunks/` directory). First chunk for a
  given file triggers `CreateMultipartUpload`; each subsequent chunk is decoded and sent as
  `UploadPart` with its own checksum, verified before acknowledging that chunk to the client; the
  final chunk triggers `CompleteMultipartUpload` once all part checksums are confirmed. This means a
  large file's bytes are never fully buffered on the PHP side at all, on top of never touching local
  disk — a stronger guarantee than core's own local-chunk-file approach. **Cleanup on failure**: any
  part failure or exhausted-retry checksum mismatch triggers an explicit `AbortMultipartUpload` in a
  catch/finally around the sequence (this is the catchable-failure case). As a backstop for the
  uncatchable case — the PHP process itself hard-crashing (OOM-kill, fatal error, server reboot)
  mid-upload, where no application code runs at all — the README recommends a bucket lifecycle rule
  (`AbortIncompleteMultipartUpload` after 1 day) so orphaned multipart uploads don't silently
  accumulate storage cost/clutter regardless of how the failure happened.
- **`reupload()` / overwrite of existing content — locking + safe-swap**: core provides **no**
  concurrency control on reupload for any backend today (confirmed: plain PDO transaction only, no
  row lock, no advisory lock, and the one staleness flag is explicitly disabled for this path — see
  above). The addon adds its own protection, stricter than core's local-disk guarantee:
  1. Fetch the DBAL `Connection` object from the container **exactly once**, hold it in a local
     variable for the whole method — nothing in the sequence re-resolves the connection service, so
     there's no path for it to be swapped mid-method. Confirmed safe under default configuration:
     `PDO::ATTR_PERSISTENT` is off by default and only even implemented for the MySQL driver (see
     grounding above), so each request already gets one dedicated non-persistent connection; this
     code just makes sure our own logic doesn't re-fetch a second, potentially different one.
  2. Using that connection, attempt a **non-blocking** advisory lock scoped to the `File` id —
     `GET_LOCK(name, 0)` on MySQL/MariaDB, `pg_try_advisory_lock(key)` on PostgreSQL (branch on
     platform, both kept — see grounding above). **Fail fast, don't block-with-timeout**: if the lock
     is already held, immediately throw a `Conflict`-style exception with the message
     `"This file is currently being updated by another process. Please try again in a few seconds."`
     and log a WARNING server-side with the file/storage IDs. Blocking would risk stacking up
     PHP-FPM workers on a small VM; failing fast lets the user retry a normal HTTP request instead.
  3. Upload the new content to a temporary key (`{key}.uploading.{uuid}`), verify its checksum there.
  4. `CopyObject` the verified temp object onto the real key, then delete the temp key.
  5. Release the advisory lock (`RELEASE_LOCK()`/`pg_advisory_unlock()`) in a `finally` using the
     same connection reference from step 1, so a thrown exception never leaves it held. Documented
     caveat for the (non-default, opt-in) MySQL persistent-connection case: a hard process crash
     between acquire and the `finally` could in rare cases leave a stale lock on a pooled connection
     until it cycles — mitigated by giving the lock a bounded max hold rather than relying solely on
     connection-scoped auto-release as the only safety net.
  6. If verification fails at step 3: delete the temp object, leave the original completely
     untouched, release the lock, throw — a failed overwrite can never destroy the original (a direct
     same-key `PutObject` would have already destroyed it before verification could run), and a
     second concurrent reupload can never interleave with this one (it fails fast at step 2 instead).
- **`renameFile`/`moveFile`**: `CopyObject` to the new key, verify (`HeadObject` checksum/ETag
  compare), only then `DeleteObject` the old key — same copy-verify-then-delete-original pattern.
- **`deleteFilePermanently()`**: real `DeleteObject`; README recommends enabling S3 bucket
  versioning for defense-in-depth (outside the addon's own guarantees, but cheap insurance).
- **`getStream()`/`getContents()`**: `GetObject`, return/consume the response body as a PSR-7
  stream — feeds directly into the existing chunked `Download` entry point unchanged.
- **`getUrl()`**: returns the same `{siteUrl}/downloads/{id}.{ext}` / `/images/{id}.{ext}` shape as
  `LocalStorage` — this is what makes the switch invisible to end users.
- **`getThumbnail()`**: delegate to the existing `Thumbnail` utility, sourcing original bytes via
  `getContents()` on cache miss.
- **`isAvailable()`**: lightweight `HeadBucket` with a short timeout, catch and return `false` on
  any failure — used for admin health checks / a "Test Connection" action.
- **`scan()` — real bounded reconciliation, not a no-op, with concrete bounds**: given integrity is
  the addon's top stated goal and core has no other drift-detection path for this or any backend, v1
  implements a genuine, scoped check:
  - **Bound**: process up to **500 `File` records per invocation, or a 240-second wall-clock cap,
    whichever hits first.** Each `HeadObject`-per-record call is far more expensive than the local
    `stat()` calls core's own local scan does, so the batch is kept deliberately small and
    time-boxed rather than sized like core's local-disk batches.
  - **Resume cursor**: a new `Storage.s3ScanCursor` field (last-processed `File` id) is written at
    the end of each invocation. The next scheduled run (core's existing cron-driven `ScanStorage`
    job dispatch) resumes from that cursor rather than rescanning from the start; once the cursor
    reaches the end of the `File` list for that `Storage`, it wraps back to the beginning — a
    continuously-cycling, resumable sweep, not a one-shot.
  - **Skips in-progress reuploads instead of flagging false-positive drift**: before `HeadObject`-
    checking a given record, `scan()` does a non-blocking try-lock probe using the *same* shared
    advisory-lock utility `reupload()` uses (acquire-then-immediately-release, just as a probe — it
    doesn't hold the lock during the check). If the lock is already held elsewhere (a reupload of
    that file is actively in progress right now), `scan()` skips that record for this pass and picks
    it up on a later cycle, rather than comparing against a transitionally-inconsistent temp/real key
    state and reporting spurious drift.
  - Each mismatch or missing object is compared against the `File` entity's own stored `hash`
    attribute (confirmed to exist — core's reupload-race finding showed `hash`/`fileSize`/`mimeType`
    as metadata that can drift from actual bytes) and logged as a flagged integrity failure through
    the same job/log channel `ScanStorage` already uses, so drift becomes visible via existing admin
    tooling rather than silent.
  - Scoped explicitly as DB-driven (walking `File` records, not an S3 `ListObjectsV2` bucket walk) to
    keep cost predictable and bounded by record count rather than bucket size; the README documents
    this scope choice and notes that S3-side orphan objects (present in the bucket, no matching DB
    record) are a separate, rarer failure mode not covered by v1's scan.
- **`createFolder`/`deleteCache`**: folders are virtual (prefix-based) in S3, so `createFolder` is a
  no-op; `deleteCache` clears any local thumbnail cache for the file.

## Security hardening (for the open-source hardening bar)

- TLS required by default; plain-HTTP endpoints rejected unless `s3VerifyTls` is explicitly
  disabled (dev/MinIO only), with a loud warning logged when disabled.
- Secrets never appear in logs, exceptions, or API responses; `s3SecretAccessKey` stays encrypted
  at rest via the existing audited `Encrypter`.
- S3 object keys are derived from `File` entity UUIDs (already collision-resistant, not
  user-controlled path input) — still explicitly validate/sanitize before use to close off any
  path-traversal/injection vector.
- README documents a least-privilege IAM policy example — deliberately getting the most common
  hand-written-S3-policy mistake right: `ListBucket` is a **bucket-level** action and must be scoped
  to the bucket ARN itself (optionally with a `s3:prefix` string-like condition to restrict it to the
  configured key prefix), never appended to the object-level ARN list; `GetObject`/`PutObject`/
  `DeleteObject`/`HeadObject`/`CopyObject` are **object-level** actions scoped to `bucket-arn/prefix/*`:
  ```json
  {
    "Version": "2012-10-17",
    "Statement": [
      {
        "Effect": "Allow",
        "Action": "s3:ListBucket",
        "Resource": "arn:aws:s3:::BUCKET_NAME",
        "Condition": { "StringLike": { "s3:prefix": ["PREFIX/*"] } }
      },
      {
        "Effect": "Allow",
        "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
        "Resource": "arn:aws:s3:::BUCKET_NAME/PREFIX/*"
      }
    ]
  }
  ```
- Composer dependency (`async-aws/s3`) pinned to a specific version range, no wildcards.

## Verification plan

- **Unit tests** (mocked `async-aws` HTTP responses): checksum match/mismatch/retry-then-fail
  paths, temp-key-copy-then-delete overwrite logic never destroys the original on failure, advisory
  lock is always released (including on thrown exceptions) using the same connection reference,
  a second lock attempt fails fast with the exact documented error message rather than blocking,
  a simulated concurrent-reupload race never interleaves, the pre-decode size check rejects an
  oversized payload before `base64_decode()` runs, multipart slicing triggers at the 100MB threshold
  and reassembles correctly, a failed multipart part triggers `AbortMultipartUpload`, `scan()`
  correctly skips a record whose lock is held (no false-positive drift) and correctly advances/wraps
  `s3ScanCursor`, and no secret ever appears in a thrown exception's message.
- **Integration tests**: real MinIO container via `docker-compose.test.yml` in CI — full
  upload/download round-trip with byte-for-byte checksum comparison, overwrite-then-verify, delete,
  `scan()` correctly flagging a deliberately corrupted/missing object across a resumed cursor, and an
  injected-corruption case (mock a bit-flipped ETag/checksum response) to confirm the addon rejects
  and fails loudly rather than silently accepting bad data.
- **Manual end-to-end**: I don't currently have SSH access to the `atropim` VM from this sandbox
  (confirmed — no key/agent available), so live verification against the real AtroPIM instance at
  `atropim.exe.xyz` will need either you running the install/verification steps there yourself
  (I'll provide exact commands), or granting this session SSH access. Locally, I can stand up MinIO
  in this sandbox and exercise the full module end-to-end against it before that handoff.

## Build order

1. Scaffold repo: `composer.json`, `LICENSE.txt` (GPL-3.0), README skeleton, `app/Module.php`.
2. Metadata: `Connection.json` + `Storage.json` overrides (using `__APPEND__`), i18n labels.
3. `S3ClientFactory` + `S3Storage` skeleton, alias registration in `Module::onLoad()`.
4. Full hardening logic in `S3Storage` (checksum verify, retry, safe-overwrite pattern) per above.
5. Unit tests against mocked SDK responses.
6. MinIO docker-compose fixture + integration tests + CI workflow (Semgrep + PHPUnit + PHPStan).
7. README: install steps, Connection/Storage configuration walkthrough, IAM policy example,
   backend compatibility matrix, known limitations.
8. Local manual verification against MinIO; hand off final live verification against the real
   atropim VM to you (or request SSH access to do it myself).

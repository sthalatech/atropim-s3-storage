# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`sthalatech/atropim-s3-storage` is a Composer-packaged **AtroCore/AtroPIM addon module** that adds
an `s3` file-storage backend (`Atro\Core\FileStorage\FileStorageInterface`), so files/media can be
stored in AWS S3 or an S3-compatible object store instead of local disk. It is not a standalone
application — it's installed as a dependency into an existing AtroCore/AtroPIM install and only
does anything when loaded by AtroCore's module system.

Top design priority, stated everywhere in this codebase: **no data loss or silent corruption on
write, ever.** Every non-obvious piece of logic in `S3FileStorage.php` exists to serve that goal
(checksum verification, retry, safe-overwrite, locking) — read `PLAN.md` before changing any of it,
since the rationale (and what was empirically verified against real MinIO/Postgres/MySQL, vs. what's
just reasoned about) lives there, not in code comments.

## Commands

```bash
composer install                                            # install deps (needs the vcs repo for atrocore/core)
docker compose -f docker-compose.test.yml up -d --wait       # local MinIO fixture for integration tests
vendor/bin/phpunit --testsuite unit                          # unit tests, mocked SDK, no external services
vendor/bin/phpunit --testsuite integration                   # real S3 calls against the MinIO fixture
vendor/bin/phpunit --filter testMethodName                   # run a single test
vendor/bin/phpstan analyse                                    # level 5, app/ only (Resources/ excluded)
docker compose -f docker-compose.test.yml down -v            # tear down the MinIO fixture
```

The integration suite also needs a real Postgres or MySQL connection (for the advisory-lock test in
`tests/Integration/S3FileStorageMinioTest.php`) via env vars: `S3_STORAGE_TEST_ENDPOINT`,
`S3_STORAGE_TEST_BUCKET`, `S3_STORAGE_TEST_ACCESS_KEY`, `S3_STORAGE_TEST_SECRET_KEY`,
`S3_STORAGE_TEST_DB_HOST`/`_DB_NAME`/`_DB_USER`/`_DB_PASSWORD` — defaults match
`docker-compose.test.yml`. CI (`.github/workflows/ci.yml`) runs Semgrep, PHPStan, unit tests, then
spins up MinIO for integration tests, in that order — mirror that order locally before pushing.

There is no build step and no local dev server for this repo itself — it's exercised by installing
it into a real AtroCore instance (see README's Installation section) or via the test suites above.

## Architecture

### How AtroCore discovers and loads this module

- Module discovery scans the *host* AtroPIM install's `composer.lock` for packages with an
  `extra.atroId` key (`"S3Storage"` here); the class `S3Storage\Module` (`app/Module.php`) is then
  autoloaded and instantiated by AtroCore's `Atro\Core\ModuleManager\AbstractModule` machinery.
  Business-logic PHP under `app/` runs straight from `vendor/` — it is **not** copied into the host
  app. Only `Migrations/*`, an `Event.php` hook (neither present in this addon), and `client/`
  frontend assets (also not present) get physically copied out on `composer.phar update`.
- `Module::onLoad()` is the only integration point: it calls
  `$this->getContainer()->setClassAlias('s3Storage', S3FileStorage::class)`. AtroCore's
  `Atro\Repositories\Storage::getFileStorage()` resolves a backend by looking up
  `"{$storage->get('type')}Storage"` in the DI container — so any `Storage` entity record with
  `type=s3` resolves straight to `S3FileStorage`. There is no other registration/bootstrap step.
- Config surface is two of AtroCore's **existing** entities, extended via metadata overrides, not a
  bespoke settings page: `Connection` (holds S3 credentials/endpoint — reuses AtroCore's own
  `Encrypter`-backed password-field encryption, no custom crypto) and `Storage` (points at a
  `Connection`, holds the bucket key prefix). One `Connection` can back multiple `Storage` records.

### Metadata override gotchas (the two ways this breaks silently if done wrong)

- **`app/Resources/metadata/entityDefs/*.json` merges are REPLACE by default**, not union. Adding
  `"s3"` as a new option to an existing enum field (e.g. `Connection.type`) *must* use the
  `__APPEND__` sigil (`"options": ["__APPEND__", "s3"]`) or it silently wipes out every other
  connection/storage type on install.
- **`app/Resources/layouts/*/detail.json` merge differently** — additively, via PHP's
  `array_merge_recursive()`, no sigil needed. But note: adding fields to `entityDefs` with
  `conditionalProperties` (to show them only when `type == s3`) does **not** by itself add them to
  the UI detail form — that requires an explicit layout panel too (`Connection/detail.json`,
  `Storage/detail.json` in this repo). This was a real shipped bug (fixed in v1.0.1): the entity
  fields existed and saved fine via API, but the Admin UI form only showed 2 fields because the
  layout override was missing.

### `S3FileStorage.php` — the object-key scheme everything else follows from

Object keys are `{Storage.s3KeyPrefix}/{File.id}` — the AtroCore `File` entity's own UUID, never a
filename or path. This one decision is why `renameFile`/`moveFile`/`renameFolder`/`moveFolder`/
`createFolder`/`deleteFolderPermanently` are all no-ops: nothing about a rename/move changes the key.
Folders are virtual/prefix-based in S3, not a real filesystem concept here.

Key hardening patterns implemented (see `PLAN.md` for the full "why" on each):
- **Credentials (`app/Core/Utils/S3ClientFactory.php`) — static keys are optional, not
  mandatory**: `buildClientConfig()` only includes `accessKeyId`/`accessKeySecret` in the config
  when `s3AccessKeyId` is actually set on the Connection. Leaving both blank omits them from the
  config *entirely* (not as empty strings — async-aws/core would otherwise treat a blank string as
  an explicit static credential and never fall back), letting async-aws's own default credential
  chain resolve an IAM role instead (EC2 instance profile via IMDS, ECS task role, etc.) — the same
  behavior the AWS SDKs use when constructed with no explicit credentials. A partially-filled pair
  (one field set, the other blank) throws rather than guessing which mode was intended.
- **Checksum-verify + retry on every write**: `PutObject`/`UploadPart` pass an explicit
  `ChecksumSHA256` value (not just `ChecksumAlgorithm` — async-aws does not auto-compute a checksum
  for a plain string body, confirmed by reading the SDK source), compared against a locally computed
  SHA-256; mismatches retry with backoff before giving up and throwing (never marking the `File` as
  stored on failure).
- **Safe overwrite (`reupload()`)**: never a same-key `PutObject`. Upload to a temp key, verify, then
  `CopyObject` onto the real key, then delete the temp key — a failed verification can never destroy
  the original, because the original was never touched.
- **Non-blocking advisory locking** (`app/Core/Utils/AdvisoryLock.php`) around the `reupload()`
  critical section — `GET_LOCK`/`RELEASE_LOCK` on MySQL/MariaDB, `pg_try_advisory_lock`/
  `pg_advisory_unlock` on PostgreSQL (branches on `getDatabasePlatform() instanceof
  PostgreSQLPlatform`). Fails fast with a `Conflict` (*"This file is currently being updated by
  another process..."*) rather than blocking-with-timeout, to avoid stacking up PHP-FPM workers.
  This is *more* protection than AtroCore core's own local-disk storage has today (core has zero
  concurrency control on reupload, for any backend).
- **In-memory size ceiling before `base64_decode()`**: uploads arrive fully as a base64 string in
  memory (confirmed from core's own upload path, not a stream/tmp file), so `createFile()` estimates
  decoded size from the base64 string length and rejects oversized uploads *before* decoding,
  computed against `memory_limit` at runtime — see README's "Known limitations" for the concrete
  numbers this yields at common `memory_limit` settings.
- **Multipart over 100MB / `createChunk()`**: chunked client uploads map directly onto real S3
  multipart parts (never buffered fully in PHP), each part checksum-verified individually; any
  failure triggers `AbortMultipartUpload`.
- **Test Connection (`app/Core/ConnectionType/ConnectionS3.php`)**: registers `s3` in AtroCore's
  `app.connectionTypes` metadata (`app/Resources/metadata/app/connectionTypes.json` — a plain
  associative map; a new top-level key merges additively per `Atro\Core\Utils\Util::merge()`, no
  `__APPEND__` needed there) and implements `ConnectionInterface` + `TestConnectionInterface`. This
  plugs into AtroCore's *existing*, generic "Test Connection" button/action — no addon-side
  frontend code required. `connect()` alone isn't a real test (an `S3Client` does no network I/O
  until an operation is called), so `testConnection()` explicitly does a real `bucketExists()` call.
- **`getLocalPath()` (`LocalFileStorageInterface`)**: exists solely to work around a core bug —
  `Repositories\File::getFilePath()` falls back to `getUrl()` (an app-internal URL string, not a real
  path) for any non-`LocalFileStorageInterface` storage, and both `EntryPoints/Thumbnail.php` and
  `Core/Download/Custom.php` pass that straight to Imagick expecting a real filesystem path. This
  method downloads a fresh temp copy per call and is swept by `deleteCache()`
  (`sweepLocalPathTmpDir()`, 1-hour abandonment threshold) — do not remove it or thumbnails/previews
  silently break for every S3-backed file.
- **`scan()`**: bounded reconciliation (500 records or 240s per run, whichever first), resumable via
  `Storage.s3ScanCursor`, DB-driven (walks `File` records, never `ListObjectsV2` — this is why the
  IAM policy in the README doesn't need `ListBucket`). Skips records currently held by the
  advisory lock instead of flagging false-positive drift on an in-progress reupload.

### Tests

- `tests/Support/ContainerBuilder.php` exists because `Atro\Core\Container` is `final` and can't be
  mocked by PHPUnit — it builds a real `Container` wired to a real Laminas `ServiceManager`
  pre-populated with mock services, so production code that type-hints the concrete `Container`
  class can still be tested.
- `tests/Unit/S3FileStorageTest.php` mocks the async-aws SDK responses.
- `tests/Integration/S3FileStorageMinioTest.php` runs the same code against a real MinIO container —
  this is where checksum-mismatch rejection, real multipart, and the advisory-lock race have
  actually been verified end-to-end, not just asserted against mocks.

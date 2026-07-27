# atropim-s3-storage

S3-compatible object storage backend for [AtroPIM / AtroCore](https://www.atrocore.com) file and
media storage — an alternative to the default local-filesystem storage, configured entirely through
AtroCore's native Admin panel (`Connection` + `Storage` entities). Once configured, the switch is
invisible to end users: uploads, downloads, thumbnails, and file URLs all behave exactly as they do
with local storage.

## Why this exists

AtroCore already ships a multi-backend storage abstraction (`FileStorageInterface`) but only a
`local` implementation. This addon adds a real `s3` backend, built around one priority above all
else: **no data loss or silent corruption**, ever, on write. See [`PLAN.md`](PLAN.md) for the full
design rationale — every checksum, retry, locking, and key-scheme decision documented there was
verified against a real running AtroCore instance and real MinIO/PostgreSQL/MySQL backends, not
just reasoned about in the abstract.

## Requirements

- AtroCore `>=2.3.4`, PHP `>=8.4`
- An S3 bucket (AWS S3) or an S3-compatible endpoint (MinIO, Cloudflare R2, Wasabi, etc. — see
  [Backend compatibility](#backend-compatibility))

## Installation

AtroCore modules are installed via Composer, resolved from your module's git repository — see
AtroCore's own ["Creating a module"](https://github.com/atrocore/example-module) guide. This addon
is not part of AtroCore's official module store, so it's added as a plain VCS repository:

1. In your AtroPIM installation's root `composer.json`, add a `repositories` entry and require the
   package:

   ```json
   {
       "repositories": [
           { "type": "vcs", "url": "https://github.com/sthalatech/atropim-s3-storage" }
       ],
       "require": {
           "sthalatech/atropim-s3-storage": "^1.0"
       }
   }
   ```

2. From your AtroPIM root, run the **AtroCore-embedded Composer** (not your system Composer — the
   embedded one contains modifications needed for backup/restore during updates), **as the same
   system user your web server runs as** (commonly `www-data`) — not as `root` via `sudo`, and not
   as your own login user, unless that's also who owns `data/`, `vendor/`, and `public/` today:

   ```bash
   php composer.phar update
   ```

   Running it as a different user than the web server is a common self-hosted-PHP pitfall, not
   specific to this addon: it leaves cache/vendor files owned by the wrong user, so the web server
   process can no longer write to `data/cache/` afterward — which tends to surface as unrelated-
   looking failures (e.g. a generic "Record cannot be created" when saving *any* entity, not just a
   `Connection`). If you did run it as a different user, fix ownership immediately after:

   ```bash
   chown -R www-data:www-data data vendor public composer.json composer.lock
   ```

   (substitute `www-data` for whatever user your web server actually runs as, if different).

3. Go to **Administration → System → Update & Modules** and confirm "S3 Storage" is listed as
   installed/enabled. If not, run `php composer.phar update` again — module discovery reads
   `composer.lock`'s package metadata, so it only appears after a successful install.

## Configuration

S3 credentials and bucket details live on a **Connection** record; the **Storage** record that
files actually get assigned to just points at one. This split means one bucket/credential pair
(a `Connection`) can back multiple `Storage` records with different key prefixes, and a single
AtroPIM install can have several S3 storages side by side with different buckets/regions.

1. **Administration → Connections → Create Connection**, set **Type** to `Amazon S3 /
   S3-Compatible`, and fill in:
   - **Region** — e.g. `us-east-1`
   - **Bucket**
   - **Access Key ID** / **Secret Access Key** — the secret is encrypted at rest using AtroCore's
     existing `Encrypter` (the same mechanism protecting SMTP/database connection passwords), not
     custom crypto. **Leave both fields blank** to authenticate via the server's own IAM identity
     instead (an EC2 instance profile role, an ECS task role, or an IRSA-bound service account) —
     recommended over static keys whenever the host already has one attached. See
     [Authenticating via an IAM role](#authenticating-via-an-iam-role-instead-of-static-keys) below.
   - **Endpoint** — leave blank for AWS; set for MinIO/R2/Wasabi/etc.
   - **Force Path-Style Addressing** — enable for most self-hosted S3-compatible backends
   - **Verify TLS** — leave enabled in production; only disable for local development against a
     plain-HTTP MinIO instance (a non-HTTPS endpoint is otherwise rejected outright)

2. **Administration → Storages → Create Storage**, set **Type** to `Amazon S3 / S3-Compatible`,
   link the **Connection** created above, and optionally set a **Key Prefix** (e.g. `atropim/prod`)
   if the bucket is shared with other applications/environments.

3. Assign the Storage to a **Folder** the same way you would a local storage. New uploads under
   that folder now land in S3; existing files already in other storages are unaffected (this
   release does not include a bulk-migration tool — see [Limitations](#known-limitations)).

### Authenticating via an IAM role instead of static keys

If the AtroPIM host already runs with an IAM identity attached (an EC2 instance profile role, an
ECS task role, or an IRSA-bound service account on EKS), leave **Access Key ID** and **Secret
Access Key** both blank on the Connection record. With no static credentials configured, this
addon omits them from the S3 client entirely rather than passing blank values — which is what lets
the underlying `async-aws/s3` SDK fall through to its own default credential provider chain
(environment variables → shared `~/.aws/credentials` file → ECS container credentials → the EC2
instance's attached role via IMDS), the same behavior the official AWS SDKs use when no explicit
credentials are passed to their S3 client constructors. No separate "auth mode" toggle exists —
it's simply whichever of the two the Connection record has configured. Static keys are unaffected
and remain fully supported for connections that need them (e.g. a host without its own IAM
identity, or a bucket in a different AWS account than the host's role can assume).

Filling in only one of the two fields (e.g. a leftover Secret Access Key after clearing the Access
Key ID) is rejected with a clear error rather than silently doing something unexpected either way
— provide both, or clear both.

### Testing a connection

Every Connection record has a built-in **Test Connection** button (AtroCore's generic
`Connection` detail view). For `s3` connections this performs a real `HeadBucket` call against the
configured bucket — using whichever credential path is configured, static keys or an IAM role — and
reports success or a clear failure reason, so a misconfiguration (missing role, wrong bucket, bad
region) is caught immediately rather than on the first real file upload.

### IAM policy (AWS)

Least-privilege policy for the bucket/prefix this addon actually needs — note `ListBucket` is
**not** required (this addon's integrity `scan()` walks `File` database records, not the bucket, so
it never calls `ListObjectsV2`). Attach this to whichever principal is actually authenticating —
the IAM user behind a static access key, or the IAM role attached to the host, if you're using the
role-based path above instead:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject", "s3:AbortMultipartUpload"],
      "Resource": "arn:aws:s3:::BUCKET_NAME/PREFIX/*"
    }
  ]
}
```

Recommended defense-in-depth, outside this addon's own guarantees but cheap insurance:
- Enable **bucket versioning** — a soft-delete safety net beyond this addon's own permanent-delete
  path.
- Add a lifecycle rule: **Abort incomplete multipart uploads after 1 day** — this addon already
  calls `AbortMultipartUpload` itself on any catchable failure, but a hard process crash mid-upload
  (OOM-kill, server reboot) leaves no application code running to do that cleanup; the lifecycle
  rule is the backstop for exactly that case.

## Backend compatibility

AWS S3 is the only backend this release is required to support. Generic S3-API compatibility
(endpoint override + path-style addressing) is implemented and has been **directly verified against
a real MinIO instance** during development — including a deliberately-corrupted checksum being
genuinely rejected by the server, a full checksum-verified upload/download/overwrite/delete
round-trip, and a real multipart upload/complete/abort cycle. Cloudflare R2 and Wasabi should work
via the same endpoint-override mechanism but have not been directly tested.

**Known MinIO-specific quirk** (verified directly, not theoretical): MinIO does not return a
checksum on `HeadObject` responses even for objects that were stored with one. This addon's
`scan()` drift-detection and copy-verification logic both already fall back to comparing the
object's `ETag` (a plain MD5 for non-multipart objects) when no checksum is present in a `HeadObject`
response, so this doesn't reduce integrity coverage against MinIO — it's mentioned here so the
fallback path isn't mistaken for dead code.

## Known limitations

- **No bulk-migration tool in this release.** Files already stored in other backends stay where
  they are; only new/changed files under an S3-type Storage go to S3.
- **In-memory upload size ceiling.** The non-chunked upload path decodes the full file in memory
  (base64 payload → `json_decode` copy → decoded bytes, roughly 3.7× the original size on top of
  AtroCore's own request baseline). Concretely: **~45MB max file size at a 256MB PHP `memory_limit`**,
  **~100MB at 512MB**. The addon computes this from your actual `memory_limit` at runtime and
  rejects oversized uploads with a clear error *before* attempting to decode, rather than risking a
  PHP OOM crash. Files expected to routinely exceed this should use the chunked-upload UI path,
  which this addon maps directly onto real S3 multipart upload parts and never fully buffers.
- **Automatic multipart threshold**: uploads over 100MB (via the non-chunked path, e.g. with
  `memory_limit` raised well above the default) are automatically sliced into a real multipart
  upload with per-part checksum verification.
- **`hash` field algorithm differs for multipart-assembled files.** Non-multipart uploads store a
  real MD5 hex digest in the `File.hash` attribute, matching AtroCore's own local-storage
  convention exactly. Multipart-assembled files (chunked uploads, or non-chunked uploads over the
  100MB threshold) instead store a SHA-256 hex digest, since S3's multipart `ETag` is not a plain
  MD5 and re-downloading a large object solely to compute one would defeat the purpose of
  streaming. `scan()`'s drift check understands both representations; other tooling that expects
  `hash` to always be MD5 should be aware of this for very large files.
- **`scan()` reconciliation is scoped and bounded**, not an exhaustive audit: up to 500 `File`
  records (or a 240-second time budget, whichever comes first) per invocation, resuming via a
  cursor stored on the `Storage` record across AtroCore's existing cron-driven scan schedule. It
  detects missing objects and content drift for records AtroCore already knows about; it does not
  detect orphan objects that exist in the bucket with no matching database record (a separate,
  much rarer failure mode).
- **Concurrency**: a second reupload of the same file while one is already in progress fails fast
  with *"This file is currently being updated by another process. Please try again in a few
  seconds."* rather than queuing — see `PLAN.md` for why (avoiding PHP-FPM worker pileups) and for
  the DB-advisory-lock mechanism (MySQL `GET_LOCK`/PostgreSQL `pg_advisory_lock`, both directly
  tested against real databases) that enforces it.

## Troubleshooting

**"Record cannot be created/saved" on a `Connection` or `Storage`, or seemingly unrelated failures
elsewhere in the app, right after installing this addon** — almost always a file-ownership
mismatch from step 2 of [Installation](#installation), not this addon's own logic: if
`composer.phar update` was run as a different system user than your web server runs as (e.g. `root`
via `sudo`), `data/cache/` (and possibly `vendor/`, `public/`) end up owned by that user, and the
web server can no longer write new cache files — which surfaces as generic save failures across
the whole app, not just S3-related ones. Fix:

```bash
chown -R www-data:www-data data vendor public composer.json composer.lock
```

(substitute `www-data` for your actual web server user) — then retry.

## Development

```bash
composer install
docker compose -f docker-compose.test.yml up -d --wait   # starts a local MinIO fixture
vendor/bin/phpunit --testsuite unit                       # no external services needed
vendor/bin/phpunit --testsuite integration                 # exercises real S3 calls against MinIO
vendor/bin/phpstan analyse
```

Environment variables for the integration suite (defaults match `docker-compose.test.yml`):
`S3_STORAGE_TEST_ENDPOINT`, `S3_STORAGE_TEST_BUCKET`, `S3_STORAGE_TEST_ACCESS_KEY`,
`S3_STORAGE_TEST_SECRET_KEY`, and `S3_STORAGE_TEST_DB_HOST`/`_DB_NAME`/`_DB_USER`/`_DB_PASSWORD`
(the `reupload()` integration test needs a real Postgres or MySQL connection for its advisory-lock
test — see `tests/Integration/S3FileStorageMinioTest.php`).

## Design & architecture

See [`PLAN.md`](PLAN.md) for the complete design document: the exact `FileStorageInterface` contract
this implements, the id-based object-keying scheme (why rename/move are pure no-ops), the
checksum-verify-then-retry logic, the temp-key-copy-verify-swap pattern that makes overwrites safe,
the advisory-locking design, and the bounded `scan()` reconciliation job.

## License

GPL-3.0-only — see [`LICENSE.txt`](LICENSE.txt). This matches AtroCore core's own license and the
license used by AtroCore's own first-party modules (e.g. `atrocore/import`), since this addon
extends AtroCore's GPL-licensed `AbstractModule`.

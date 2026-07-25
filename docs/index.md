---
title: S3 Storage
---

## Overview

Adds an `s3` Storage type so files can be stored in AWS S3 or an S3-compatible object store
(MinIO, Cloudflare R2, Wasabi) instead of local disk. Configure credentials on a `Connection`
record (type `Amazon S3 / S3-Compatible`), then point a `Storage` record at it.

See the [project README](https://github.com/sthalatech/atropim-s3-storage#readme) for full
installation, configuration, IAM policy, and known-limitations documentation, and `PLAN.md` in the
repository root for the detailed design rationale (checksum verification, safe-overwrite pattern,
concurrency locking, integrity scanning).

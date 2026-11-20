# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Require Laravel 13.
- Read only the file that `retrieve()` checked, so an upload replaced after retrieval can no longer bypass the size limit. `TemporaryFile` now needs an S3 disk and the object's ETag.
- Delete a rejected oversized upload only while its ETag still matches the file that was checked, so a replacement uploaded in the meantime is kept on storage that honours conditional deletes, such as Amazon S3.

## v1.0.0 - 2026-10-05

- Initial release.

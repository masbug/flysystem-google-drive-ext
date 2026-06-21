# Changelog

All notable changes to this package are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-06-21

### Added
- GitHub Actions CI: test matrix (PHP 8.2/8.3/8.4 × prefer-lowest/prefer-stable),
  PHPStan static analysis, and Laravel Pint code-style checks.
- Dependabot configuration for Composer and GitHub Actions.
- Credential-free unit test suite covering the pure path/sanitization/mime logic
  and security regression tests for the download trusted-host check.
- `GoogleDriveAdapter::escapeQueryValue()` helper for safe query-literal building.
- `CHANGELOG.md`, `CONTRIBUTING.md` and `SECURITY.md`.

### Changed
- **BREAKING:** minimum PHP version is now `8.2`.
- **BREAKING:** dropped support for Guzzle 6 and `guzzlehttp/psr7` v1; now requires
  `guzzlehttp/guzzle ^7.5` and `guzzlehttp/psr7 ^2.4`.
- **BREAKING:** requires `league/flysystem ^3.0` (Flysystem v2 is no longer supported).
- Dev tooling moved to PHPUnit 10/11; `phpunit.xml.dist` migrated to the new schema.
- Tests reorganised into `Unit` and `Integration` suites under the
  `Masbug\Flysystem\Tests` namespace.

### Fixed
- Reading metadata of a Google Drive **shortcut** no longer triggers a fatal
  "property on null" error (`shortcutDetails` is now requested and guarded).
- `copy()` no longer dereferences a possibly-absent cache entry when computing
  the source visibility.
- `delete()` now reports genuine API failures instead of silently succeeding;
  deleting a non-existent path remains a no-op success.
- `move()` now throws `UnableToMoveFile` when the post-update fetch fails instead
  of reporting a false success.
- `writeData()` now forwards the underlying error as the exception cause.
- `hasDir()` no longer fatals when metadata cannot be resolved.
- Fixed a broken `for`-loop condition in the path-cache builder.

### Security
- `readStream()` now only sends the OAuth bearer token (and cookies) to verified
  Google-owned hosts, enforces HTTPS with TLS peer verification, and guards the
  socket connection — preventing the access token from leaking to a third-party
  redirect target.
- File names interpolated into Google Drive query literals are now escaped,
  preventing query-language injection via crafted names.

### Removed
- Legacy CI configuration (`.travis.yml`, `.scrutinizer.yml`, `.styleci.yml`).
- The global `define('DEBUG_ME', ...)` constant and all leftover `echo` debug code.

[3.0.0]: https://github.com/masbug/flysystem-google-drive-ext/releases/tag/v3.0.0

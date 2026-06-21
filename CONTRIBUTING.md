# Contributing

Thanks for considering a contribution! This package is a framework-agnostic
Flysystem adapter for Google Drive.

## Development setup

```bash
composer install
```

## Quality gates

All pull requests must keep the following green (they also run in CI):

```bash
composer test          # PHPUnit (Unit suite runs without credentials)
composer analyse       # PHPStan
composer format:test   # Laravel Pint (style check)
```

To auto-fix code style:

```bash
composer format
```

## Tests

- The **Unit** suite runs without any external service and must stay green.
- The **Integration** suite talks to a real Google Drive account. It is skipped
  automatically unless a `google-drive-service-account.json` file is present in
  the project root:

  ```json
  {
      "GOOGLE_DRIVE_CLIENT_ID": "xxxxxx.apps.googleusercontent.com",
      "GOOGLE_DRIVE_CLIENT_SECRET": "xxxxxx",
      "GOOGLE_DRIVE_REFRESH_TOKEN": "xxxxxx",
      "GOOGLE_DRIVE_TEAM_DRIVE_ID": null,
      "GOOGLE_DRIVE_SHARED_FOLDER_ID": null
  }
  ```

  This file is git-ignored — **never commit credentials**.

## Guidelines

- Add a unit test for any pure-logic change.
- Keep the public adapter API framework-agnostic (no hard dependency on a
  specific framework).
- Update `CHANGELOG.md` under an `Unreleased`/next-version heading.
- Follow Conventional Commit prefixes (`feat:`, `fix:`, `refactor:`, `ci:`,
  `test:`, `docs:`) where practical.

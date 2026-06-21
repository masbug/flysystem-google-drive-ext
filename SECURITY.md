# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 3.x     | :white_check_mark: |
| 2.x     | :white_check_mark: |
| < 2.0   | :x:                |

## Reporting a Vulnerability

Please **do not** open a public issue for security vulnerabilities.

Instead, report them privately via GitHub's
[private vulnerability reporting](https://github.com/masbug/flysystem-google-drive-ext/security/advisories/new),
or by emailing the maintainer listed in `composer.json`.

You can expect an acknowledgement within a few business days. Once a fix is
available, a patched release will be published and the advisory disclosed.

## Handling of credentials

This adapter never logs OAuth tokens. When streaming downloads it only sends the
access token to verified Google-owned hosts over TLS. Keep your
`google-drive-service-account.json` / `.env` credentials out of version control —
both are git-ignored by default.

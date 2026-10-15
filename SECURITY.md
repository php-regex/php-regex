# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 2.x     | :white_check_mark: |
| 1.x     | :x: frozen at v1.3.0, no longer supported |

## Verifying the phar

`regex.phar` is built from the release tag by the repository's GitHub Actions
workflow, never on a maintainer's machine, and carries a build provenance
attestation. Check a downloaded phar with the GitHub CLI:

```bash
gh attestation verify regex.phar --repo php-regex/php-regex
```

## Reporting a Vulnerability

If you discover a security vulnerability within PHPRegex (e.g., a pattern that crashes the parser or causes an infinite loop in the parser itself), please send an e-mail to **younes.ennaji.pro@gmail.com**, or prefer the GitHub Private Vulnerability Reporting form, which is enabled on this repository.

**Do not open a public issue** for security vulnerabilities.

We will respond as quickly as possible to acknowledge your report and work with you to resolve the issue.

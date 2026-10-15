# Scanner

Integrate a scanner for bar- and QR-codes into your extension templates.

## Installation

```bash
composer require psbits/scanner
```

## Development

```bash
composer install
composer qa
```

`composer qa` fixes everything that can be fixed automatically and afterwards runs all linters and the static
analysis. The same checks run in the `Quality` GitHub Actions workflow and in the `pre-commit` hook that is
installed by `composer install`.

The workflow also runs a support matrix that checks the supported TYPO3 and PHP combinations still resolve their
dependencies, and publishes the extension to TER when a semver tag is pushed. Publishing requires the
`TYPO3_API_TOKEN` repository secret.

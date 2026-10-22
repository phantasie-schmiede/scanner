# PSBits Scanner

Reusable camera- and file-based barcode scanning for other extensions. The intended use cases are
media archiving (books, DVDs, CDs) and household management (recording purchases).

## Requirements

- TYPO3: `13.4` or `14.3`
- PHP: `8.3` or newer
- Live camera scanning requires a secure context (HTTPS or localhost)
- Browser: `getUserMedia` for live camera scanning. The native `BarcodeDetector` is used where
  available; the fallback decodes with the vendored zxing-wasm reader (about 1 MB wasm binary,
  loaded on first use)

## Installation

```bash
composer require psbits/scanner
```

## Integration

1. Add the scanner partials to your view configuration:

   ```typoscript
   plugin.tx_<your_extension> {
       view {
           partialRootPaths.20 = EXT:scanner/Resources/Private/Partials
       }
   }
   ```

2. Render the partial in a Fluid template:

   ```html
   <f:render partial="Scanner" />
   ```

3. Listen to the scanner events on the root element (`data-psbits-scanner`). Scan results are
   submitted to the API automatically unless disabled:

   ```javascript
   document
       .querySelector('[data-psbits-scanner]')
       .addEventListener('psbits-scanner:scan', (event) => {
           // event.detail = { content, format, source, clientTimestamp, meta }
       });
   ```

Events emitted by the component:

- `psbits-scanner:ready`
- `psbits-scanner:capabilities`
- `psbits-scanner:permission`
- `psbits-scanner:scan`
- `psbits-scanner:submitted`
- `psbits-scanner:error`
- `psbits-scanner:stopped`

## API contract (v1)

All responses share this envelope:

```json
{
  "success": true,
  "data": {},
  "error": null
}
```

For errors:

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "validation_failed",
    "message": "...",
    "details": {}
  }
}
```

### `GET /scanner/api/v1/health`

Health endpoint.

### `GET /scanner/api/v1/capabilities`

Returns supported formats, modes and endpoint references.

### `POST /scanner/api/v1/result`

Accepts scan results:

```json
{
  "content": "9780201379624",
  "format": "ean_13",
  "source": "camera",
  "clientTimestamp": "2026-01-01T12:00:00+00:00",
  "meta": {}
}
```

- `content` is required (1–1024 characters)
- `format` is required and must be one of the supported formats
- `source` is either `camera` or `image` (defaults to `camera`)
- `clientTimestamp` is optional, any date/time string the server can parse
- `meta` is optional, any JSON object

Supported formats:

- `ean_13`
- `ean_8`
- `upc_a`
- `upc_e`
- `code_128`
- `code_39`
- `itf`
- `codabar`

## Security and privacy

- Live camera mode only works in secure contexts (HTTPS/localhost).
- The frontend checks browser support and permission state and emits explicit events.
- The backend validates incoming result payloads and does not persist scan contents by default.

## Development

```bash
composer install
composer qa
```

`composer qa` fixes everything that can be fixed automatically and afterwards runs all linters and the static
analysis. The same checks run in the `CI` GitHub Actions workflow and in the `pre-commit` hook that is
installed by `composer install`.

The workflow also runs a support matrix that checks the supported TYPO3 and PHP combinations still resolve their
dependencies, and publishes the extension to TER when a semver tag is pushed. Publishing requires the
`TYPO3_API_TOKEN` repository secret.

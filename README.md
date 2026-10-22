# PSBits Scanner

TYPO3 extension to provide reusable camera/file based barcode and QR scanning for other extensions.

## Quick start

1. Include the partial `Resources/Private/Partials/Scanner.html` in your Fluid template.
2. Listen to scanner events on the scanner root element.
3. Consume the API endpoints under `/scanner/api/v1`.

Example events emitted by the component:

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
  "content": "...",
  "format": "qr_code",
  "source": "camera",
  "clientTimestamp": "2026-01-01T12:00:00+00:00",
  "meta": {}
}
```

Supported formats:

- `qr_code`
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

## Compatibility

- TYPO3: `11.5`, `12.4`, `13.4`
- Browser: modern browsers with `getUserMedia`; preferred with `BarcodeDetector`; fallback image decoding via Quagga2.

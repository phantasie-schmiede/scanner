<?php

declare(strict_types=1);

namespace PSBits\Scanner\Api;

final class ScannerApiService
{
    public const API_VERSION = 'v1';

    /**
     * @var string[]
     */
    private const SUPPORTED_FORMATS = [
        'ean_13',
        'ean_8',
        'upc_a',
        'upc_e',
        'code_128',
        'code_39',
        'itf',
        'codabar',
    ];

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(bool $isSecureContext): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'formats' => self::SUPPORTED_FORMATS,
            'modes' => [
                'liveCamera',
                'imageUpload',
            ],
            'requirements' => [
                'secureContextRequired' => true,
                'isSecureContext' => $isSecureContext,
            ],
            'endpoints' => [
                'health' => '/scanner/api/' . self::API_VERSION . '/health',
                'capabilities' => '/scanner/api/' . self::API_VERSION . '/capabilities',
                'result' => '/scanner/api/' . self::API_VERSION . '/result',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{isValid: bool, errors: string[], normalized: array<string, mixed>}
     */
    public function validateResultPayload(array $payload): array
    {
        $errors = [];

        $content = trim((string)($payload['content'] ?? ''));
        if ($content === '') {
            $errors[] = 'Field "content" must be a non-empty string.';
        } elseif (mb_strlen($content) > 1024) {
            $errors[] = 'Field "content" exceeds maximum length of 1024.';
        }

        $format = strtolower(trim((string)($payload['format'] ?? '')));
        if ($format === '') {
            $errors[] = 'Field "format" must be set.';
        } elseif (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            $errors[] = 'Field "format" is not supported.';
        }

        $source = strtolower(trim((string)($payload['source'] ?? 'camera')));
        if (!in_array($source, ['camera', 'image'], true)) {
            $errors[] = 'Field "source" must be either "camera" or "image".';
        }

        $clientTimestamp = trim((string)($payload['clientTimestamp'] ?? ''));
        if ($clientTimestamp !== '' && strtotime($clientTimestamp) === false) {
            $errors[] = 'Field "clientTimestamp" must be a valid date/time string.';
        }

        $normalized = [
            'content' => $content,
            'format' => $format,
            'source' => $source,
            'clientTimestamp' => $clientTimestamp,
            'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        ];

        return [
            'isValid' => $errors === [],
            'errors' => $errors,
            'normalized' => $normalized,
        ];
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Scanner.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Scanner\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PSBits\Scanner\Api\ScannerApiService;

final class ScannerApiServiceTest extends TestCase
{
    private ScannerApiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ScannerApiService();
    }

    public function testCapabilitiesContainTheExpectedContract(): void
    {
        $capabilities = $this->service->getCapabilities(false);

        self::assertSame('v1', $capabilities['apiVersion']);
        self::assertSame(
            ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar'],
            $capabilities['formats']
        );
        self::assertNotContains('qr_code', $capabilities['formats']);
        self::assertSame(['liveCamera', 'imageUpload'], $capabilities['modes']);
        self::assertSame(
            [
                'secureContextRequired' => true,
                'isSecureContext' => false,
            ],
            $capabilities['requirements']
        );
        self::assertSame(
            [
                'health' => '/scanner/api/v1/health',
                'capabilities' => '/scanner/api/v1/capabilities',
                'result' => '/scanner/api/v1/result',
            ],
            $capabilities['endpoints']
        );
    }

    public function testCapabilitiesReportTheSecureContext(): void
    {
        self::assertTrue($this->service->getCapabilities(true)['requirements']['isSecureContext']);
    }

    #[DataProvider('validPayloadProvider')]
    public function testPayloadValidationAcceptsValidPayloads(array $payload, array $expected): void
    {
        $validation = $this->service->validateResultPayload($payload);

        self::assertTrue(
            $validation['isValid'],
            'Expected a valid payload, errors: ' . implode(', ', $validation['errors'])
        );
        self::assertSame($expected, $validation['normalized']);
    }

    public static function validPayloadProvider(): array
    {
        $payloads = [];
        $formats = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar'];

        foreach ($formats as $format) {
            $payloads[$format] = [
                [
                    'content' => '9780201379624',
                    'format' => $format,
                    'source' => 'camera',
                    'clientTimestamp' => '2026-01-01T12:00:00+00:00',
                    'meta' => ['confidence' => 0.9],
                ],
                [
                    'content' => '9780201379624',
                    'format' => $format,
                    'source' => 'camera',
                    'clientTimestamp' => '2026-01-01T12:00:00+00:00',
                    'meta' => ['confidence' => 0.9],
                ],
            ];
        }

        $payloads['omits the optional fields'] = [
            [
                'content' => '40012345',
                'format' => 'ean_8',
            ],
            [
                'content' => '40012345',
                'format' => 'ean_8',
                'source' => 'camera',
                'clientTimestamp' => '',
                'meta' => [],
            ],
        ];

        $payloads['lowercases the format and source values'] = [
            [
                'content' => '9780201379624',
                'format' => 'EAN_13',
                'source' => 'Camera',
            ],
            [
                'content' => '9780201379624',
                'format' => 'ean_13',
                'source' => 'camera',
                'clientTimestamp' => '',
                'meta' => [],
            ],
        ];

        $payloads['accepts content at the maximum length'] = [
            [
                'content' => str_repeat('1', 1024),
                'format' => 'ean_13',
                'source' => 'image',
            ],
            [
                'content' => str_repeat('1', 1024),
                'format' => 'ean_13',
                'source' => 'image',
                'clientTimestamp' => '',
                'meta' => [],
            ],
        ];

        return $payloads;
    }

    public function testPayloadValidationRejectsMissingContent(): void
    {
        $validation = $this->service->validateResultPayload(['format' => 'ean_13']);

        self::assertFalse($validation['isValid']);
        self::assertStringContainsString('content', $validation['errors'][0]);
    }

    public function testPayloadValidationRejectsBlankContent(): void
    {
        $validation = $this->service->validateResultPayload([
            'content' => '   ',
            'format' => 'ean_13',
        ]);

        self::assertFalse($validation['isValid']);
        self::assertStringContainsString('content', $validation['errors'][0]);
    }

    public function testPayloadValidationRejectsContentExceedingTheMaximumLength(): void
    {
        $validation = $this->service->validateResultPayload([
            'content' => str_repeat('1', 1025),
            'format' => 'ean_13',
        ]);

        self::assertFalse($validation['isValid']);
        self::assertStringContainsString('1024', $validation['errors'][0]);
    }

    public function testPayloadValidationRejectsMissingFormat(): void
    {
        $validation = $this->service->validateResultPayload(['content' => '9780201379624']);

        self::assertFalse($validation['isValid']);
        self::assertStringContainsString('format', $validation['errors'][0]);
    }

    public function testPayloadValidationRejectsUnsupportedFormat(): void
    {
        $validation = $this->service->validateResultPayload([
            'content' => '9780201379624',
            'format' => 'qr_code',
        ]);

        self::assertFalse($validation['isValid']);
        self::assertStringContainsString('format', $validation['errors'][0]);
    }

    public function testPayloadValidationRejectsUnsupportedSource(): void
    {
        $validation = $this->service->validateResultPayload([
            'content' => '9780201379624',
            'format' => 'ean_13',
            'source' => 'webcam',
        ]);

        self::assertFalse($validation['isValid']);
        self::assertStringContainsString('source', $validation['errors'][0]);
    }

    public function testPayloadValidationRejectsInvalidClientTimestamps(): void
    {
        $validation = $this->service->validateResultPayload([
            'content' => '9780201379624',
            'format' => 'ean_13',
            'source' => 'camera',
            'clientTimestamp' => 'not-a-timestamp',
        ]);

        self::assertFalse($validation['isValid']);
        self::assertStringContainsString('clientTimestamp', $validation['errors'][0]);
    }

    public function testPayloadValidationNormalizesNonArrayMetaToAnEmptyArray(): void
    {
        $validation = $this->service->validateResultPayload([
            'content' => '9780201379624',
            'format' => 'ean_13',
            'source' => 'camera',
            'meta' => 'not-an-array',
        ]);

        self::assertTrue($validation['isValid']);
        self::assertSame([], $validation['normalized']['meta']);
    }
}

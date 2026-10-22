<?php

declare(strict_types=1);

namespace PSBits\Scanner\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use PSBits\Scanner\Api\ScannerApiService;

final class ScannerApiServiceTest extends TestCase
{
    public function testCapabilitiesContainExpectedContractData(): void
    {
        $service = new ScannerApiService();

        $capabilities = $service->getCapabilities(true);

        self::assertSame('v1', $capabilities['apiVersion']);
        self::assertNotContains('qr_code', $capabilities['formats']);
        self::assertContains('liveCamera', $capabilities['modes']);
        self::assertTrue($capabilities['requirements']['secureContextRequired']);
        self::assertTrue($capabilities['requirements']['isSecureContext']);
    }

    public function testPayloadValidationRejectsInvalidPayload(): void
    {
        $service = new ScannerApiService();

        $validation = $service->validateResultPayload([
            'content' => '',
            'format' => 'unknown',
            'source' => 'webcam',
            'clientTimestamp' => 'not-a-date',
        ]);

        self::assertFalse($validation['isValid']);
        self::assertNotEmpty($validation['errors']);
    }

    public function testPayloadValidationAcceptsValidPayload(): void
    {
        $service = new ScannerApiService();

        $validation = $service->validateResultPayload([
            'content' => '9780201379624',
            'format' => 'ean_13',
            'source' => 'camera',
            'clientTimestamp' => '2026-01-01T12:00:00+00:00',
            'meta' => ['confidence' => 0.9],
        ]);

        self::assertTrue($validation['isValid']);
        self::assertSame('9780201379624', $validation['normalized']['content']);
        self::assertSame('ean_13', $validation['normalized']['format']);
        self::assertSame('camera', $validation['normalized']['source']);
    }
}

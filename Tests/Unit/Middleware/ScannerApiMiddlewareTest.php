<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Scanner.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Scanner\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PSBits\Scanner\Middleware\ScannerApiMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Class ScannerApiMiddlewareTest
 *
 * @package PSBits\Scanner\Tests\Unit\Middleware
 */
final class ScannerApiMiddlewareTest extends UnitTestCase
{
    private ScannerApiMiddleware $middleware;
    private RequestHandlerInterface $requestHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new ScannerApiMiddleware();
        $this->requestHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['passedThrough' => true], 200);
            }
        };
    }

    #[Test]
    public function requestsWithoutTheApiPrefixArePassedThrough(): void
    {
        $request = $this->createRequest('GET', 'https://example.com/any/page');
        $response = $this->middleware->process($request, $this->requestHandler);

        $payload = $this->getPayload($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['passedThrough']);
    }

    #[Test]
    public function healthEndpointReturnsTheApiVersion(): void
    {
        $request = $this->createRequest('GET', 'https://example.com/scanner/api/v1/health');
        $response = $this->middleware->process($request, $this->requestHandler);

        $payload = $this->getPayload($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertNull($payload['error']);
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame('v1', $payload['data']['apiVersion']);
    }

    #[Test]
    public function capabilitiesEndpointReportsSecureContexts(): void
    {
        $request = $this->createRequest('GET', 'https://example.com/scanner/api/v1/capabilities');
        $payload = $this->getPayload($this->middleware->process($request, $this->requestHandler));

        self::assertTrue($payload['data']['requirements']['isSecureContext']);
        self::assertSame('v1', $payload['data']['apiVersion']);
    }

    #[Test]
    public function capabilitiesEndpointTreatsLocalhostAsSecure(): void
    {
        $request = $this->createRequest('GET', 'http://127.0.0.1/scanner/api/v1/capabilities');
        $payload = $this->getPayload($this->middleware->process($request, $this->requestHandler));

        self::assertTrue($payload['data']['requirements']['isSecureContext']);
    }

    #[Test]
    public function capabilitiesEndpointTreatsTheLocalhostHostnameAsSecure(): void
    {
        $request = $this->createRequest('GET', 'http://localhost/scanner/api/v1/capabilities');
        $payload = $this->getPayload($this->middleware->process($request, $this->requestHandler));

        self::assertTrue($payload['data']['requirements']['isSecureContext']);
    }

    #[Test]
    public function apiRoutesAcceptATrailingSlash(): void
    {
        $request  = $this->createRequest('GET', 'https://example.com/scanner/api/v1/health/');
        $response = $this->middleware->process($request, $this->requestHandler);

        $payload = $this->getPayload($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['data']['status']);
    }

    #[Test]
    public function capabilitiesEndpointReportsNonSecureContexts(): void
    {
        $request = $this->createRequest('GET', 'http://example.com/scanner/api/v1/capabilities');
        $payload = $this->getPayload($this->middleware->process($request, $this->requestHandler));

        self::assertFalse($payload['data']['requirements']['isSecureContext']);
    }

    #[Test]
    public function resultEndpointAcceptsValidPayloads(): void
    {
        $request = $this->createJsonRequest('POST', 'https://example.com/scanner/api/v1/result', [
            'content' => '9780201379624',
            'format' => 'ean_13',
            'source' => 'camera',
        ]);
        $response = $this->middleware->process($request, $this->requestHandler);

        $payload = $this->getPayload($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('accepted', $payload['data']['status']);
        self::assertSame('9780201379624', $payload['data']['result']['content']);
        self::assertSame('ean_13', $payload['data']['result']['format']);
    }

    #[Test]
    public function resultEndpointRejectsInvalidJson(): void
    {
        $request = $this->createJsonRequest('POST', 'https://example.com/scanner/api/v1/result', 'not-json');
        $response = $this->middleware->process($request, $this->requestHandler);

        $payload = $this->getPayload($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('invalid_json', $payload['error']['code']);
    }

    #[Test]
    public function resultEndpointRejectsInvalidPayloads(): void
    {
        $request = $this->createJsonRequest('POST', 'https://example.com/scanner/api/v1/result', [
            'content' => '',
            'format' => 'unknown',
            'source' => 'webcam',
        ]);
        $response = $this->middleware->process($request, $this->requestHandler);

        $payload = $this->getPayload($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertNotEmpty($payload['error']['details']['errors']);
    }

    #[Test]
    public function resultEndpointRejectsGetRequests(): void
    {
        $request = $this->createRequest('GET', 'https://example.com/scanner/api/v1/result');
        $response = $this->middleware->process($request, $this->requestHandler);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function unknownApiRoutesReturnNotFound(): void
    {
        $request = $this->createRequest('GET', 'https://example.com/scanner/api/v1/unknown');
        $response = $this->middleware->process($request, $this->requestHandler);

        $payload = $this->getPayload($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('method_or_route_not_supported', $payload['error']['code']);
    }

    private function createRequest(string $method, string $uri): ServerRequest
    {
        return new ServerRequest(new Uri($uri), $method, new Stream('php://temp', 'r+'));
    }

    private function createJsonRequest(string $method, string $uri, array|string $body): ServerRequest
    {
        $stream = new Stream('php://temp', 'r+');
        $stream->write(is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body);
        $stream->rewind();

        return new ServerRequest(
            new Uri($uri),
            $method,
            $stream,
            ['Content-Type' => 'application/json']
        );
    }

    private function getPayload(ResponseInterface $response): array
    {
        self::assertInstanceOf(JsonResponse::class, $response);

        return json_decode((string)$response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
    }
}

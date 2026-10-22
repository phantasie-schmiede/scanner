<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Scanner.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Scanner\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use PSBits\Scanner\Middleware\ScannerApiMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;

final class ScannerApiMiddlewareTest extends TestCase
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

    public function testRequestsWithoutTheApiPrefixArePassedThrough(): void
    {
        $request = $this->createRequest('GET', 'https://example.com/any/page');
        $response = $this->middleware->process($request, $this->requestHandler);

        $payload = $this->getPayload($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['passedThrough']);
    }

    public function testHealthEndpointReturnsTheApiVersion(): void
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

    public function testCapabilitiesEndpointReportsSecureContexts(): void
    {
        $request = $this->createRequest('GET', 'https://example.com/scanner/api/v1/capabilities');
        $payload = $this->getPayload($this->middleware->process($request, $this->requestHandler));

        self::assertTrue($payload['data']['requirements']['isSecureContext']);
        self::assertSame('v1', $payload['data']['apiVersion']);
    }

    public function testCapabilitiesEndpointTreatsLocalhostAsSecure(): void
    {
        $request = $this->createRequest('GET', 'http://127.0.0.1/scanner/api/v1/capabilities');
        $payload = $this->getPayload($this->middleware->process($request, $this->requestHandler));

        self::assertTrue($payload['data']['requirements']['isSecureContext']);
    }

    public function testCapabilitiesEndpointReportsNonSecureContexts(): void
    {
        $request = $this->createRequest('GET', 'http://example.com/scanner/api/v1/capabilities');
        $payload = $this->getPayload($this->middleware->process($request, $this->requestHandler));

        self::assertFalse($payload['data']['requirements']['isSecureContext']);
    }

    public function testResultEndpointAcceptsValidPayloads(): void
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

    public function testResultEndpointRejectsInvalidJson(): void
    {
        $request = $this->createJsonRequest('POST', 'https://example.com/scanner/api/v1/result', 'not-json');
        $response = $this->middleware->process($request, $this->requestHandler);

        $payload = $this->getPayload($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('invalid_json', $payload['error']['code']);
    }

    public function testResultEndpointRejectsInvalidPayloads(): void
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

    public function testResultEndpointRejectsGetRequests(): void
    {
        $request = $this->createRequest('GET', 'https://example.com/scanner/api/v1/result');
        $response = $this->middleware->process($request, $this->requestHandler);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testUnknownApiRoutesReturnNotFound(): void
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

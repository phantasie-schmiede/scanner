<?php

declare(strict_types=1);

namespace PSBits\Scanner\Middleware;

use PSBits\Scanner\Api\ScannerApiService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final class ScannerApiMiddleware implements MiddlewareInterface
{
    private const API_PREFIX = '/scanner/api/v1';

    public function __construct(private readonly ScannerApiService $scannerApiService = new ScannerApiService())
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');

        if (!str_starts_with($path, self::API_PREFIX)) {
            return $handler->handle($request);
        }

        $method = strtoupper($request->getMethod());

        if ($path === self::API_PREFIX . '/health' && 'GET' === $method) {
            return $this->jsonSuccess([
                'status'          => 'ok',
                'apiVersion'      => ScannerApiService::API_VERSION,
                'serverTimestamp' => gmdate(DATE_ATOM),
            ]);
        }

        if ($path === self::API_PREFIX . '/capabilities' && 'GET' === $method) {
            $isSecureContext = $this->isSecureRequest($request);

            return $this->jsonSuccess($this->scannerApiService->getCapabilities($isSecureContext));
        }

        if ($path === self::API_PREFIX . '/result' && 'POST' === $method) {
            $rawBody = (string)$request->getBody();
            $payload = json_decode($rawBody, true);

            if (!is_array($payload)) {
                return $this->jsonError('invalid_json', 'Invalid JSON payload.', 400);
            }

            $validation = $this->scannerApiService->validateResultPayload($payload);

            if (!$validation['isValid']) {
                return $this->jsonError('validation_failed', 'Scan result payload validation failed.', 422, [
                    'errors' => $validation['errors'],
                ]);
            }

            return $this->jsonSuccess([
                'status'          => 'accepted',
                'result'          => $validation['normalized'],
                'serverTimestamp' => gmdate(DATE_ATOM),
            ]);
        }

        return $this->jsonError('method_or_route_not_supported', 'Route or method not supported.', 404);
    }

    private function isSecureRequest(ServerRequestInterface $request): bool
    {
        $uri    = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host   = strtolower(trim($uri->getHost(), '[]'));

        return 'https' === $scheme || in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonSuccess(array $data, int $statusCode = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data'    => $data,
            'error'   => null,
        ], $statusCode);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function jsonError(string $code, string $message, int $statusCode, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'data'    => null,
            'error'   => [
                'code'    => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $statusCode);
    }
}

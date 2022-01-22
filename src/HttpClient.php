<?php

declare(strict_types=1);

namespace Neucore\Plugin\Discord;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class HttpClient
{
    private const RATE_LIMIT_REMAIN = 'X-RateLimit-Remaining';

    private const RATE_LIMIT_RESET_AFTER = 'X-RateLimit-Reset-After';

    private const RATE_LIMIT_BUCKET = 'X-RateLimit-Bucket';

    private Logger $logger;

    private ?ClientInterface $client = null;

    private int $lastResponseErrorCode = 0;

    private string $lastResponseErrorBody = '';

    private ?ResponseInterface $lastResponse = null;

    /**
     * @var array<string, array>
     */
    private array $rateLimits = [];

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function getLastResponseErrorBody(): string
    {
        return $this->lastResponseErrorBody;
    }

    public function getLastResponseErrorCode(): int
    {
        return $this->lastResponseErrorCode;
    }

    public function apiRequest(string $method, string $url, array $headers = [], ?string $body = null): ?string
    {
        // https://discord.com/developers/docs/topics/rate-limits

        // Simple rate limit implementation, sleep if any bucket is too low
        $resetAfter = 0;
        $remaining = PHP_INT_MAX;
        foreach ($this->rateLimits as /*$bucket =>*/ $limit) {
            $resetAfter = (int)ceil(max($resetAfter, $limit['time'] + ($limit['resetAfter'] + 0.01) - microtime(true)));
            $remaining = min($remaining, $limit['remaining']);
        }
        if ($remaining < 1 && $resetAfter > 0) {
            $this->logger->log("Rate limit: remaining < 1, sleeping $resetAfter second(s)");
            sleep($resetAfter);
        }

        $result = $this->sendRequest($method, $url, $headers, $body);

        // Store rate limit info, do not use X-RateLimit-Reset in case the local time is wrong.
        $rateLimitRemaining = $this->lastResponse->getHeader(self::RATE_LIMIT_REMAIN)[0] ?? '';
        $rateLimitResetAfter = $this->lastResponse->getHeader(self::RATE_LIMIT_RESET_AFTER)[0] ?? '';
        $rateLimitBucket = $this->lastResponse->getHeader(self::RATE_LIMIT_BUCKET)[0] ?? '';
        if ($rateLimitRemaining !== '' && !empty($rateLimitResetAfter) && !empty($rateLimitBucket)) {
            $this->rateLimits[$rateLimitBucket] = [
                'remaining' => (int)$rateLimitRemaining,
                'resetAfter' => (float)$rateLimitResetAfter,
                'time' => microtime(true),
            ];
        }
        if ($this->lastResponseErrorCode === 429) {
            $parsedBody = json_decode($this->lastResponseErrorBody, true);
            if (isset($parsedBody['retry_after'])) {
                $this->rateLimits['http429'] = [
                    'remaining' => 0,

                    // seems to be milliseconds, not seconds as the documentation states.
                    'resetAfter' => round($parsedBody['retry_after'] / 1000, 1),

                    'time' => microtime(true),
                ];
            }
        }

        return $result;
    }

    public function sendRequest(string $method, string $url, array $headers = [], ?string $body = null): ?string
    {
        #$this->logger->log([$method, $url, $headers, $body], \Psr\Log\LogLevel::DEBUG);
        #$this->logger->log("$method, $url", \Psr\Log\LogLevel::DEBUG);

        $this->lastResponseErrorCode = 0;
        $this->lastResponseErrorBody = '';

        $request = new Request($method, $url, $headers, $body);
        try {
            $this->lastResponse = $this->getClient()->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->logException($e, __FUNCTION__);
            return null;
        }

        try {
            $body = $this->lastResponse->getBody()->getContents();
        } catch (RuntimeException $e) {
            $this->logger->logException($e, __FUNCTION__);
            return null;
        }

        $code = $this->lastResponse->getStatusCode();
        if ($code < 200 || $code > 299) {
            $requestHeaders = $headers;
            if (isset($requestHeaders['Authorization'])) {
                $authValues = explode(' ', $requestHeaders['Authorization']); // value is e.g. "Bot abc123"
                $requestHeaders['Authorization'] = $authValues[0] . ' ****';
            }
            $responseHeaders = $this->getDebugHeaders($this->lastResponse->getHeaders());
            $this->logger->logError(
                "Request: $method $url " . json_encode($requestHeaders) . ', ' .
                "Response: $code $body " . json_encode($responseHeaders)
            );
            $this->lastResponseErrorCode = $code;
            $this->lastResponseErrorBody = $body;
            return null;
        }

        return $body;
    }

    private function getDebugHeaders(array $headers): array
    {
        $result = [];
        $keep = [
            strtolower('Retry-After'),
            strtolower('X-RateLimit-Global'),
            strtolower('X-RateLimit-Limit'),
            strtolower(self::RATE_LIMIT_REMAIN),
            strtolower('X-RateLimit-Reset'),
            strtolower(self::RATE_LIMIT_RESET_AFTER),
            strtolower(self::RATE_LIMIT_BUCKET),
        ];
        foreach ($headers as $name => $header) {
            if (in_array(strtolower($name), $keep)) {
                $result[$name] = $header[0] ?? '';
            }
        }
        return $result;
    }

    private function getClient(): ClientInterface
    {
        if ($this->client === null) {
            $this->client = new Client([
                'headers' => [
                    'User-Agent' => 'Neucore Discord Plugin (https://github.com/tkhamez/'.Service::PLUGIN_NAME.')',
                ],
            ]);
        }
        return $this->client;
    }
}

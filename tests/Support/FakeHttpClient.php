<?php

declare(strict_types=1);

namespace Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 client for tests: returns queued responses in order and records every request.
 */
final class FakeHttpClient implements ClientInterface
{
    /**
     * Responses (or exceptions to throw) still to be returned.
     *
     * @var list<ResponseInterface|ClientExceptionInterface>
     */
    private array $queue = [];

    /**
     * Requests sent so far, in order.
     *
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * Queue a JSON response.
     *
     * @param array<array-key, mixed> $body
     */
    public function queueJson(array $body, int $status = 200): self
    {
        $this->queue[] = new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );

        return $this;
    }

    /**
     * Queue a raw response.
     */
    public function queueResponse(ResponseInterface $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * Queue a network error.
     */
    public function queueNetworkError(string $message = 'Connection refused'): self
    {
        $this->queue[] = new class ($message) extends RuntimeException implements ClientExceptionInterface {
        };

        return $this;
    }

    /**
     * Return the next queued response, recording the request.
     *
     * @throws ClientExceptionInterface When a network error was queued.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException('FakeHttpClient: no response queued for ' . $request->getMethod() . ' '
                . $request->getUri());
        }

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /**
     * The most recent request.
     */
    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)]
            ?? throw new RuntimeException('FakeHttpClient: no requests sent.');
    }

    /**
     * Query parameters of a recorded request (the last one by default).
     *
     * @return array<string, mixed>
     */
    public function query(?int $index = null): array
    {
        $request = $index === null ? $this->lastRequest() : $this->requests[$index];
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }
}

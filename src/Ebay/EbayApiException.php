<?php

declare(strict_types=1);

namespace App\Ebay;

use Throwable;

/**
 * An eBay API call failed: a network error, or an error response from eBay.
 */
final class EbayApiException extends EbayException
{
    /**
     * Create the exception.
     *
     * @param int $statusCode HTTP status of eBay's response, or 0 for network errors.
     * @param string $errorCode eBay's error code, e.g. `invalid_client` (OAuth) or an errorId (REST).
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        ?Throwable $previous = null,
        private readonly string $errorCode = '',
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Build an exception from an eBay error response body.
     *
     * Understands both REST errors (`errors[].message`/`longMessage`) and OAuth
     * errors (`error`/`error_description`).
     */
    public static function fromResponse(string $operation, int $statusCode, string $body): self
    {
        $data = json_decode($body, true);
        $detail = '';
        $code = '';

        if (is_array($data)) {
            $first = is_array($data['errors'][0] ?? null) ? $data['errors'][0] : [];
            $detail = (string) ($first['longMessage'] ?? $first['message'] ?? $data['error_description']
                ?? $data['error'] ?? '');
            $code = (string) ($data['error'] ?? $first['errorId'] ?? '');
        }

        return new self(
            sprintf('%s failed (HTTP %d)%s', $operation, $statusCode, $detail !== '' ? ': ' . $detail : '.'),
            $statusCode,
            null,
            $code
        );
    }

    /**
     * eBay's error code (e.g. `invalid_client`, `invalid_grant`), or '' when unknown.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * HTTP status of eBay's response, or 0 for network errors.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

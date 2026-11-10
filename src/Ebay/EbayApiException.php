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
     */
    public function __construct(string $message, private readonly int $statusCode = 0, ?Throwable $previous = null)
    {
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

        if (is_array($data)) {
            $first = is_array($data['errors'][0] ?? null) ? $data['errors'][0] : [];
            $detail = (string) ($first['longMessage'] ?? $first['message'] ?? $data['error_description']
                ?? $data['error'] ?? '');
        }

        return new self(
            sprintf('%s failed (HTTP %d)%s', $operation, $statusCode, $detail !== '' ? ': ' . $detail : '.'),
            $statusCode
        );
    }

    /**
     * HTTP status of eBay's response, or 0 for network errors.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

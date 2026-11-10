<?php

declare(strict_types=1);

namespace App\Ebay;

/**
 * eBay Commerce Feedback API v1.
 *
 * Replaces the Trading API `GetFeedback` call used by the legacy app.
 *
 * @see https://developer.ebay.com/develop/api/sell/feedback_api
 */
class FeedbackApi
{
    /**
     * Largest page size getFeedback allows.
     */
    public const MAX_PAGE_SIZE = 200;

    /**
     * Create the API wrapper.
     */
    public function __construct(private readonly EbayClient $client)
    {
    }

    /**
     * One page of feedback received by a user, newest first.
     *
     * @param string $userId eBay username of the seller.
     *
     * @return array{entries: list<array<string, mixed>>, total: int}
     *
     * @throws EbayException
     */
    public function receivedFeedback(string $userId, int $offset = 0, int $limit = self::MAX_PAGE_SIZE): array
    {
        $data = $this->client->get('/commerce/feedback/v1/feedback', [
            'user_id' => $userId,
            'feedback_type' => 'FEEDBACK_RECEIVED',
            'sort' => 'TIME',
            'limit' => max(1, min(self::MAX_PAGE_SIZE, $limit)),
            'offset' => max(0, $offset),
        ]);

        $entries = is_array($data['feedbackEntries'] ?? null)
            ? array_values(array_filter($data['feedbackEntries'], 'is_array'))
            : [];
        $total = is_array($data['pagination'] ?? null) ? (int) ($data['pagination']['total'] ?? 0) : 0;

        return ['entries' => $entries, 'total' => $total];
    }
}

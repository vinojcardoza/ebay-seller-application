<?php

declare(strict_types=1);

namespace Tests\Ebay;

use App\Ebay\FeedbackApi;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\EbayTestCase;

/**
 * Tests for the Commerce Feedback API wrapper.
 */
#[CoversClass(FeedbackApi::class)]
final class FeedbackApiTest extends EbayTestCase
{
    /**
     * Received feedback is requested for the user, newest first, with paging.
     */
    public function testReceivedFeedbackRequest(): void
    {
        $this->http->queueJson([
            'feedbackEntries' => [['feedbackId' => '1'], ['feedbackId' => '2']],
            'pagination' => ['total' => 42, 'count' => 2],
        ]);

        $result = (new FeedbackApi($this->client))->receivedFeedback('test-seller', 200, 50);

        $this->assertSame(['entries' => [['feedbackId' => '1'], ['feedbackId' => '2']], 'total' => 42], $result);
        $this->assertSame('/commerce/feedback/v1/feedback', $this->http->lastRequest()->getUri()->getPath());
        $this->assertSame([
            'user_id' => 'test-seller',
            'feedback_type' => 'FEEDBACK_RECEIVED',
            'sort' => 'TIME',
            'limit' => '50',
            'offset' => '200',
        ], $this->http->query());
    }

    /**
     * Page size is limited to 1–200 and offset to 0 or more.
     */
    public function testLimitsAreClamped(): void
    {
        $this->http->queueJson([])->queueJson([]);
        $api = new FeedbackApi($this->client);

        $this->assertSame(['entries' => [], 'total' => 0], $api->receivedFeedback('s', -5, 1000));
        $this->assertSame(['200', '0'], [$this->http->query()['limit'], $this->http->query()['offset']]);

        $api->receivedFeedback('s', 0, 0);
        $this->assertSame('1', $this->http->query()['limit']);
    }
}

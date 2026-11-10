<?php

declare(strict_types=1);

namespace App\Ebay\Import;

use App\Ebay\EbayConfig;
use App\Ebay\EbayException;
use App\Ebay\FeedbackApi;
use App\Entities\Feedback;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

/**
 * Imports feedback that buyers left for the seller into the `feedbacks` table.
 *
 * Replaces the legacy Trading API `GetFeedback` import, which kept only
 * feedback received in the seller role. Feedback is fetched newest first and
 * the import stops at the first page with nothing new, so repeat runs are cheap.
 */
class FeedbackImporter
{
    /**
     * Create the importer.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeedbackApi $api,
        private readonly EbayConfig $config,
    ) {
    }

    /**
     * Fetch and save new feedback.
     *
     * @param int $maxPages Most pages of 200 to fetch in one run.
     *
     * @return array{fetched: int, imported: int}
     *
     * @throws EbayException When the seller username is not configured or the API call fails.
     */
    public function import(int $maxPages = 10): array
    {
        if ($this->config->sellerUsername === '') {
            throw new EbayException('Set EBAY_SELLER_USERNAME to import feedback.');
        }

        $result = ['fetched' => 0, 'imported' => 0];

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->api->receivedFeedback(
                $this->config->sellerUsername,
                $page * FeedbackApi::MAX_PAGE_SIZE
            );
            $entries = array_values(array_filter($response['entries'], [$this, 'isFromBuyer']));
            $result['fetched'] += count($response['entries']);

            $new = $this->newEntries($entries);

            foreach ($new as $entry) {
                $this->em->persist($this->toFeedback($entry));
            }

            $result['imported'] += count($new);

            $fetchedSoFar = ($page + 1) * FeedbackApi::MAX_PAGE_SIZE;

            if ($new === [] || $response['entries'] === [] || $fetchedSoFar >= $response['total']) {
                break;
            }
        }

        if ($result['imported'] > 0) {
            $this->em->flush();
        }

        return $result;
    }

    /**
     * Whether a feedback entry was left by a buyer (i.e. received in the seller role).
     *
     * @param array<string, mixed> $entry
     */
    private function isFromBuyer(array $entry): bool
    {
        $provider = $entry['providerUserDetail'] ?? [];

        return is_array($provider) && ($provider['role'] ?? '') === 'BUYER';
    }

    /**
     * Entries not already stored, matched on feedback ID or order line item ID.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function newEntries(array $entries): array
    {
        $feedbackIds = array_values(array_filter(
            array_map(fn (array $e): string => $this->string($e, 'feedbackId'), $entries)
        ));
        $lineItemIds = array_values(array_filter(array_map(fn (array $e): string => $this->lineItemId($e), $entries)));
        $known = [];
        $repository = $this->em->getRepository(Feedback::class);

        if ($feedbackIds !== []) {
            foreach ($repository->findBy(['feedbackId' => $feedbackIds]) as $feedback) {
                $known['f:' . $feedback->getFeedbackId()] = true;
            }
        }

        if ($lineItemIds !== []) {
            foreach ($repository->findBy(['orderLineItemId' => $lineItemIds]) as $feedback) {
                $known['l:' . $feedback->getOrderLineItemId()] = true;
            }
        }

        return array_values(array_filter(
            $entries,
            fn (array $e): bool => $this->string($e, 'feedbackId') !== ''
                && !isset($known['f:' . $this->string($e, 'feedbackId')])
                && !isset($known['l:' . $this->lineItemId($e)])
        ));
    }

    /**
     * Map a Feedback API entry to a Feedback entity, in the legacy column formats.
     *
     * @param array<string, mixed> $entry
     */
    private function toFeedback(array $entry): Feedback
    {
        $provider = is_array($entry['providerUserDetail'] ?? null) ? $entry['providerUserDetail'] : [];
        $comment = is_array($entry['feedbackComment'] ?? null) ? $entry['feedbackComment'] : [];
        $line = is_array($entry['orderLineItemSummary'] ?? null) ? $entry['orderLineItemSummary'] : [];
        $price = is_array($line['listingPrice'] ?? null) ? $line['listingPrice'] : [];
        $lineItemId = $this->lineItemId($entry);
        $type = $this->string($entry, 'commentType');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $this->string($comment, 'commentText'));

        return (new Feedback())
            ->setFeedbackId($this->string($entry, 'feedbackId'))
            ->setEbayUserId($this->nullable($this->string($provider, 'userId')))
            ->setCommentText($this->nullable((string) $text))
            ->setCommentTime($this->date($this->string($entry, 'feedbackEnteredDate')))
            ->setCommentType($type !== '' ? ucfirst(strtolower($type)) : null)
            ->setItemId($this->nullable($this->string($line, 'listingId')))
            ->setItemTitle($this->nullable($this->string($line, 'listingTitle')))
            ->setItemPrice(isset($price['value']) ? Money::format(Money::toPence((string) $price['value'])) : null)
            ->setOrderLineItemId($this->nullable($lineItemId))
            ->setTransactionId($this->nullable($this->transactionId($lineItemId)));
    }

    /**
     * Transaction ID part of an order line item ID ("itemId-transactionId"), or ''.
     */
    private function transactionId(string $lineItemId): string
    {
        $dash = strpos($lineItemId, '-');

        return $dash === false ? '' : substr($lineItemId, $dash + 1);
    }

    /**
     * The entry's order line item ID ("itemId-transactionId"), or ''.
     *
     * @param array<string, mixed> $entry
     */
    private function lineItemId(array $entry): string
    {
        return is_array($entry['orderLineItemSummary'] ?? null)
            ? $this->string($entry['orderLineItemSummary'], 'orderLineItemId') : '';
    }

    /**
     * Parse an eBay UTC timestamp into the app's time zone, or null.
     */
    private function date(string $value): ?DateTime
    {
        if ($value === '') {
            return null;
        }

        try {
            return DateTime::createFromImmutable(
                (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))
            );
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Read a scalar field as a trimmed string ('' when missing).
     *
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }

    /**
     * Null for empty strings.
     */
    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}

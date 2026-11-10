<?php

declare(strict_types=1);

namespace App\Ebay;

/**
 * No valid eBay authorisation: the seller has never connected, or the refresh
 * token has expired or been revoked and they must connect again.
 */
final class EbayNotConnectedException extends EbayException
{
}

<?php

declare(strict_types=1);

namespace App\Ebay\Import;

use App\Ebay\EbayException;

/**
 * An eBay order cannot be imported (already imported, cancelled, unknown SKU, …).
 */
final class OrderImportException extends EbayException
{
}

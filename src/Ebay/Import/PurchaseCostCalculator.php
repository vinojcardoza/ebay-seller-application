<?php

declare(strict_types=1);

namespace App\Ebay\Import;

use App\Entities\Product;
use App\Entities\Purchase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Unit cost of the next item of stock to be sold, on a first-in, first-out basis.
 *
 * Ported from the legacy `OrdersController::getPurchaseCost()`: delivered
 * purchases are taken oldest first, and the unit cost is that of the first
 * batch not yet used up by units already sold.
 */
class PurchaseCostCalculator
{
    /**
     * Create the calculator.
     */
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Unit cost of the product's next unit of stock, or "0.00" if none is left.
     */
    public function unitCost(Product $product): string
    {
        $purchases = $this->em->getRepository(Purchase::class)->findBy(
            ['product' => $product, 'status' => Purchase::STATUS_DELIVERED],
            ['receivedDate' => 'ASC', 'id' => 'ASC']
        );

        $sold = (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE product = ?',
            [$product->getId()]
        );

        return self::fifoUnitCost($purchases, $sold);
    }

    /**
     * Unit cost of the first purchase batch that still has unsold stock.
     *
     * @param list<Purchase> $purchases Delivered purchases, oldest first.
     * @param int $soldQuantity Units of the product already sold.
     */
    public static function fifoUnitCost(array $purchases, int $soldQuantity): string
    {
        $purchased = 0;

        foreach ($purchases as $purchase) {
            $quantity = $purchase->getQuantity();
            $purchased += $quantity;

            if ($purchased > $soldQuantity && $quantity > 0) {
                return Money::format((int) round(Money::toPence($purchase->getTotalPrice()) / $quantity));
            }
        }

        return '0.00';
    }
}

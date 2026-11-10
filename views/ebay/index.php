<?php

/**
 * View: eBay integration — connection, actions and recent orders to import.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Ebay\EbayConfig $config
 * @var \App\Ebay\Token\OAuthToken|null $connection Stored tokens when connected.
 * @var list<\App\Ebay\Import\ImportPreview> $previews Recent eBay orders.
 * @var int $days Look-back period for recent orders.
 * @var list<int> $dayOptions Allowed look-back periods.
 * @var string|null $notice Success message.
 * @var string|null $error Error message for the last action.
 * @var string|null $ordersError Error loading recent orders.
 */

use App\Ebay\Import\ImportPreview;

$this->layout('layout', ['title' => 'eBay', 'breadcrumbs' => ['eBay' => null]]);

/**
 * Badge class for how a line's SKU matched.
 */
$matchBadge = static fn (?string $match): string => match ($match) {
    ImportPreview::MATCH_PRODUCT => 'tint-success',
    ImportPreview::MATCH_CUSTOM_SKU => 'tint-info',
    default => 'tint-danger',
};
?>
<?php if ($notice !== null) : ?>
    <div class="alert alert-success" role="status"><?php echo $this->e($notice); ?></div>
<?php endif; ?>
<?php if ($error !== null) : ?>
    <div class="alert alert-danger" role="alert"><?php echo $this->e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header">
                <h2>Connection</h2>
                <?php if ($connection !== null) : ?>
                    <span class="badge tint-success">Connected</span>
                <?php else : ?>
                    <span class="badge tint-danger">Not connected</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <dl class="row detail-list mb-3">
                    <dt class="col-sm-5">Environment</dt>
                    <dd class="col-sm-7"><?php echo $this->e(ucfirst($config->environment)); ?></dd>
                    <dt class="col-sm-5">Marketplace</dt>
                    <dd class="col-sm-7"><?php echo $this->e($config->marketplaceId); ?></dd>
                    <dt class="col-sm-5">Seller</dt>
                    <dd class="col-sm-7"><?php echo $config->sellerUsername !== '' ? $this->e($config->sellerUsername) : '—'; ?></dd>
                    <?php if ($connection !== null) : ?>
                        <dt class="col-sm-5">Reconnect by</dt>
                        <dd class="col-sm-7"><?php echo $connection->refreshTokenExpiresAt->format('d M Y'); ?></dd>
                    <?php endif; ?>
                </dl>

                <?php if (!$config->isConfigured()) : ?>
                    <p class="mb-0">
                        Add <code>EBAY_CLIENT_ID</code>, <code>EBAY_CLIENT_SECRET</code> and <code>EBAY_RU_NAME</code>
                        to <code>.env</code>, then restart the containers.
                    </p>
                <?php elseif ($connection !== null) : ?>
                    <form method="post" action="/ebay/disconnect" data-confirm="Disconnect eBay?">
                        <button type="submit" class="btn btn-light-danger">
                            <i class="ti ti-plug-connected-x" aria-hidden="true"></i> Disconnect
                        </button>
                    </form>
                <?php else : ?>
                    <a class="btn btn-primary" href="/ebay/connect">
                        <i class="ti ti-plug-connected" aria-hidden="true"></i> Connect eBay account
                    </a>
                    <form method="post" action="/ebay/code" class="mt-3">
                        <label class="form-label" for="code">Or paste the authorization code</label>
                        <div class="d-flex gap-2">
                            <input class="form-control" id="code" name="code" autocomplete="off"
                                   placeholder="Code, or the URL eBay redirected you to">
                            <button type="submit" class="btn btn-light-primary">Connect</button>
                        </div>
                        <small class="text-body-secondary">
                            Use this when eBay can't redirect back to this app (its accepted URL must be HTTPS).
                        </small>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card">
            <div class="card-header">
                <h2>Sync</h2>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div>
                        <strong>Shipment tracking</strong>
                        <p class="mb-0 small">
                            Carrier, tracking number and shipped date for eBay orders from the last 90 days without tracking.
                        </p>
                    </div>
                    <form method="post" action="/ebay/tracking">
                        <button type="submit" class="btn btn-light-primary" <?php echo $connection === null ? 'disabled' : ''; ?>>
                            <i class="ti ti-truck-delivery" aria-hidden="true"></i> Sync tracking
                        </button>
                    </form>
                </div>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div>
                        <strong>Feedback</strong>
                        <p class="mb-0 small">New feedback left by buyers.</p>
                    </div>
                    <form method="post" action="/ebay/feedback">
                        <button type="submit" class="btn btn-light-primary" <?php echo $connection === null ? 'disabled' : ''; ?>>
                            <i class="ti ti-message-star" aria-hidden="true"></i> Import feedback
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Recent eBay orders</h2>
        <form method="get" action="/ebay" class="d-flex align-items-center gap-2">
            <label class="small text-nowrap" for="days">Created in the last</label>
            <select class="form-select form-select-sm" id="days" name="days" style="min-width: 7.5rem"
                    onchange="this.form.submit()">
                <?php foreach ($dayOptions as $option) : ?>
                    <option value="<?php echo $option; ?>" <?php echo $option === $days ? 'selected' : ''; ?>>
                        <?php echo $option; ?> day<?php echo $option === 1 ? '' : 's'; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-light-secondary">Show</button></noscript>
        </form>
    </div>

    <?php if ($connection === null) : ?>
        <div class="empty-state">
            <i class="ti ti-plug-connected-x" aria-hidden="true"></i>
            Connect your eBay account to see recent orders.
        </div>
    <?php elseif ($ordersError !== null) : ?>
        <div class="card-body">
            <div class="alert alert-danger mb-0" role="alert"><?php echo $this->e($ordersError); ?></div>
        </div>
    <?php elseif ($previews === []) : ?>
        <div class="empty-state">
            <i class="ti ti-shopping-cart" aria-hidden="true"></i>
            No eBay orders in the last <?php echo $days; ?> day<?php echo $days === 1 ? '' : 's'; ?>.
        </div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Buyer</th>
                        <th>Items</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                        <th class="text-end">Import</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previews as $preview) : ?>
                        <tr>
                            <td class="text-nowrap">
                                <?php echo $this->e($preview->orderId); ?>
                                <?php if ($preview->created !== null) : ?>
                                    <div class="small text-body-secondary">
                                        <?php echo $preview->created->format('d M Y H:i'); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $this->e($preview->buyer !== '' ? $preview->buyer : '—'); ?></td>
                            <td>
                                <?php foreach ($preview->lines as $line) : ?>
                                    <div>
                                        <span class="badge <?php echo $matchBadge($line['match']); ?>">
                                            <?php echo $this->e($line['sku'] !== '' ? $line['sku'] : 'No SKU'); ?>
                                        </span>
                                        × <?php echo $line['quantity']; ?>
                                        <span class="small text-body-secondary"><?php echo $this->e($line['title']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php echo $this->e($preview->currency === 'GBP' ? '£' : $preview->currency . ' '); ?><?php echo $this->e($preview->total); ?>
                            </td>
                            <td>
                                <?php if ($preview->alreadyImported) : ?>
                                    <span class="badge tint-primary">Imported</span>
                                <?php elseif ($preview->canImport()) : ?>
                                    <span class="badge tint-success">Ready</span>
                                <?php else : ?>
                                    <span class="badge tint-danger">Needs attention</span>
                                    <div class="small text-body-secondary"><?php echo $this->e(implode(' ', $preview->problems())); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($preview->canImport()) : ?>
                                    <form method="post" action="/ebay/orders/<?php echo $this->e(rawurlencode($preview->orderId)); ?>"
                                          class="d-inline-flex align-items-center gap-2">
                                        <input type="hidden" name="days" value="<?php echo $days; ?>">
                                        <label class="small text-nowrap">
                                            <input type="checkbox" name="promoted" value="1"> Promoted
                                        </label>
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="ti ti-download" aria-hidden="true"></i> Import
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

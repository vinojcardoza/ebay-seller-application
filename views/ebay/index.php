<?php

/**
 * View: eBay integration — connection status and connect / disconnect.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Ebay\EbayConfig $config
 * @var \App\Ebay\Token\OAuthToken|null $connection Stored tokens when connected.
 * @var string|null $notice Success message.
 * @var string|null $error Error message for the last action.
 */

$this->layout('layout', ['title' => 'eBay', 'breadcrumbs' => ['eBay' => null]]);
?>
<?php if ($notice !== null) : ?>
    <div class="alert alert-success" role="status"><?php echo $this->e($notice); ?></div>
<?php endif; ?>
<?php if ($error !== null) : ?>
    <div class="alert alert-danger" role="alert"><?php echo $this->e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-xl-6">
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
                    <dt class="col-sm-5">Scopes</dt>
                    <dd class="col-sm-7 small">
                        <?php foreach ($config->scopes as $scope) : ?>
                            <div><?php echo $this->e(substr($scope, strlen(\App\Ebay\EbayConfig::SCOPE_PREFIX)) ?: 'api_scope'); ?></div>
                        <?php endforeach; ?>
                    </dd>
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
</div>

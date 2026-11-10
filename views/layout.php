<?php

/**
 * Layout: Admin shell with a top navigation header and page header.
 *
 * Views set these through `$this->layout('layout', [...])`:
 *
 * @var \League\Plates\Template\Template $this
 * @var string|null $title Page title, shown in the tab and page header.
 * @var array<string, string|null>|null $breadcrumbs Trail after "Home", label => URL (null for the current page).
 *
 * Views can also fill an `actions` section with buttons for the page header.
 */

$title = $title ?? 'Business App';
$breadcrumbs = $breadcrumbs ?? [];
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

$navItems = [
    ['label' => 'Dashboard', 'url' => '/', 'icon' => 'ti-layout-dashboard'],
    ['label' => 'Orders', 'url' => '/orders', 'icon' => 'ti-shopping-cart'],
    ['label' => 'Products', 'url' => '/products', 'icon' => 'ti-package', 'match' => '/product'],
    ['label' => 'Categories', 'url' => '/categories', 'icon' => 'ti-category'],
    ['label' => 'Custom SKUs', 'url' => '/custom-skus', 'icon' => 'ti-packages'],
    ['label' => 'Purchases', 'url' => '/purchases', 'icon' => 'ti-receipt'],
    ['label' => 'Feedback', 'url' => '/feedbacks', 'icon' => 'ti-message-star'],
    ['label' => 'eBay', 'url' => '/ebay', 'icon' => 'ti-shopping-bag'],
];

/**
 * Whether a nav item is the current section: exact match for "/", prefix match otherwise.
 */
$isActive = static function (array $item) use ($path): bool {
    $prefix = $item['match'] ?? $item['url'];

    return $prefix === '/' ? $path === '/' : str_starts_with($path, $prefix);
};
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $this->e($title); ?> · Business App</title>
    <script>
        // Apply the saved theme before first paint to avoid a light flash in dark mode.
        try {
            var saved = localStorage.getItem('app-theme');
            if (saved === 'dark' || saved === 'light') {
                document.documentElement.setAttribute('data-bs-theme', saved);
            }
        } catch (e) {}
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="app-header">
        <a class="app-brand" href="/">
            <span class="app-brand-mark"><i class="ti ti-building-store" aria-hidden="true"></i></span>
            Business
        </a>

        <nav class="app-nav" id="app-nav" aria-label="Main navigation">
            <ul>
                <?php foreach ($navItems as $item) : ?>
                    <?php $active = $isActive($item); ?>
                    <li>
                        <a class="app-nav-link<?php echo $active ? ' active' : ''; ?>" href="<?php echo $item['url']; ?>"
                            <?php echo $active ? 'aria-current="page"' : ''; ?>>
                            <i class="ti <?php echo $item['icon']; ?>" aria-hidden="true"></i>
                            <span><?php echo $this->e($item['label']); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="app-user">
            <button type="button" class="app-icon-btn" data-theme-toggle aria-label="Switch to dark mode">
                <i class="ti ti-moon" aria-hidden="true"></i>
            </button>
            <span class="app-avatar" aria-hidden="true"><i class="ti ti-user"></i></span>
            <button type="button" class="app-icon-btn app-nav-toggle" data-nav-toggle
                    aria-controls="app-nav" aria-expanded="false" aria-label="Toggle navigation">
                <i class="ti ti-menu-2" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <div class="app-main">
        <main class="app-content">
            <div class="app-page-header">
                <div>
                    <nav aria-label="Breadcrumb">
                        <ol class="breadcrumb">
                            <?php if ($breadcrumbs === []) : ?>
                                <li class="breadcrumb-item active" aria-current="page">Home</li>
                            <?php else : ?>
                                <li class="breadcrumb-item"><a href="/">Home</a></li>
                                <?php foreach ($breadcrumbs as $label => $url) : ?>
                                    <?php if ($url === null) : ?>
                                        <li class="breadcrumb-item active" aria-current="page"><?php echo $this->e($label); ?></li>
                                    <?php else : ?>
                                        <li class="breadcrumb-item"><a href="<?php echo $this->e($url); ?>"><?php echo $this->e($label); ?></a></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ol>
                    </nav>
                    <h1><?php echo $this->e($title); ?></h1>
                </div>
                <?php if ($this->section('actions')) : ?>
                    <div class="d-flex flex-wrap gap-2"><?php echo $this->section('actions'); ?></div>
                <?php endif; ?>
            </div>

            <?php echo $this->section('content'); ?>
        </main>

        <footer class="app-footer">&copy; <?php echo date('Y'); ?> Business App</footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/app.js"></script>
</body>
</html>

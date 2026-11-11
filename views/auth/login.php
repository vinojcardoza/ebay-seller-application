<?php

/**
 * View: Login page, outside the admin shell.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $login Login name or email entered so far.
 * @var string $next Local path to return to after logging in.
 * @var string|null $error Error message for a failed attempt.
 * @var string|null $notice Information message (e.g. after logging out).
 */
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Log in · JRN Shop</title>
    <link rel="icon" type="image/png" sizes="64x64" href="/assets/img/favicon-64.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
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
<body class="auth-page">
    <main class="auth-main">
        <div class="auth-brand">
            <?php /* Black logo on the light theme's pale background, white logo on the dark theme. */ ?>
            <img class="auth-logo auth-logo-on-light" src="/assets/img/logo-dark-160.png" width="64" height="64" alt="">
            <img class="auth-logo auth-logo-on-dark" src="/assets/img/logo-light-160.jpg" width="64" height="64" alt="">
            <span>JRN Shop</span>
        </div>

        <div class="card auth-card">
            <div class="card-body">
                <h1 class="h4 mb-1">Log in</h1>
                <p class="text-body-secondary mb-4">Sign in to manage orders, stock and reports.</p>

                <?php if ($notice !== null) : ?>
                    <div class="alert alert-success" role="status"><?php echo $this->e($notice); ?></div>
                <?php endif; ?>

                <?php if ($error !== null) : ?>
                    <div class="alert alert-danger" role="alert" id="login-error"><?php echo $this->e($error); ?></div>
                <?php endif; ?>

                <form method="post" action="/login" novalidate>
                    <input type="hidden" name="next" value="<?php echo $this->e($next); ?>">
                    <div class="mb-3">
                        <label class="form-label" for="login">Login or email</label>
                        <input class="form-control<?php echo $error !== null ? ' is-invalid' : ''; ?>" id="login" name="login"
                               autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="191"
                               value="<?php echo $this->e($login); ?>"
                            <?php echo $login === '' ? 'autofocus' : ''; ?>
                            <?php echo $error !== null ? 'aria-describedby="login-error"' : ''; ?>>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control<?php echo $error !== null ? ' is-invalid' : ''; ?>" type="password"
                               id="password" name="password" autocomplete="current-password" required
                            <?php echo $login !== '' ? 'autofocus' : ''; ?>
                            <?php echo $error !== null ? 'aria-describedby="login-error"' : ''; ?>>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="ti ti-login-2" aria-hidden="true"></i> Log in
                    </button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>

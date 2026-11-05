<?php
declare(strict_types=1);

/** Presentation helpers only. Authentication and authorization belong to the controller. */
function nt_e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nt_url(string $base, string $path): string
{
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function nt_icon(string $name): string
{
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'audit' => '<path d="M8 4H5v17h14V4h-3"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M8 11h8M8 15h6"/>',
        'shield' => '<path d="M12 3 3.5 6v6c0 5 8.5 9 8.5 9s8.5-4 8.5-9V6L12 3Z"/><path d="m8 12 3 3 5-6"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'alert' => '<path d="m12 3 10 18H2L12 3Z"/><path d="M12 9v5M12 17h.01"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'telegram' => '<path d="m21 3-4 18-6-6-4 4 1-7L3 9 21 3Z"/><path d="m8 12 9-5-6 8"/>',
        'chain' => '<path d="m10 13 4-4M8 15l-2 2a3 3 0 0 1-4-4l4-4a3 3 0 0 1 4 0m4 0 2-2a3 3 0 0 1 4 4l-4 4a3 3 0 0 1-4 0"/>',
        'key' => '<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-5-5 3-3m0 6 3-3"/>',
        'identity' => '<rect x="2" y="5" width="20" height="15" rx="2"/><circle cx="8" cy="11" r="2"/><path d="M5 17c0-3 6-3 6 0m3-7h5m-5 4h5"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['shield']) . '</svg>';
}

function nt_brand(): void
{
    ?>
    <div class="brand">
        <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M5 19V5l14 14V5M5 5h4m6 14h4" stroke="currentColor" stroke-width="1.8"/></svg></span>
        <span class="brand-name">NOVIKOV<span class="brand-trade"> TRADE</span><small>ADMINISTRATOR CONSOLE</small></span>
    </div>
    <?php
}

function nt_document_start(string $title, string $base): void
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">
        <meta name="robots" content="noindex, nofollow">
        <title><?= nt_e($title) ?> · NOVIKOV TRADE</title>
        <link rel="stylesheet" href="<?= nt_e(nt_url($base, 'assets/bootstrap.min.css')) ?>">
        <link rel="stylesheet" href="<?= nt_e(nt_url($base, 'assets/console.css')) ?>">
    </head>
    <body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <?php
}

function nt_nav_routes(): array
{
    return [
        '' => ['Overview', 'grid'],
        'users' => ['Users', 'identity'],
        'packages' => ['Packages', 'grid'],
        'investments' => ['Investments', 'chain'],
        'deposits' => ['Deposits', 'arrow'],
        'withdrawals' => ['Withdrawals', 'arrow'],
        'transactions' => ['Transactions', 'audit'],
        'referrals' => ['Referrals', 'chain'],
        'reports' => ['Reports', 'audit'],
        'settings' => ['Settings', 'key'],
        'staff' => ['Staff', 'lock'],
        'health' => ['Health', 'shield'],
    ];
}

function nt_page_label(string $page): string
{
    if ($page === 'audit') {
        return 'Audit trail';
    }
    $routes = nt_nav_routes();
    return $routes[$page][0] ?? 'Overview';
}

function nt_workspace_start(string $page, ?array $admin, string $csrf, string $base, bool $canAudit): void
{
    $current = ($page === 'dashboard') ? '' : $page;
    nt_document_start($page === 'dashboard' || $page === '' ? 'Operations overview' : nt_page_label($page), $base);
    ?>
    <div class="app-shell">
        <aside class="sidebar" aria-label="Console navigation">
            <?php nt_brand(); ?>
            <div class="nav-caption eyebrow">Workspace</div>
            <nav class="nav-items" aria-label="Main navigation">
                <?php foreach (nt_nav_routes() as $route => [$label, $icon]): $active = $current === $route; ?>
                <a class="nav-item<?= $active ? ' active' : '' ?>" href="<?= nt_e(nt_url($base, $route)) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= nt_icon($icon) ?><?= nt_e($label) ?></a>
                <?php endforeach; ?>
                <?php if ($canAudit): ?>
                <a class="nav-item<?= $page === 'audit' ? ' active' : '' ?>" href="<?= nt_e(nt_url($base, 'audit')) ?>"<?= $page === 'audit' ? ' aria-current="page"' : '' ?>><?= nt_icon('audit') ?>Audit trail</a>
                <?php endif; ?>
            </nav>
            <div class="sidebar-bottom">
                <div class="environment">
                    <div class="eyebrow">Permitted network</div>
                    <p>BSC testnet <span class="mono">/ 97</span></p>
                    <small>Test environment only</small>
                </div>
                <p class="sidebar-note">Audited operations.<br>BSC testnet only.</p>
            </div>
        </aside>
        <div class="workspace">
            <header class="topbar">
                <div class="topbar-title">Workspace <span>/</span> <?= nt_e(nt_page_label($current === '' ? '' : $page)) ?></div>
                <div class="account">
                    <div class="account-copy">
                        <strong><?= nt_e($admin['email'] ?? 'Administrator') ?></strong>
                        <small><?= nt_e(str_replace('_', ' ', (string) ($admin['role'] ?? ''))) ?></small>
                    </div>
                    <form action="<?= nt_e(nt_url($base, 'logout')) ?>" method="post">
                        <input type="hidden" name="csrf" value="<?= nt_e($csrf) ?>">
                        <button type="submit" class="signout">Sign out</button>
                    </form>
                </div>
            </header>
            <main id="main-content" class="main-content">
    <?php
}

function nt_workspace_end(): void
{
    ?>
                <footer class="workspace-footer">
                    <span>NOVIKOV TRADE · Administrator workspace</span>
                    <span class="mono">FOUNDATION / NO REAL-MONEY OPERATIONS</span>
                </footer>
            </main>
        </div>
    </div>
    </body>
    </html>
    <?php
}

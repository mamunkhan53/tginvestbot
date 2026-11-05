<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';

$base = (string) ($base ?? '');
$csrf = (string) ($csrf ?? '');
$error = $error ?? null;
nt_document_start('Administrator sign in', $base);
?>
<div class="login-shell">
    <section class="login-story" aria-label="About this console">
        <?php nt_brand(); ?>
        <div class="login-narrative">
            <span class="eyebrow">Control before capital.</span>
            <h1>A clear view.<br>A defined boundary.</h1>
            <p>The administrator workspace for NOVIKOV TRADE. Review the foundation, inspect activity, and keep financial operations out of scope.</p>
            <div class="login-safety">
                <strong><?= nt_icon('shield') ?> Safety is the starting point.</strong>
                <p>BSC testnet 97 only. Real-money deposits, investments and withdrawals are disabled. This console must not accept funds.</p>
            </div>
        </div>
        <div class="login-story-footer">
            <span class="mono">BSC TESTNET / CHAIN 97</span>
            <span>Foundation release</span>
        </div>
    </section>
    <div class="login-form-side">
        <div class="login-top-note"><?= nt_icon('lock') ?> Restricted administrator access</div>
        <main id="main-content" class="login-form-wrap">
            <div class="eyebrow muted">Administrator access</div>
            <h2>Sign in to the console.</h2>
            <p class="login-intro">Use your provisioned administrator account.</p>
            <?php if ($error !== null && $error !== ''): ?>
                <div class="error-notice" role="alert"><?= nt_e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= nt_e(nt_url($base, 'login')) ?>">
                <input type="hidden" name="csrf" value="<?= nt_e($csrf) ?>">
                <div class="mb-4">
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control" id="email" name="email" type="email" autocomplete="username" inputmode="email" maxlength="254" placeholder="you@company.com" required spellcheck="false" autocapitalize="none">
                </div>
                <div>
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required aria-describedby="password-help">
                </div>
                <p class="form-help" id="password-help">Credentials are managed by your system administrator.</p>
                <button type="submit" class="btn btn-primary w-100">Sign in <?= nt_icon('arrow') ?></button>
            </form>
            <div class="login-access-note">
                <?= nt_icon('shield') ?>
                <p>No public registration. Access is limited to approved administrators. Lost your authenticator? Use a recovery code after your password. For complete account recovery, contact your system administrator, then <a href="<?= nt_e(nt_url($base, 'recovery')) ?>">redeem an operator recovery authorization</a>.</p>
            </div>
        </main>
        <footer class="login-footer">Test environment only. No real-money services are available.</footer>
    </div>
</div>
</body>
</html>

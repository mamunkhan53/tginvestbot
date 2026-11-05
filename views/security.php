<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
nt_document_start('Administrator security', $base);
?>
<main class="login-form-wrap" id="main-content">
    <?php nt_brand(); ?>
    <h1><?= $page === 'mfa' ? 'Two-step verification' : ($page === 'recovery' ? 'Account recovery' : 'Save your recovery codes') ?></h1>
    <p>Test environment only. Real-money operations remain disabled.</p>
    <?php if ($error): ?><div class="error-notice" role="alert"><?= nt_e($error) ?></div><?php endif; ?>
    <?php if ($page === 'recovery-codes'): ?>
        <p>Each code can be used once with your password if your authenticator is unavailable. Store these in a private password manager. They are shown only once.</p>
        <?php if (!$codes): ?><p>Codes are no longer available to display.</p><?php endif; ?>
        <ul><?php foreach ($codes as $code): ?><li><code><?= nt_e($code) ?></code></li><?php endforeach; ?></ul>
        <a class="btn btn-primary" href="<?= nt_e(nt_url($base, '')) ?>">Continue to console</a>
    <?php else: ?>
        <form method="post" action="<?= nt_e(nt_url($base, $page)) ?>">
            <input type="hidden" name="csrf" value="<?= nt_e($_SESSION['csrf']) ?>">
            <?php if ($page === 'mfa'): ?>
                <?php if (isset($_SESSION['enroll_secret'])): ?>
                    <p>Add a new account in your authenticator using this setup key. Choose time-based codes (SHA-1, 6 digits, 30 seconds). Enrollment must be confirmed within five minutes.</p>
                    <p><strong>Setup key:</strong> <code><?= nt_e($_SESSION['enroll_secret']) ?></code></p>
                <?php else: ?>
                    <p>Enter your current authenticator code or one unused recovery code. This sign-in challenge expires after five minutes.</p>
                <?php endif; ?>
                <label for="code" class="form-label">Verification code</label>
                <input id="code" class="form-control" name="code" autocomplete="one-time-code" maxlength="64" required>
            <?php else: ?>
                <p>Contact your trusted operator first. After verifying your identity outside this website, they can provide a single-use authorization valid for 15 minutes. Recovery revokes all sessions and requires a new authenticator enrollment.</p>
                <label for="email" class="form-label">Email</label>
                <input id="email" class="form-control" name="email" type="email" autocomplete="username" maxlength="254" required>
                <label for="token" class="form-label">Operator authorization</label>
                <input id="token" class="form-control" name="token" type="password" autocomplete="off" maxlength="64" required>
                <label for="password" class="form-label">New password (at least 16 characters)</label>
                <input id="password" class="form-control" name="password" type="password" autocomplete="new-password" minlength="16" maxlength="1024" required>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Verify and continue</button>
        </form>
        <p><a href="<?= nt_e(nt_url($base, 'login')) ?>">Return to sign in</a></p>
    <?php endif; ?>
</main>
</body></html>

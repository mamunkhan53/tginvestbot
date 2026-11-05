<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

// No authorization tokens in command arguments, logs, URLs or environment variables.
if (PHP_SAPI !== 'cli' || !function_exists('stream_isatty') || !stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
    fwrite(STDERR, "Recovery requires a private interactive operator terminal.\n");
    exit(1);
}
try {
    echo "Verify the administrator identity through your approved independent channel first.\nAccount email: ";
    $email = trim((string)fgets(STDIN));
    echo "Identity-verification ticket/reference (letters, digits, _ or - only): ";
    $reference = trim((string)fgets(STDIN));
    echo "All sessions will be revoked immediately and sign-in suspended until recovery completes.\nType AUTHORIZE to proceed: ";
    if (trim((string)fgets(STDIN)) !== 'AUTHORIZE') exit(1);
    $auth = new Novikov\AdminAuth(Novikov\Database::connect(), getenv('SESSION_SECRET') ?: '');
    $token = $auth->mfa()->issueReset($email, $reference);
    echo "Deliver privately to the verified account owner. Valid once for 15 minutes:\n$token\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Recovery authorization failed. Check account, reference and secure configuration.\n");
    exit(1);
}

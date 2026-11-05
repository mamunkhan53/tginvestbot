<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';

$page = ($page ?? 'dashboard') === 'audit' ? 'audit' : 'dashboard';
$base = (string) ($base ?? '');
$csrf = (string) ($csrf ?? '');
$admin = $admin ?? null;
$canAudit = (bool) ($canAudit ?? false);
$error = $error ?? null;
$logs = is_array($logs ?? null) ? $logs : [];
$overview = is_array($overview ?? null) ? $overview : [];
$metrics = is_array($overview['metrics'] ?? null) ? $overview['metrics'] : [];
$jobs = is_array($overview['jobs'] ?? null) ? $overview['jobs'] : [];
$integrations = is_array($overview['integrations'] ?? null) ? $overview['integrations'] : [];
$statusClass = static function (string $v): string {
    $v = strtolower($v);
    if (preg_match('/(fail|error|down|stale|disabled|not connected|missing)/', $v)) return ' bad';
    if (preg_match('/(pending|running|degraded|warn|delayed|unknown)/', $v)) return ' warning';
    if (preg_match('/(ok|healthy|success|succeeded|connected|configured|enabled|active)/', $v)) return ' good';
    return ' off';
};
nt_workspace_start($page, $admin, $csrf, $base, $canAudit);
?>
<div class="page-heading">
    <div>
        <div class="eyebrow muted"><?= $page === 'audit' ? 'Accountability / Access history' : 'Operations / Environment' ?></div>
        <h1><?= $page === 'audit' ? 'Audit trail' : 'Operations overview' ?></h1>
        <p><?= $page === 'audit' ? 'Recorded administrator activity, as supplied by the application.' : 'Platform metrics, scheduled job health and reported integration status.' ?></p>
    </div>
    <span class="status warning">Testnet only</span>
</div>
<?php if ($error !== null && $error !== ''): ?>
    <div class="error-notice" role="alert">
        <?= nt_e($error) ?>
        <a class="d-block mt-2" href="<?= nt_e(nt_url($base, $page === 'audit' ? 'audit' : '')) ?>">Reload this page</a>
    </div>
<?php endif; ?>

<?php if ($page === 'audit'): ?>
    <section class="panel" aria-labelledby="audit-title">
        <div class="panel-header">
            <h2 id="audit-title">Administrator activity</h2>
            <?php if ($canAudit): ?><span class="section-index"><?= nt_e(count($logs)) ?> displayed</span><?php endif; ?>
        </div>
        <?php if (!$canAudit): ?>
            <div class="empty-state">
                <div class="empty-icon"><?= nt_icon('lock') ?></div>
                <h2>Restricted to authorized roles.</h2>
                <p>Your account does not have permission to view the audit trail. Contact your system administrator if access is required.</p>
                <a class="text-link" href="<?= nt_e(nt_url($base, '')) ?>">Return to overview <?= nt_icon('arrow') ?></a>
            </div>
        <?php elseif ($logs === []): ?>
            <div class="empty-state">
                <div class="empty-icon"><?= nt_icon('audit') ?></div>
                <h2><?= $error ? 'Activity could not be loaded.' : 'No activity to display.' ?></h2>
                <p><?= $error ? 'Use the reload link above to try again. No sample records are shown.' : 'No audit records were returned. This view displays recorded events only; it does not generate sample activity.' ?></p>
                <a class="text-link" href="<?= nt_e(nt_url($base, '')) ?>">Return to overview <?= nt_icon('arrow') ?></a>
            </div>
        <?php else: ?>
            <div class="table-responsive" role="region" aria-label="Administrator activity records" tabindex="0">
                <table class="table">
                    <caption>Recorded timestamps are shown as stored. Displayed records are not a completeness or immutability guarantee.</caption>
                    <thead><tr><th scope="col">Event ID</th><th scope="col">Administrator</th><th scope="col">Action</th><th scope="col">Reason</th><th scope="col">Recorded at</th></tr></thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="mono"><?= nt_e($log['id'] ?? '') ?></td>
                                <td><?= nt_e($log['email'] ?? '') ?></td>
                                <td class="mono"><?= nt_e($log['action'] ?? '') ?></td>
                                <td><?= nt_e($log['reason'] ?? '') ?></td>
                                <td class="mono text-nowrap"><?= nt_e($log['created_at'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <p class="audit-note">Read-only view. Real-money operations remain disabled. Audit access is subject to server-side authorization.</p>
<?php else: ?>
    <div class="safety-notice" role="note" aria-label="Real-money operations disabled">
        <?= nt_icon('alert') ?>
        <div>
            <strong>Testnet only. Real money is disabled.</strong>
            <p>All deposits, investments and withdrawals run against BSC testnet 97. Do not accept real funds through this platform.</p>
        </div>
    </div>
    <?php if ($metrics !== []): ?>
        <dl class="ops-metrics" aria-label="Platform metrics">
            <?php foreach ($metrics as $label => $value): ?>
                <div class="ops-metric"><dt><?= nt_e($label) ?></dt><dd><?= nt_e(is_scalar($value) ? $value : '') ?></dd></div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
    <div class="overview-grid">
        <section class="panel" aria-labelledby="jobs-title">
            <div class="panel-header"><h2 id="jobs-title">Job health</h2><span class="section-index">01 · <?= nt_e(count($jobs)) ?> jobs</span></div>
            <?php if ($jobs === []): ?>
                <div class="empty-state">
                    <div class="empty-icon"><?= nt_icon($error ? 'alert' : 'check') ?></div>
                    <h2><?= $error ? 'Job status could not be loaded.' : 'No job runs recorded.' ?></h2>
                    <p><?= $error ? 'Use the reload link above to try again. No sample job data is shown.' : 'Scheduled jobs report here after their first run. Nothing is assumed healthy until it reports.' ?></p>
                    <a class="text-link" href="<?= nt_e(nt_url($base, 'health')) ?>">Open health <?= nt_icon('arrow') ?></a>
                </div>
            <?php else: ?>
                <div class="table-responsive" role="region" aria-labelledby="jobs-title" tabindex="0">
                    <table class="table ops-table">
                        <caption>Job state as recorded at load time.</caption>
                        <thead><tr><th scope="col">Job</th><th scope="col">Status</th><th scope="col">Last succeeded</th><th scope="col">Summary</th></tr></thead>
                        <tbody>
                            <?php foreach ($jobs as $job): $job = is_array($job) ? $job : []; $st = (string) ($job['status'] ?? ''); $last = (string) ($job['last_succeeded_at'] ?? ''); $sum = (string) ($job['summary'] ?? ''); ?>
                                <tr>
                                    <td class="mono"><?= nt_e($job['name'] ?? '') ?></td>
                                    <td><span class="status<?= $statusClass($st) ?>"><?= nt_e($st !== '' ? $st : 'Unknown') ?></span></td>
                                    <td class="mono text-nowrap"><?= $last !== '' ? nt_e($last) : '<span class="ops-null">Never</span>' ?></td>
                                    <td class="ops-text"><?= $sum !== '' ? nt_e($sum) : '<span class="ops-null" aria-label="No value">—</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <section class="panel network-panel" aria-labelledby="network-title">
            <span class="eyebrow">02 / Network boundary</span>
            <h2 id="network-title">Testnet. Nothing else.</h2>
            <p>BSC testnet 97 only. Mainnet and real-money settlement are not enabled.</p>
            <dl class="network-meta">
                <div><dt>PERMITTED CHAIN</dt><dd class="mono">97</dd></div>
                <div><dt>NETWORK</dt><dd>BSC testnet</dd></div>
                <div><dt>MAINNET</dt><dd>Not enabled</dd></div>
            </dl>
        </section>
    </div>
    <div class="lower-grid">
        <section class="panel" aria-labelledby="integrations-title">
            <div class="panel-header"><h2 id="integrations-title">Integrations</h2><span class="section-index">03</span></div>
            <?php if ($integrations === []): ?>
                <div class="empty-state">
                    <div class="empty-icon"><?= nt_icon('chain') ?></div>
                    <h2>No integration status reported.</h2>
                    <p>Connectivity is shown only when the application reports it.</p>
                </div>
            <?php else: foreach ($integrations as $label => $st): $st = is_scalar($st) ? (string) $st : ''; ?>
                <div class="integration-row">
                    <span class="integration-icon"><?= nt_icon('chain') ?></span>
                    <div><h3><?= nt_e($label) ?></h3></div>
                    <span class="status<?= $statusClass($st) ?>"><?= nt_e($st !== '' ? $st : 'Unknown') ?></span>
                </div>
            <?php endforeach; endif; ?>
        </section>
        <section class="panel boundaries" aria-labelledby="scope-title">
            <div class="eyebrow muted mb-3">04 / Modules</div>
            <h2 id="scope-title">Operator workspace.</h2>
            <p>Packages, funding-checked investments, deposits, withdrawals, referrals, staff and settings are managed from the navigation.</p>
            <div class="boundary-item"><?= nt_icon('lock') ?><span>Actions are authorized server-side per role.</span></div>
            <div class="boundary-item"><?= nt_icon('audit') ?><span>Administrative changes are recorded in the audit trail.</span></div>
            <div class="boundary-item"><?= nt_icon('shield') ?><span>Security, custody and compliance review are required before any real-money service.</span></div>
            <?php if ($canAudit): ?>
                <a class="text-link" href="<?= nt_e(nt_url($base, 'audit')) ?>">Review audit trail <?= nt_icon('arrow') ?></a>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
<?php nt_workspace_end(); ?>

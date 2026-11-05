<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';

$page = (string) ($page ?? '');
$base = (string) ($base ?? '');
$csrf = (string) ($csrf ?? '');
$admin = $admin ?? null;
$canAudit = (bool) ($canAudit ?? false);
$error = $error ?? null;
$notice = $notice ?? null;
$data = is_array($data ?? null) ? $data : [];

$title = (string) ($data['title'] ?? nt_page_label($page));
$description = (string) ($data['description'] ?? '');
$columns = is_array($data['columns'] ?? null) ? $data['columns'] : [];
$rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
$metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
$forms = is_array($data['forms'] ?? null) ? $data['forms'] : [];
$search = (string) ($data['search'] ?? '');
$current = max(1, (int) ($data['page'] ?? 1));
$hasNext = (bool) ($data['has_next'] ?? false);
$routeUrl = nt_url($base, $page);
$canExport = in_array($page, ['reports', 'transactions'], true);
$exportQuery = ['export' => 'csv'];
if ($search !== '') { $exportQuery['q'] = $search; }
$exportQuery['p'] = $current;
$exportUrl = $routeUrl . '?' . http_build_query($exportQuery);

$pageLink = static function (int $p) use ($routeUrl, $search): string {
    $q = ['p' => $p];
    if ($search !== '') {
        $q = ['q' => $search] + $q;
    }
    return $routeUrl . '?' . http_build_query($q);
};

$isMoney = static function (string $key): bool {
    return (bool) preg_match('/(amount|balance|total|fee|profit|principal|price|sum|payout|bonus|commission|value|usdt|bnb)/i', $key);
};
$isStatus = static function (string $key): bool {
    return (bool) preg_match('/(status|state)$/i', $key);
};
$isMono = static function (string $key): bool {
    return (bool) preg_match('/(^id$|_id$|hash|tx|address|wallet|_at$|date|time|code|ref)/i', $key);
};
$statusClass = static function (string $v): string {
    $v = strtolower($v);
    if (preg_match('/(fail|reject|denied|error|cancel|block|suspend|down|disabled)/', $v)) return ' bad';
    if (preg_match('/(pending|review|hold|queue|processing|await|degraded|warn)/', $v)) return ' warning';
    if (preg_match('/(complete|confirm|approved|active|paid|ok|healthy|success|enabled|settled)/', $v)) return ' good';
    return ' off';
};

nt_workspace_start($page, $admin, $csrf, $base, $canAudit);
?>
<div class="page-heading">
    <div>
        <div class="eyebrow muted">Operations / <?= nt_e(nt_page_label($page)) ?></div>
        <h1><?= nt_e($title) ?></h1>
        <?php if ($description !== ''): ?><p><?= nt_e($description) ?></p><?php endif; ?>
    </div>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="error-notice" role="alert">
        <?= nt_e($error) ?>
        <a class="d-block mt-2" href="<?= nt_e($routeUrl) ?>">Reload this page</a>
    </div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="ops-notice" role="status"><?= nt_e($notice) ?></div>
<?php endif; ?>

<?php if ($metrics !== []): ?>
    <dl class="ops-metrics" aria-label="<?= nt_e($title) ?> summary">
        <?php foreach ($metrics as $label => $value): ?>
            <div class="ops-metric"><dt><?= nt_e($label) ?></dt><dd><?= nt_e($value) ?></dd></div>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>

<section class="panel" aria-labelledby="records-title">
    <div class="panel-header">
        <h2 id="records-title">Records</h2>
        <span class="section-index">Page <?= nt_e($current) ?> · <?= nt_e(count($rows)) ?> shown</span>
    </div>
    <div class="ops-toolbar">
        <form class="ops-search" method="get" action="<?= nt_e($routeUrl) ?>" role="search">
            <div class="flex-grow-1">
                <label for="ops-q">Search records</label>
                <input id="ops-q" class="form-control" type="search" name="q" value="<?= nt_e($search) ?>" maxlength="200">
            </div>
            <button class="ops-btn" type="submit">Search</button>
            <?php if ($search !== ''): ?><a class="ops-btn ghost" href="<?= nt_e($routeUrl) ?>">Clear</a><?php endif; ?>
        </form>
        <?php if ($canExport && $rows !== [] && $columns !== []): ?>
            <a class="ops-btn ghost" href="<?= nt_e($exportUrl) ?>" rel="nofollow">Export this page CSV</a>
        <?php endif; ?>
    </div>

    <?php if ($rows === [] || $columns === []): ?>
        <div class="empty-state">
            <div class="empty-icon"><?= nt_icon($error ? 'alert' : 'audit') ?></div>
            <h2><?= $error ? 'Records could not be loaded.' : ($search !== '' ? 'No records match this search.' : 'No records to display.') ?></h2>
            <p><?= $error ? 'Use the reload link above to try again. No sample records are shown.' : ($search !== '' ? 'Nothing matched “' . nt_e($search) . '”. Clear the search to see all records.' : 'The application returned no records for this view. Only stored data is displayed.') ?></p>
            <?php if ($search !== ''): ?><a class="text-link" href="<?= nt_e($routeUrl) ?>">Clear search <?= nt_icon('arrow') ?></a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive" role="region" aria-labelledby="records-title" tabindex="0">
            <table class="table ops-table">
                <caption>Amounts are shown exactly as stored. Statuses reflect the recorded state at load time.</caption>
                <thead><tr>
                    <?php foreach ($columns as $key => $label): ?>
                        <th scope="col"<?= $isMoney((string) $key) ? ' class="ops-money"' : '' ?>><?= nt_e($label) ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): $row = is_array($row) ? $row : []; ?>
                        <tr>
                            <?php foreach ($columns as $key => $label):
                                $key = (string) $key;
                                $raw = $row[$key] ?? null;
                                if (is_bool($raw)) { $raw = $raw ? 'Yes' : 'No'; }
                                elseif (is_array($raw)) { $raw = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
                                $val = $raw === null ? '' : (string) $raw;
                            ?>
                                <?php if ($val === ''): ?>
                                    <td><span class="ops-null" aria-label="No value">—</span></td>
                                <?php elseif ($isStatus($key)): ?>
                                    <td><span class="status<?= $statusClass($val) ?>"><?= nt_e($val) ?></span></td>
                                <?php elseif ($isMoney($key)): ?>
                                    <td class="ops-money"><?= nt_e($val) ?></td>
                                <?php elseif ($isMono($key)): ?>
                                    <td class="mono"><?= nt_e($val) ?></td>
                                <?php else: ?>
                                    <td class="ops-text"><?= nt_e($val) ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($current > 1 || $hasNext): ?>
        <nav class="ops-pager" aria-label="Pagination">
            <span class="mono">Page <?= nt_e($current) ?></span>
            <div>
                <?php if ($current > 1): ?>
                    <a class="ops-btn ghost" href="<?= nt_e($pageLink($current - 1)) ?>" rel="prev">Previous</a>
                <?php else: ?>
                    <span class="ops-btn ghost" aria-disabled="true">Previous</span>
                <?php endif; ?>
                <?php if ($hasNext): ?>
                    <a class="ops-btn ghost" href="<?= nt_e($pageLink($current + 1)) ?>" rel="next">Next</a>
                <?php else: ?>
                    <span class="ops-btn ghost" aria-disabled="true">Next</span>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>

<?php if ($forms !== []): ?>
    <div class="ops-forms">
        <?php foreach ($forms as $fi => $form):
            if (!is_array($form)) continue;
            $fid = 'f' . (int) $fi;
            $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
        ?>
            <section class="panel ops-form" aria-labelledby="<?= $fid ?>-title">
                <h2 id="<?= $fid ?>-title"><?= nt_e($form['title'] ?? '') ?></h2>
                <form method="post" action="<?= nt_e($routeUrl) ?>">
                    <input type="hidden" name="csrf" value="<?= nt_e($csrf) ?>">
                    <input type="hidden" name="action" value="<?= nt_e($form['action'] ?? '') ?>">
                    <?php foreach ($fields as $fj => $field):
                        if (!is_array($field)) continue;
                        $name = (string) ($field['name'] ?? '');
                        $type = (string) ($field['type'] ?? 'text');
                        $id = $fid . '-' . (int) $fj;
                        $req = !empty($field['required']);
                        $value = (string) ($field['value'] ?? '');
                        $reqAttr = $req ? ' required aria-required="true"' : '';
                        $label = nt_e($field['label'] ?? $name) . ($req ? '<span class="ops-req" aria-hidden="true">*</span>' : '');
                    ?>
                        <div class="ops-field">
                            <?php if ($type === 'checkbox'): ?>
                                <label class="ops-check" for="<?= $id ?>">
                                    <input type="hidden" name="<?= nt_e($name) ?>" value="0">
                                    <input id="<?= $id ?>" type="checkbox" name="<?= nt_e($name) ?>" value="1"<?= in_array($value, ['1', 'true', 'on', 'yes'], true) ? ' checked' : '' ?><?= $reqAttr ?>>
                                    <span><?= $label ?></span>
                                </label>
                            <?php else: ?>
                                <label class="form-label" for="<?= $id ?>"><?= $label ?></label>
                                <?php if ($type === 'textarea'): ?>
                                    <textarea id="<?= $id ?>" class="form-control" name="<?= nt_e($name) ?>" rows="3"<?= $reqAttr ?>><?= nt_e($value) ?></textarea>
                                <?php elseif ($type === 'select'): ?>
                                    <select id="<?= $id ?>" class="form-select" name="<?= nt_e($name) ?>"<?= $reqAttr ?>>
                                        <?php foreach ((is_array($field['options'] ?? null) ? $field['options'] : []) as $ov => $ol): ?>
                                            <option value="<?= nt_e($ov) ?>"<?= (string) $ov === $value ? ' selected' : '' ?>><?= nt_e($ol) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else:
                                    $inputType = in_array($type, ['number', 'password'], true) ? $type : 'text';
                                    $extra = '';
                                    foreach (['step', 'min', 'max'] as $a) {
                                        if ($inputType === 'number' && isset($field[$a]) && $field[$a] !== '') {
                                            $extra .= ' ' . $a . '="' . nt_e($field[$a]) . '"';
                                        }
                                    }
                                ?>
                                    <input id="<?= $id ?>" class="form-control<?= $inputType === 'number' ? ' mono' : '' ?>" type="<?= $inputType ?>" name="<?= nt_e($name) ?>"<?= $inputType !== 'password' ? ' value="' . nt_e($value) . '"' : '' ?><?= $extra ?><?= $inputType === 'number' ? ' inputmode="decimal"' : '' ?><?= $inputType === 'password' ? ' autocomplete="new-password"' : '' ?><?= $reqAttr ?>>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!empty($form['warning'])): ?>
                        <div class="ops-warning" role="note"><?= nt_e($form['warning']) ?></div>
                    <?php endif; ?>
                    <button type="submit" class="ops-btn"><?= nt_e($form['submit'] ?? 'Submit') ?></button>
                </form>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php nt_workspace_end(); ?>

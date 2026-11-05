<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Novikov\Database;
use Novikov\Telegram\Inbox;
use Novikov\Telegram\Menu;

$checks = 0;
function verify(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}

try {
    $db = Database::connect();
    // Shadow application tables with connection-local fixtures. Foreign-key
    // behavior is covered separately; no test users reach persistent tables.
    foreach (['users','ledger_accounts','ledger_journals','ledger_entries','telegram_updates','telegram_outbox'] as $table) {
        $row = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $ddl = preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$row[1]);
        $ddl = preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n/m','',$ddl);
        $ddl = preg_replace('/,\s*\)/',"\n)",$ddl);
        $db->exec($ddl);
    }
    $menu = new Menu($db);
    $inbox = new Inbox($db);
    $update = [
        'update_id'=>100,'telegram_id'=>101,'first_name'=>'Test-only',
        'last_name'=>null,'username'=>null,'text'=>'/start',
        'message_id'=>1,'callback_id'=>null,
    ];
    verify($inbox->accept($update), 'first update stored');
    verify(!$inbox->accept($update), 'duplicate update ignored');
    $db->beginTransaction();
    $home = $menu->respond($update);
    $db->commit();
    verify(str_contains($home['text'],'NOVIKOV TRADE'), 'start screen');
    $first = $db->query('SELECT * FROM users WHERE telegram_id=101')->fetch();
    verify($first !== false, 'user registered');
    $db->beginTransaction();
    $menu->respond($update);
    $db->commit();
    verify((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1,'registration idempotent');
    $second = $update;
    $second['telegram_id'] = 102;
    $second['text'] = '/start ref_' . $first['referral_code'];
    $db->beginTransaction();
    $menu->respond($second);
    $db->commit();
    verify((int)$db->query('SELECT referrer_id FROM users WHERE telegram_id=102')->fetchColumn() === (int)$first['id'],'referral assigned');
    $secondUser = $db->query('SELECT * FROM users WHERE telegram_id=102')->fetch();
    $third = $update; $third['telegram_id'] = 103;
    $third['text'] = '/start ref_' . $secondUser['referral_code'];
    $db->beginTransaction(); $menu->respond($third); $db->commit();
    $replay = $update; $replay['text'] = '/start ref_' . $secondUser['referral_code'];
    $db->beginTransaction(); $menu->respond($replay); $db->commit();
    verify($db->query('SELECT referrer_id FROM users WHERE telegram_id=101')->fetchColumn() === null,'referrer immutable on replay');
    $callback = $update; $callback['callback_id'] = 'test-callback'; $callback['text'] = 'referrals';
    $db->beginTransaction(); $screen = $menu->respond($callback); $db->commit();
    verify(str_contains($screen['text'],"L1: 1\nL2: 1"),'two-level counts');
    $callback['text'] = 'cabinet';
    $db->beginTransaction(); $screen = $menu->respond($callback); $db->commit();
    verify(str_contains($screen['text'],'0.000000000000000000 USDT'),'new account zero ledger balance');
    verify(!isset($screen['parse_mode']),'plain-text user data');
    $callback['text'] = 'history:0';
    $db->beginTransaction(); $screen = $menu->respond($callback); $db->commit();
    verify(str_contains($screen['text'],'No transactions yet.'),'empty history');
    $callback['text'] = 'cabinet:102';
    $db->beginTransaction(); $screen = $menu->respond($callback); $db->commit();
    verify(str_contains($screen['text'],'unavailable'),'manipulated account callback rejected');
    $db->exec("UPDATE users SET status='suspended' WHERE telegram_id=101");
    $callback['text'] = 'cabinet';
    $db->beginTransaction(); $screen = $menu->respond($callback); $db->commit();
    verify(str_contains($screen['text'],'restricted'),'suspension enforced');
    verify((int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn() === 0,'menus never create funds');
    $fake = new class implements \Novikov\Telegram\Api {
        public array $calls = [];
        public string $state = 'sent';
        public function call(string $method, array $payload): array {
            $this->calls[] = $method;
            return ['state'=>$this->state,'code'=>$this->state === 'retry' ? 429 : 200,'delay'=>60];
        }
    };
    $worker = new \Novikov\Telegram\Worker($db,$fake);
    $result = $worker->run();
    verify($result['processed'] === 1 && $result['sent'] === 1,'worker processes durable update and response');
    $worker->run();
    verify(count($fake->calls) === 1,'worker replay sends no duplicate');
    $next = $update; $next['update_id'] = 200; $next['telegram_id'] = 104;
    $inbox->accept($next); $fake->state = 'retry';
    $worker->run();
    verify($db->query('SELECT status FROM telegram_outbox WHERE update_id=200')->fetchColumn() === 'pending','rate limit schedules retry');
    verify((int)$db->query('SELECT available_at>UTC_TIMESTAMP(6) FROM telegram_outbox WHERE update_id=200')->fetchColumn() === 1,'retry waits before resend');
    $next['update_id'] = 201; $inbox->accept($next); $fake->state = 'uncertain';
    $worker->run();
    verify($db->query('SELECT status FROM telegram_outbox WHERE update_id=201')->fetchColumn() === 'uncertain','uncertain outcome retained');
    $calls = count($fake->calls);
    $worker->run();
    verify(count($fake->calls) === $calls,'uncertain send not retried');
    $db->exec("UPDATE telegram_outbox SET status='sending' WHERE update_id=201");
    $worker->run();
    verify($db->query('SELECT status FROM telegram_outbox WHERE update_id=201')->fetchColumn() === 'uncertain','crashed send quarantined');
    $next['update_id'] = 202; $next['callback_id'] = 'test-callback'; $next['text'] = 'cabinet';
    $inbox->accept($next); $fake->state = 'sent';
    $worker->run();
    verify(array_slice($fake->calls,-2) === ['answerCallbackQuery','editMessageText'],'callbacks acknowledge and edit existing message');
    verify((int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn() === 0,'worker never creates funds');
    echo "$checks Telegram database checks passed using temporary tables. No live Telegram messages sent.\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $driver = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
    fwrite(STDERR,'Telegram database check failed: ' . get_class($e) . " (driver $driver). Sensitive details suppressed.\n");
    exit(1);
}

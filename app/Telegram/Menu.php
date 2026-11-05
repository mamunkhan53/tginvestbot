<?php
declare(strict_types=1);
namespace Novikov\Telegram;

use PDO;

final class Menu
{
    private array $copy;

    public function __construct(private PDO $db)
    {
        $this->copy = require dirname(__DIR__, 2) . '/translations/en.php';
    }

    /** Called inside the worker's transaction; never writes financial records. */
    public function respond(array $update): array
    {
        $q = $this->db->prepare('SELECT * FROM users WHERE telegram_id = ? FOR UPDATE');
        $q->execute([$update['telegram_id']]);
        $user = $q->fetch();
        $isStart = preg_match('/^\/start(?:@[A-Za-z0-9_]+)?(?:\s+ref_([A-Za-z0-9_-]{1,64}))?\s*$/D', $update['text'], $match) === 1;
        if (!$user && !$isStart) return $this->screen($this->copy['start_first'], []);
        if (!$user) {
            $referrer = null;
            if (!empty($match[1])) {
                $q = $this->db->prepare("SELECT id FROM users WHERE referral_code = ? AND telegram_id <> ? AND status = 'active'");
                $q->execute([$match[1], $update['telegram_id']]);
                $referrer = $q->fetchColumn() ?: null;
            }
            $q = $this->db->prepare('INSERT INTO users (telegram_id,username,first_name,last_name,referral_code,referrer_id) VALUES (?,?,?,?,?,?)');
            $q->execute([$update['telegram_id'],$update['username'],$update['first_name'],$update['last_name'],bin2hex(random_bytes(16)),$referrer]);
            $q = $this->db->prepare('SELECT * FROM users WHERE id = ?');
            $q->execute([$this->db->lastInsertId()]);
            $user = $q->fetch();
        }
        if ($user['status'] !== 'active') return $this->screen($this->copy['restricted'], []);
        $action = $isStart ? 'home' : $update['text'];
        if ($update['callback_id'] === null && !$isStart && $action !== '/help') $action = 'unknown';
        $back = [[$this->button('back','home')]];
        if ($action === 'home' || $action === '/help') {
            return $this->screen($this->copy['welcome'], [
                [$this->button('portfolio','portfolio')],[$this->button('cabinet','cabinet')],
            ]);
        }
        if ($action === 'portfolio') {
            return $this->screen($this->copy['portfolio'] . "\n\n" . $this->balanceText((int)$user['id'])
                . "\n\nBSC testnet only. Operations require explicit enablement and account review.", [
                    [['text'=>'Activate package','callback_data'=>'fin:packages'],['text'=>'My packages','callback_data'=>'fin:mine:0']],
                    [['text'=>'Portfolio analytics','callback_data'=>'fin:analytics'],['text'=>'Calculation examples','callback_data'=>'fin:examples']],
                    [['text'=>'How to start','callback_data'=>'fin:help']],
                    [$this->button('history','history:0')],[$this->button('referrals','referrals')],...$back,
                ]);
        }
        if ($action === 'cabinet') {
            return $this->screen($this->copy['cabinet'] . "\n\nID: " . $user['telegram_id']
                . "\nUser: " . ($user['username'] ? '@' . $user['username'] : $user['first_name'])
                . "\n\n" . $this->balanceText((int)$user['id'])
                . "\n\n" . $this->copy['wallet_unconfigured'], [
                    [['text'=>'Top up balance','callback_data'=>'fin:deposit'],['text'=>'Withdraw funds','callback_data'=>'fin:withdraw']],
                    [['text'=>'Settings','callback_data'=>'fin:settings']],
                    [$this->button('history','history:0')],[$this->button('referrals','referrals')],...$back,
                ]);
        }
        if ($action === 'referrals') {
            $q = $this->db->prepare('SELECT COUNT(*) FROM users WHERE referrer_id = ?');
            $q->execute([$user['id']]);
            $l1 = $q->fetchColumn();
            $q = $this->db->prepare('SELECT COUNT(*) FROM users child JOIN users parent ON child.referrer_id = parent.id WHERE parent.referrer_id = ?');
            $q->execute([$user['id']]);
            $text = $this->copy['referrals'] . "\n\nL1: $l1\nL2: " . $q->fetchColumn();
            $username = getenv('TELEGRAM_BOT_USERNAME') ?: '';
            if (preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username)) {
                $text .= "\n\nhttps://t.me/$username?start=ref_" . $user['referral_code'];
            } else $text .= "\n\n" . $this->copy['link_unconfigured'];
            return $this->screen($text . "\n\n" . $this->copy['commissions_disabled'], $back);
        }
        if (preg_match('/^history:([0-9]{1,5})$/D', $action, $match)) {
            $page = (int)$match[1];
            $q = $this->db->prepare("SELECT j.reference,j.created_at,e.amount,e.side FROM ledger_entries e
                JOIN ledger_journals j ON j.id=e.journal_id JOIN ledger_accounts a ON a.id=e.account_id
                WHERE a.user_id=? AND a.code=? ORDER BY e.id DESC LIMIT 6 OFFSET ?");
            $q->bindValue(1,(int)$user['id'],PDO::PARAM_INT);
            $q->bindValue(2,'user:' . $user['id'] . ':available');
            $q->bindValue(3,$page*5,PDO::PARAM_INT);
            $q->execute();
            $rows = $q->fetchAll();
            $text = $this->copy['history'] . "\n\n";
            foreach (array_slice($rows,0,5) as $row) {
                $text .= $row['reference'] . "\n" . $row['side'] . ': ' . $row['amount']
                    . ' USDT · ' . $row['created_at'] . " UTC\n\n";
            }
            if (!$rows) $text .= $this->copy['empty_history'];
            $navigation = [];
            if ($page > 0) $navigation[] = $this->button('previous','history:' . ($page-1));
            if (count($rows) > 5) $navigation[] = $this->button('next','history:' . ($page+1));
            return $this->screen($text, $navigation ? [$navigation,...$back] : $back);
        }
        return $this->screen($this->copy['unknown'], $back);
    }

    private function balanceText(int $userId): string
    {
        $q = $this->db->prepare("SELECT COALESCE(SUM(CASE WHEN e.side=a.normal_side THEN e.amount ELSE -e.amount END),0)
            FROM ledger_accounts a LEFT JOIN ledger_entries e ON e.account_id=a.id
            WHERE a.user_id=? AND a.code=?");
        $q->execute([$userId,'user:' . $userId . ':available']);
        return $this->copy['balance'] . ': ' . bcadd((string)$q->fetchColumn(),'0',18) . ' USDT';
    }

    private function button(string $label, string $callback): array
    {
        return ['text'=>$this->copy[$label],'callback_data'=>$callback];
    }

    private function screen(string $text, array $buttons): array
    {
        // Plain text: Telegram usernames and names cannot inject HTML/Markdown.
        return ['text'=>$text,'reply_markup'=>['inline_keyboard'=>$buttons]];
    }
}

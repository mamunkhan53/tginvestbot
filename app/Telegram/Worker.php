<?php
declare(strict_types=1);
namespace Novikov\Telegram;

use PDO;
use Throwable;

final class Worker
{
    public function __construct(private PDO $db, private Api $client) {}

    public function run(int $limit = 50): array
    {
        if (!$this->db->query("SELECT GET_LOCK('novikov_telegram_worker',0)")->fetchColumn()) {
            return ['locked'=>true];
        }
        $processed = $sent = 0;
        try {
            // A previous crash after claiming a send is ambiguous. Do not
            // automatically send again: Telegram has no send idempotency key.
            $this->db->exec("UPDATE telegram_outbox SET status='uncertain',updated_at=UTC_TIMESTAMP(6) WHERE status='sending'");
            $menu = new Menu($this->db);
            for ($i=0; $i<$limit; $i++) {
                $id = null;
                try {
                    $this->db->beginTransaction();
                    $row = $this->db->query("SELECT update_id,payload FROM telegram_updates WHERE status='pending' ORDER BY update_id LIMIT 1 FOR UPDATE")->fetch();
                    if (!$row) { $this->db->commit(); break; }
                    $id = $row['update_id'];
                    $update = json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
                    // Domain services own their financial transactions. Commit
                    // the inbox selection before executing an idempotent intent.
                    $this->db->commit();
                    $finance = new FinanceBot($this->db);
                    $administrator = new AdminBot($this->db);
                    if ($administrator->handles($update)) {
                        $response = $administrator->respond($update);
                    } elseif ($finance->handles($update)) {
                        $response = $finance->respond($update);
                    } else {
                        $this->db->beginTransaction();
                        $response = $menu->respond($update);
                        $this->db->commit();
                    }
                    $this->db->beginTransaction();
                    if ($update['callback_id'] !== null) {
                        $this->enqueue($id,'ack','answerCallbackQuery',['callback_query_id'=>$update['callback_id']]);
                        $response['message_id'] = $update['message_id'];
                    }
                    $response['chat_id'] = $update['telegram_id'];
                    $this->enqueue($id,'screen',$update['callback_id'] === null ? 'sendMessage' : 'editMessageText',$response);
                    $this->db->prepare("UPDATE telegram_updates SET status='processed',processed_at=UTC_TIMESTAMP(6) WHERE update_id=?")->execute([$id]);
                    $this->db->commit();
                    $processed++;
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                    if ($id === null) throw $e;
                    $this->db->prepare("UPDATE telegram_updates SET attempts=attempts+1, status=IF(attempts>=5,'failed','pending') WHERE update_id=?")->execute([$id]);
                    break;
                }
            }
            for ($i=0; $i<$limit*2; $i++) {
                $row = $this->db->query("SELECT * FROM telegram_outbox WHERE status='pending' AND available_at<=UTC_TIMESTAMP(6) ORDER BY id LIMIT 1")->fetch();
                if (!$row) break;
                $this->db->prepare("UPDATE telegram_outbox SET status='sending',attempts=attempts+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$row['id']]);
                $result = $this->client->call($row['method'],json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR));
                $state = $result['state'];
                $delay = 0;
                if ($state === 'retry') {
                    $state = $row['attempts'] >= 9 ? 'failed' : 'pending';
                    $delay = $result['delay'];
                }
                $this->db->prepare("UPDATE telegram_outbox SET status=?,response_code=?,available_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),updated_at=UTC_TIMESTAMP(6) WHERE id=?")
                    ->execute([$state,$result['code'],$delay,$row['id']]);
                if ($state === 'sent') $sent++;
            }
            return ['processed'=>$processed,'sent'=>$sent];
        } finally {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->db->query("SELECT RELEASE_LOCK('novikov_telegram_worker')");
        }
    }

    private function enqueue(int|string $id, string $kind, string $method, array $payload): void
    {
        $this->db->prepare('INSERT INTO telegram_outbox (update_id,message_kind,method,payload) VALUES (?,?,?,?)')
            ->execute([$id,$kind,$method,json_encode($payload,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }
}

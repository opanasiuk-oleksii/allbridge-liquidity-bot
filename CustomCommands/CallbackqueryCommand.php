<?php
namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Entities\InlineKeyboard;
use PDO;

class CallbackqueryCommand extends SystemCommand
{
    protected $name = 'callbackquery';
    protected $description = 'Handle callback queries';
    protected $version = '1.0.0';

    public function execute(): \Longman\TelegramBot\Entities\ServerResponse
    {
        $callback = $this->getCallbackQuery();
        $data     = $callback->getData();
        $chat_id  = $callback->getMessage()->getChat()->getId();
        $user_id  = $callback->getFrom()->getId();

        $conv = new Conversation($user_id, $chat_id, 'subscriptions');
        $notes = &$conv->notes;
        !is_array($notes) && $notes = [];

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        if (($decoded['action'] ?? '') === 'wallet_details') {
            $notes['selected_wallet_id'] = (int)$decoded['wallet_id'];
            $notes['state'] = 3;
            $conv->update();
            Request::answerCallbackQuery(['callback_query_id' => $callback->getId()]);
            return $this->renderWalletDetails($chat_id, $user_id, $callback->getMessage()->getMessageId(), $notes['selected_wallet_id']);
        }

        if (($decoded['action'] ?? '') === 'wallet_edit') {
            $notes['selected_wallet_id'] = (int)$decoded['wallet_id'];
            $notes['state'] = 4;
            $notes['edit_step'] = 'choose_field';
            $conv->update();
            Request::answerCallbackQuery(['callback_query_id' => $callback->getId()]);
            return $this->renderEditMenu($chat_id, $user_id, $callback->getMessage()->getMessageId(), $notes['selected_wallet_id']);
        }

        if (($decoded['action'] ?? '') === 'wallet_delete') {
            $this->deleteWallet($user_id, (int)$decoded['wallet_id']);
            $notes['state'] = 2;
            unset($notes['selected_wallet_id']);
            $conv->update();
            Request::answerCallbackQuery(['callback_query_id' => $callback->getId(), 'text' => 'Deleted']);
            return $this->renderManageList($chat_id, $callback->getMessage()->getMessageId(), $user_id);
        }

        if (($decoded['action'] ?? '') === 'refresh_manage') {
            $notes['state'] = 2;
            $conv->update();
            Request::answerCallbackQuery(['callback_query_id' => $callback->getId(), 'text' => 'Updated ✅']);
            return $this->renderManageList($chat_id, $callback->getMessage()->getMessageId(), $user_id);
        }

        // User chose which field to edit
        if (($decoded['action'] ?? '') === 'edit_field') {
            $notes['selected_wallet_id'] = $decoded['wallet_id'];
            $notes['state'] = 4;
            $notes['edit_step'] = 'enter_value';
            $notes['edit_field'] = $decoded['field'] ?? null;
            $conv->update();

            Request::answerCallbackQuery([
                'callback_query_id' => $callback->getId(),
                'text'              => 'Send new value',
            ]);

            $prompt = "Enter new value for " . ($decoded['field'] ?? 'field') . ":";
            return Request::editMessageText([
                'chat_id'    => $chat_id,
                'message_id' => $callback->getMessage()->getMessageId(),
                'text'       => $prompt,
            ]);
        }

        if (($decoded['action'] ?? '') === 'back' && ($decoded['to'] ?? '') === 'manage') {
            $notes['state'] = 2;
            unset($notes['selected_wallet_id']);
            $conv->update();
            Request::answerCallbackQuery(['callback_query_id' => $callback->getId()]);
            return $this->renderManageList($chat_id, $callback->getMessage()->getMessageId(), $user_id);
        }

        if (($decoded['action'] ?? '') === 'back' && ($decoded['to'] ?? '') === 'list') {
            $notes['state'] = 1;
            unset($notes['selected_wallet_id']);
            $conv->update();
            Request::answerCallbackQuery(['callback_query_id' => $callback->getId()]);
            return $this->renderSummary($chat_id, $callback->getMessage()->getMessageId(), $user_id);
        }

        return Request::answerCallbackQuery(['callback_query_id' => $callback->getId()]);
    }


    private function renderSummary(int $chat_id, int $message_id, int $user_id)
    {
        $text = $this->buildSummaryText($user_id);
        return Request::editMessageText([
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function buildSummaryText(int $user_id): string
    {
        $pdo = DB::getPdo();

        $sql = "
            SELECT
                w.id,
                w.name,
                w.blockchain,
                w.token,
                w.wallet_address,
                w.report_frequency,
                w.threshold,
                r.balance_amount   AS balance,
                r.reward_amount    AS rewards,
                r.created_at       AS last_update
            FROM wallets w
            LEFT JOIN (
                SELECT r1.wallet_id,
                       r1.balance_amount,
                       r1.reward_amount,
                       r1.created_at
                FROM rewards r1
                INNER JOIN (
                    SELECT wallet_id, MAX(created_at) AS max_created
                    FROM rewards
                    GROUP BY wallet_id
                ) r2
                    ON r1.wallet_id = r2.wallet_id
                   AND r1.created_at = r2.max_created
            ) r
                ON w.id = r.wallet_id
            WHERE w.user_id = :uid
            ORDER BY w.id ASC
        ";

        $sth = $pdo->prepare($sql);
        $sth->bindValue(':uid', $user_id, PDO::PARAM_INT);
        $sth->execute();
        $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return "You don't have any subscriptions yet. Use /subscribe to add one.";
        }

        $lines = ["📋 *Your subscriptions (" . count($rows) . ")*\n"];
        $i = 1;
        foreach ($rows as $r) {
            $short   = strlen($r['wallet_address']) > 10
                ? substr($r['wallet_address'],0,5).'…'.substr($r['wallet_address'],-5)
                : $r['wallet_address'];

            $balance = $r['balance'] ?? '0';
            $rewards = $r['rewards'] ?? '0';

            $lines[] = sprintf(
                "%d) *%s* [%s/%s]\n".
                " Chain: %s \n".
                " Balance: %s %s\n".
                " Rewards: %s %s\n".
                " Address: `%s`\n",
                $i++,
                $r['name'],
                $r['blockchain'],
                $r['token'],
                $r['blockchain'],
                $balance,
                $r['token'],
                $rewards,
                $r['token'],
                $r['wallet_address']
            );
        }
        return implode("\n", $lines);
    }

    private function renderManageList(int $chat_id, int $message_id, int $user_id)
    {
        [$text, $inline] = $this->buildManageUi($user_id);
        return Request::editMessageText([
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'text'         => $text,
            'reply_markup' => $inline,
        ]);
    }

    private function buildManageUi(int $user_id): array
    {
        $pdo = DB::getPdo();
        $rows = $pdo->query("SELECT * FROM wallets WHERE user_id = " . (int)$user_id . " ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return [
                "No subscriptions yet. Use /subscribe.",
                new InlineKeyboard([['text' => '⬅ Back', 'callback_data' => json_encode(["action" => "back", "to" => "list"])]])
            ];
        }

        $text = "Manage your subscriptions. Pick one:";
        $grid = [];
        foreach ($rows as $r) {
            $short = strlen($r['wallet_address']) > 10 ? substr($r['wallet_address'],0,5).'…'.substr($r['wallet_address'],-5) : $r['wallet_address'];
            $label = sprintf('%s/%s %s', $r['blockchain'], $r['token'], $short);
            $grid[] = [['text' => $label, 'callback_data' => json_encode(["action" => "wallet_details", "wallet_id" => $r['id']])]];
        }
        $grid[] = [['text' => '⬅ Back', 'callback_data' => json_encode(['action' => 'back', 'to' => 'list'])], ['text' => '🔄 Refresh', 'callback_data' => json_encode(['action' => 'refresh_manage'])]];

        return [$text, new InlineKeyboard(...$grid)];
    }

    private function renderWalletDetails(int $chat_id, int $user_id, int $message_id, int $wallet_id)
    {
        $pdo = DB::getPdo();

        $sql = "
            SELECT
                w.id,
                w.name,
                w.blockchain,
                w.token,
                w.wallet_address,
                w.report_frequency,
                w.threshold,
                r.balance_amount   AS balance,
                r.reward_amount    AS rewards,
                r.created_at       AS last_update
            FROM wallets w
            LEFT JOIN (
                SELECT r1.wallet_id,
                       r1.balance_amount,
                       r1.reward_amount,
                       r1.created_at
                FROM rewards r1
                INNER JOIN (
                    SELECT wallet_id, MAX(created_at) AS max_created
                    FROM rewards
                    WHERE wallet_id = :wid
                    GROUP BY wallet_id
                ) r2
                    ON r1.wallet_id = r2.wallet_id
                   AND r1.created_at = r2.max_created
            ) r
                ON w.id = r.wallet_id
            WHERE w.id = :wid
              AND w.user_id = :uid
            LIMIT 1
        ";

        $sth = $pdo->prepare($sql);
        $sth->bindValue(':wid', $wallet_id, PDO::PARAM_INT);
        $sth->bindValue(':uid', $user_id, PDO::PARAM_INT);
        $sth->execute();
        $w = $sth->fetch(PDO::FETCH_ASSOC);

        if (!$w) {
            return Request::editMessageText([
                'chat_id'    => $chat_id,
                'message_id' => $message_id,
                'text'       => 'Wallet not found.',
            ]);
        }

        $text  = "📂 *Subscription Details:*\n\n";
        $text .= "Name: " . $w['name'] . "\n";
        $text .= "Chain: " . $w['blockchain'] . "\n";
        $text .= "Token: " . $w['token'] . "\n";
        $text .= "Address: " . $w['wallet_address'] . "\n";
        $text .= "Balance: " . ($w['balance'] ?? '0') . " " . $w['token'] . "\n";
        $text .= "Rewards: " . ($w['rewards'] ?? '0') . " " . $w['token'] . "\n";
        $text .= "Report Frequency: " . $w['report_frequency'] . "\n";
        $text .= "Threshold: " . $w['threshold'] . "\n";
        if (!empty($w['last_update'])) {
            $text .= "Last update: " . $w['last_update'] . "\n";
        }

        $kbd = new InlineKeyboard(
            [['text' => '✏️ Edit',   'callback_data' => json_encode(["action" => "wallet_edit", "wallet_id" => $w['id']])]],
            [['text' => '🗑 Delete', 'callback_data' => json_encode(["action" => "wallet_delete", "wallet_id" => $w['id']])]],
            [['text' => '⬅ Back',    'callback_data' => json_encode(['action' => 'back', 'to' => 'manage'])]]
        );

        return Request::editMessageText([
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'text'         => $text,
            'reply_markup' => $kbd,
            'parse_mode'   => 'Markdown',
        ]);
    }

    private function renderEditMenu(int $chat_id, int $user_id, int $message_id, int $wallet_id)
    {
        $pdo = DB::getPdo();
        $sth = $pdo->prepare("SELECT * FROM wallets WHERE id = :id and user_id = :uid");
        $sth->bindValue(':id', $wallet_id, PDO::PARAM_INT);
        $sth->bindValue(':uid', $user_id, PDO::PARAM_INT);
        $sth->execute();
        $w = $sth->fetch(PDO::FETCH_ASSOC);

        if (!$w) {
            return Request::editMessageText([
                'chat_id'    => $chat_id,
                'message_id' => $message_id,
                'text'       => 'Wallet not found.',
            ]);
        }

        $text = "✏️ *Edit subscription*\n\n";
        $text .= "Name: " . $w['name'] . "\n";
        $text .= "Report Frequency: " . $w['report_frequency'] . "\n";
        $text .= "Threshold: " . $w['threshold'] . "\n\n";
        $text .= "Select what you want to edit:";

        $kbd = new InlineKeyboard(
            [['text' => 'Name',              'callback_data' => json_encode(["action" => "edit_field", "field" => "name", "wallet_id" => $w['id']])]],
            [['text' => 'Report Frequency',  'callback_data' => json_encode(["action" => "edit_field", "field" => "report_frequency", "wallet_id" => $w['id']])]],
            [['text' => 'Threshold',         'callback_data' => json_encode(["action" => "edit_field", "field" => "threshold", "wallet_id" => $w['id']])]],
            [['text' => '⬅ Back',            'callback_data' => json_encode(['action' => 'back', 'to' => 'manage'])]]
        );

        return Request::editMessageText([
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'text'         => $text,
            'reply_markup' => $kbd,
            'parse_mode'   => 'Markdown',
        ]);
    }

    private function deleteWallet(int $user_id, int $id): void
    {
        $pdo = DB::getPdo();
        $sth = $pdo->prepare("DELETE FROM wallets WHERE id = :id AND user_id = :uid");
        $sth->bindValue(':id',  $id, PDO::PARAM_INT);
        $sth->bindValue(':uid', $user_id, PDO::PARAM_INT);
        $sth->execute();
    }
}

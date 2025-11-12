<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\InlineKeyboard;
use PDO;

class SubscriptionsCommand extends UserCommand
{
    protected $name = 'subscriptions';
    protected $description = 'Subscriptions list';
    protected $usage = '/subscriptions';
    protected $version = '1.0.0';
    protected $private_only = true;

    public function execute(): \Longman\TelegramBot\Entities\ServerResponse
    {

        $message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $text = trim($message->getText(true));

        $conv = new Conversation($user_id, $chat_id, $this->getName());
        $notes = &$conv->notes;
        !is_array($notes) && $notes = [];
        $state = (int)($notes['state'] ?? 0);

        switch ($state) {
            case 0:
                $notes['state'] = 1;
                $conv->update();
                return $this->renderSummaryNewMessage($chat_id, $user_id);
            case 1:
                if ($text === 'Refresh') {
                    return $this->renderSummaryNewMessage($chat_id, $user_id);
                }
                if ($text === 'Manage') {
                    $notes['state'] = 2;
                    $conv->update();
                    return $this->renderManageNewMessage($chat_id, $user_id);
                }
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => 'Choose: Refresh or Manage.',
                ]);

            case 2:
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => 'You are in interactive mode. Use buttons on screen.',
                ]);

            case 3:
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => 'You are in interactive mode. Use buttons on screen.',
                ]);

            case 4:
                if (($notes['edit_step'] ?? '') === 'choose_field') {
                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => 'You are in interactive mode. Use buttons on screen.',
                    ]);
                }
                if (($notes['edit_step'] ?? '') === 'enter_value') {
                    $new_value = $text;
                    $wallet_id = (int)($notes['selected_wallet_id'] ?? 0);
                    $field     = $notes['edit_field'] ?? null;

                    if ($wallet_id && in_array($field, ['name','report_frequency','threshold'], true)) {
                        $pdo = DB::getPdo();
                        $sql = "UPDATE wallets SET {$field} = :val WHERE id = :id AND user_id = :uid";
                        $sth = $pdo->prepare($sql);
                        $sth->bindValue(':val', $new_value);
                        $sth->bindValue(':id', $wallet_id, PDO::PARAM_INT);
                        $sth->bindValue(':uid', $user_id, PDO::PARAM_INT);
                        $sth->execute();
                    }

                    // go back to wallet details view
                    $notes['state'] = 2;
                    unset($notes['edit_step'], $notes['edit_field']);
                    $conv->update();

                    Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text'    => 'Saved ✅',
                    ]);
                    return $this->renderManageNewMessage($chat_id, $user_id);
                }
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => 'You are in interactive mode. Use buttons on screen.',
                ]);

            default:
                $conv->stop();
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => 'Something went wrong. Try /subscriptions again.',
                ]);
        }
    }

    private function renderSummaryNewMessage(int $chat_id, int $user_id)
    {
        $text = $this->buildSummaryText($user_id);
        $kb = (new Keyboard(['Refresh', 'Manage']))->setResizeKeyboard(true)->setOneTimeKeyboard(false);

        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'reply_markup' => $kb,
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

    private function renderManageNewMessage(int $chat_id, int $user_id)
    {
        [$text, $inline] = $this->buildManageUi($user_id);
        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'reply_markup' => $inline,
        ]);
    }

    private function buildManageUi(int $user_id): array
    {
        $pdo = DB::getPdo();
        $rows = $pdo->query("SELECT id, blockchain, token, wallet_address FROM wallets WHERE user_id = " . (int)$user_id . " ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return [
                "No subscriptions yet. Use /subscribe.",
                new InlineKeyboard([['text' => '⬅ Back', 'callback_data' => json_encode(["action" => "back", "to" => "list"])], ['text' => '🔄 Refresh', 'callback_data' => json_encode(["action" => "refresh_manage"])]]),
            ];
        }

        $text = "Manage your subscriptions. Pick one:";
        $grid = [];
        foreach ($rows as $r) {
            $label = sprintf('%s/%s %s', $r['blockchain'], $r['token'], $this->short($r['wallet_address']));
            $grid[] = [['text' => $label, 'callback_data' => json_encode(["action" => "wallet_details", "wallet_id" => $r['id']])]];
        }
        $grid[] = [['text' => '⬅ Back', 'callback_data' => json_encode(['action' => 'back', 'to' => 'list'])], ['text' => '🔄 Refresh', 'callback_data' => json_encode(['action' => 'refresh_manage'])]];

        return [$text, new InlineKeyboard(...$grid)];
    }

    private function short(string $addr): string
    {
        return strlen($addr) > 10 ? substr($addr, 0, 5) . '…' . substr($addr, -5) : $addr;
    }
}

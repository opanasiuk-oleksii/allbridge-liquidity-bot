<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Conversation;

class SubscribeCommand extends UserCommand
{
    protected $name = 'subscribe';
    protected $description = 'Subscribe for monitoring liquidity rewards';
    protected $usage = '/subscribe';
    protected $version = '1.2.0';
    protected $private_only = true;

    protected $chainsMap = [];

    private $poolsData = [];

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $text = trim($message->getText(true));

        try {
            if (empty($this->poolsData)) {
                $this->poolsData = $this->fetchPoolsData();
            }
            if (empty($this->chainsMap)) {
                foreach ($this->poolsData as $pool_name => $pool_data) {
                    $this->chainsMap[$pool_data["name"]] = $pool_data["chainSymbol"];
                }
            }

            // Start or continue the conversation
            $conversation = new Conversation($user_id, $chat_id, $this->getName());
            $notes = &$conversation->notes;
            !is_array($notes) && $notes = [];
            $state = $notes['state'] ?? 0;

            switch ($state) {
                case 0: // Step 1: Ask for blockchain
                    if ($text === '') {
                        $keyboard = new Keyboard(...array_chunk(array_keys($this->chainsMap), 4));
                        $keyboard->setResizeKeyboard(true)->setOneTimeKeyboard(true);

                        $notes['state'] = 0;
                        $conversation->update();

                        return Request::sendMessage([
                            'chat_id' => $chat_id,
                            'text' => 'Select the blockchain for your wallet:',
                            'reply_markup' => $keyboard,
                        ]);
                    }

                    if (!array_key_exists($text, $this->chainsMap)) {
                        return Request::sendMessage([
                            'chat_id' => $chat_id,
                            'text' => 'Invalid blockchain selected. Please choose from the list.',
                        ]);
                    }
                    $chainSymbol = $this->chainsMap[$text];
                    if (!isset($this->poolsData[$chainSymbol])) {
                        return Request::sendMessage([
                            'chat_id' => $chat_id,
                            'text' => 'This blockchain is not supported right now.',
                        ]);
                    }

                    $notes['blockchain'] = $chainSymbol;
                    $notes['state'] = 1;
                    $conversation->update();

                    $tokens = array_column($this->poolsData[$notes['blockchain']]['tokens'], 'symbol');

                    $keyboard = new Keyboard(...array_chunk($tokens, 4));
                    $keyboard->setResizeKeyboard(true)->setOneTimeKeyboard(true);

                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => 'Select the token you want to monitor:',
                        'reply_markup' => $keyboard,
                    ]);

                case 1: // Step 2: Ask for token
                    if ($text === '') {
                        return Request::sendMessage([
                            'chat_id' => $chat_id,
                            'text' => 'Please select the token:',
                        ]);
                    }

                    $blockchain = $notes['blockchain'];
                    $tokens = array_column($this->poolsData[$blockchain]['tokens'], 'symbol');

                    if (!in_array($text, $tokens)) {
                        return Request::sendMessage([
                            'chat_id' => $chat_id,
                            'text' => 'Invalid token for the selected blockchain. Please try again.',
                        ]);
                    }

                    $notes['token'] = $text;
                    $notes['state'] = 2;
                    $conversation->update();

                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => 'Enter your wallet address (for liquidity providers on core.allbridge.io):',
                    ]);

                case 2: // Step 3: Ask for wallet address
                    if ($text === '') {
                        return Request::sendMessage([
                            'chat_id' => $chat_id,
                            'text' => 'Please enter your wallet address:',
                        ]);
                    }

                    $notes['wallet_address'] = $text;
                    $notes['state'] = 3;
                    $conversation->update();

                    $keyboard = new Keyboard(
                        ['Hourly', 'Daily'],
                        ['Weekly', 'Only on reward threshold']
                    );
                    $keyboard->setResizeKeyboard(true)->setOneTimeKeyboard(true);

                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => 'Choose the report frequency:',
                        'reply_markup' => $keyboard,
                    ]);

                case 3: // Step 4: Ask for report frequency
                    if ($text === '') {
                        return Request::sendMessage([
                            'chat_id' => $chat_id,
                            'text' => 'Please select the report frequency:',
                        ]);
                    }

                    $notes['report_frequency'] = $text;
                    $notes['state'] = 4;
                    $conversation->update();

                    $keyboard = new Keyboard(['1', '5', '10'], ['20', '50', '100']);
                    $keyboard->setResizeKeyboard(true)->setOneTimeKeyboard(true);

                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => 'Select the reward amount threshold for notifications:',
                        'reply_markup' => $keyboard,
                    ]);

                case 4: // Step 5: Ask for reward threshold
                    if ($text === '') {
                        return Request::sendMessage([
                            'chat_id' => $chat_id,
                            'text' => 'Please select the reward threshold:',
                        ]);
                    }

                    $notes['threshold'] = $text;
                    $notes['state'] = 5;
                    $conversation->update();

                    return $this->finalizeConversation($chat_id, $notes);

                case 5: // Finalize
                    return $this->finalizeConversation($chat_id, $notes);

                default:
                    $conversation->stop();
                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => 'Something went wrong. Please try again.',
                    ]);
            }
        } catch (TelegramException | \Exception $e) {
            error_log('Error in AddSubscriptionCommand: ' . $e->getMessage());
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => 'An error occurred. Please try again later.',
            ]);
        }
    }

    private function fetchPoolsData(): array
    {
        $base = trim($this->getConfig('allbridge_core_api_url'));
        $url  = rtrim($base, '/') . '/chains';
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        return $data;
    }

    private function finalizeConversation(int $chat_id, array $notes): ServerResponse
    {
        $user_id = $this->getMessage()->getFrom()->getId();
        $name = "{$notes['blockchain']}-{$notes['token']}-{$notes['wallet_address']}";

        $this->saveWalletConfiguration($user_id, $notes, $name);

        $conversation = new Conversation($user_id, $chat_id, $this->getName());
        $conversation->stop();

        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "Your wallet has been successfully added for monitoring!\n\n"
                . "Name: $name\n"
                . "Blockchain: {$notes['blockchain']}\n"
                . "Token: {$notes['token']}\n"
                . "Wallet: {$notes['wallet_address']}\n"
                . "Frequency: {$notes['report_frequency']}\n"
                . "Threshold: {$notes['threshold']}",
            'reply_markup' => Keyboard::remove()
        ]);
    }

    private function saveWalletConfiguration(int $user_id, array $notes, string $name): void
    {
        $data = [
            'user_id'         => $user_id,
            'blockchain'      => $notes['blockchain'],
            'token'           => $notes['token'],
            'wallet_address'  => $notes['wallet_address'],
            'report_frequency'=> $notes['report_frequency'],
            'threshold'       => $notes['threshold'],
            'name'            => $name,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];
        try {
            $columns = implode('`, `', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO `wallets` (`$columns`) VALUES ($placeholders)";

            $pdo = \Longman\TelegramBot\DB::getPdo();
            $sth = $pdo->prepare($sql);

            foreach ($data as $key => $value) {
                $sth->bindValue(":$key", $value);
            }

            $sth->execute();
        } catch (\PDOException $e) {
            error_log('Failed to save wallet configuration: ' . $e->getMessage());
        }
    }
}

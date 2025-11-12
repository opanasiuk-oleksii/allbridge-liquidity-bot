<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load configuration
$config = require __DIR__ . '/../config.php';

// Create Telegram API object
$telegram = new Longman\TelegramBot\Telegram($config['api_key'], $config['bot_username']);

// Enable MySQL
$telegram->enableMySql($config['mysql']);

use Longman\TelegramBot\DB;
use Longman\TelegramBot\Request;

try {
    $pdo = DB::getPdo();

    $wallets = fetchWallets($pdo);
    if (empty($wallets)) {
        logMessage("No wallet configurations found.");
        exit;
    }

    $totals = [];

    $daily_report_time = '08:00';
    $now               = new DateTime();
    $current_hour      = $now->format('H:i');
    $current_minute    = $now->format('i');
    $is_monday         = $now->format('l') === 'Monday';

    $is_hourly_window  = $current_minute === '00';
    $is_daily_window   = ($current_hour === $daily_report_time);
    $is_weekly_window  = ($is_monday && $current_hour === $daily_report_time);

    $pools_index = fetchPoolsIndex();

    foreach ($wallets as $wallet) {
        processWallet(
            $pdo,
            $pools_index,
            $wallet,
            $totals,
            $is_hourly_window,
            $is_daily_window,
            $is_weekly_window
        );
    }

    notifyTotals($totals);

    logMessage("Rewards processing completed successfully.");
} catch (Exception $e) {
    handleError($e);
}

function fetchWallets(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT * FROM wallets');
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function processWallet(
    PDO $pdo,
    array $pools_index,
    array $wallet,
    array &$totals,
    bool $is_hourly_window,
    bool $is_daily_window,
    bool $is_weekly_window
) {
    $wallet_id = $wallet['id'];
    $user_id = $wallet['user_id'];
    $name = $wallet['name'] ?? "{$wallet['blockchain']}-{$wallet['wallet_address']}";

    if (!isset($totals[$user_id])) {
        $totals[$user_id] = [
            'diffExist'     => false,
            'totalWallets'  => 0,
            'totalRewards'  => 0,
            'totalBalance'  => 0,
        ];
    }

    $chain   = $wallet['blockchain'];
    $token   = $wallet['token'];
    $token_meta = $pools_index[$chain][$token] ?? null;
    if (!$token_meta || empty($token_meta['tokenAddress'])) {
        return;
    }
    $token_address = $token_meta['tokenAddress'];

    $current_data = getLiquidityDetails($wallet['wallet_address'], $token_address);
    if (!$current_data) {
        return;
    }

    $last_rewards_row = fetchLastRewards($pdo, $wallet_id);
    $last_amount      = $last_rewards_row['reward_amount'] ?? 0;
    $diff_reward      = round($current_data['rewardDebt'] - $last_amount, 2);

    $threshold_hit = $diff_reward >= (float)$wallet['threshold'];

    $wants_hourly = ($wallet['report_frequency'] === 'Hourly' && $is_hourly_window);
    $wants_daily  = ($wallet['report_frequency'] === 'Daily'  && $is_daily_window);
    $wants_weekly = ($wallet['report_frequency'] === 'Weekly' && $is_weekly_window);

    $current_balance = $current_data['lpAmount'] / (10 ** 3);

    saveRewards($pdo, $wallet_id, $current_data['rewardDebt'], $current_balance);

    if ($threshold_hit) {
        sendTelegramMessage(
            $user_id,
            "🎉 Your reward for *$name* increased by *$diff_reward*.\n".
            "Now claimable: *{$current_data['rewardDebt']}* {$token}\n".
            "Balance: *{$current_balance}* {$token}"
        );
        $totals[$user_id]['diffExist'] = true;
    }

    if ($wants_hourly || $wants_daily || $wants_weekly) {
        $interval = $wants_hourly ? 3 : ($wants_daily ? 24 : 168);
        $summary  = generateSummary($pdo, $wallet_id, $interval);
        sendTelegramMessage($user_id, "📊 Report for *$name*:\n$summary");
    }

    $totals[$user_id]['totalWallets']++;
    $totals[$user_id]['totalRewards'] += $current_data['rewardDebt'];
    $totals[$user_id]['totalBalance'] += $current_balance;
}

function fetchLastRewards(PDO $pdo, int $wallet_id): array
{
    $stmt = $pdo->prepare(
        'SELECT reward_amount, balance_amount FROM rewards WHERE wallet_id = :wallet_id ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->bindValue(':wallet_id', $wallet_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function saveRewards(PDO $pdo, int $wallet_id, float $reward, float $balance): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO rewards (wallet_id, reward_amount, balance_amount, created_at) VALUES (:wallet_id, :reward_amount, :balance_amount, :created_at)'
    );
    $stmt->bindValue(':wallet_id', $wallet_id, PDO::PARAM_INT);
    $stmt->bindValue(':reward_amount', $reward);
    $stmt->bindValue(':balance_amount', $balance);
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'));
    $stmt->execute();
}

function notifyTotals(array $totals): void
{
    foreach ($totals as $user_id => $total) {
        if (!$total['diffExist']) {
            continue;
        }
        sendTelegramMessage($user_id, "📈 Overview across all your wallets:\n".
            "Total wallets: *{$total['totalWallets']}*\n".
            "Total rewards: *{$total['totalRewards']}*\n".
            "Total balance: *{$total['totalBalance']}*");
    }
}

function sendTelegramMessage(int $chat_id, string $text): void
{
    Request::sendMessage([
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ]);
}

function logMessage(string $message): void
{
    echo $message . "\n";
}

function handleError(Exception $e): void
{
    error_log('Error: ' . $e->getMessage());
    echo "An error occurred. Check logs for details.\n";
}

function fetchPoolsIndex(): array
{
    global $config;
    $base = trim($config['allbridge_core_api_url']);
    $url  = rtrim($base, '/') . '/chains';
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    $index = [];
    foreach ($data as $pool_data) {
        $chain_symbol = $pool_data['chainSymbol'] ?? null;
        if (!$chain_symbol) {
            continue;
        }
        if (!isset($index[$chain_symbol])) {
            $index[$chain_symbol] = [];
        }
        foreach ($pool_data['tokens'] as $token) {
            $symbol = $token['symbol'] ?? null;
            if (!$symbol) {
                continue;
            }
            $index[$chain_symbol][$symbol] = [
                'tokenAddress' => $token['tokenAddress'] ?? null,
                'decimals'     => $token['decimals'] ?? 0,
            ];
        }
    }
    return $index;
}

function getLiquidityDetails(string $walletAddress, $tokenAddress): array
{
    global $config;
    $base = trim($config['allbridge_core_api_url']);
    $url  = rtrim($base, '/') . '/liquidity/details';
    $url .= '?ownerAddress=' . $walletAddress;
    $url .= '&tokenAddress=' . $tokenAddress;
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    return $data;
}

function generateSummary(PDO $pdo, int $wallet_id, int $interval = 1): string
{
    $stmt = $pdo->prepare(
        'SELECT reward_amount, balance_amount, created_at 
         FROM rewards 
         WHERE wallet_id = :wallet_id AND created_at >= NOW() - INTERVAL :interval HOUR 
         ORDER BY created_at ASC'
    );
    $stmt->bindValue(':wallet_id', $wallet_id, PDO::PARAM_INT);
    $stmt->bindValue(':interval', $interval, PDO::PARAM_INT);
    $stmt->execute();
    $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rewards)) {
        return "There are no records yet.";
    }

    $summary = '';
    $total_difference_rewards = 0;
    $total_difference_balance = 0;
    $last_amount = round($rewards[0]['reward_amount'], 2);
    $last_balance = round($rewards[0]['balance_amount'], 2);

    foreach ($rewards as $reward) {
        $difference_rewards = round($reward['reward_amount'], 2) - $last_amount;
        $difference_balance = round($reward['balance_amount'], 2) - $last_balance;
        if ($difference_rewards == 0 && $difference_balance == 0) {
            continue;
        }
        $total_difference_rewards += $difference_rewards;
        $total_difference_balance += $difference_balance;
        $summary .= date('H:i:s', strtotime($reward['created_at'])) . " – rewards: " . ($difference_rewards > 0 ? '+' : '-') . " $difference_rewards". ($difference_balance > 0 ? " | balance: $difference_balance" : "" ) . " \n";
        $last_amount = round($reward['reward_amount'], 2);
        $last_balance = round($reward['balance_amount'], 2);
    }
    if ($total_difference_rewards == 0 && $total_difference_balance == 0 && count($rewards) > 1) {
        $interval_text = "";
        if ($interval < 24) {
            $interval_text = "hour";
        }
        if ($interval == 24) {
            $interval_text = "day";
        }
        if ($interval == 168) {
            $interval_text = "week";
        }
        return "No balance change recorded in the last $interval_text.";
    }
    if ($total_difference_rewards > 0) {
        $summary .= "\nTotal rewards change: +$total_difference_rewards";
    }
    if ($total_difference_balance > 0) {
        $summary .= "\nTotal balance change: +$total_difference_balance";
    }

    return $summary;
}

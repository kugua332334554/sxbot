<?php
ignore_user_abort(true); 
set_time_limit(0);      

// 日志函数
function logBroadcastError($message) {
    $log_file = __DIR__ . '/broadcast.log';
    $timestamp = date("[Y-m-d H:i:s]");
    file_put_contents($log_file, $timestamp . " " . $message . PHP_EOL, FILE_APPEND);
}

// 单次请求函数
function sendTelegramRequest($bot_token, $method, $params) {
    $url = 'https://api.telegram.org/bot' . $bot_token . '/' . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        logBroadcastError("cURL Error: " . $error);
        return null;
    }
    return json_decode($response, true);
}

// 获取参数
$bot_token = $_REQUEST['token'] ?? '';
$broadcast_text = $_REQUEST['text'] ?? '';
$photo_file_id = $_REQUEST['photo'] ?? '';
$user_ids_json = $_REQUEST['users'] ?? '';
$admin_chat_id = $_REQUEST['admin_id'] ?? '';

// 参数验证
if (empty($bot_token) || empty($user_ids_json) || empty($admin_chat_id)) {
    logBroadcastError("Missing required parameters");
    die('Invalid parameters');
}

if (empty($broadcast_text) && empty($photo_file_id)) {
    logBroadcastError("No content to broadcast");
    die('No content provided');
}

$user_ids = json_decode($user_ids_json, true);
if (!is_array($user_ids) || empty($user_ids)) {
    logBroadcastError("Invalid user IDs");
    die('Invalid user list');
}

// 向管理员发送开始通知
sendTelegramRequest($bot_token, 'sendMessage', [
    'chat_id' => $admin_chat_id,
    'text' => "⏳ 广播任务已启动...\n目标用户: " . count($user_ids) . " 人。\n后台运行中，完成后将向您发送报告。"
]);

$total_users = count($user_ids);
$success_count = 0;
$fail_count = 0;
$batch_size = 30; // more send time out
$chunks = array_chunk($user_ids, $batch_size);

foreach ($chunks as $chunk) {
    $start_time = microtime(true);
    $mh = curl_multi_init();
    $handles = [];

    foreach ($chunk as $target_user_id) {
        $ch = curl_init();
        $url = 'https://api.telegram.org/bot' . $bot_token . '/';
        $params = ['chat_id' => $target_user_id];

        if (!empty($photo_file_id)) {
            $url .= 'sendPhoto';
            $params['photo'] = $photo_file_id;
            if (!empty($broadcast_text)) $params['caption'] = $broadcast_text;
        } else {
            $url .= 'sendMessage';
            $params['text'] = $broadcast_text;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        curl_multi_add_handle($mh, $ch);
        $handles[] = ['ch' => $ch, 'uid' => $target_user_id];
    }

    // 执行批处理请求
    $running = null;
    do {
        curl_multi_exec($mh, $running);
    } while ($running > 0);

    // 解析结果
    foreach ($handles as $item) {
        $response_raw = curl_multi_getcontent($item['ch']);
        $response = json_decode($response_raw, true);
        
        if ($response && isset($response['ok']) && $response['ok']) {
            $success_count++;
        } else {
            $fail_count++;
            $error_desc = $response['description'] ?? 'Unknown API Error';
            logBroadcastError("Failed for user_id {$item['uid']}: {$error_desc}");
        }
        
        curl_multi_remove_handle($mh, $item['ch']);
        curl_close($item['ch']);
    }
    curl_multi_close($mh);

    // <s
    $end_time = microtime(true);
    $execution_time = $end_time - $start_time;
    if ($execution_time < 1.0) {
        usleep((1.0 - $execution_time) * 1000000);
    }
}

// 发送报告
$report_message = "✅ 广播完成！\n\n";
$report_message .= "📊 最终报告:\n";
$report_message .= "总目标: {$total_users} 人\n";
$report_message .= "发送成功: {$success_count} 人\n";
$report_message .= "发送失败: {$fail_count} 人";

sendTelegramRequest($bot_token, 'sendMessage', [
    'chat_id' => $admin_chat_id,
    'text' => $report_message
]);

logBroadcastError("Broadcast completed. Success: {$success_count}, Failed: {$fail_count}");

echo "Broadcast task completed";

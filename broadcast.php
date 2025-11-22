<?php
// 日志
function logBroadcastError($message) {
    $log_file = __DIR__ . '/broadcast.log';
    $timestamp = date("[Y-m-d H:i:s]");
    file_put_contents($log_file, $timestamp . " " . $message . PHP_EOL, FILE_APPEND);
}

// 发送 Telegram API 请求
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

// 解析用户ID
$user_ids = json_decode($user_ids_json, true);
if (!is_array($user_ids) || empty($user_ids)) {
    logBroadcastError("Invalid user IDs");
    die('Invalid user list');
}

// 向管理员发送开始通知
sendTelegramRequest($bot_token, 'sendMessage', [
    'chat_id' => $admin_chat_id,
    'text' => "⏳ 广播任务已启动...\n目标用户: " . count($user_ids) . " 人。\n完成后将向您发送报告。"
]);

// 执行广播
$total_users = count($user_ids);
$success_count = 0;
$fail_count = 0;

foreach ($user_ids as $target_user_id) {
    $params = ['chat_id' => $target_user_id];
    $response = null;

    try {
        if (!empty($photo_file_id)) {
            // 发送图片
            $params['photo'] = $photo_file_id;
            if (!empty($broadcast_text)) {
                $params['caption'] = $broadcast_text;
            }
            $response = sendTelegramRequest($bot_token, 'sendPhoto', $params);
        } else {
            // 仅发送文本
            $params['text'] = $broadcast_text;
            $response = sendTelegramRequest($bot_token, 'sendMessage', $params);
        }

        if ($response && isset($response['ok']) && $response['ok']) {
            $success_count++;
        } else {
            $fail_count++;
            $error_desc = $response['description'] ?? 'Unknown API Error';
            logBroadcastError("Failed for user_id {$target_user_id}: {$error_desc}");
        }
    } catch (Exception $e) {
        $fail_count++;
        logBroadcastError("Exception for user_id {$target_user_id}: " . $e->getMessage());
    }
    
    // 避免触发洪水
    usleep(100000); 
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
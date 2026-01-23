<?php
ignore_user_abort(true); // 提交给后台
set_time_limit(0);      // stl0
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

// 获取当前域名和路径
function getCurrentBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    
    // 确保路径以/结尾
    $script_dir = rtrim($script_dir, '/') . '/';
    
    return $protocol . '://' . $host . $script_dir;
}

// 发送HTTP请求到下一个广播文件
function sendToNextBroadcast($base_url, $file_name, $data) {
    $url = $base_url . $file_name;
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // 异步执行，不等待响应
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        logBroadcastError("Error calling next broadcast: " . $error);
    }
    
    return $response;
}

// 获取参数
$bot_token = $_REQUEST['token'] ?? '';
$broadcast_text = $_REQUEST['text'] ?? '';
$photo_file_id = $_REQUEST['photo'] ?? '';
$user_ids_json = $_REQUEST['users'] ?? '';
$admin_chat_id = $_REQUEST['admin_id'] ?? '';
$batch_number = intval($_REQUEST['batch'] ?? 1);

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

$total_users = count($user_ids);
$max_per_batch = 100;

// 向管理员发送开始通知
if ($batch_number == 1) {
    sendTelegramRequest($bot_token, 'sendMessage', [
        'chat_id' => $admin_chat_id,
        'text' => "⏳ 广播任务已启动...\n目标用户: " . count($user_ids) . " 人。\n预计分批: " . ceil(count($user_ids) / $max_per_batch) . " 批。\n完成后将向您发送报告。"
    ]);
}

// 计算当前批次处理的用户
$start_index = ($batch_number - 1) * $max_per_batch;
$current_batch_users = array_slice($user_ids, $start_index, $max_per_batch);

// 执行当前批次广播
$batch_total = count($current_batch_users);
$success_count = 0;
$fail_count = 0;

logBroadcastError("Starting batch {$batch_number}, processing {$batch_total} users");

foreach ($current_batch_users as $target_user_id) {
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
    usleep(50000); 
}

// 检查是否还有更多用户需要处理
$processed_count = $batch_number * $max_per_batch;
if ($processed_count < $total_users) {
    // 还有剩余用户，传递给下一个广播文件
    $remaining_users = array_slice($user_ids, $processed_count);
    $next_batch_number = $batch_number + 1;
    
    // 自动检测下一个文件名
    $current_file = basename($_SERVER['SCRIPT_NAME']);
    $next_file = '';
    
    if ($current_file == 'broadcast.php') {
        $next_file = 'broadcast2.php';
    } else {
        // 从文件名提取数字
        preg_match('/broadcast(\d+)\.php/', $current_file, $matches);
        if (isset($matches[1])) {
            $next_num = intval($matches[1]) + 1;
            $next_file = "broadcast{$next_num}.php";
        } else {
            $next_file = 'broadcast2.php';
        }
    }
    
    // 获取当前域名基础URL
    $base_url = getCurrentBaseUrl();
    
    // 准备传递给下一个文件的数据
    $next_data = [
        'token' => $bot_token,
        'text' => $broadcast_text,
        'photo' => $photo_file_id,
        'users' => json_encode($remaining_users),
        'admin_id' => $admin_chat_id,
        'batch' => $next_batch_number
    ];
    
    logBroadcastError("Passing remaining " . count($remaining_users) . " users to {$next_file}");
    
    // 发送到下一个文件
    sendToNextBroadcast($base_url, $next_file, $next_data);
    
    // 当前批次完成报告
    $batch_report = "📦 批次 {$batch_number} 完成\n";
    $batch_report .= "处理用户: {$batch_total} 人\n";
    $batch_report .= "成功: {$success_count} 人\n";
    $batch_report .= "失败: {$fail_count} 人\n";
    $batch_report .= "剩余用户: " . count($remaining_users) . " 人\n";
    $batch_report .= "已传递到下一批次继续处理";
    
    sendTelegramRequest($bot_token, 'sendMessage', [
        'chat_id' => $admin_chat_id,
        'text' => $batch_report
    ]);
    
    logBroadcastError("Batch {$batch_number} completed. Success: {$success_count}, Failed: {$fail_count}. Passed to next batch.");
    
    echo "Batch {$batch_number} completed. Processing next batch...";
} else {
    // 所有批次完成，发送最终报告
    $report_message = "✅ 广播完成！\n\n";
    $report_message .= "📊 最终报告:\n";
    $report_message .= "总批次: {$batch_number} 批\n";
    $report_message .= "总目标: {$total_users} 人\n";
    $report_message .= "总成功: {$success_count} 人（当前批次）\n";
    $report_message .= "总失败: {$fail_count} 人（当前批次）\n";
    
    sendTelegramRequest($bot_token, 'sendMessage', [
        'chat_id' => $admin_chat_id,
        'text' => $report_message
    ]);
    
    logBroadcastError("All batches completed. Final batch {$batch_number}: Success: {$success_count}, Failed: {$fail_count}");
    
    echo "Broadcast task completed";
}
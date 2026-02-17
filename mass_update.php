<?php

// 发布环境不带日志版
// cf
define('DB_HOST', 'localhost');
define('DB_USER', '数据库名');
define('DB_PASS', '数据库密码');
define('DB_NAME', '数据库名');

define('BOT_TOKEN', '你的TOKEN'); // tk
define('MAIN_BOT_DOMAIN', '你的根域名');
define('USER_DATA_BASE_DIR', __DIR__ . '/userdata/');
define('COPY_SOURCE_FILE', __DIR__ . '/copy/bot.php');

// 后台
ignore_user_abort(true);
set_time_limit(0); // 取消超时限制

$admin_chat_id = $_POST['admin_id'] ?? null;

//con db
function connectDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        // 移除日志记录
        return null; 
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
//tg msg
function sendTelegramMsg($chat_id, $text) {
    if (!$chat_id) return;
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
}


$conn = connectDB();
if (!$conn) {
    sendTelegramMsg($admin_chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 批量更新失败：数据库连接错误。");
    exit;
}

// 检查源文件是否存在
if (!file_exists(COPY_SOURCE_FILE)) {
    sendTelegramMsg($admin_chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 批量更新失败：源文件 `/copy/bot.php` 不存在。");
    exit;
}

// 获取所有机器人
$sql = "SELECT owner_id, bot_token, bot_username, secret_token FROM `token`";
$result = $conn->query($sql);

if (!$result) {
    sendTelegramMsg($admin_chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 批量更新失败：查询 Token 表失败。");
    exit;
}

$bots = $result->fetch_all(MYSQLI_ASSOC);
$total_bots = count($bots);
$success_count = 0;
$fail_count = 0;

sendTelegramMsg($admin_chat_id, "<tg-emoji emoji-id=\"5900104897885376843\">⏳</tg-emoji> 开始处理 {$total_bots} 个机器人，请耐心等待...");

// read
$source_content = file_get_contents(COPY_SOURCE_FILE);

foreach ($bots as $bot) {
    $username = $bot['bot_username'];
    $token = $bot['bot_token'];
    $owner_id = $bot['owner_id'];
    $secret_token = !empty($bot['secret_token']) ? $bot['secret_token'] : bin2hex(random_bytes(32));

    // prepare way
    $bot_dir = USER_DATA_BASE_DIR . $username;
    $bot_file = $bot_dir . '/bot.php';

    // 确保目录存在
    if (!is_dir($bot_dir)) {
        mkdir($bot_dir, 0755, true);
    }

    // delete
    if (file_exists($bot_file)) {
        unlink($bot_file);
    }

    // 变量
    $placeholders = [
        '__SUB_BOT_ADMIN_ID__',
        '__SUB_BOT_USER_TABLE__',
        'YOUR_SUB_BOT_TOKEN_HERE',
        '__YOUR_SECRET_TOKEN__' 
    ];
    $replacements = [
        $owner_id,      
        $username,      
        $token,         
        $secret_token   
    ];

    $new_content = str_replace($placeholders, $replacements, $source_content);

    $write_result = file_put_contents($bot_file, $new_content);

    if ($write_result === false) {
        $fail_count++;
        continue;
    }

    $webhook_url = MAIN_BOT_DOMAIN . '/userdata/' . $username . '/bot.php';
    $api_url = "https://api.telegram.org/bot{$token}/setWebhook";
    
    $params = [
        'url' => $webhook_url,
        'secret_token' => $secret_token,
        'drop_pending_updates' => false // 通常更新时不丢弃消息
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // timeout
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_json = json_decode($response, true);

    if ($http_code == 200 && isset($res_json['ok']) && $res_json['ok'] === true) {
        $success_count++;
    } else {
        $fail_count++;
    }
    
    // sleep
    usleep(50000); 
}

// msg
$msg = "<tg-emoji emoji-id=\"5985780596268339498\">✅</tg-emoji> <b>批量更新完成</b>\n\n";
$msg .= "<tg-emoji emoji-id=\"5931415565955503486\">🤖</tg-emoji> 总数: <code>{$total_bots}</code>\n";
$msg .= "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 成功: <code>{$success_count}</code>\n";
$msg .= "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 失败: <code>{$fail_count}</code>\n";
$msg .= "<tg-emoji emoji-id=\"5936170807716745162\">📄</tg-emoji> 核心代码已从 <code>/copy/bot.php</code> 同步。\n";
$msg .= "<tg-emoji emoji-id=\"5883964170268840032\">🔗</tg-emoji> Webhook 已重新注册。";

sendTelegramMsg($admin_chat_id, $msg);
?>

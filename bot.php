<?php

define('MY_SECRET_TOKEN', '你的密钥'); 

$received_token = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if ($received_token !== MY_SECRET_TOKEN) {
    // 记录非法请求
    error_log("Unauthorized webhook access attempt. Secret token did not match.");
    // 返回 403
    http_response_code(403);
    die('你是黑客吗');
}

//定义一些东西
define('BOT_TOKEN', '你的TOKEN');
define('MAIN_BOT_DOMAIN', '你的根域名');
define('DB_HOST', 'localhost');
define('DB_USER', '数据库名');
define('DB_PASS', '数据库密码');
define('DB_NAME', '数据库名');
define('USER_DATA_BASE_DIR', __DIR__ . '/userdata/');
define('COPY_SOURCE_DIR', __DIR__ . '/copy/');
define('CONFIG_FILE', __DIR__ . '/你的目录/config.txt');
require_once __DIR__ . '/OkayPay.php';


/**
 * 建立数据库连接。
 * @return mysqli|null 数据库连接对象
 */
function connectDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log("Database Connection Failed: " . $conn->connect_error);
        return null; 
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * 递归复制文件和目录。
 * @param string $source 源路径
 * @param string $dest 目标路径
 * @return bool 是否成功
 */
function recursiveCopy($source, $dest) {
    if (!file_exists($source)) {
        return false;
    }
    
    if (!is_dir($dest)) {
        // 递归创建目录
        mkdir($dest, 0755, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $dest_path = $dest . '/' . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($dest_path)) {
                mkdir($dest_path);
            }
        } else {
            copy($item, $dest_path);
        }
    }
    
    return true;
}


function custom_error_log($message) {
    $log_file = 'err.log'; 
    $timestamp = date("[Y-m-d H:i:s]");
    $log_message = $timestamp . " " . $message . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND);
}


function createNewBotTable($newTableName, $adminUserId) {
    $conn = connectDB();
    if (!$conn) {
        error_log("Database connection failed for table creation.");
        return false;
    }

    // 安全处理
    $safeTableName = '`' . $conn->real_escape_string($newTableName) . '`';
    // 防止SQL注入
    $safeAdminId = (int)$adminUserId;

    // 事务
    $conn->begin_transaction();

    // 1. 创建表的 SQL 语句
    $sql_create = "CREATE TABLE {$safeTableName} (
      `id` bigint(20) UNSIGNED NOT NULL COMMENT 'Telegram User ID',
      `username` varchar(255) DEFAULT NULL COMMENT 'Telegram Username',
      `first_name` varchar(255) NOT NULL COMMENT 'Telegram First Name',
      `last_name` varchar(255) DEFAULT NULL COMMENT 'Telegram Last Name',
      `registered_at` datetime NOT NULL COMMENT '注册时间',
      `role` varchar(50) NOT NULL DEFAULT 'user' COMMENT '用户身份',
      `input_state` varchar(50) DEFAULT 'none',
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Telegram Bot 用户信息表';";

    // 2. 管理员
    $sql_insert_admin = "INSERT INTO {$safeTableName} (`id`, `username`, `first_name`, `last_name`, `registered_at`, `role`, `input_state`) VALUES
    ({$safeAdminId}, 'ADMIN', 'ADMIN', 'ADMIN', NOW(), 'admin', 'none');";

    try {
        // 执行创建表
        if ($conn->query($sql_create) !== TRUE) {
            throw new Exception("Error creating table {$safeTableName}: " . $conn->error);
        }
        error_log("Table {$safeTableName} created successfully.");

        // 执行插入管理员
        if ($conn->query($sql_insert_admin) !== TRUE) {
            throw new Exception("Error inserting admin into {$safeTableName}: " . $conn->error);
        }
        error_log("Admin user {$safeAdminId} inserted into {$safeTableName}.");

        // 如果一切顺利，提交事务
        $conn->commit();
        $conn->close();
        return true;

    } catch (Exception $e) {
        // 如果有任何错误，回滚事务
        error_log($e->getMessage());
        $conn->rollback();
        $conn->close();
        return false;
    }
}
// 转义
function escapeMarkdownV2($text) {
    $replacements = [
        '\\' => '\\\\', '*' => '\\*', '_' => '\\_', '`' => '\\`', 
        '[' => '\\[', ']' => '\\]', '(' => '\\(', ')' => '\\)', 
        '~' => '\\~', '>' => '\\>', '#' => '\\#', '+' => '\\+', 
        '-' => '\\-', '=' => '\\=', '|' => '\\|', '{' => '\\{', 
        '}' => '\\}', '.' => '\\.', '!' => '\\!'
    ];
    return strtr($text, $replacements);
}


function getAdmins() {
    $conn = connectDB();
    if (!$conn) {
        return [];
    }

    $admins = [];
    $admin_identity = 'admin';
    $stmt = $conn->prepare("SELECT user_id, username FROM user WHERE identity = ?");
    $stmt->bind_param("s", $admin_identity);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }

    $stmt->close();
    $conn->close();
    return $admins;
}

/**
 * 获取所有用户的 ID，用于广播。
 * @return array 包含所有 user_id 的数组
 */
function getAllUsers() {
    $conn = connectDB();
    if (!$conn) {
        return [];
    }

    $users = [];
    $result = $conn->query("SELECT user_id FROM user");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row['user_id'];
        }
        $result->free();
    }

    $conn->close();
    return $users;
}


function getStatistics() {
    $conn = connectDB();
    if (!$conn) {
        // 数据库连接失败时返回默认值
        return [
            'total_users' => 0,
            'total_admins' => 0,
            'total_bots' => 0
        ];
    }

    // 1. 获取总用户数
    $result_users = $conn->query("SELECT COUNT(*) FROM user");
    $total_users = $result_users ? $result_users->fetch_row()[0] : 0;
    
    // 2. 获取管理员数量
    $result_admins = $conn->query("SELECT COUNT(*) FROM user WHERE identity = 'admin'");
    $total_admins = $result_admins ? $result_admins->fetch_row()[0] : 0;
    
    // 3. 获取 Bot 数量
    $result_bots = $conn->query("SELECT COUNT(*) FROM token");
    $total_bots = $result_bots ? $result_bots->fetch_row()[0] : 0;

    $conn->close();

    return [
        'total_users' => $total_users,
        'total_admins' => $total_admins,
        'total_bots' => $total_bots
    ];
}

/**
 * 检查指定的 Bot Token 是否已存在于 token 表中。
 * @param string $token 要检查的 Bot Token。
 * @return bool 如果 Token 存在则返回 true，否则返回 false。
 */
function isTokenExists($token) {
    $conn = connectDB();
    if (!$conn) {
        // 如果数据库连接失败，记录错误日志
        error_log("Database connection failed for isTokenExists check.");
        return false;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM `token` WHERE bot_token = ?");
    if (!$stmt) {
        error_log("Database preparation failed for isTokenExists: " . $conn->error);
        $conn->close();
        return false;
    }

    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();

    $stmt->close();
    $conn->close();

    return $count > 0;
}

/**
 * 设置用户的身份（identity）。
 * @param int $user_id 目标用户ID
 * @return bool 是否成功
 */
function setAdminIdentity($user_id, $identity) {
    $conn = connectDB();
    if (!$conn) {
        return false;
    }
    
    if (!in_array($identity, ['user', 'admin'])) {
        return false;
    }
    
    // 检查用户是否存在
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM user WHERE user_id = ?");
    $stmt_check->bind_param("i", $user_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_row();
    $user_exists = $row_check[0] > 0;
    $stmt_check->close();
    
    if (!$user_exists) {
        $conn->close();
        return false; 
    }

    // 更新身份
    $stmt = $conn->prepare("UPDATE user SET identity = ? WHERE user_id = ?");
    $stmt->bind_param("si", $identity, $user_id);
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    
    return $success;
}


function getBotsByOwnerId($owner_id) {
    $conn = connectDB();
    if (!$conn) {
        error_log("Database connection failed for getBotsByOwnerId.");
        return [];
    }

    $stmt = $conn->prepare("SELECT bot_username, bot_token FROM `token` WHERE owner_id = ? ORDER BY created_at DESC");
    if (!$stmt) {
        error_log("Database preparation failed for getBotsByOwnerId: " . $conn->error);
        $conn->close();
        return [];
    }
    
    $owner_id_int = (int)$owner_id;
    $stmt->bind_param("i", $owner_id_int);
    $stmt->execute();
    $result = $stmt->get_result();

    $bots = [];
    while ($row = $result->fetch_assoc()) {
        $bots[] = $row;
    }

    $stmt->close();
    $conn->close();
    return $bots;
}
/**
 * 发送/编辑用户的机器人列表菜单。
 * @param int $chat_id 聊天ID
 * @param int $user_id Telegram 用户 ID
 */
function sendMyBotsMenu($chat_id, $user_id, $message_id = null) {
    // 1. 获取用户拥有的所有机器人
    $bots = getBotsByOwnerId($user_id);
    
    $message = "🤖 *我的机器人*\n\n";
    $keyboard = [];

    if (empty($bots)) {
        $message .= "您尚未创建任何机器人。\n点击 *➕ 创建机器人* 即可开始。";
    } else {
        $message .= "以下是您拥有的机器人列表（共 ".count($bots)." 个）：\n\n";
        
        // 2. 为每个机器人创建按钮
        foreach ($bots as $bot) {
            $username = $bot['bot_username'];
            
            $bot_name_display = "@" . $username;
            
            // 构造 Telegram 机器人链接
            $bot_link = "https://t.me/{$username}";
            
            $row = [
                ['text' => $bot_name_display, 'url' => $bot_link], 
                ['text' => '⚙️ 设置', 'callback_data' => "bot_settings:{$username}"], 
            ];
            $keyboard[] = $row;
        }
        $message .= "点击机器人名字可快速跳转或启动机器人。\n点击 *⚙️ 设置* 来管理您的机器人。";
    }
    
    // 3. 添加返回主菜单按钮
    $keyboard[] = [['text' => '🔙 返回主菜单', 'callback_data' => 'main_menu_back']];
    
    $reply_markup = ['inline_keyboard' => $keyboard];

    $params = [
        'chat_id' => $chat_id,
        'text' => $message,
        'reply_markup' => json_encode($reply_markup),
        'parse_mode' => 'Markdown' 
    ];
    
    // 4. 发送或编辑消息
    if ($message_id) {
        $params['message_id'] = $message_id;
        sendTelegramApi('editMessageText', $params);
    } else {
        sendTelegramApi('sendMessage', $params);
    }
}

/**
 * 复制完文件后，为新的克隆机器人设置 Webhook。
 * @param string $new_bot_token 新克隆机器人的 Bot Token。
 * @param string $new_bot_username 新克隆机器人的 Username。
 * @return array|null API响应的JSON解码数组。
 */
function setNewBotWebhookForClonedBot($new_bot_token, $new_bot_username) {
    $base_url = MAIN_BOT_DOMAIN;

    // 构造完整的 Webhook URL
    $webhook_url = $base_url . '/userdata/' . $new_bot_username . '/bot.php';
    $api_url = 'https://api.telegram.org/bot' . $new_bot_token . '/setWebhook';
    $secret_token = bin2hex(random_bytes(32)); 

    $params = [
        'url' => $webhook_url,
        'secret_token' => $secret_token
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("setWebhook cURL Error for bot {$new_bot_username}: " . $error);
        return null;
    }

    $result = json_decode($response, true);
    
    // 记录 Webhook 设置结果，方便调试
    if ($result && $result['ok'] === true) {
        error_log("Webhook set successfully for bot {$new_bot_username}. URL: {$webhook_url}");
        
        $bot_php_file = USER_DATA_BASE_DIR . $new_bot_username . '/bot.php';
        if (file_exists($bot_php_file)) {
            $file_content = file_get_contents($bot_php_file);
            $new_content = str_replace('__YOUR_SECRET_TOKEN__', $secret_token, $file_content);
            file_put_contents($bot_php_file, $new_content);
        }

    } else {
         error_log("Failed to set Webhook for bot {$new_bot_username}. Response: " . ($response ?: 'No response'));
    }

    return $result;
}
/**
 * 检查用户是否存在于数据库中，如果不存在则插入记录，并返回用户的当前操作习惯。
 */
function ensureUserExistsAndGetMode($user_id, $username) {
    $conn = connectDB();
    if (!$conn) {
        // 如果连接失败，默认使用 inline 模式
        return 'inline';
    }

    $username = $conn->real_escape_string($username);
    
    // 1. 检查用户是否存在
    $stmt = $conn->prepare("SELECT mode, number, sta, identity, created_at FROM user WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // 用户已存在，返回其操作习惯
        $row = $result->fetch_assoc();
        $mode = $row['mode'] ?? 'inline'; // 确保有默认值
        $stmt->close();
        $conn->close();
        return $mode;
    } else {
        // 用户不存在，插入新记录
        $default_mode = 'inline';
        $default_number = 0; // 下级机器人数量默认为 0
        $default_sta = 'none'; // 输入状态默认为 none
        $default_identity = 'user'; // 身份默认为 user
        
        $stmt_insert = $conn->prepare("INSERT INTO user (user_id, username, mode, number, sta, identity, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt_insert->bind_param("isssis", $user_id, $username, $default_mode, $default_number, $default_sta, $default_identity);
        $stmt_insert->execute();
        $stmt_insert->close();
        $conn->close();
        return $default_mode;
    }
}

/**
 * 获取用户的个人资料信息。
 * @param int $user_id Telegram 用户 ID
 * @return array|null 用户的资料数组或 null
 */
function getUserProfile($user_id) {
    $conn = connectDB();
    if (!$conn) {
        return null;
    }

    $stmt = $conn->prepare("SELECT user_id, username, created_at FROM user WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $profile = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        return $profile;
    } 
    
    $stmt->close();
    $conn->close();
    return null;
}

/**
 * 获取用户的身份信息。
 */
function getUserIdentity($user_id) {
    $conn = connectDB();
    if (!$conn) {
        return 'none';
    }

    $stmt = $conn->prepare("SELECT identity FROM user WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $identity = $row['identity'] ?? 'user';
        $stmt->close();
        $conn->close();
        return $identity;
    } 
    
    $stmt->close();
    $conn->close();
    return 'none';
}

/**
 * 获取用户的当前输入状态 (sta)。
 */
function getUserState($user_id) {
    $conn = connectDB();
    if (!$conn) {
        return 'none';
    }

    $stmt = $conn->prepare("SELECT sta FROM user WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $state = 'none';

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $state = $row['sta'] ?? 'none';
    } 
    $stmt->close();
    $conn->close();
    return $state;
}

/**
 * 设置用户的输入状态
 * @param int $user_id Telegram 用户 ID
 * @param string $state 新的输入状态
 * @return bool 是否成功
 */
function setUserState($user_id, $state) {
    $conn = connectDB();
    if (!$conn) {
        return false;
    }

    // 更新用户字段
    $stmt = $conn->prepare("UPDATE user SET sta = ? WHERE user_id = ?");
    $stmt->bind_param("si", $state, $user_id);
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $success;
}

function getBotsForAdmin($page = 1, $limit = 5, $search_term = null, $search_by = null) {
    $conn = connectDB();
    if (!$conn) return ['bots' => [], 'total_pages' => 0, 'current_page' => 1];

    $count_sql = "SELECT COUNT(*) FROM `token`";
    $data_sql = "SELECT id, owner_id, bot_username, cost, created_at FROM `token`";
    $where_clause = "";
    $params = [];
    $types = "";

    if ($search_term !== null && in_array($search_by, ['owner_id', 'bot_username'])) {
        $where_clause = " WHERE `{$search_by}` = ?";
        $params[] = $search_term;
        $types .= ($search_by === 'owner_id') ? 'i' : 's';
    }

    // 获取总数用于分页
    $count_sql .= $where_clause;
    $stmt_count = $conn->prepare($count_sql);
    if ($types) $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $total_pages = ceil($total_records / $limit);
    $stmt_count->close();

    // 获取当页数据
    $offset = ($page - 1) * $limit;
    $data_sql .= $where_clause . " ORDER BY `id` DESC LIMIT ? OFFSET ?";
    $data_params = $params;
    $data_params[] = $limit;
    $data_params[] = $offset;
    $data_types = $types . 'ii';
    
    $stmt_data = $conn->prepare($data_sql);
    $stmt_data->bind_param($data_types, ...$data_params);
    $stmt_data->execute();
    $result = $stmt_data->get_result();
    $bots = $result->fetch_all(MYSQLI_ASSOC);
    
    $stmt_data->close();
    $conn->close();

    return ['bots' => $bots, 'total_pages' => (int)$total_pages, 'current_page' => (int)$page];
}


function updateBotCost($bot_username, $new_cost) {
    if (!in_array($new_cost, ['pay', 'free'])) {
        return false;
    }
    $conn = connectDB();
    if (!$conn) return false;

    $stmt = $conn->prepare("UPDATE `token` SET `cost` = ? WHERE `bot_username` = ?");
    if (!$stmt) {
        error_log("DB prepare failed for updateBotCost: " . $conn->error);
        $conn->close();
        return false;
    }
    $stmt->bind_param("ss", $new_cost, $bot_username);
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $success;
}

/**
 * 发送/编辑管理员的Bot管理菜单。
 * @param int $chat_id
 * @param int|null $message_id
 * @param int $page
 * @param string|null $search_term
 * @param string|null $search_by
 */
function sendAdminBotManagementMenu($chat_id, $message_id = null, $page = 1, $search_term = null, $search_by = null) {
    $limit = 5;
    $data = getBotsForAdmin($page, $limit, $search_term, $search_by);
    $bots = $data['bots'];
    $total_pages = $data['total_pages'];
    $current_page = $data['current_page'];

    $keyboard = [];
    $message = "🤖 *机器人管理面板*\n\n";

    if (empty($bots)) {
        $message .= "数据库中没有找到任何机器人记录。";
        if($search_term) $message .= "\n\n*当前搜索条件:*\n字段: `{$search_by}`\n关键词: `{$search_term}`";
    } else {
        foreach ($bots as $bot) {
            $bot_username = $bot['bot_username'];
            $owner_id = $bot['owner_id'];
            $cost = strtoupper($bot['cost']);
            $cost_icon = ($cost === 'PAY') ? '💰' : '🆓';

            // Bot
            $keyboard[] = [['text' => "{$cost_icon} @{$bot_username} (Owner: {$owner_id})", 'callback_data' => 'admin_noop']];
            // 操作
            $keyboard[] = [
                ['text' => '🗑️ 删除', 'callback_data' => "admin_del_bot_confirm:{$bot_username}"],
                ['text' => '设为付费', 'callback_data' => "admin_set_cost:pay:{$bot_username}:{$current_page}"],
                ['text' => '设为免费', 'callback_data' => "admin_set_cost:free:{$bot_username}:{$current_page}"]
            ];
        }
    }
    
    // 分页
    $pagination_row = [];
    $search_suffix = ($search_term !== null) ? ":{$search_by}:{$search_term}" : "::";
    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        $pagination_row[] = ['text' => '⬅️ 上一页', 'callback_data' => "admin_bot_page:{$prev_page}{$search_suffix}"];
    }
    if ($total_pages > 0) {
        $pagination_row[] = ['text' => "{$current_page} / {$total_pages}", 'callback_data' => 'admin_noop'];
    }
    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        $pagination_row[] = ['text' => '下一页 ➡️', 'callback_data' => "admin_bot_page:{$next_page}{$search_suffix}"];
    }
    if (!empty($pagination_row)) {
        $keyboard[] = $pagination_row;
    }

    // 功能按钮
    $keyboard[] = [
        ['text' => '🔍 按OwnerID搜索', 'callback_data' => 'admin_search_bot:owner_id'],
        ['text' => '🔍 按Bot名搜索', 'callback_data' => 'admin_search_bot:bot_username']
    ];
    //new key[recover and update webhook 和code]
    if (!$search_term) {
        $keyboard[] = [
            ['text' => '🔄 强刷所有Bot内核 ', 'callback_data' => 'admin_force_update_all_bots']
        ];
    }
    if ($search_term) {
         $keyboard[] = [['text' => '🔄 清除搜索结果', 'callback_data' => 'admin_bot_management']];
    }
    $keyboard[] = [['text' => '🔙 返回管理面板', 'callback_data' => 'admin_panel_back']];

    $params = [
        'chat_id' => $chat_id,
        'text' => $message,
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        'parse_mode' => 'Markdown'
    ];

    if ($message_id) {
        $params['message_id'] = $message_id;
        sendTelegramApi('editMessageText', $params);
    } else {
        sendTelegramApi('sendMessage', $params);
    }
}

/**
 * 切换用户的操作习惯。
 * @param int $user_id Telegram 用户 ID
 * @param string $current_mode 当前操作习惯
 * @return string 切换后的操作习惯
 */
function toggleUserMode($user_id, $current_mode) {
    $new_mode = ($current_mode === 'inline') ? 'bottom_keyboard' : 'inline';
    $conn = connectDB();
    if (!$conn) {
        return $current_mode; // 切换失败，返回旧模式
    }

    $stmt = $conn->prepare("UPDATE user SET mode = ? WHERE user_id = ?");
    $stmt->bind_param("si", $new_mode, $user_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    return $new_mode;
}


/**
 * 记录新的机器人到数据库。
 */
function recordBotToken($owner_id, $token, $bot_username, $secret_token) {
    $conn = connectDB();
    if (!$conn) return false;

    // 检查机器人是否已经存在
    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM `token` WHERE bot_username = ?");
    $check_stmt->bind_param("s", $bot_username);
    $check_stmt->execute();
    $check_stmt->bind_result($count);
    $check_stmt->fetch();
    $check_stmt->close();

    if ($count > 0) {
        // 机器人已存在
        $stmt = $conn->prepare("UPDATE `token` SET owner_id = ?, bot_token = ?, secret_token = ?, updated_at = NOW() WHERE bot_username = ?");
        $owner_id_int = (int)$owner_id;
        $stmt->bind_param("isss", $owner_id_int, $token, $secret_token, $bot_username);
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();
        return $result;
    }

    // 机器人不存在，插入新记录
    $stmt = $conn->prepare("INSERT INTO `token` (owner_id, bot_token, bot_username, secret_token, cost, created_at) VALUES (?, ?, ?, ?, 'free', NOW())");
    
    $owner_id_int = (int)$owner_id;
    $stmt->bind_param("isss", $owner_id_int, $token, $bot_username, $secret_token);
    
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}
/**
 * 更新 config.txt 文件中的配置项。
 */
function updateConfigFile($key, $value) {
    $file_path = CONFIG_FILE;
    // 检查文件是否存在且可写
    if (!file_exists($file_path) || !is_writable($file_path)) {
        error_log("Config file not found or not writable: " . $file_path);
        return false;
    }

    $lines = file($file_path, FILE_IGNORE_NEW_LINES);
    $new_lines = [];
    $found = false;
    $value = trim($value); // 清理新值

    foreach ($lines as $line) {
        // 清理行尾可能的注释和值
        $clean_line = trim(explode('#', $line, 2)[0]); 
        
        if (strpos($clean_line, '=') !== false) {
            list($k, $v) = explode('=', $clean_line, 2);
            $k = trim($k);
            if ($k === $key) {
                // 替换找到的键值
                $new_lines[] = $key . '=' . $value;
                $found = true;
                continue;
            }
        }
        $new_lines[] = $line;
    }

    // 如果未找到该键，则在文件末尾添加
    if (!$found) {
        // 在文件末尾添加一个空行和新的键值对
        $new_lines[] = "\n" . $key . '=' . $value;
    }

    // 将新内容写回文件
    $result = file_put_contents($file_path, implode("\n", $new_lines));
    return $result !== false;
}

/**
 * 从 token 表中获取特定 bot_username 的所有信息。
 */
function getBotInfoByUsername($username) {
    $conn = connectDB();
    if (!$conn) {
        error_log("Database connection failed for getBotInfoByUsername.");
        return null;
    }

    $stmt = $conn->prepare("SELECT bot_token, cost, created_at FROM `token` WHERE bot_username = ?");
    if (!$stmt) {
        error_log("Database preparation failed for getBotInfoByUsername: " . $conn->error);
        $conn->close();
        return null;
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $bot_info = $result->fetch_assoc();

    $stmt->close();
    $conn->close();
    return $bot_info;
}


/**
 * 发送续费/升级机器人选择菜单。
 * @param int $chat_id
 * @param int $user_id
 * @param int|null $message_id
 */
function sendUpgradeSelectionMenu($chat_id, $user_id, $message_id = null) {
    $bots = getBotsByOwnerId($user_id);
    $message = "⭐ *续费/升级*\n\n请选择您想升级的机器人：";
    $keyboard = [];

    if (empty($bots)) {
        $message = "您没有任何机器人可供升级。请先创建机器人。";
    } else {
        foreach ($bots as $bot) {
            $bot_info = getBotInfoByUsername($bot['bot_username']);
            $cost_status = $bot_info['cost'] ?? 'free';
            $bot_display = "@{$bot['bot_username']} - " . ($cost_status === 'pay' ? '付费版' : '免费版');
            
            $action_button = [];
            if ($cost_status === 'free') {
                $action_button = ['text' => '去解锁高级版', 'callback_data' => "do_upgrade:{$bot['bot_username']}"];
            } else {
                $action_button = ['text' => '已解锁', 'url' => "https://t.me/{$bot['bot_username']}"];
            }
            
            $keyboard[] = [
                ['text' => $bot_display, 'callback_data' => 'noop'],
                $action_button
            ];
        }
    }
    
    $keyboard[] = [['text' => '🔙 返回主菜单', 'callback_data' => 'main_menu_back']];
    $params = ['chat_id' => $chat_id, 'text' => $message, 'reply_markup' => json_encode(['inline_keyboard' => $keyboard]), 'parse_mode' => 'Markdown'];
    
    if ($message_id) {
        $params['message_id'] = $message_id;
        sendTelegramApi('editMessageText', $params);
    } else {
        sendTelegramApi('sendMessage', $params);
    }
}

/**
 * 发送/编辑特定机器人的设置菜单。
 * @param int $chat_id 聊天ID
 * @param int $user_id Telegram 用户 ID
 * @param string $bot_username 目标机器人的用户名
 * @param int $message_id 要编辑的消息ID
 */
function sendBotSettingsMenu($chat_id, $user_id, $bot_username, $message_id) {
    $bot_info = getBotInfoByUsername($bot_username);

    if (!$bot_info) {
        $message = "❌ 无法找到机器人 *@{$bot_username}* 的信息。";
        $keyboard = [[['text' => '🔙 返回我的机器人', 'callback_data' => 'my_bots']]];
    } else {
        $cost_status = $bot_info['cost'] ?? 'free';
        $version_display = ($cost_status === 'free') ? '🎈 免费版' : '🌟 付费版';

        $message = "🤖 *机器管理 - @{$bot_username}*\n\n";
        $message .= "机器人 Token: `{$bot_info['bot_token']}`\n";
        $message .= "当前版本: *{$version_display}*\n";
        $message .= "到期时间: *无限制*\n\n";
        $message .= "💡这里为机器人管理页面,关于机器人内部的设置请前往私聊机器人。";

        $keyboard = [
            [['text' => '🧹清理缓存', 'callback_data' => "bot_action:sync:{$bot_username}"], ['text' => '🗑️ 删除机器人', 'callback_data' => "bot_action:delete:{$bot_username}"]],
        ];
        
        // 如果是免费版,添加升级按钮
        if ($cost_status === 'free') {
            $keyboard[] = [['text' => '⭐ 续费/升级', 'callback_data' => "upgrade_bot:{$bot_username}"]];
        }

        $keyboard[] = [['text' => '🔙 返回', 'callback_data' => 'my_bots']];
    }
    
    $params = [
        'chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $message,
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard]), 'parse_mode' => 'Markdown'
    ];
    sendTelegramApi('editMessageText', $params);
}

/**
 * 从 config.txt 文件中读取配置链接。
 */
function getConfigLink($key) {
    if (!defined('CONFIG_FILE') || !file_exists(CONFIG_FILE)) {
        return '#';
    }
    
    $config_data = [];
    $config_lines = file(CONFIG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($config_lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            // 移除值两端的空格或注释
            $v = trim(explode('#', $v, 2)[0]);
            $config_data[trim($k)] = $v;
        }
    }
    
    return $config_data[$key] ?? '#';
}

/**
 * 调用 Telegram Bot API
 */
function sendTelegramApi($method, $params) {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("cURL Error: " . $error);
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * 发送欢迎消息文本
 */
function getWelcomeMessageText() {
    return "*[ 双向工厂 ]*\n\n👋 你好!\n这里有功能最丰富的双向机器人。\n点击 【创建机器人】 即可开始克隆。\n\n您可使用 /mode 切换操作习惯";
}

/**
 * 从数据库中删除指定的机器人Token记录
 */
function deleteTokenRecord($bot_username) {
    $conn = connectDB();
    if (!$conn) return false;

    $stmt = $conn->prepare("DELETE FROM `token` WHERE bot_username = ?");
    if (!$stmt) {
        error_log("DB prepare failed for deleteTokenRecord: " . $conn->error);
        $conn->close();
        return false;
    }
    $stmt->bind_param("s", $bot_username);
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $success;
}

/**
 * 从数据库中删除机器人对应的用户表
 */
function deleteBotUserTable($bot_username) {
    $conn = connectDB();
    if (!$conn) return false;

    // 对表名进行安全处理，防止SQL注入
    $safeTableName = '`' . $conn->real_escape_string($bot_username) . '`';
    $sql = "DROP TABLE IF EXISTS {$safeTableName}";

    if ($conn->query($sql) === TRUE) {
        $conn->close();
        return true;
    } else {
        error_log("Error deleting table {$safeTableName}: " . $conn->error);
        $conn->close();
        return false;
    }
}

/**
 * delete 文件夹
 */
function deleteUserDataDirectory($bot_username) {
    $dir = USER_DATA_BASE_DIR . $bot_username;
    if (!is_dir($dir)) {
        return true; // 如果目录不存在，视为删除成功
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    try {
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir); // 最后删除顶层目录
        return true;
    } catch (Exception $e) {
        error_log("Error deleting directory {$dir}: " . $e->getMessage());
        return false;
    }
}

/**
 * 根据用户的 mode 发送相应的键盘和欢迎消息。
 */
function sendWelcomeMessageAndKeyboard($chat_id, $mode, $confirmation_message = null, $message_id = null) {
    // 1. 基础消息文本
    $message_text = getWelcomeMessageText();
    $reply_markup = [];

    if ($mode === 'bottom_keyboard') {
        $reply_keyboard_buttons = [
            ['➕ 创建机器人', '🤖 我的机器人'],
            ['⭐ 续费/升级', '💬 联系客服'],
            ['👤 个人中心'],
        ];

        $reply_markup = [
            'keyboard' => $reply_keyboard_buttons,
            'is_persistent' => true,
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
        
        if ($confirmation_message) {
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $confirmation_message,
            ]);
        }
        
    } else { 
        
        $message_text = str_replace("粘贴机器人 token 到这里或者", "", $message_text);


        if ($confirmation_message) {
            $remove_keyboard = [
                'remove_keyboard' => true,
                'selective' => true 
            ];
            
             sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $confirmation_message,
                'reply_markup' => json_encode($remove_keyboard), 
            ]);
        }


        $KEFUURL = getConfigLink('KEFUURL');
        $JIAOCHENGPINDAO = getConfigLink('JIAOCHENGPINDAO');

        $inline_keyboard = [
            [['text' => '➕ 创建机器人', 'callback_data' => 'create_bot']],
            [['text' => '⭐ 续费/升级', 'callback_data' => 'upgrade'], ['text' => '🤖 我的机器人', 'callback_data' => 'my_bots']],
            [['text' => '👤 个人中心', 'callback_data' => 'profile'], ['text' => '📖 教程频道', 'url' => $JIAOCHENGPINDAO]],
            [['text' => '💬 客服', 'url' => $KEFUURL]],
        ];

        $reply_markup = [
            'inline_keyboard' => $inline_keyboard
        ];
    }
    
    // 准备发送参数
    $params = [
        'chat_id' => $chat_id,
        'text' => $message_text,
        'reply_markup' => json_encode($reply_markup),
        'parse_mode' => 'Markdown'
    ];
    
    if ($message_id && $mode === 'inline') {
        $params['message_id'] = $message_id;
        sendTelegramApi('editMessageText', $params);
    } else {
        sendTelegramApi('sendMessage', $params);
    }
}

/**
 * 发送/编辑用户的个人资料信息。
 */
function sendUserProfileMenu($chat_id, $user_id, $message_id = null) {
    $profile = getUserProfile($user_id);
    
    if ($profile) {
        $username_display = $profile['username'] ? "@{$profile['username']}" : "N/A";
        // 格式化注册时间
        $registered_time = date('Y-m-d H:i:s', strtotime($profile['created_at']));

        $message = "*👤 个人中心*\n\n";
        $message .= "🆔 *用户 ID*: `{$profile['user_id']}`\n";
        $message .= "💬 *用户名*: `{$username_display}`\n";
        $message .= "🗓️ *注册时间*: `{$registered_time}`";
    } else {
        $message = "❌ 无法获取您的个人资料。请确保您已开始过 /start 命令。";
    }
    
    // 个人中心菜单的内联键盘
    $keyboard = [
        [['text' => '🔙 返回主菜单', 'callback_data' => 'main_menu_back']],
    ];
    $reply_markup = ['inline_keyboard' => $keyboard];

    $params = [
        'chat_id' => $chat_id,
        'text' => $message,
        'reply_markup' => json_encode($reply_markup),
        'parse_mode' => 'Markdown'
    ];
    
    if ($message_id) {
        $params['message_id'] = $message_id;
        sendTelegramApi('editMessageText', $params);
    } else {
        sendTelegramApi('sendMessage', $params);
    }
}


/**
 * 生成管理员专属面板的消息文本和内联键盘。
 */
function getAdminPanelMarkupAndText() {
    // 3. 准备管理面板内联键盘
    $admin_keyboard = [
        [['text' => '⚙️ 管理配置项', 'callback_data' => 'admin_manage_configs']],
        [['text' => '👤 用户管理', 'callback_data' => 'admin_user_management']], 
        [['text' => '🤖 Bot 管理', 'callback_data' => 'admin_bot_management']], 
        [['text' => '📊 统计信息', 'callback_data' => 'admin_stats']],
    ];

    $reply_markup = [
        'inline_keyboard' => $admin_keyboard
    ];
    
    $text = "尊敬的管理员，这是管理面板";

    return [
        'text' => $text,
        'reply_markup' => json_encode($reply_markup)
    ];
}

/**
 * 发送管理员专属面板和信息。
 */
function sendAdminPanel($chat_id) {
    // 1. 发送第一条确认消息：用户将看到的消息已发送
    sendTelegramApi('sendMessage', [
        'chat_id' => $chat_id,
        'text' => '👆🏻 这是用户将看到的消息。',
        'parse_mode' => 'Markdown'
    ]);

    // 2. 发送第二条分隔消息：管理员可见的提示
    sendTelegramApi('sendMessage', [
        'chat_id' => $chat_id,
        'text' => '👇🏻 本信息仅管理员可见。',
        'parse_mode' => 'Markdown'
    ]);

    // 3. 获取管理面板内容
    $panel_content = getAdminPanelMarkupAndText();

    // 4. 发送管理面板
    sendTelegramApi('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $panel_content['text'],
        'reply_markup' => $panel_content['reply_markup'],
        'parse_mode' => 'Markdown'
    ]);
}

/**
 * update ads.txt
 */
function updateAdsFile($content) {
    $file_path = __DIR__ . '/ads.txt';
    
    if (file_put_contents($file_path, $content) !== false) {
        error_log("ads.txt updated successfully.");
        return true;
    } else {
        error_log("Failed to update ads.txt: " . $file_path);
        return false;
    }
}

/**
 * 发送管理员配置项管理子菜单。
 */
function sendAdminConfigSubMenu($chat_id, $message_id) {
    $KEFUURL = getConfigLink('KEFUURL');
    $JIAOCHENGPINDAO = getConfigLink('JIAOCHENGPINDAO');
    $ADS = getConfigLink('ADS'); // 保留这行
    $OKPAYTOKEN = getConfigLink('OKPAYTOKEN');
    $OKPAYID = getConfigLink('OKPAYID');
    $COST = getConfigLink('COST');
    $COIN = getConfigLink('COIN');
    
    // 读取广告文件内容
    $ads_file_path = __DIR__ . '/ads.txt';
    $ads_content = file_exists($ads_file_path) ? file_get_contents($ads_file_path) : '暂无内容';
    
    $config_message = "当前配置值:\n\n";
    $config_message .= "客服链接: `{$KEFUURL}`\n";
    $config_message .= "教程频道: `{$JIAOCHENGPINDAO}`\n";
    $config_message .= "广告文件内容: `{$ads_content}`\n"; 
    $config_message .= "OKPAY Token: `{$OKPAYTOKEN}`\n";
    $config_message .= "OKPAY ID: `{$OKPAYID}`\n";
    $config_message .= "基础费用: `{$COST}`\n";
    $config_message .= "结算币种: `{$COIN}`\n\n";
    $config_message .= "请选择要修改的配置项:";
    
    $config_keyboard = [
        [['text' => '修改 客服链接', 'callback_data' => 'admin_set_kefu']],
        [['text' => '修改 教程频道', 'callback_data' => 'admin_set_jiaocheng']],
        [['text' => '修改 广告文件内容', 'callback_data' => 'admin_set_ads_content']], 
        [['text' => '修改 OKPAY TOKEN', 'callback_data' => 'admin_set_okpaytoken']],
        [['text' => '修改 OKPAY ID', 'callback_data' => 'admin_set_okpayid']],
        [['text' => '修改 基础费用', 'callback_data' => 'admin_set_cost']],
        [['text' => '修改 结算币种', 'callback_data' => 'admin_set_coin']],
        [['text' => '🔙 返回管理面板', 'callback_data' => 'admin_panel_back']],
    ];

    $reply_markup = [
        'inline_keyboard' => $config_keyboard
    ];
    
    $params = [
        'chat_id' => $chat_id,
        'text' => $config_message,
        'reply_markup' => json_encode($reply_markup),
        'parse_mode' => 'Markdown'
    ];

    if ($message_id) {
        $params['message_id'] = $message_id;
        sendTelegramApi('editMessageText', $params);
    } else {
        sendTelegramApi('sendMessage', $params);
    }
}

/**
 * 发送用户管理子菜单。
 */
function sendAdminUserManagementSubMenu($chat_id, $message_id) {
    $message = "👤 *用户管理*:\n\n请选择一个管理选项：";
    
    $keyboard = [
        [['text' => '👑 管理员设置', 'callback_data' => 'admin_settings']],
        [['text' => '🔙 返回管理面板', 'callback_data' => 'admin_panel_back']],
    ];

    $reply_markup = ['inline_keyboard' => $keyboard];
    $params = [
        'chat_id' => $chat_id,
        'text' => $message,
        'reply_markup' => json_encode($reply_markup),
        'parse_mode' => 'Markdown'
    ];
    
    $params['message_id'] = $message_id;
    sendTelegramApi('editMessageText', $params);
}

function writeLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$level] $message\n";
    file_put_contents('err.log', $log_entry, FILE_APPEND);
}

function sendAdminSettingsMenu($chat_id, $message_id) {
    $admins = getAdmins();
    if ($admins === false) { 
        writeLog("Failed to fetch admin list for chat ID: $chat_id.", 'ERROR');
        $admins = []; // 避免后续代码崩溃
    } else {
        writeLog("Successfully fetched " . count($admins) . " admins for settings menu.", 'INFO');
    }
    
  $admin_list = "*👑 当前管理员列表:*\n\n";

    if (!empty($admins)) {
        foreach ($admins as $admin) {
            
            $safe_username = str_replace('_', '\_', $admin['username']);
            
            $safe_user_id = str_replace('`', '', $admin['user_id']); 

            $username_display = $safe_username ? " (@{$safe_username})" : "";
            
            $admin_list .= "• ID: `{$safe_user_id}` {$username_display}\n"; 
        }
    }
    
    
    $keyboard = [
        [['text' => '➕ 添加管理员', 'callback_data' => 'admin_add_admin']],
        [['text' => '➖ 删除管理员', 'callback_data' => 'admin_remove_admin']],
        [['text' => '🔙 返回用户管理', 'callback_data' => 'admin_user_management']],
    ];

    $reply_markup = ['inline_keyboard' => $keyboard];
    $params = [
        'chat_id' => $chat_id,
        'text' => $admin_list,
        'reply_markup' => json_encode($reply_markup),
        'parse_mode' => 'Markdown'
    ];
    writeLog("Menu parameters prepared for chat ID: $chat_id. Action: " . ($message_id ? "Edit" : "Send") . " Message.", 'INFO');

    $api_method = $message_id ? 'editMessageText' : 'sendMessage';

    if ($message_id) {
        $params['message_id'] = $message_id;
    }
    
    $response = sendTelegramApi($api_method, $params);

    if (isset($response['ok']) && $response['ok'] === true) {
        writeLog("Telegram API call SUCCESS: $api_method for chat ID: $chat_id.", 'INFO');
    } else {
        $error_desc = isset($response['description']) ? $response['description'] : 'Unknown API Error';
        writeLog("Telegram API call FAILED: $api_method for chat ID: $chat_id. Error: $error_desc", 'ERROR');
    }
}

function handleCreateBotCommand($chat_id, $user_id) {
    setUserState($user_id, 'waiting_bot_token');

    $message_text = "📖 *克隆教程*\n\n";
    $message_text .= "无需代码、无需服务器，仅通过简单的交互即可创建自己的机器人。\n\n";
    $message_text .= "1. *创建机器人账户*\n";
    $message_text .= "↳ 1) 打开 [@BotFather](https://t.me/BotFather)\n";
    $message_text .= "↳ 2) 发送 `/newbot`\n";
    $message_text .= "↳ 3) 按指引设置机器人名字和 username，在设置时请注意 username *必须以 bot 结尾* (例如: MyAwesomeBot)\n";
    $message_text .= "↳ 4) 看到 `Done! Congratulations...` 即表示创建成功\n";
    $message_text .= "↳ 5) 成功后将获取到的 *Api token* 发送给本机器人\n\n";
    $message_text .= "2. *将创建完成的 token 发送给本机器人*\n\n";
    $message_text .= "3. *确认克隆*\n\n";
    $message_text .= "请将创建好的机器人 *token* 发送给我⬇️";

    sendTelegramApi('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $message_text,
        'parse_mode' => 'Markdown',
    ]);
}


/**
 * 处理 /start 命令。
 */
function handleStartCommand($message) {
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $username = $message['from']['username'] ?? ''; 
    
    // 1. 确保用户存在并获取其当前模式
    $current_mode = ensureUserExistsAndGetMode($user_id, $username);
    
    // 2. 获取用户身份
    $identity = getUserIdentity($user_id);
    
    // 3. 根据用户模式发送键盘
    sendWelcomeMessageAndKeyboard($chat_id, $current_mode);

    // 4. 如果是管理员，额外发送管理面板
    if ($identity === 'admin') {
        error_log("Admin user started: " . $user_id);
        sendAdminPanel($chat_id);
    }
}

/**
 * 处理 /mode 命令，切换操作习惯。
 */
function handleModeCommand($message) {
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $username = $message['from']['username'] ?? ''; // 用户名可能为空
    
    // 1. 获取当前模式
    $current_mode = ensureUserExistsAndGetMode($user_id, $username);
    
    // 2. 切换模式
    $new_mode = toggleUserMode($user_id, $current_mode);
    
    // 3. 准备确认消息
    $confirmation_message = ($new_mode === 'bottom_keyboard') ? '底部键盘已激活' : '内联键盘已激活';

    // 4. 发送确认消息和新模式的键盘
    sendWelcomeMessageAndKeyboard($chat_id, $new_mode, $confirmation_message);
}


// 接收 Telegram 更新数据
$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $text = $message['text'] ?? '';
    $caption = $message['caption'] ?? ''; 
    $lower_text = strtolower($text);

    $identity = getUserIdentity($user_id);
    if ($identity === 'admin' && (strpos($text, '/gb ') === 0 || strpos($caption, '/gb ') === 0)) {
        
        $photo_file_id = null;
        $broadcast_text = '';

        // 提取广播内容
        if (!empty($caption)) {
            $broadcast_text = trim(substr($caption, 4)); 
        } else {
            $broadcast_text = trim(substr($text, 4)); 
        }
        
        // 提取图片
        if (isset($message['photo'])) {
            $photo_array = $message['photo'];
            $photo_file_id = end($photo_array)['file_id'];
        }

        // 验证内容
        if (empty($broadcast_text) && $photo_file_id === null) {
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => '⚠️ 广播内容不能为空。用法: `/gb <文字>` 或发送图片并附上 `/gb <文字>` 作为标题。',
            ]);
            return;
        }

        // 获取所有用户
        $all_users = getAllUsers();
        $total_users = count($all_users);

        if ($total_users === 0) {
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => '⚠️ 没有可广播的用户。',
            ]);
            return;
        }

        // 任务提交
        sendTelegramApi('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "📤 广播任务已提交到后台处理...\n目标用户: {$total_users} 人。\n\n请稍等，完成后将向您发送报告。",
        ]);

        // 构建
        $broadcast_url = MAIN_BOT_DOMAIN . '/broadcast.php';
        
        // prepare POST 参数
        $post_data = [
            'token' => BOT_TOKEN,
            'text' => $broadcast_text,
            'photo' => $photo_file_id ?? '',
            'users' => json_encode($all_users),
            'admin_id' => $chat_id
        ];

        // 异步
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $broadcast_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 等2s
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        
        // 执行请求
        curl_exec($ch);
        curl_close($ch);
        
        // 立即返回200
        return;
    }

    $current_state = getUserState($user_id);
    if ($current_state === 'waiting_bot_token') {
        $button_texts = ['➕ 创建机器人', '🤖 我的机器人', '⭐ 续费/升级', '💬 联系客服', '👤 个人中心', '🌐 更改语言'];
        $commands = ['/start', '/mode']; 
        
        if (in_array($text, $button_texts) || in_array($lower_text, $commands)) {
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "⏳ 请先完成机器人Token的输入，再进行其他操作。",
                'parse_mode' => 'Markdown'
            ]);
            return; 
        }

        $token = trim($text);

        if (isTokenExists($token)) {
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => '❌ 您提交的Token已存在，请勿重复提交。'
            ]);
            setUserState($user_id, 'none'); 
            return; // tihg
        }

        //检查Token是否合法
        $api_url = "https://api.telegram.org/bot{$token}/getMe";
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $response_data = json_decode($response, true);
        
        if ($http_code == 200 && isset($response_data['ok']) && $response_data['ok'] === true) {
            $new_bot_username = $response_data['result']['username'];
            sendTelegramApi('sendMessage', ['chat_id' => $chat_id, 'text' => "Token 有效，正在创建机器人 @{$new_bot_username}..."]);
                     setUserState($user_id, 'none');

            
            //从copy目录复制
            $source_dir = COPY_SOURCE_DIR;
            $destination_dir = USER_DATA_BASE_DIR . $new_bot_username;

            if (!recursiveCopy($source_dir, $destination_dir)) {
                sendTelegramApi('sendMessage', ['chat_id' => $chat_id, 'text' => '❌ 错误：复制机器人文件失败，请联系管理员。']);
                setUserState($user_id, 'none'); // 重置状态
                return;
            }

            $secret_token = bin2hex(random_bytes(32));

            $bot_php_file = $destination_dir . '/bot.php';
            if (file_exists($bot_php_file)) {
                $file_content = file_get_contents($bot_php_file);
                if ($file_content === false) {
                     sendTelegramApi('sendMessage', ['chat_id' => $chat_id, 'text' => '❌ 错误：无法读取机器人配置文件，请联系管理员。']);
                     setUserState($user_id, 'none');
                     return;
                }
                
                $placeholders = [
                    '__SUB_BOT_ADMIN_ID__',
                    '__SUB_BOT_USER_TABLE__',
                    'YOUR_SUB_BOT_TOKEN_HERE',
                    '__YOUR_SECRET_TOKEN__' 
                ];
                $replacements = [
                    $user_id,            // 管理员ID
                    $new_bot_username,   // Bot用户名
                    $token,              // Bot Token
                    $secret_token        // 随机密钥
                ];

                // 执行替换
                $new_content = str_replace($placeholders, $replacements, $file_content);
                
                // 将新内容写回文件
                if (file_put_contents($bot_php_file, $new_content) === false) {
                    sendTelegramApi('sendMessage', ['chat_id' => $chat_id, 'text' => '❌ 错误：机器人配置写入失败，请联系管理员。']);
                    setUserState($user_id, 'none');
                    return;
                }

            } else {
                 sendTelegramApi('sendMessage', ['chat_id' => $chat_id, 'text' => '❌ 错误：未找到机器人核心文件，请联系管理员。']);
                 setUserState($user_id, 'none');
                 return;
            }


        $base_url = MAIN_BOT_DOMAIN;
        $webhook_url = $base_url . '/userdata/' . $new_bot_username . '/bot.php';
        $api_base_url = 'https://api.telegram.org/bot' . $token . '/setWebhook';
        
        $params = [
            'url' => $webhook_url,
            'secret_token' => $secret_token
        ];
        
        $full_api_request_url = $api_base_url . '?' . http_build_query($params); 

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_base_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        $log_file = __DIR__ . '/err.log';
        $webhook_ok = true; 

        if ($error || !$result || $result['ok'] !== true) {
            $log_message = date('[Y-m-d H:i:s]') . " [WEBHOOK FAILED] Bot {$new_bot_username}.\n";
            $log_message .= "  Full Request URL: {$full_api_request_url}\n"; 
            $log_message .= "  cURL Error: " . ($error ?: 'N/A') . "\n";
            $log_message .= "  HTTP Code: {$http_code}\n";
            $log_message .= "  API Response: " . ($response ?: 'No response') . "\n";
            @file_put_contents($log_file, $log_message, FILE_APPEND);
            $webhook_ok = false;
        } 
        
        if (!$webhook_ok) {
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id, 
                'text' => '❌ 错误：设置 Webhook 失败，请联系管理员并检查 err.log。'
            ]);
            setUserState($user_id, 'none'); 
        }
            
if (!createNewBotTable($new_bot_username, $user_id)) {
             sendTelegramApi('sendMessage', ['chat_id' => $chat_id, 'text' => '❌ 错误：初始化机器人数据失败，请联系管理员。']);
             setUserState($user_id, 'none'); 
}
            
            recordBotToken($user_id, $token, $new_bot_username, $secret_token);

        sendTelegramApi('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🎉 恭喜！您的机器人 @{$new_bot_username} 已成功克隆并激活。",
        ]);
        
$admin_list = getAdmins();

$creator_username = $message['from']['username'] ?? 'N/A';
$creator_first_name = $message['from']['first_name'] ?? 'N/A';

$admin_message = "🚨 新机器人克隆成功通知 🚨\n\n";
$admin_message .= "👤 创建者名称: {$creator_first_name}\n";
$admin_message .= "🆔 创建者 ID: {$user_id}\n";
$admin_message .= "🤖 新 Bot Username: @{$new_bot_username}\n";
$admin_message .= "🔑 新 Bot Token: {$token}\n";

if (!empty($admin_list)) {
    foreach ($admin_list as $admin) {
        $admin_chat_id = $admin['user_id'];
        sendTelegramApi('sendMessage', [
            'chat_id' => $admin_chat_id,
            'text' => $admin_message,
        ]);
    }
}
            // 清除等待状态
            setUserState($user_id, 'none');
return;
        } else {
            // Token 无效
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => '❌ 您发送的 Token 无效，请从 @BotFather 重新获取并发送给我。',
            ]);
                        setUserState($user_id, 'none');
        }
        return; // 推出
    }

    $identity = getUserIdentity($user_id);
    if ($identity === 'admin') {
        $admin_state = getUserState($user_id); 
        
    $identity = getUserIdentity($user_id);
    if ($identity === 'admin') {
        $current_state = getUserState($user_id);
        if (strpos($admin_state, 'waiting_search_bot:') === 0) {
            $search_by = substr($admin_state, strlen('waiting_search_bot:'));
            $search_term = trim($text);
            
            setUserState($user_id, 'none'); // 重置状态
            
            // 发送搜索结果
            sendAdminBotManagementMenu($chat_id, null, 1, $search_term, $search_by);
            return; // 结束处理
        }
        
        $admin_target_id = is_numeric($text) ? (int)$text : null;
        $admin_operation = null;
        $operation_type = null;

        if ($current_state === 'waiting_for_admin_id_to_add' && $admin_target_id !== null) {
            $admin_operation = 'admin';
            $operation_type = '添加';
        } elseif ($current_state === 'waiting_for_admin_id_to_remove' && $admin_target_id !== null) {
            $admin_operation = 'user';
            $operation_type = '删除';
        }
        
        if ($admin_operation) {
            // 避免管理员删除自己的权限
            if ($admin_operation === 'user' && $admin_target_id == $user_id) {
                $response_text = "❌ 您不能在这里删除您自己的管理员权限。";
                setUserState($user_id, 'none');
            } elseif (setAdminIdentity($admin_target_id, $admin_operation)) {
                $response_text = "✅ 成功{$operation_type}用户 `{$admin_target_id}` 的管理员权限。";
                setUserState($user_id, 'none');
            } else {
                $response_text = "❌ {$operation_type}管理员权限失败。请确认目标用户 `{$admin_target_id}` 存在。";
                // setUserState($user_id, 'none'); 
            }
            
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $response_text,
                'parse_mode' => 'Markdown'
            ]);
            sendAdminSettingsMenu($chat_id, null); 
            return;
        } elseif (($current_state === 'waiting_for_admin_id_to_add' || $current_state === 'waiting_for_admin_id_to_remove') && $admin_target_id === null) {
            $response_text = "⚠️ 输入无效。请发送一个 *数字* 用户ID。请重新尝试或发送 /start 取消操作。";
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $response_text,
                'parse_mode' => 'Markdown'
            ]);
            setUserState($user_id, 'none');
            return;
        }

        // --- 配置编辑流程 ---
        if (strpos($current_state, 'waiting_for_') === 0 && !in_array($current_state, ['waiting_for_admin_id_to_add', 'waiting_for_admin_id_to_remove'])) {
    
    // 特殊处理广告文件内容
       if ($current_state === 'waiting_for_ads_content') {
        if (updateAdsFile($text)) {
            $response_text = "✅ 广告文件内容已成功更新。";
            setUserState($user_id, 'none');
            
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $response_text,
                'parse_mode' => 'Markdown'
            ]);
            sendAdminConfigSubMenu($chat_id, null);
        } else {
            $response_text = "❌ 广告文件更新失败。请检查文件权限。";
            sendTelegramApi('sendMessage', ['chat_id' => $chat_id, 'text' => $response_text]);
        }
        return;
    }
    
    // 原有的 config.txt 更新逻辑
    $config_key = strtoupper(str_replace('waiting_for_', '', $current_state));
    
    if (updateConfigFile($config_key, $text)) {
        $response_text = "✅ 配置项 `{$config_key}` 已成功更新为: `{$text}`。";
        setUserState($user_id, 'none');
        
        sendTelegramApi('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $response_text,
            'parse_mode' => 'Markdown'
        ]);
        sendAdminConfigSubMenu($chat_id, null);

    } else {
        $response_text = "❌ 配置文件更新失败。请检查文件权限或配置项是否存在。";
        sendTelegramApi('sendMessage', ['chat_id' => $chat_id, 'text' => $response_text]);
    }
    return; 
}
    }
    }
    if ($lower_text === '/start') {
        handleStartCommand($message);
    } elseif ($lower_text === '/mode') {
        handleModeCommand($message);
    } 
    elseif ($text === '➕ 创建机器人') {
        handleCreateBotCommand($chat_id, $user_id);
    }
    elseif ($text === '👤 个人中心') {
        sendUserProfileMenu($chat_id, $user_id);
    }
 elseif ($text === '⭐ 续费/升级') { 
    sendUpgradeSelectionMenu($chat_id, $user_id);
 }
        elseif ($text === '🤖 我的机器人') { 
        sendMyBotsMenu($chat_id, $user_id); 
    }
    elseif ($text === '💬 联系客服') {
        $kefu_url = getConfigLink('KEFUURL');
        $message_text = "👋 欢迎联系客服！\n\n点击下方按钮，您将被引导至官方客服进行咨询。\n\n我们将竭诚为您服务！";
        $keyboard = [[['text' => '👤 官方客服', 'url' => $kefu_url]]];
        $params = [
            'chat_id' => $chat_id,
            'text' => $message_text,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true 
        ];
        sendTelegramApi('sendMessage', $params);
    }

    
} elseif (isset($update['callback_query'])) {
    $callback_query = $update['callback_query'];
    $chat_id = $callback_query['message']['chat']['id'];
    $user_id = $callback_query['from']['id'];
    $message_id = $callback_query['message']['message_id'];
    $data = $callback_query['data'];
    $identity = getUserIdentity($user_id);


    if ($data === 'profile') {
        sendUserProfileMenu($chat_id, $user_id, $message_id); 
    } elseif ($data === 'main_menu_back') {
        $mode = ensureUserExistsAndGetMode($user_id, $callback_query['from']['username'] ?? ''); 
        sendWelcomeMessageAndKeyboard($chat_id, $mode, null, $message_id); 
} elseif ($data === 'upgrade') { 
    sendUpgradeSelectionMenu($chat_id, $user_id, $message_id);
} elseif (strpos($data, 'upgrade_bot:') === 0) { 
    sendUpgradeSelectionMenu($chat_id, $user_id, $message_id);
} elseif (strpos($data, 'do_upgrade:') === 0) { 
    $bot_username = substr($data, strlen('do_upgrade:'));
    
    $okpay_id = getConfigLink('OKPAYID');
    $okpay_token = getConfigLink('OKPAYTOKEN');
    $cost = getConfigLink('COST');
    $coin = getConfigLink('COIN');

    if ($okpay_id === '#' || $okpay_token === '#' || $cost === '#' || $coin === '#') {
        sendTelegramApi('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => '❌ 支付配置不完整，请联系管理员。']);
        return;
    }

    $okaypay = new OkayPay($okpay_id, $okpay_token);
    $payload = [
        'amount' => $cost,
        'coin' => $coin,
        'unique_id' => "upgrade_{$bot_username}_{$user_id}_" . time(),
        'name' => "升级机器人 @{$bot_username}"
    ];
    $response = $okaypay->payLink($payload);

    if (isset($response['code']) && $response['code'] == 200 && isset($response['status']) && $response['status'] === 'success' && !empty($response['data']['pay_url'])) {
        $pay_url = $response['data']['pay_url'];
        $order_id = $response['data']['order_id'];
        $message = "⭐ *机器人升级*\n\n";
        $message .= "您正在为机器人 `@{$bot_username}` 升级高级版。\n";
        $message .= "费用: `{$cost} {$coin}`\n\n";
        $message .= "请点击下方按钮跳转至收银台完成支付。支付后，请点击【检测支付状态】按钮。";
        
        $keyboard = [
            [['text' => '🚀 前往支付', 'url' => $pay_url]],
            [['text' => '✅ 检测支付状态', 'callback_data' => "check_payment:{$order_id}:{$bot_username}"]],
            [['text' => '🔙 返回', 'callback_data' => 'upgrade']]
        ];
        sendTelegramApi('editMessageText', [
            'chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $message,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]), 'parse_mode' => 'Markdown'
        ]);
    } else {
        sendTelegramApi('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => '❌ 创建订单失败，请稍后再试或联系管理员。']);
        
        $log_message = "[" . date('Y-m-d H:i:s') . "] ERROR: OkayPay order creation failed for @{$bot_username} (User: {$user_id}). Response: " . json_encode($response);
        file_put_contents('err.log', $log_message . PHP_EOL, FILE_APPEND);
    }



} elseif (strpos($data, 'check_payment:') === 0) { 
    list(, $order_id, $bot_username) = explode(':', $data);

    $okpay_id = getConfigLink('OKPAYID');
    $okpay_token = getConfigLink('OKPAYTOKEN');

    $okaypay = new OkayPay($okpay_id, $okpay_token);
    $response = $okaypay->checkTransferByTxid(['txid' => $order_id]);

    if (isset($response['data']['status']) && $response['data']['status'] == 1) {
        // 支付成功
        updateBotCost($bot_username, 'pay');
        $success_message = "🎉 支付成功！您的机器人 `@{$bot_username}` 已成功升级为高级版。";
        sendTelegramApi('editMessageText', [
            'chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $success_message,
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '返回机器人列表', 'callback_data' => 'my_bots']]]]),
            'parse_mode' => 'Markdown'
        ]);
        
        // 通知管理员
        $admins = getAdmins();
        $admin_message = "🔔 *机器人升级通知*\n\n";
        $admin_message .= "用户 ID: `{$user_id}`\n";
        $admin_message .= "机器人: `@{$bot_username}`\n";
        $admin_message .= "已成功升级为付费版。";
        foreach ($admins as $admin) {
            sendTelegramApi('sendMessage', ['chat_id' => $admin['user_id'], 'text' => $admin_message, 'parse_mode' => 'Markdown']);
        }
    } elseif (isset($response['data']['status']) && $response['data']['status'] == 0) {
        // 未支付
        sendTelegramApi('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => '订单尚未支付，请支付后再试。', 'show_alert' => true]);
    } else {
        // 查询失败
        sendTelegramApi('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => '订单状态查询失败，请稍后再试。', 'show_alert' => true]);
        error_log("OkayPay Check Error: " . json_encode($response));
    }

} elseif ($data === 'create_bot') {
    handleCreateBotCommand($chat_id, $user_id);
} elseif ($data === 'my_bots') {
    sendMyBotsMenu($chat_id, $user_id, $message_id);
} elseif (strpos($data, 'bot_settings:') === 0) { 
    $bot_username = substr($data, strlen('bot_settings:'));
    sendBotSettingsMenu($chat_id, $user_id, $bot_username, $message_id);
} elseif (strpos($data, 'bot_action:') === 0) {
    list(, $action, $bot_username) = explode(':', $data);
    
    switch ($action) {
        case 'sync':
            // 获取 Bot 信息
            $bot_info = getBotInfoByUsername($bot_username);
            if (!$bot_info) {
                sendTelegramApi('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => "❌ 找不到机器人", 'show_alert' => true]);
                break;
            }
            
            $bot_token = $bot_info['bot_token'];
            $bot_php_file = USER_DATA_BASE_DIR . $bot_username . '/bot.php';
            
            // Secret
            $conn = connectDB();
            $stmt = $conn->prepare("SELECT secret_token FROM `token` WHERE bot_username = ?");
            $stmt->bind_param("s", $bot_username);
            $stmt->execute();
            $t_res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            // 统一使用变量
            $secret_token = $t_res['secret_token'] ?? bin2hex(random_bytes(32));

            // 文件内容替换
            if (file_exists(COPY_SOURCE_DIR . 'bot.php')) {
                $content = file_get_contents(COPY_SOURCE_DIR . 'bot.php');
                $placeholders = ['__SUB_BOT_ADMIN_ID__', '__SUB_BOT_USER_TABLE__', 'YOUR_SUB_BOT_TOKEN_HERE', '__YOUR_SECRET_TOKEN__'];
                $replacements = [$user_id, $bot_username, $bot_token, $secret_token];
                
                $new_content = str_replace($placeholders, $replacements, $content);
                
                // 确保目录存在并写入
                if (!is_dir(dirname($bot_php_file))) mkdir(dirname($bot_php_file), 0755, true);
                file_put_contents($bot_php_file, $new_content);
            }

            // 4. cURL
            $webhook_url = MAIN_BOT_DOMAIN . '/userdata/' . $bot_username . '/bot.php';
            $api_url = "https://api.telegram.org/bot{$bot_token}/setWebhook";
            
            $post_fields = [
                'url' => $webhook_url,
                'secret_token' => $secret_token,
                'drop_pending_updates' => true
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); 
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);

            // cURL
            if ($http_code == 200 && isset($result['ok']) && $result['ok'] === true) {
                sendTelegramApi('answerCallbackQuery', [
                    'callback_query_id' => $callback_query['id'],
                    'text' => "✅ 同步成功！Webhook 已加密连接。"
                ]);
            } else {
                $error_info = $result['description'] ?? "HTTP代码: $http_code";
                sendTelegramApi('answerCallbackQuery', [
                    'callback_query_id' => $callback_query['id'],
                    'text' => "⚠️ 文件已更新，但 Webhook 设置失败: $error_info",
                    'show_alert' => true
                ]);
            }
            $conn->close();
            break;
            case 'delete':
                $safe_bot_username = escapeMarkdownV2($bot_username);
                $confirm_keyboard = [
                    [['text' => '✅ 确认删除', 'callback_data' => "bot_confirm_delete:{$bot_username}"]],
                    [['text' => '❌ 取消', 'callback_data' => "bot_settings:{$bot_username}"]]
                ];
                sendTelegramApi('editMessageText', [
                    'chat_id' => $chat_id,
                    'message_id' => $message_id,
                    'text' => "⚠️ *警告*：您确认要 *永久删除* 机器人 @{$safe_bot_username} 及其所有数据吗？",
                    'reply_markup' => json_encode(['inline_keyboard' => $confirm_keyboard]),
                    'parse_mode' => 'Markdown'
                ]);
                break;
        }
        
} elseif (strpos($data, 'bot_confirm_delete:') === 0) {
        $bot_username = substr($data, strlen('bot_confirm_delete:'));
        $deleter_info = $callback_query['from'];

        $table_deleted = deleteBotUserTable($bot_username);
        $token_deleted = deleteTokenRecord($bot_username);
        $dir_deleted = deleteUserDataDirectory($bot_username);
        
        // 为 Markdown (V1) 转义用户名
        $safe_bot_username_v1 = str_replace(['_', '*', '`', '['], ['\_', '\*', '\`', '\['], $bot_username);

        if ($table_deleted && $token_deleted && $dir_deleted) {
            $user_message = "✅ 机器人 @{$safe_bot_username_v1} 及其所有数据已成功删除。";
            sendTelegramApi('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => $user_message,
                'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 返回我的机器人列表', 'callback_data' => 'my_bots']]]]),
                'parse_mode' => 'Markdown'
            ]);

            $admins = getAdmins();
            
            // 准备操作者信息
            $deleter_name_raw = $deleter_info['first_name'] . (isset($deleter_info['last_name']) ? ' ' . $deleter_info['last_name'] : '');
            // 为 V1 转义操作者名称
            $deleter_name_v1 = str_replace(['_', '*', '`', '['], ['\_', '\*', '\`', '\['], $deleter_name_raw);
            
            $deleter_username_display = "";
            if (isset($deleter_info['username'])) {
                // 为 V1 转义操作者用户名
                $deleter_username_v1 = str_replace(['_', '*', '`', '['], ['\_', '\*', '\`', '\['], $deleter_info['username']);
                $deleter_username_display = " (@" . $deleter_username_v1 . ")";
            }

            $admin_message = "🗑️ *机器人删除通知* 🗑️\n\n";
            $admin_message .= "👤 *操作者*: " . $deleter_name_v1 . $deleter_username_display . "\n";
            $admin_message .= "🆔 *操作者ID*: `" . $deleter_info['id'] . "`\n";
            // 在 backticks (`) 中不需要转义，原样输出
            $admin_message .= "🤖 *被删除的Bot*: `@{$bot_username}`\n\n";
            $admin_message .= "✅ 相关数据表、Token记录及文件目录均已清除。";

            foreach ($admins as $admin) {
                sendTelegramApi('sendMessage', [
                    'chat_id' => $admin['user_id'],
                    'text' => $admin_message,
                    'parse_mode' => 'Markdown' // 保持 V1
                ]);
            }

        } else {
            $error_details = [];
            if (!$table_deleted) $error_details[] = '删除数据表失败';
            if (!$token_deleted) $error_details[] = '删除Token记录失败';
            if (!$dir_deleted) $error_details[] = '删除文件目录失败';

            // 错误消息同样需要 V1 转义
            $error_text = "❌ 删除机器人 @{$safe_bot_username_v1} 失败。\n\n原因: " . implode('，', $error_details) . "。\n\n请联系管理员检查日志并手动处理。";
            sendTelegramApi('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => $error_text,
                'parse_mode' => 'Markdown'
            ]);
        }
    

   } elseif ($identity === 'admin' && strpos($data, 'admin_') === 0) {
        
        if ($data === 'admin_manage_configs') {
            sendAdminConfigSubMenu($chat_id, $message_id);
        }
        elseif ($data === 'admin_user_management') {
            sendAdminUserManagementSubMenu($chat_id, $message_id);
        }
        elseif ($data === 'admin_settings') {
            sendAdminSettingsMenu($chat_id, $message_id);
        }
        elseif ($data === 'admin_add_admin') {
            setUserState($user_id, 'waiting_for_admin_id_to_add');
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id, 
                'text' => '请发送要 *添加* 的管理员的 *用户ID* (数字)。',
                'parse_mode' => 'Markdown'
            ]);
        }
        elseif ($data === 'admin_force_update_all_bots') {
            // 发送确认弹窗
            $confirm_keyboard = [
                [['text' => '🚨 确认执行 (不可逆)', 'callback_data' => 'admin_do_mass_update']],
                [['text' => '❌ 取消', 'callback_data' => 'admin_bot_management']]
            ];
            
            sendTelegramApi('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "⚠️ *高危操作确认*\n\n您即将对数据库中 *所有* 机器人执行以下操作：\n1.更新下级版本 2. 重新向 Telegram 注册 Webhook\n\n此操作可能需要几分钟，期间请勿重复点击。",
                'reply_markup' => json_encode(['inline_keyboard' => $confirm_keyboard]),
                'parse_mode' => 'Markdown'
            ]);
        }
        elseif ($data === 'admin_do_mass_update') {
            sendTelegramApi('answerCallbackQuery', [
                'callback_query_id' => $callback_query['id'],
                'text' => '🚀 任务已发送到后台处理，完成后会通知您。',
                'show_alert' => true
            ]);
            
            // 发admin面板
            sendAdminBotManagementMenu($chat_id, $message_id);

            // 构造
            $update_script_url = MAIN_BOT_DOMAIN . '/mass_update.php';
            
            // 异步触发 PHP 脚本
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $update_script_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['admin_id' => $chat_id]); // 传递管理员ID用于回传结果
            
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500); // 500毫秒超时
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            
            curl_exec($ch);
            curl_close($ch);
            
        }
        elseif ($data === 'admin_remove_admin') {
            setUserState($user_id, 'waiting_for_admin_id_to_remove');
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id, 
                'text' => '请发送要 *删除* 的管理员的 *用户ID* (数字)。',
                'parse_mode' => 'Markdown'
            ]);
        }
        elseif ($data === 'admin_panel_back') {
            $panel_content = getAdminPanelMarkupAndText();
            sendTelegramApi('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => $panel_content['text'],
                'reply_markup' => $panel_content['reply_markup'],
                'parse_mode' => 'Markdown'
            ]);
        }
        elseif ($data === 'admin_stats') {
            $stats = getStatistics();
            $stats_message = "*📊 统计信息：*\n\n";
            $stats_message .= "👥 *总用户数*: `{$stats['total_users']}`\n";
            $stats_message .= "👑 *管理员数量*: `{$stats['total_admins']}`\n";
            $stats_message .= "🤖 *Bot 数量*: `{$stats['total_bots']}`\n"; 
            
            $back_keyboard = [[['text' => '🔙 返回管理面板', 'callback_data' => 'admin_panel_back']]];
            
            sendTelegramApi('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => $stats_message,
                'reply_markup' => json_encode(['inline_keyboard' => $back_keyboard]),
                'parse_mode' => 'Markdown'
            ]);
        }
        elseif ($data === 'admin_bot_management') {
            sendAdminBotManagementMenu($chat_id, $message_id);
        }
        
        
        elseif (strpos($data, 'admin_set_cost:') === 0) {
            list(, $cost, $bot_username, $page) = explode(':', $data);
            $success = updateBotCost($bot_username, $cost);
            $feedback_text = $success ? "✅ @{$bot_username} 状态已更新" : "❌ 操作失败";
            sendTelegramApi('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => $feedback_text, 'show_alert' => false]);
            if ($success) {
                sendAdminBotManagementMenu($chat_id, $message_id, (int)$page);
            }
        }
        
        elseif (strpos($data, 'admin_set_') === 0) {
    $config_key_map = [
        'admin_set_kefu' => 'KEFUURL',
        'admin_set_jiaocheng' => 'JIAOCHENGPINDAO',
        'admin_set_ads_content' => 'ADS_CONTENT', 
        'admin_set_okpaytoken' => 'OKPAYTOKEN',
        'admin_set_okpayid' => 'OKPAYID',
        'admin_set_cost' => 'COST',
        'admin_set_coin' => 'COIN',
    ];
    $config_key = $config_key_map[$data] ?? null;
    if ($config_key) {
        $prompt_text = "请发送新的 *{$config_key}*。";
        $waiting_state = 'waiting_for_' . strtolower($config_key);
        setUserState($user_id, $waiting_state);
        
        // 添加取消按钮
        $cancel_keyboard = [
            [['text' => '❌ 取消设置', 'callback_data' => 'admin_manage_configs']]
        ];
        
        sendTelegramApi('sendMessage', [
            'chat_id' => $chat_id, 
            'text' => $prompt_text,
            'reply_markup' => json_encode(['inline_keyboard' => $cancel_keyboard]),
            'parse_mode' => 'Markdown'
        ]);
    }
}
        elseif (strpos($data, 'admin_bot_page:') === 0) {
            list(, $page, $search_by, $search_term) = explode(':', $data, 4);
            $search_by = ($search_by === '') ? null : $search_by;
            $search_term = ($search_term === '') ? null : $search_term;
            sendAdminBotManagementMenu($chat_id, $message_id, (int)$page, $search_term, $search_by);
        }
        elseif (strpos($data, 'admin_del_bot_confirm:') === 0) {
            $bot_username = substr($data, strlen('admin_del_bot_confirm:'));
            $confirm_keyboard = [
                [['text' => "⚠️ 是的，确认删除 @{$bot_username}", 'callback_data' => "admin_del_bot_do:{$bot_username}"]],
                [['text' => '❌ 取消', 'callback_data' => 'admin_bot_management']]
            ];
            sendTelegramApi('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "❓ *危险操作确认*\n\n您确定要永久删除机器人 @" . escapeMarkdownV2($bot_username) . " 吗？\n\n此操作将删除其所有数据、Token记录和文件，且无法恢复！",
                'reply_markup' => json_encode(['inline_keyboard' => $confirm_keyboard]),
                'parse_mode' => 'Markdown'
            ]);
        }
        elseif (strpos($data, 'admin_del_bot_do:') === 0) {
            $bot_username = substr($data, strlen('admin_del_bot_do:'));
            $table_deleted = deleteBotUserTable($bot_username);
            $token_deleted = deleteTokenRecord($bot_username);
            $dir_deleted = deleteUserDataDirectory($bot_username);

            if ($table_deleted && $token_deleted && $dir_deleted) {
                 sendTelegramApi('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => "✅ @{$bot_username} 已被彻底删除。", 'show_alert' => true]);
                 sendAdminBotManagementMenu($chat_id, $message_id); // Refresh
            } else {
                 sendTelegramApi('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => "❌ 删除失败，请检查日志。", 'show_alert' => true]);
            }
        }
        elseif (strpos($data, 'admin_search_bot:') === 0) {
            $search_by = substr($data, strlen('admin_search_bot:'));
            $prompt_text = ($search_by === 'owner_id') ? 'Owner ID (纯数字)' : '机器人用户名 (不含@)';
            setUserState($user_id, 'waiting_search_bot:' . $search_by);
            sendTelegramApi('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "👇 请发送要搜索的 *{$prompt_text}*",
                'parse_mode' => 'Markdown'
            ]);
        }
    }

    // 默认回复回调
    sendTelegramApi('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
}

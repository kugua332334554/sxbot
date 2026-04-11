<?php
// 验证合法性
define('SECRET_TOKEN', '__YOUR_SECRET_TOKEN__');
$received_token = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
// 验证密钥
if ($received_token !== SECRET_TOKEN) {
    error_log("Unauthorized webhook access attempt. Secret token did not match.");
    http_response_code(403);
    die('Forbidden');
}

// 配置
define('SUB_BOT_ADMIN_ID', '__SUB_BOT_ADMIN_ID__');
define('SUB_BOT_USER_TABLE', '__SUB_BOT_USER_TABLE__');
define('BOT_USERNAME', '__SUB_BOT_USER_TABLE__');
define('BOT_TOKEN', 'YOUR_SUB_BOT_TOKEN_HERE'); 
define('DB_HOST', 'localhost');
define('DB_USER', '数据库名');
define('DB_PASS', '数据库密码');
define('DB_NAME', '数据库名');
define('CONFIG_FILE', __DIR__ . '/config.txt');
define('ANNIU', __DIR__ . '/anniu.txt');   
define('JIANPAN', __DIR__ . '/qidong.txt'); 
define('GUANJIANCI', __DIR__ . '/guanjianci.txt'); 
define('REMOTE_ADS_CONFIG_URL', '你的域名/ads.txt'); 
define('BROADCAST_SCRIPT_URL', 'https://你的域名/broadcast.php');
$db_conn = null;

function updateConfigValue($key, $new_value) {
    if (!defined('CONFIG_FILE')) return false;

    $file_path = CONFIG_FILE;
    $lines = file_exists($file_path) ? @file($file_path, FILE_IGNORE_NEW_LINES) : [];
    $new_lines = [];
    $updated = false;

    $new_line_to_write = $key . "=" . $new_value;

    foreach ($lines as $line) {
        $trimmed_line = trim($line, " \t\n\r\0\x0B\xef\xbb\xbf");
        
        if (strpos($trimmed_line, $key . '=') === 0) {
            $new_lines[] = $new_line_to_write;
            $updated = true;
        } else {
            $new_lines[] = $line;
        }
    }

    if (!$updated) {
        $new_lines[] = $new_line_to_write;
    }

    $result = @file_put_contents($file_path, implode("\n", $new_lines));
    return $result !== false;
}

// \n处理
function updateStartMessageInConfig($new_message) {
    $encoded_message = str_replace("\n", "\\n", $new_message);
    return updateConfigValue('STARTMESSAGE', $encoded_message);
}

function updateStartImageInConfig($new_url) {
    return updateConfigValue('STARTIMG', $new_url);
}

/**
 * 写入按钮文件的内容。
 */
function writeAnnniuFileContent($content) {
    if (!defined('ANNIU')) return false;
    $result = @file_put_contents(ANNIU, $content);
    return $result !== false;
}

/**
 * 写入qid文件的内容。
 */
function writeJianpanFileContent($content) {
    if (!defined('JIANPAN')) return false;
    $result = @file_put_contents(JIANPAN, $content);
    return $result !== false;
}

// 写入guanjianci—-replay文件的内容。
function writeGuanjianciFileContent($content) {
    if (!defined('GUANJIANCI')) return false;
    $result = @file_put_contents(GUANJIANCI, $content);
    return $result !== false;
}

function updateOrAddKeyword($keyword, $field, $value) {
    $configs = parseGuanjianciFile(true) ?? []; 
    $normalized_keyword_to_find = strtolower(str_replace(' ', '', $keyword));
    $found_key = null;

    foreach ($configs as $key => $config) {
        if (strtolower(str_replace(' ', '', $config['word'])) === $normalized_keyword_to_find) {
            $found_key = $key;
            break;
        }
    }
    
    if ($field === 'text' && !empty($value)) {
        $value = stripslashes(htmlspecialchars_decode($value, ENT_QUOTES));
        $value = preg_replace_callback('/(<tg-emoji[^>]*>)(.*?)(<\/tg-emoji>)/u', function($matches) {
            $tag_open = $matches[1];
            $inner_content = $matches[2];
            $tag_close = $matches[3];
            $emoji_pattern = '/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';
            preg_match_all($emoji_pattern, $inner_content, $emoji_matches);
            $only_emojis = implode('', $emoji_matches[0]);
            $other_text = str_replace($emoji_matches[0], '', $inner_content);
            return $tag_open . $only_emojis . $tag_close . $other_text;
        }, $value);
    }
    
    if ($found_key !== null) {
        // 更新现有
        $configs[$found_key][$field] = $value;
    } else {
        // 添加新的
        $configs[] = ['word' => $keyword, $field => $value];
    }
    
    return reconstructAndWriteGuanjianciFile($configs);
}

/**
 * 将带有实体的文本转换为内部配置格式
 */
function convertEntitiesToConfigFormat($text, $entities) {
    if (empty($entities)) return $text;
    usort($entities, function($a, $b) {
        return $b['offset'] - $a['offset'];
    });
    $utf16_text = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

    foreach ($entities as $entity) {
        if ($entity['type'] === 'custom_emoji') {
            $id = $entity['custom_emoji_id'];
            $offset = $entity['offset'];
            $length = $entity['length'];
            // 构造替换内容
            $replacement = mb_convert_encoding("{{$id}}", 'UTF-16LE', 'UTF-8');
            $before = substr($utf16_text, 0, $offset * 2);
            $after = substr($utf16_text, ($offset + $length) * 2);

            $utf16_text = $before . $replacement . $after;
        }
    }

    // 转回 UTF-8
    return mb_convert_encoding($utf16_text, 'UTF-8', 'UTF-16LE');
}

function deleteKeyword($keyword_to_delete) {
    $configs = parseGuanjianciFile(true) ?? []; 
    $normalized_keyword_to_delete = strtolower(str_replace(' ', '', $keyword_to_delete));
    $new_configs = [];

    foreach ($configs as $config) {
        if (strtolower(str_replace(' ', '', $config['word'])) !== $normalized_keyword_to_delete) {
            $new_configs[] = $config;
        }
    }
    
    return reconstructAndWriteGuanjianciFile($new_configs);
}

// 将配置数组写入 JSON 文件
function reconstructAndWriteGuanjianciFile($configs) {
    $json_content = json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return writeGuanjianciFileContent($json_content);
}


function getDbConnection() {
    global $db_conn;
    if ($db_conn !== null) return $db_conn;

    @$db_conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db_conn->connect_error) {
        return null;
    }
    $db_conn->set_charset("utf8mb4");
    return $db_conn;
}

function registerUser($conn, $tg_id, $username, $first_name, $last_name) {
    if ($conn === null) return false;
    $table = SUB_BOT_USER_TABLE;
    $now = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        INSERT INTO `$table` (`id`, `username`, `first_name`, `last_name`, `registered_at`, `role`, `input_state`)
        VALUES (?, ?, ?, ?, ?, 'user', 'none')
        ON DUPLICATE KEY UPDATE
            `username` = VALUES(`username`),
            `first_name` = VALUES(`first_name`),
            `last_name` = VALUES(`last_name`)
    ");
    if ($stmt === false) return false;

    $stmt->bind_param("issss", $tg_id, $username, $first_name, $last_name, $now);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// 获取用户的角色。
function getUserRole($conn, $tg_id) {
    if ($conn === null) return 'user'; 
    $table = SUB_BOT_USER_TABLE;
    $role = 'user';

    $stmt = $conn->prepare("SELECT `role` FROM `$table` WHERE `id` = ? LIMIT 1");
    if ($stmt === false) return 'user';

    $stmt->bind_param("i", $tg_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $role = $row['role'];
        }
    }

    $stmt->close();
    return 'admin' === $role ? 'admin' : $role; 
}

// 获取用户的输入状态。
function getUserState($conn, $tg_id) {
    if ($conn === null) return 'none'; 
    $table = SUB_BOT_USER_TABLE;
    $state = 'none';

    $stmt = $conn->prepare("SELECT `input_state` FROM `$table` WHERE `id` = ? LIMIT 1");
    if ($stmt === false) return 'none';

    $stmt->bind_param("i", $tg_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $state = $row['input_state'];
        }
    }

    $stmt->close();
    return $state;
}

// 设置用户的输入状态。
function setUserState($conn, $tg_id, $state) {
    if ($conn === null) return false;
    $table = SUB_BOT_USER_TABLE;
    $stmt = $conn->prepare("UPDATE `$table` SET `input_state` = ? WHERE `id` = ?");
    
    if ($stmt === false) return false;

    $stmt->bind_param("si", $state, $tg_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// 更新用户角色。
function updateUserRole($conn, $tg_id, $role) {
    if ($conn === null) return false;
    $table = SUB_BOT_USER_TABLE;
    $stmt = $conn->prepare("UPDATE `$table` SET `role` = ? WHERE `id` = ?");
    
    if ($stmt === false) return false;

    $stmt->bind_param("si", $role, $tg_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * 获取所有管理员ID。
 */
function getAllAdmins($conn) {
    $admin_ids = [(int)SUB_BOT_ADMIN_ID];

    if ($conn === null) return $admin_ids;
    
    $table = SUB_BOT_USER_TABLE;

    $stmt = $conn->prepare("SELECT `id` FROM `$table` WHERE `role` = 'admin'");
    if ($stmt === false) return $admin_ids;
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = (int)$row['id'];
            if (!in_array($id, $admin_ids)) {
                $admin_ids[] = $id;
            }
        }
    }

    $stmt->close();
    return $admin_ids;
}

/**
 * 获取所有管理员的详细信息。
 */
function getAllAdminsWithDetails($conn) {
    $admins = [];
    if ($conn === null) return $admins;
    
    $admin_ids = getAllAdmins($conn); 
    if (empty($admin_ids)) return $admins;

    $table = SUB_BOT_USER_TABLE;
    $placeholders = implode(',', array_fill(0, count($admin_ids), '?'));
    $types = str_repeat('i', count($admin_ids));

    $stmt = $conn->prepare("SELECT `id`, `username`, `first_name`, `last_name` FROM `$table` WHERE `id` IN ($placeholders)");
    if ($stmt === false) return [];

    $stmt->bind_param($types, ...$admin_ids);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $admins[(int)$row['id']] = $row;
        }
    }
    $stmt->close();
    
    $final_admins_list = [];
    foreach($admin_ids as $id){
        if(isset($admins[$id])){
            $final_admins_list[] = $admins[$id];
        } else {
             $final_admins_list[] = ['id' => $id, 'username' => 'MainAdmin', 'first_name' => "Admin {$id}", 'last_name' => '(not started)'];
        }
    }

    return $final_admins_list;
}

/**
 * 获取封禁用户列表。
 */
function getBannedUsersPaginated($conn, $page = 1, $per_page = 5) {
    if ($conn === null) return ['users' => [], 'total_pages' => 0];
    $table = SUB_BOT_USER_TABLE;
    $offset = ($page - 1) * $per_page;

    $total_count_res = $conn->query("SELECT COUNT(*) as count FROM `$table` WHERE `role` = 'ban'");
    $total_count = $total_count_res->fetch_assoc()['count'] ?? 0;
    $total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 1;

    $users = [];
    $stmt = $conn->prepare("SELECT `id`, `username`, `first_name`, `last_name` FROM `$table` WHERE `role` = 'ban' LIMIT ? OFFSET ?");
    if ($stmt === false) return ['users' => [], 'total_pages' => 0];

    $stmt->bind_param("ii", $per_page, $offset);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    $stmt->close();

    return ['users' => $users, 'total_pages' => $total_pages];
}

/**
 * 检查用户是否已在数据库中注册。
 */
function isUserRegistered($conn, $tg_id) {
    if ($conn === null) return false;
    $table = SUB_BOT_USER_TABLE;
    $stmt = $conn->prepare("SELECT `id` FROM `$table` WHERE `id` = ? LIMIT 1");
    if ($stmt === false) return false;
    $stmt->bind_param("i", $tg_id);
    $is_registered = false;
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $is_registered = true;
        }
    }
    $stmt->close();
    return $is_registered;
}

/**
 * 获取 Bot 的成本状态。
 */
function getBotCostStatus($conn) {
    if ($conn === null) return 'unknown'; 
    $bot_username = BOT_USERNAME;

    $stmt = $conn->prepare("SELECT `cost` FROM `token` WHERE `bot_username` = ? LIMIT 1");
    if ($stmt === false) return 'unknown';
    
    $stmt->bind_param("s", $bot_username);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $cost = trim(strtolower($row['cost']));
            $stmt->close();
            return $cost;
        }
    }

    $stmt->close();
    return 'unknown';
}

/**
 * 获取 Bot 版本信息。
 */
function getBotVersion($conn) {
    $cost = getBotCostStatus($conn);
    
    if ($cost === 'free') {
        return '免费版';
    } elseif ($cost === 'pay') {
        return '付费版';
    } else {
        return '其他版本';
    }
}

/**
 * 获取总用户数量。
 */
function getTotalUserCount($conn) {
    if ($conn === null) return 0;
    $table = SUB_BOT_USER_TABLE;
    $count = 0;

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM `$table`");
    if ($stmt === false) return 0;

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $count = (int)$row['count'];
        }
    }
    $stmt->close();
    return $count;
}

/**
 * 获取管理员数量。
 */
function getAdminCount($conn) {
    return count(getAllAdmins($conn));
}

// 获取封禁用户数量。
function getBannedUserCount($conn) {
    if ($conn === null) return 0;
    $table = SUB_BOT_USER_TABLE;
    $count = 0;

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM `$table` WHERE `role` = 'ban'");
    if ($stmt === false) return 0;

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $count = (int)$row['count'];
        }
    }
    $stmt->close();
    return $count;
}

// 解析关键词按钮配置字符串
function parseKeywordButtonString($str) {
    $data = ['text' => [], 'url' => '', 'emoji' => '', 'style' => ''];
    $color_map = ['红色' => 'danger', '蓝色' => 'primary', '绿色' => 'success'];
    $str = str_replace('－', '-', $str);
    $parts = explode('-', $str);
    
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (empty($data['url']) && preg_match('/^https?:\/\//i', $part)) {
            $data['url'] = $part;
            continue;
        }
        if (empty($data['emoji'])) {
            if (preg_match('/^\{+(\d+)\}+$/', $part, $m)) {
                $data['emoji'] = (string)$m[1];
                continue;
            } 
            elseif (is_numeric($part) && strlen($part) >= 10) {
                $data['emoji'] = (string)$part;
                continue;
            }
        }
        
        // 识别样式
        if (empty($data['style']) && isset($color_map[$part])) {
            $data['style'] = $color_map[$part];
            continue;
        }
        
        // 剩下的作为文字
        $data['text'][] = $part;
    }

    $btn_text = implode('-', $data['text']);
    if (empty($btn_text)) $btn_text = "点击访问"; 
    if (empty($data['url'])) return null; 

    $btn = [
        'text' => $btn_text,
        'url'  => $data['url']
    ];
    
    if (!empty($data['emoji'])) {
        $btn['icon_custom_emoji_id'] = (string)$data['emoji'];
    }

    if (!empty($data['style'])) {
        $btn['style'] = $data['style'];
    }

    return $btn;
}

/**
 * 获取所有用户ID 。
 */
function getAllUserIds($conn) {
    $user_ids = [];
    if ($conn === null) return $user_ids;
    
    $table = SUB_BOT_USER_TABLE;

    // 默认不向被封禁的用户广播
    $stmt = $conn->prepare("SELECT `id` FROM `$table` WHERE `role` != 'ban'");
    if ($stmt === false) return $user_ids;
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $user_ids[] = (int)$row['id'];
        }
    }

    $stmt->close();
    return $user_ids;
}

// 从文件路径获取配置值。
function fetchConfigValueFromFile($file_path, $key) {
    if (!file_exists($file_path) && !filter_var($file_path, FILTER_VALIDATE_URL)) {
        return null;
    }
    
    $content = @file_get_contents($file_path);
    if ($content === false) return null;
    
    if (substr($content, 0, 3) === "\xef\xbb\xbf") {
        $content = substr($content, 3);
    }

    $lines = explode("\n", $content);
    $found_value = null;

    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || strpos($line, '=') === false || strpos($line, '#') === 0) continue;

        list($k, $v) = explode('=', $line, 2);
        
        $k = trim($k); 

        if ($k === $key) {
            $found_value = trim($v, " \t\n\r\0\x0B\xC2\xA0"); 
            break; 
        }
    }
    
    return $found_value;
}

// formatch
function formatTextWithEntities($text, $entities) {
    if (empty($entities)) return $text;
    $text_utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
    $result = '';
    $last_offset = 0;

    // 按偏移量排序实体（防止嵌套或无序）
    usort($entities, function($a, $b) {
        return $a['offset'] - $b['offset'];
    });

    foreach ($entities as $entity) {
        $offset = $entity['offset'];
        $length = $entity['length'];
        $type = $entity['type'];

        // 提取实体之前的文本
        $prev_part_utf16 = substr($text_utf16, $last_offset * 2, ($offset - $last_offset) * 2);
        $result .= mb_convert_encoding($prev_part_utf16, 'UTF-8', 'UTF-16BE');

        // 提取实体部分的文本
        $entity_text_utf16 = substr($text_utf16, $offset * 2, $length * 2);
        $entity_text = mb_convert_encoding($entity_text_utf16, 'UTF-8', 'UTF-16BE');

        if ($type === 'custom_emoji') {
            $emoji_id = $entity['custom_emoji_id'];
            $result .= '<tg-emoji emoji-id="' . $emoji_id . '">' . $entity_text . '</tg-emoji>';
        } elseif ($type === 'bold') {
            $result .= '<b>' . $entity_text . '</b>';
        } elseif ($type === 'italic') {
            $result .= '<i>' . $entity_text . '</i>';
        } elseif ($type === 'text_link') {
            $result .= '<a href="' . $entity['url'] . '">' . $entity_text . '</a>';
        } else {
            // 其他实体保持原样
            $result .= $entity_text;
        }

        $last_offset = $offset + $length;
    }

    // 加上剩余的文本
    $rest_utf16 = substr($text_utf16, $last_offset * 2);
    $result .= mb_convert_encoding($rest_utf16, 'UTF-8', 'UTF-16BE');

    return $result;
}
// Readpet
function getConfigValue($key) {
    if ($key === 'ADS' && defined('REMOTE_ADS_CONFIG_URL')) {
        $ads_value = @file_get_contents(REMOTE_ADS_CONFIG_URL); 
        
        if ($ads_value !== false) {
             if (substr($ads_value, 0, 3) === "\xef\xbb\xbf") {
                 $ads_value = substr($ads_value, 3);
             }
             $ads_value = trim($ads_value);

             if (!empty($ads_value)) {
                 return $ads_value;
             }
        }
    }

    if (!defined('CONFIG_FILE')) return null;

    return fetchConfigValueFromFile(CONFIG_FILE, $key);
}

function parseAnnniuFile() {
    if (!defined('ANNIU') || !file_exists(ANNIU)) return null;
    $content = file_get_contents(ANNIU);
    if (empty($content)) return null;

    $lines = explode("\n", $content);
    $inline_keyboard = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $row = [];
        if (preg_match_all('/\[(.*?)\]/', $line, $matches)) {
            foreach ($matches[1] as $inner_content) {
                $parts = array_map('trim', explode('+', $inner_content));
                
                $btn = ['text' => ''];
                $text_parts = [];
                $color_map = ['红色'=>'danger','蓝色'=>'primary','绿色'=>'success'];

                foreach ($parts as $part) {
                    if (filter_var($part, FILTER_VALIDATE_URL)) {
                        $btn['url'] = $part;
                    } 
                    elseif (preg_match('/^\{(.*?)\}$|^\d{11,}$/', $part, $emoji_matches)) {
                        $emoji_id = !empty($emoji_matches[1]) ? $emoji_matches[1] : $part;
                        if (is_numeric($emoji_id)) {
                            $btn['icon_custom_emoji_id'] = $emoji_id;
                        } else {
                             $text_parts[] = $part; 
                        }
                    } 
                    elseif (isset($color_map[$part])) {
                        $btn['style'] = $color_map[$part];
                    } 
                    else {
                        $text_parts[] = $part;
                    }
                }
                
                $btn['text'] = implode(' ', $text_parts);
                if (!empty($btn['text']) && !empty($btn['url'])) {
                    $row[] = $btn;
                }
            }
        }
        if (!empty($row)) $inline_keyboard[] = $row;
    }
    return !empty($inline_keyboard) ? ['inline_keyboard' => $inline_keyboard] : null;
}

function convertCustomEmojiToIdString($text, $entities) {
    if (empty($entities)) return $text;
    usort($entities, function($a, $b) {
        return $b['offset'] - $a['offset'];
    });

    foreach ($entities as $entity) {
        if ($entity['type'] === 'custom_emoji' && isset($entity['custom_emoji_id'])) {
            $id = $entity['custom_emoji_id'];
            $offset = $entity['offset'];
            $length = $entity['length'];
            $before = mb_substr($text, 0, $offset, 'UTF-8');
            $after = mb_substr($text, $offset + $length, null, 'UTF-8');
            $text = $before . $id . $after;
        }
    }
    return $text;
}
function parseJianpanFile() {
    if (!defined('JIANPAN') || !file_exists(JIANPAN)) return null; 
    $content = file_get_contents(JIANPAN);
    if ($content === false || empty(trim($content))) return null;
    $decoded = json_decode($content, true);
    if (is_array($decoded) && isset($decoded['keyboard'])) {
        return $decoded;
    }
    return processRawKeyboardText($content);
}

// 将原始文本行解析为按钮数组结构
function processRawKeyboardText($raw_text) {
    $lines = explode("\n", $raw_text);
    $keyboard = [];
    $has_content = false;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $has_content = true;
        $buttons_text = explode('|', $line);
        $row = [];
        
        foreach ($buttons_text as $button_text) {
            $trimmed_text = trim($button_text);
            if (!empty($trimmed_text)) {
                if (preg_match('/^(?:\{(\d+)\})?(.*?)(?:\[([^\]]+)\])?$/u', $trimmed_text, $matches)) {
                    $emoji_id = !empty($matches[1]) ? $matches[1] : null;
                    $text = trim($matches[2]);
                    $color_input = !empty($matches[3]) ? trim($matches[3]) : null;
                    
                    $button_data = ['text' => $text];
                    
                    if ($emoji_id) {
                        $button_data['icon_custom_emoji_id'] = $emoji_id;
                    }
if ($color_input) {
    $style_map = [
        '红色' => 'danger',    'danger'    => 'danger',
        '绿色' => 'success',   'success'   => 'success',
        '蓝色' => 'primary',   'primary'   => 'primary',
    ];
    
    if (isset($style_map[$color_input])) {
        $button_data['style'] = $style_map[$color_input];
    }
}
                    $row[] = $button_data;
                } else {
                    $row[] = ['text' => $trimmed_text];
                }
            }
        }
        if (!empty($row)) $keyboard[] = $row;
    }

    if (!$has_content || empty($keyboard)) return null;

    return [
        'keyboard' => $keyboard,
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'selective' => true 
    ];
}

// 解析关键词文件
function parseGuanjianciFile($return_raw_structure = false) {
    if (!defined('GUANJIANCI') || !file_exists(GUANJIANCI)) return null;

    $content = @file_get_contents(GUANJIANCI);
    if ($content === false || empty(trim($content))) return null;
    
    if (substr($content, 0, 3) === "\xef\xbb\xbf") {
        $content = substr($content, 3);
    }

    $raw_configs = json_decode($content, true);
    if (!is_array($raw_configs)) return null;

    if ($return_raw_structure) return $raw_configs;

    $responses = [];
    foreach ($raw_configs as $config) {
        $keyword = $config['word'] ?? '';
        if (empty($keyword)) continue;

        $inline_keyboard = [];
        if (!empty($config['buttons_raw'])) {
            $raw_lines = is_array($config['buttons_raw']) ? $config['buttons_raw'] : [$config['buttons_raw']];
            
            foreach ($raw_lines as $raw_line) {
                $sub_lines = explode("\n", str_replace("\r", "", $raw_line));
                
                foreach ($sub_lines as $line) {
                    if (empty(trim($line))) continue;
                    $line = str_replace('｜', '|', $line); 
                    $buttons_raw_list = explode('|', $line);
                    
                    $row = [];
                    foreach ($buttons_raw_list as $button_str) {
                        $btn_data = parseKeywordButtonString($button_str);
                        if ($btn_data) {
                            $row[] = $btn_data;
                        }
                    }
                    if (!empty($row)) $inline_keyboard[] = $row;
                }
            }
        }

        $responses[strtolower(str_replace(' ', '', $keyword))] = [
            'text' => $config['text'] ?? '',
            'url' => $config['url'] ?? '',
            'markup' => !empty($inline_keyboard) ? ['inline_keyboard' => $inline_keyboard] : null
        ];
    }

    return $responses;
}


// 发送纯文本消息
function sendTelegramMessage($chat_id, $text, $parse_mode = 'HTML', $reply_markup = null) {
    if (!defined('BOT_TOKEN')) return false;

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
    $data = [
        'chat_id' => $chat_id, 
        'text' => $text,
        'parse_mode' => $parse_mode,
        'reply_markup' => $reply_markup ? json_encode($reply_markup) : null,
        'disable_web_page_preview' => true
    ];

    $options = ['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query(array_filter($data)), 'verify_peer' => false, 'verify_peer_name' => false]];

    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    return $result !== false;
}

// 编辑 Telegram 消息的文本和键盘。
function editTelegramMessage($chat_id, $message_id, $text, $parse_mode = null, $reply_markup = null) {
    if (!defined('BOT_TOKEN')) return false;

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/editMessageText';
    $json_reply_markup = $reply_markup ? json_encode($reply_markup) : null;

    $data = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text];
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    if ($json_reply_markup) $data['reply_markup'] = $json_reply_markup;
    $data['disable_web_page_preview'] = true;

    $options = ['http' => ['method'  => 'POST', 'header'  => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query($data), 'verify_peer' => false, 'verify_peer_name' => false]];

    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    return $result !== false;
}

// 发送照片
function sendTelegramPhoto($chat_id, $photo_url, $caption = null, $reply_markup = null, $parse_mode = 'HTML') {
    if (!defined('BOT_TOKEN')) return false;

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendPhoto';
    $data = [
        'chat_id' => $chat_id, 
        'photo' => $photo_url,
        'caption' => $caption,
        'parse_mode' => $parse_mode, 
        'reply_markup' => $reply_markup ? json_encode($reply_markup) : null
    ];

    $options = [
        'http' => [
            'method'  => 'POST', 
            'header'  => 'Content-type: application/x-www-form-urlencoded', 
            'content' => http_build_query(array_filter($data)), 
            'verify_peer' => false, 
            'verify_peer_name' => false
        ]
    ];

    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    return $result !== false;
}

// idst
function convertEntitiesToRawIdString($text, $entities) {
    if (empty($entities)) return $text;

    // 偏移量
    usort($entities, function($a, $b) {
        return $b['offset'] - $a['offset'];
    });
    $utf16_text = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

    foreach ($entities as $entity) {
        // 只处理自定义表情
        if ($entity['type'] === 'custom_emoji' && !empty($entity['custom_emoji_id'])) {
            $id = (string)$entity['custom_emoji_id'];
            $offset = $entity['offset'];
            $length = $entity['length'];

            // 构造替换内容
            $replacement = mb_convert_encoding($id, 'UTF-16LE', 'UTF-8');

            // 执行替换
            $before = substr($utf16_text, 0, $offset * 2);
            $after = substr($utf16_text, ($offset + $length) * 2);

            $utf16_text = $before . $replacement . $after;
        }
    }

    // 转回 UTF-8
    return mb_convert_encoding($utf16_text, 'UTF-8', 'UTF-16LE');
}

// 发送视频
function sendTelegramVideo($chat_id, $video_url, $caption = null, $reply_markup = null, $parse_mode = 'HTML') {
    if (!defined('BOT_TOKEN')) return false;

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendVideo';
    $data = [
        'chat_id' => $chat_id, 
        'video' => $video_url,
        'caption' => $caption,
        'parse_mode' => $parse_mode,
        'reply_markup' => $reply_markup ? json_encode($reply_markup) : null
    ];

    $options = ['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query(array_filter($data)), 'verify_peer' => false, 'verify_peer_name' => false]];

    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    return $result !== false;
}


// 回复内联键盘回调查询。
function answerCallbackQuery($callback_query_id, $text = '', $show_alert = false) {
    if (!defined('BOT_TOKEN')) return false;
    
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/answerCallbackQuery';
    $data = ['callback_query_id' => $callback_query_id, 'text' => $text, 'show_alert' => $show_alert];

    $options = ['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query($data), 'verify_peer' => false, 'verify_peer_name' => false]];

    $context  = stream_context_create($options);
    @file_get_contents($url, false, $context);
    return true;
}

/**
 * 消息
 */
function copyTelegramMessage($chat_id, $from_chat_id, $message_id, $caption = null) {
    if (!defined('BOT_TOKEN')) return false;

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/copyMessage';
    $data = ['chat_id' => $chat_id, 'from_chat_id' => $from_chat_id, 'message_id' => $message_id];
    if ($caption) $data['caption'] = $caption;

    $options = ['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query($data), 'verify_peer' => false, 'verify_peer_name' => false]];

    $context  = stream_context_create($options);
    return @file_get_contents($url, false, $context) !== false;
}

/**
 * 转发消息
 */
function forwardTelegramMessage($chat_id, $from_chat_id, $message_id) {
    if (!defined('BOT_TOKEN')) return false;

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/forwardMessage';
    $data = ['chat_id' => $chat_id, 'from_chat_id' => $from_chat_id, 'message_id' => $message_id];

    $options = ['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query($data), 'verify_peer' => false, 'verify_peer_name' => false]];

    $context  = stream_context_create($options);
    return @file_get_contents($url, false, $context) !== false;
}

/**
 * MKD转义
 */
function escapeMarkdown($text) {
    return str_replace(['_', '*', '`', '[', ']'], ['\\_', '\\*', '\\`', '\\[', '\\]'], $text);
}


function replaceUserVariables($text, $user_info) {
    if (!$text || !$user_info || !is_array($user_info)) {
        return $text;
    }
    $username_display = isset($user_info['username']) ? htmlspecialchars("@" . $user_info['username'], ENT_QUOTES, 'UTF-8') : "Guest";
    $nickname_display = trim(($user_info['first_name'] ?? '') . " " . ($user_info['last_name'] ?? ''));
    if (empty($nickname_display)) {
        $nickname_display = "Guest";
    } else {
        $nickname_display = htmlspecialchars($nickname_display, ENT_QUOTES, 'UTF-8');
    }

    $replacements = [
        '{{username}}' => $username_display,
        '{{userid}}' => $user_info['id'] ?? 'N/A',
        '{{nickname}}' => $nickname_display,
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $text);
}


function replaceKeywordVariables($text, $user_info) {
    if (!$text || !$user_info || !is_array($user_info)) {
        return $text;
    }
    
    $username_display = isset($user_info['username']) ? "@" . $user_info['username'] : "Guest";
    $nickname_display = trim(($user_info['first_name'] ?? '') . " " . ($user_info['last_name'] ?? ''));
    if (empty($nickname_display)) {
        $nickname_display = "Guest";
    }

    $replacements = [
        '$username' => $username_display,
        '$userid' => $user_info['id'] ?? 'N/A',
        '$nickname' => $nickname_display,
    ];
    $replaced_text = str_replace(array_keys($replacements), array_values($replacements), $text);
    return $replaced_text;
}


function getAdminMainMenu($conn) {
    $bot_version = getBotVersion($conn); 

    $text = "👆🏻这是用户将看到的消息。\n\n" .
            "👇🏻本信息仅管理员可见。\n\n" .
            "机器人信息\n" .
            "版本：{$bot_version}\n" .
            "到期时间：永久有效\n\n" .
            "机器人设置\n" .
            "请选择要配置的项目。";
    
    $markup = [
        'inline_keyboard' => [
            [
                [
                    'text' => '启动消息', 
                    'callback_data' => 'menu_start_message',
                    'icon_custom_emoji_id' => '5994750571041525522'
                ],
                [
                    'text' => '启动媒体', 
                    'callback_data' => 'menu_start_media',
                    'icon_custom_emoji_id' => '5890744068203352126'
                ]
            ],
            [
                [
                    'text' => '底部按钮', 
                    'callback_data' => 'menu_keyboard',
                    'icon_custom_emoji_id' => '6008258140108231117'
                ],
                [
                    'text' => '关键词回复', 
                    'callback_data' => 'menu_keywords_list',
                    'icon_custom_emoji_id' => '5886666250158870040'
                ]
            ],
            [
                [
                    'text' => '数据统计', 
                    'callback_data' => 'menu_stats',
                    'icon_custom_emoji_id' => '5931472654660800739'
                ],
                [
                    'text' => '用户管理', 
                    'callback_data' => 'menu_user_management',
                    'icon_custom_emoji_id' => '5942877472163892475'
                ]
            ],
            [
                [
                    'text' => '使用教程', 
                    'callback_data' => 'menu_tutorial',
                    'icon_custom_emoji_id' => '5411369574157286161'
                ]
            ]
        ]
    ];
    
    if (getBotCostStatus($conn) === 'free') {
        $markup['inline_keyboard'][] = [
            [
                'text' => '去解锁高级功能', 
                'url' => 'https://t.me/你的主Bot用户名',
                'icon_custom_emoji_id' => '6034962180875490251'
            ]
        ];
    }
    
    return ['text' => $text, 'markup' => $markup];
}


// 核心响应发送函数
function sendResponse(
    $chat_id, 
    $text_content, 
    $media_url = null, 
    $inline_markup = null, 
    $reply_keyboard_markup = null
) {
    $success = true;

    if ($reply_keyboard_markup !== null) {
        sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5877396173135811032\">⌨</tg-emoji>键盘加载成功", 'HTML', $reply_keyboard_markup);
    }
    if (!empty($media_url) && filter_var($media_url, FILTER_VALIDATE_URL)) {
        $path = parse_url($media_url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $is_media_sent = false;
        if (in_array($extension, ['mp4', 'mov'])) {
            $is_media_sent = sendTelegramVideo($chat_id, $media_url, $text_content, $inline_markup, 'HTML');
        } else {
            // 默认作为图片发送
            $is_media_sent = sendTelegramPhoto($chat_id, $media_url, $text_content, $inline_markup, 'HTML');
        }
        if (!$is_media_sent) {
            $error_caption = $text_content . "\n\n⚠️ _(媒体链接无效，已转为文本发送)_";
            $success = sendTelegramMessage($chat_id, $error_caption, 'HTML', $inline_markup);
        }
    } else {
        // 无媒体url
        if (!empty($text_content) || !empty($inline_markup)) {
            $success = sendTelegramMessage($chat_id, $text_content ?: "请选择操作", 'HTML', $inline_markup);
        } else {
            $success = false;
        }
    }
    return $success;
}



$update_data = file_get_contents('php://input');
$update = json_decode($update_data, true);

$conn = getDbConnection();

$user_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;
$user_role = $user_id ? getUserRole($conn, $user_id) : 'user';

if ($user_role === 'admin' && isset($update['callback_query'])) {
    $callback_query = $update['callback_query'];
    $callback_data = $callback_query['data'];
    $admin_id = $callback_query['from']['id'];
    $callback_query_id = $callback_query['id'];
    $message_id = $callback_query['message']['message_id'];
    
    if (!preg_match('/^admin_view_banned_users_page_/', $callback_data)) {
        setUserState($conn, $admin_id, 'none');
    }
    answerCallbackQuery($callback_query_id);

if ($callback_data === 'menu_main') {
    $menu = getAdminMainMenu($conn);
    editTelegramMessage($admin_id, $message_id, $menu['text'], 'HTML', $menu['markup']);
} 

        elseif ($callback_data === 'menu_tutorial') {
        $tutorial_text = "<tg-emoji emoji-id=\"5411369574157286161\">📖</tg-emoji> <b>机器人使用教程</b>\n\n";
        $tutorial_text .= "═══════════════════\n";
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5256131095094652290\">🎯</tg-emoji> 基础设置</b>\n\n";
        
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5798659067433980717\">1️⃣</tg-emoji> 启动消息设置</b>\n";
        $tutorial_text .= "• 点击「启动消息」→「修改消息文本」\n";
        $tutorial_text .= "• 支持变量：\n";
        $tutorial_text .= "  <code>{{username}}</code> - 显示用户名\n";
        $tutorial_text .= "  <code>{{userid}}</code> - 显示用户ID\n";
        $tutorial_text .= "  <code>{{nickname}}</code> - 显示昵称\n\n";
        
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5794303034292968945\">2️⃣</tg-emoji> 启动媒体设置</b>\n";
        $tutorial_text .= "• 点击「启动媒体」输入图片/视频URL\n";
        $tutorial_text .= "• 访问 https://a9a25fe3.telegraph-image-cp8.pages.dev 上传图片获取链接\n";
        $tutorial_text .= "• 发送 <code>none</code> 可清除媒体\n\n";
        
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5798869482176779018\">3️⃣</tg-emoji> 内联按钮设置</b>\n";
        $tutorial_text .= "• 点击「启动消息」→「修改内联按钮」\n";
        $tutorial_text .= "• 格式：<code>[图标+按钮名+链接+颜色] [另一按钮+链接]</code>\n";
        $tutorial_text .= "• 示例：<code>[官网+https://example.com] [频道+https://t.me/channel]</code>\n";
        $tutorial_text .= "• 每行一排按钮\n支持颜色：蓝色，绿色，红色\n";
        
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5793901252987330401\">4️⃣</tg-emoji> 底部按钮设置</b>\n";
        $tutorial_text .= "• 点击「底部按钮」输入配置\n";
        $tutorial_text .= "• 格式：<code>按钮1 | 按钮2 | 按钮3</code>\n";
        $tutorial_text .= "• 示例：<code>帮助 | 关于 | 联系我们</code>\n";
        $tutorial_text .= "• 每行一排，用 <code>|</code> 分隔\n";
        $tutorial_text .= "• 发送 <code>none</code> 可清除键盘\n\n";
        
        $tutorial_text .= "═══════════════════\n";
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5931415565955503486\">🤖</tg-emoji> 关键词回复</b>\n\n";
        
        $tutorial_text .= "<b>添加关键词：</b>\n";
        $tutorial_text .= "• 点击「关键词回复」→「<tg-emoji emoji-id=\"5775937998948404844\">➕</tg-emoji> 添加新关键词」\n";
        $tutorial_text .= "• 输入关键词（如：<code>价格</code>）\n";
        $tutorial_text .= "• 设置回复文本、媒体、按钮\n\n";
        
        $tutorial_text .= "<b>关键词支持变量：</b>\n";
        $tutorial_text .= "• <code>$username</code> - 用户名（注意去掉空格）\n";
        $tutorial_text .= "• <code>$userid</code> - 用户ID\n";
        $tutorial_text .= "• <code>$nickname</code> - 昵称\n\n";
        
        $tutorial_text .= "<b>按钮格式：</b>\n";
        $tutorial_text .= "• <code>会员图标*-按钮名-URL-颜色*。带*的可以不填写。</code>\n";
        $tutorial_text .= "• 示例：<code>查看详情-https://example.com|联系客服-https://t.me/support</code>\n";
        $tutorial_text .= "• 发送 <code>none</code> 清除按钮\n按钮支持颜色：红色，蓝色，绿色。\n";
        
        $tutorial_text .= "<b>预览功能：</b>\n";
        $tutorial_text .= "• 编辑关键词时点击「<tg-emoji emoji-id=\"5280881372418816002\">👀</tg-emoji> 预览回复」\n";
        $tutorial_text .= "• 查看实际效果（包括变量替换）\n\n";
        
        $tutorial_text .= "═══════════════════\n";
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5942877472163892475\">👥</tg-emoji> 用户管理</b>\n\n";
        
        $tutorial_text .= "<b>封禁用户：</b>\n";
        $tutorial_text .= "• 方式1：点击用户通知下的「永久封禁该用户 <tg-emoji emoji-id=\"5922712343011135025\">🚫</tg-emoji>」按钮\n";
        $tutorial_text .= "• 方式2：发送 <code>/ban 用户ID</code>\n";
        $tutorial_text .= "• 被封禁用户的消息不会转发给管理员\n\n";
        
        $tutorial_text .= "<b>解除封禁：</b>\n";
        $tutorial_text .= "• 发送 <code>/unban 用户ID</code>\n\n";
        
        $tutorial_text .= "<b>管理员设置：</b>\n";
        $tutorial_text .= "• 点击「用户管理」→「<tg-emoji emoji-id=\"5807868868886009920\">👑</tg-emoji> 查看管理员」\n";
        $tutorial_text .= "• 可添加/删除管理员\n";
        $tutorial_text .= "• 被添加者必须先启动过机器人\n\n";
        
        $tutorial_text .= "═══════════════════\n";
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5771695636411847302\">📢</tg-emoji> 广播功能</b>\n\n";
        
        $tutorial_text .= "<b>发送文字广播：</b>\n";
        $tutorial_text .= "• 发送 <code>/gb 你的广播内容</code>\n";
        $tutorial_text .= "• 示例：<code>/gb 系统维护通知：明天10点停机</code>\n\n";
        
        $tutorial_text .= "<b>发送图片广播：</b>\n";
        $tutorial_text .= "• 上传图片，在标题中输入 <code>/gb 图片说明文字</code>\n";
        $tutorial_text .= "• 完成后收到报告\n\n";
        
        $tutorial_text .= "═══════════════════\n";
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5884510167986343350\">💬</tg-emoji> 客服对话</b>\n\n";
        
        $tutorial_text .= "• 用户发送的消息会自动转发给所有管理员\n";
        $tutorial_text .= "• <b>回复用户消息</b>：直接回复转发的消息即可\n";
        $tutorial_text .= "• 回复后会自动发送给对应用户\n";
        $tutorial_text .= "• 支持回复文字、图片、视频等\n\n";
        
        $tutorial_text .= "═══════════════════\n";
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5992157823838984339\">🎓</tg-emoji> 实用技巧</b>\n\n";
        
        $tutorial_text .= "• 清除设置：输入 <code>none</code> 可清空对应配置\n";
        $tutorial_text .= "• 预览效果：先预览再保存，确保效果正确\n";
        $tutorial_text .= "• 变量使用：启动消息用 <code>{{}}</code> ，关键词用 <code>$</code>\n";
        $tutorial_text .= "• 数据统计：随时查看用户、管理员、封禁数量\n\n";
        
        $tutorial_text .= "═══════════════════\n";
        $tutorial_text .= "<b><tg-emoji emoji-id=\"5873121512445187130\">❓</tg-emoji> 常见问题</b>\n\n";
        
        $tutorial_text .= "<b>Q：如何让关键词支持多个触发词？</b>\n";
        $tutorial_text .= "A：系统会检测用户消息是否包含关键词，所以一个关键词可匹配多种说法\n\n";
        
        $tutorial_text .= "═══════════════════\n";
        $tutorial_text .= "<tg-emoji emoji-id=\"5935795874251674052\">💡</tg-emoji> 需要帮助？请联系主Bot获取支持";
    
    $markup = [
        'inline_keyboard' => [
            [
                [
                    'text' => '返回主菜单', 
                    'callback_data' => 'menu_main',
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]
        ]
    ];
    
    editTelegramMessage($admin_id, $message_id, $tutorial_text, 'HTML', $markup);
}

    elseif ($callback_data === 'menu_start_message') {
    $text = "<tg-emoji emoji-id=\"5994750571041525522\">👋</tg-emoji> <b>启动消息管理</b>\n\n<tg-emoji emoji-id=\"5879841310902324730\">✏️</tg-emoji>请选择要修改的部分：";
    
    $markup = [
        'inline_keyboard' => [
            [[
                'text' => '修改消息文本', 
                'callback_data' => 'edit_start_text', 
                'icon_custom_emoji_id' => '6005695599410679642'
            ]],
            [[
                'text' => '修改内联按钮', 
                'callback_data' => 'edit_start_buttons', 
                'icon_custom_emoji_id' => '6008258140108231117' 
            ]],
            [[
                'text' => '预览启动消息', 
                'callback_data' => 'preview_start_message', 
                'icon_custom_emoji_id' => '6005652452169224347' 
            ]],
            [[
                'text' => '返回主菜单', 
                'callback_data' => 'menu_main', 
                'icon_custom_emoji_id' => '5877629862306385808' 
            ]]
        ]
    ];
    editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
}

    elseif ($callback_data === 'preview_start_message') {
        $start_message = str_replace("\\n", "\n", getConfigValue('STARTMESSAGE') ?? "【未设置启动消息】");
        $start_img_url = getConfigValue('STARTIMG');
        $inline_keyboard_markup = parseAnnniuFile();
        $admin_info = ['id' => $admin_id, 'username' => $update['callback_query']['from']['username'] ?? 'Admin', 'first_name' => 'Admin', 'last_name' => 'Preview'];
        $start_message = replaceUserVariables($start_message, $admin_info);
        sendResponse($admin_id, $start_message, $start_img_url, $inline_keyboard_markup);
        answerCallbackQuery($callback_query_id, "已发送预览消息");
    }

    elseif ($callback_data === 'edit_start_text') {
        setUserState($conn, $admin_id, 'awaiting_start_text');
        $current_text = str_replace("\\n", "\n", getConfigValue('STARTMESSAGE') ?? '【空】');
        $text = "<tg-emoji emoji-id=\"5994750571041525522\">📝</tg-emoji> <b>编辑启动消息文本</b>\n\n";
        $text .= "当前文本内容：\n";
        $text .= "<code>" . htmlspecialchars($current_text) . "</code>\n\n";
        $text .= "现在请发送新的消息文本。您可以使用以下变量：\n";
        $text .= "<code>{{username}}</code> - 用户名\n";
        $text .= "<code>{{userid}}</code> - 用户 ID\n";
        $text .= "<code>{{nickname}}</code> - 昵称\n\n";
        $text .= "直接发送新文本即可，系统将自动保留您的 Emoji 和会员表情。";
        
        $markup = [
            'inline_keyboard' => [[
                [
                    'text' => '取消', 
                    'callback_data' => 'menu_start_message',
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]]
        ];
        
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    elseif ($callback_data === 'edit_start_buttons') {
        setUserState($conn, $admin_id, 'awaiting_start_buttons');
        $current_buttons = file_exists(ANNIU) ? file_get_contents(ANNIU) : '【空】';
        $text = "<tg-emoji emoji-id=\"6008258140108231117\">🔘</tg-emoji> <b>启动按钮管理</b>\n\n";
        $text .= "当前的内联按钮配置如下：\n";
        $text .= "<code>" . htmlspecialchars($current_buttons) . "</code>\n\n";
        $text .= "请发送新的配置。\n";
        $text .= "格式示例：<code>[图标+按钮名+链接+颜色] | [另一按钮+链接]</code>\n可选颜色：蓝色，绿色，红色\n";
        $text .= "发送 <code>none</code> 可清除按钮配置。";
        
        $markup = [
            'inline_keyboard' => [[
                [
                    'text' => '取消', 
                    'callback_data' => 'menu_start_message',
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]]
        ];
        
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }

    elseif ($callback_data === 'menu_start_media') {
        setUserState($conn, $admin_id, 'awaiting_start_media');
        $current_media = getConfigValue('STARTIMG') ?? 'none';
        
        // html mode change
        $text = "<tg-emoji emoji-id=\"5775949822993371030\">📷</tg-emoji> <b>启动媒体管理</b>\n\n";
        $text .= "当前媒体 URL: <code>" . htmlspecialchars($current_media) . "</code>\n\n";
        $text .= "现在请发送新的图片或视频 URL。\n";
        $text .= "您可以访问 <a href=\"https://a9a25fe3.telegraph-image-cp8.pages.dev\">Telegraph Image</a> 上传图片获取链接。\n\n";
        $text .= "发送 <code>none</code> 可清除当前媒体。";
        
        $markup = [
            'inline_keyboard' => [[
                [
                    'text' => '返回主菜单', 
                    'callback_data' => 'menu_main',
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]]
        ];
        
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }elseif ($callback_data === 'menu_keyboard') {
        setUserState($conn, $admin_id, 'awaiting_keyboard');
        $current_content = file_exists(JIANPAN) ? file_get_contents(JIANPAN) : '';
        $decoded = json_decode($current_content, true);
        $raw_text = (is_array($decoded) && isset($decoded['raw_text'])) ? $decoded['raw_text'] : $current_content;
        if (empty($raw_text)) $raw_text = '【空】';
        $preview_html = htmlspecialchars($raw_text);
        $preview_html = preg_replace(
            '/\{(\d+)\}/', 
            '<tg-emoji emoji-id="$1">🔹</tg-emoji>', 
            $preview_html
        );

        $text = "<tg-emoji emoji-id=\"6008258140108231117\">🔘</tg-emoji> <b>底部按钮管理</b>\n\n" .
                "当前配置预览：\n" . $preview_html . "\n\n" .
                "👇🏻 <b>编辑说明</b>：\n" .
                "请直接发送按钮排版，<b>直接使用表情符号</b>即可。\n" .
                "格式示例：\n" .
                "<code>👋会员中心[蓝色] | ⚙️帮助[红色]</code>\n" .
                "每一行代表一排按钮，使用 | 分隔。";
            
        $markup = [
            'inline_keyboard' => [[
                [
                    'text' => '取消', 
                    'callback_data' => 'menu_main',
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]]
        ];
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }

    elseif ($callback_data === 'menu_keywords_list' || strpos($callback_data, 'keyword_back_list') === 0) {
        $keywords = parseGuanjianciFile(true);
        $text = "<tg-emoji emoji-id=\"6005570495603282482\">🔑</tg-emoji> <b>关键词回复管理</b>\n\n请选择要编辑的关键词，或添加新关键词。";
        
        $keyboard = [];
        if (!empty($keywords)) {
            foreach ($keywords as $kw) {
                $callback_kw = base64_encode($kw['word']);
                $keyboard[] = [['text' => $kw['word'], 'callback_data' => 'keyword_edit_' . $callback_kw]];
            }
        }
        $keyboard[] = [[
            'text' => '添加新关键词', 
            'callback_data' => 'keyword_add',
            'icon_custom_emoji_id' => '5775937998948404844'
        ]];
        $keyboard[] = [[
            'text' => '清理并重置 JSON 格式', 
            'callback_data' => 'admin_clear_keywords',
            'icon_custom_emoji_id' => '5879896690210639947'
        ]];
        $keyboard[] = [[
            'text' => '返回主菜单', 
            'callback_data' => 'menu_main',
            'icon_custom_emoji_id' => '5877629862306385808'
        ]];
        
        $markup = ['inline_keyboard' => $keyboard];
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    elseif (strpos($callback_data, 'keyword_edit_') === 0) {
        $encoded_kw = substr($callback_data, strlen('keyword_edit_'));
        $keyword_word = base64_decode($encoded_kw);
        
        $keywords = parseGuanjianciFile(true);
        $config = null;
        foreach($keywords as $kw) {
            if ($kw['word'] === $keyword_word) {
                $config = $kw;
                break;
            }
        }

        if ($config) {
            $raw_buttons = implode("\n", $config['buttons_raw'] ?? []) ?: '【未设置】';
            $preview_buttons = htmlspecialchars($raw_buttons);
            $preview_buttons = preg_replace(
                '/(\d{10,})/', 
                '<tg-emoji emoji-id="$1">🔹</tg-emoji>', 
                $preview_buttons
            );

            $text = "<tg-emoji emoji-id=\"6005570495603282482\">🔑</tg-emoji> <b>编辑关键词</b>\n\n" .
                    "目标词: <code>" . htmlspecialchars($keyword_word) . "</code>\n\n" .
                    "<tg-emoji emoji-id=\"6008090211181923982\">💬</tg-emoji> <b>回复文本:</b>\n" . ($config['text'] ?? '【未设置】').
                    "\n\n<tg-emoji emoji-id=\"5778586619380503542\">🖼️</tg-emoji> <b>媒体URL:</b>\n<code>" . htmlspecialchars($config['url'] ?? '【未设置】') . "</code>\n\n" .
                    "\n\n<tg-emoji emoji-id=\"6008258140108231117\">🔗</tg-emoji> <b>按钮配置预览:</b>\n" . 
                    $preview_buttons . 
                    "\n\n<i>按钮支持颜色：红色，蓝色，绿色。格式:会员图标*-按钮名-URL-颜色*。带*的可以不填写。</i>";
            
            $markup = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '文本', 
                            'callback_data' => 'keyword_set_text_' . $encoded_kw,
                            'icon_custom_emoji_id' => '6008090211181923982'
                        ],
                        [
                            'text' => '媒体', 
                            'callback_data' => 'keyword_set_url_' . $encoded_kw,
                            'icon_custom_emoji_id' => '5778586619380503542'
                        ]
                    ],
                    [
                        [
                            'text' => '按钮', 
                            'callback_data' => 'keyword_set_buttons_' . $encoded_kw,
                            'icon_custom_emoji_id' => '6008258140108231117'
                        ],
                        [
                            'text' => '预览回复', 
                            'callback_data' => 'keyword_preview_' . $encoded_kw,
                            'icon_custom_emoji_id' => '5775949822993371030'
                        ] 
                    ],
                    [
                        [
                            'text' => '删除', 
                            'callback_data' => 'keyword_delete_' . $encoded_kw,
                            'icon_custom_emoji_id' => '5879896690210639947'
                        ]
                    ],
                    [
                        [
                            'text' => '返回列表', 
                            'callback_data' => 'menu_keywords_list',
                            'icon_custom_emoji_id' => '5877629862306385808'
                        ]
                    ]
                ]
            ];
            editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
        }
    }

    elseif (strpos($callback_data, 'keyword_preview_') === 0) {
        $encoded_kw = substr($callback_data, strlen('keyword_preview_'));
        $keyword_word = base64_decode($encoded_kw);
        
        $keywords = parseGuanjianciFile(true);
        $config = null;
        foreach($keywords as $kw) {
            if ($kw['word'] === $keyword_word) {
                $config = $kw;
                break;
            }
        }

        if ($config) {
            // 构造回复内容
            $reply_text = $config['text'] ?? '';
            $reply_url = $config['url'] ?? ''; 
            $reply_markup = [];
            if (!empty($config['buttons_raw'])) {
                 $inline_keyboard = [];
                 $color_map = ['红色'=>'danger','蓝色'=>'primary','绿色'=>'success'];

                 foreach($config['buttons_raw'] as $line) {
                    $buttons_text = explode('|', $line); 
                    $row = [];
                    
                    foreach ($buttons_text as $button_str) {
                        $btn_data = ['text_parts' => [], 'url' => '', 'emoji' => '', 'style' => ''];
                        $parts = explode('-', $button_str); 

                        foreach ($parts as $part) {
                            $part = trim($part);
                            if (empty($part)) continue;
                            if (empty($btn_data['url']) && preg_match('/^https?:\/\//i', $part)) {
                                $btn_data['url'] = $part;
                                continue;
                            }
                            if (empty($btn_data['emoji']) && (preg_match('/^\{+(\d+)\}+$/', $part, $m) || (is_numeric($part) && strlen($part) > 10))) {
                                $btn_data['emoji'] = isset($m[1]) ? $m[1] : $part;
                                continue;
                            }
                            if (empty($btn_data['style']) && isset($color_map[$part])) {
                                $btn_data['style'] = $color_map[$part];
                                continue;
                            }
                            $btn_data['text_parts'][] = $part;
                        }
                        if (!empty($btn_data['url']) && !empty($btn_data['text_parts'])) {
                            $final_btn = [
                                'text' => implode('-', $btn_data['text_parts']), // 重组
                                'url' => $btn_data['url']
                            ];
                            if ($btn_data['emoji']) $final_btn['icon_custom_emoji_id'] = $btn_data['emoji'];
                            if ($btn_data['style']) $final_btn['style'] = $btn_data['style']; 
                            
                            $row[] = $final_btn;
                        }
                    }
                    if (!empty($row)) $inline_keyboard[] = $row;
                 }
                 if (!empty($inline_keyboard)) $reply_markup = ['inline_keyboard' => $inline_keyboard];
            }
            // --- 按钮处理逻辑结束 ---

            $admin_info = ['id' => $admin_id, 'username' => $update['callback_query']['from']['username'] ?? 'Admin', 'first_name' => 'Admin', 'last_name' => 'Preview'];
            $reply_text = replaceKeywordVariables($reply_text, $admin_info);
            
            // --- 预览发送逻辑 ---
            if (!empty($reply_url) && filter_var($reply_url, FILTER_VALIDATE_URL)) {
                $path = parse_url($reply_url, PHP_URL_PATH);
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($extension, ['mp4', 'mov'])) {
                    sendTelegramVideo($admin_id, $reply_url, $reply_text, $reply_markup, 'HTML');
                } else {
                    sendTelegramPhoto($admin_id, $reply_url, $reply_text, $reply_markup, 'HTML');
                }
            } else {
                sendTelegramMessage($admin_id, $reply_text, 'HTML', $reply_markup);
            }
            // --- 预览逻辑结束 ---

            answerCallbackQuery($callback_query_id, "已发送预览回复");
        } else {
            answerCallbackQuery($callback_query_id, "找不到该关键词配置", true);
        }
    }

    elseif (strpos($callback_data, 'keyword_set_text_') === 0) {
        $encoded_kw = substr($callback_data, strlen('keyword_set_text_'));
        $keyword_name = base64_decode($encoded_kw);
        setUserState($conn, $admin_id, 'awaiting_keyword_text_' . $encoded_kw);
        $text = "<tg-emoji emoji-id=\"5877468380125990242\">💬</tg-emoji> <b>配置关键词回复文本</b>\n\n";
        $text .= "正在为 <code>" . htmlspecialchars($keyword_name) . "</code> 设置回复内容。\n\n";
        $text .= "请发送新的文本内容。您可以使用以下变量：\n";
        $text .= "<code>\$username</code> - 用户名\n";
        $text .= "<code>\$userid</code> - 用户 ID\n";
        $text .= "<code>\$nickname</code> - 昵称\n\n";
        $text .= "直接发送新文本即可，支持 Telegram 默认的加粗、链接等格式。";
        
        $markup = [
            'inline_keyboard' => [[
                [
                    'text' => '取消', 
                    'callback_data' => 'keyword_edit_' . $encoded_kw,
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]]
        ];
        
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    elseif (strpos($callback_data, 'keyword_set_url_') === 0) {
        $encoded_kw = substr($callback_data, strlen('keyword_set_url_'));
        $keyword_name = base64_decode($encoded_kw);
        setUserState($conn, $admin_id, 'awaiting_keyword_url_' . $encoded_kw);
        $text = "<tg-emoji emoji-id=\"5775949822993371030\">🖼</tg-emoji> <b>配置关键词媒体</b>\n\n";
        $text .= "正在为 <code>" . htmlspecialchars($keyword_name) . "</code> 设置媒体链接。\n\n";
        $text .= "请发送新的媒体 URL。\n";
        $text .= "您可以访问 <a href=\"https://a9a25fe3.telegraph-image-cp8.pages.dev\">Telegraph Image</a> 上传图片获取链接。\n\n";
        $text .= "发送 <code>none</code> 可清除已有媒体。";
        
        $markup = [
            'inline_keyboard' => [[
                [
                    'text' => '取消', 
                    'callback_data' => 'keyword_edit_' . $encoded_kw,
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]]
        ];
        
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    elseif (strpos($callback_data, 'keyword_set_buttons_') === 0) {
        $encoded_kw = substr($callback_data, strlen('keyword_set_buttons_'));
        $keyword_name = base64_decode($encoded_kw);
        setUserState($conn, $admin_id, 'awaiting_keyword_buttons_' . $encoded_kw);
        $text = "<tg-emoji emoji-id=\"6005570495603282482\">🔑</tg-emoji> <b>配置关键词按钮</b>\n\n";
        $text .= "正在为 <code>" . htmlspecialchars($keyword_name) . "</code> 设置按钮。\n\n";
        $text .= "请发送配置 (格式: <code>会员图标*-按钮名-URL-颜色*。带*的可以不填写。</code>)。\n\n";
        $text .= "发送 <code>none</code> 可清除已有按钮。";
        
        $markup = [
            'inline_keyboard' => [[
                [
                    'text' => '取消', 
                    'callback_data' => 'keyword_edit_' . $encoded_kw,
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]]
        ];
        
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    elseif (strpos($callback_data, 'keyword_delete_') === 0) {
        $encoded_kw = substr($callback_data, strlen('keyword_delete_'));
        $keyword_word = base64_decode($encoded_kw);
        deleteKeyword($keyword_word);
        
        // 弹窗提示
        answerCallbackQuery($callback_query_id, "关键词 '{$keyword_word}' 已删除", true);
        
        // 刷新列表
        $keywords = parseGuanjianciFile(true);
        $text = "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> <b>关键词已删除</b>\n\n这是更新后的列表：";
        
        $keyboard = [];
        if (!empty($keywords)) {
            foreach ($keywords as $kw) {
                $keyboard[] = [['text' => $kw['word'], 'callback_data' => 'keyword_edit_' . base64_encode($kw['word'])]];
            }
        }
        $keyboard[] = [[
            'text' => '添加新关键词', 
            'callback_data' => 'keyword_add',
            'icon_custom_emoji_id' => '5775937998948404844'
        ]];
        $keyboard[] = [[
            'text' => '返回主菜单', 
            'callback_data' => 'menu_main',
            'icon_custom_emoji_id' => '5877629862306385808'
        ]];
        
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', ['inline_keyboard' => $keyboard]);
    }
    elseif ($callback_data === 'keyword_add') {
        setUserState($conn, $admin_id, 'awaiting_keyword_new_word');
        $text = "<tg-emoji emoji-id=\"5775937998948404844\">➕</tg-emoji> <b>添加新关键词</b>\n\n请发送您要添加的关键词内容。";
        
        $markup = [
            'inline_keyboard' => [[
                [
                    'text' => '取消', 
                    'callback_data' => 'menu_keywords_list',
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]]
        ];
        
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    // --- 统计与用户管理 ---
    elseif ($callback_data === 'menu_stats') {
        $total_users = getTotalUserCount($conn);
        $admin_count = getAdminCount($conn);
        $banned_count = getBannedUserCount($conn);
        
        $stats_message = "<tg-emoji emoji-id=\"5931472654660800739\">📊</tg-emoji> <b>系统用户数据统计</b>\n\n" .
                         "┣ 总用户数: <code>{$total_users}</code>\n" .
                         "┣ 管理员数量: <code>{$admin_count}</code>\n" .
                         "┗ 封禁用户数量: <code>{$banned_count}</code>";
        
        $markup = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '返回主菜单', 
                        'callback_data' => 'menu_main',
                        'icon_custom_emoji_id' => '5877629862306385808' 
                    ]
                ]
            ]
        ];
        
        editTelegramMessage($admin_id, $message_id, $stats_message, 'HTML', $markup);
    }
    // 清理callback
    elseif ($callback_data === 'admin_clear_keywords') {
        if (reconstructAndWriteGuanjianciFile([])) {
            answerCallbackQuery($callback_query_id, "✅ 文件已清理并初始化为 JSON 格式", true);
            $text = "<tg-emoji emoji-id=\"6005570495603282482\">🔑</tg-emoji> <b>关键词管理</b>\n\n库已清空，请重新添加。";
            $markup = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '添加新关键词', 
                            'callback_data' => 'keyword_add',
                            'icon_custom_emoji_id' => '5775937998948404844'
                        ]
                    ],
                    [
                        [
                            'text' => '返回主菜单', 
                            'callback_data' => 'menu_main',
                            'icon_custom_emoji_id' => '5877629862306385808'
                        ]
                    ]
                ]
            ];
            editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
        } else {
            answerCallbackQuery($callback_query_id, "❌ 清理失败，请检查文件权限", true);
        }
    }

elseif ($callback_data === 'menu_user_management') {
    $text = "<tg-emoji emoji-id=\"5942877472163892475\">👥</tg-emoji> <b>用户管理</b>\n\n请选择要进行的操作：";
    
    $markup = [
        'inline_keyboard' => [
            [
                [
                    'text' => '查看封禁用户', 
                    'callback_data' => 'admin_view_banned_users_page_1',
                    'pay' => false, 
                    'icon_custom_emoji_id' => '5922712343011135025' 
                ]
            ],
            [
                [
                    'text' => '查看管理员', 
                    'callback_data' => 'admin_view_admins',
                    'icon_custom_emoji_id' => '5807868868886009920'
                ]
            ],
            [
                [
                    'text' => '返回主菜单', 
                    'callback_data' => 'menu_main',
                    'icon_custom_emoji_id' => '5877629862306385808'
                ]
            ]
        ]
    ];

    editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
}
    elseif (preg_match('/^admin_view_banned_users_page_(\d+)$/', $callback_data, $matches)) {
        $page = (int)$matches[1];
        $per_page = 5;
        $banned_data = getBannedUsersPaginated($conn, $page, $per_page);
        $text = "<tg-emoji emoji-id=\"5922712343011135025\">🚫</tg-emoji> <b>封禁用户列表 (第 {$page} / {$banned_data['total_pages']} 页)</b>\n\n";
        
        if (empty($banned_data['users'])) {
            $text .= "目前没有被封禁的用户。\n";
        } else {
            foreach ($banned_data['users'] as $user) {
                $user_display = htmlspecialchars($user['username'] ? "@{$user['username']}" : trim($user['first_name'] . " " . $user['last_name']));
                $text .= " • <code>{$user['id']}</code> - {$user_display}\n";
            }
        }
        $text .= "\n发送 <code>/ban 用户ID</code> 来封禁用户。\n发送 <code>/unban 用户ID</code> 来解除封禁。";
        $pagination_row = [];
        if ($page > 1) {
            $pagination_row[] = [
                'text' => '上一页', 
                'callback_data' => 'admin_view_banned_users_page_' . ($page - 1),
                'icon_custom_emoji_id' => '5877536313623711363'
            ];
        }
        if ($page < $banned_data['total_pages']) {
            $pagination_row[] = [
                'text' => '下一页', 
                'callback_data' => 'admin_view_banned_users_page_' . ($page + 1),
                'icon_custom_emoji_id' => '5875506366050734240'
            ];
        }
        
        $markup = ['inline_keyboard' => []];
        if (!empty($pagination_row)) {
            $markup['inline_keyboard'][] = $pagination_row;
        }
        $markup['inline_keyboard'][] = [[
            'text' => '返回', 
            'callback_data' => 'menu_user_management',
            'icon_custom_emoji_id' => '5877629862306385808'
        ]];

        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    elseif ($callback_data === 'admin_view_admins') {
        $admins = getAllAdminsWithDetails($conn);
        $text = "<tg-emoji emoji-id=\"5807868868886009920\">👑</tg-emoji> <b>管理员列表</b>\n\n";
        foreach ($admins as $admin_user) {
            $user_display = htmlspecialchars($admin_user['username'] ? "@{$admin_user['username']}" : trim($admin_user['first_name'] . " " . $admin_user['last_name']));
            $is_main = (int)$admin_user['id'] === (int)SUB_BOT_ADMIN_ID ? " (主)" : "";
            $text .= " • <code>{$admin_user['id']}</code> - {$user_display}{$is_main}\n";
        }
        $markup = [
            'inline_keyboard' => [
                [
                    ['text' => '添加', 'callback_data' => 'admin_add_admin', 'icon_custom_emoji_id' => '5775937998948404844'],
                    ['text' => '删除', 'callback_data' => 'admin_remove_admin', 'icon_custom_emoji_id' => '5877413297170419326']
                ],
                [
                    ['text' => '返回', 'callback_data' => 'menu_user_management', 'icon_custom_emoji_id' => '5877629862306385808']
                ]
            ]
        ];
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    elseif ($callback_data === 'admin_add_admin') {
        setUserState($conn, $admin_id, 'awaiting_add_admin_id');
        $text = "<tg-emoji emoji-id=\"5920090136627908485\">👑</tg-emoji> <b>请输入要添加为管理员的用户 ID</b>\n\n该用户必须先启动过机器人。";
        $markup = [
            'inline_keyboard' => [
                [['text' => '取消', 'callback_data' => 'admin_view_admins', 'icon_custom_emoji_id' => '5877629862306385808']]
            ]
        ];
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    elseif ($callback_data === 'admin_remove_admin') {
        setUserState($conn, $admin_id, 'awaiting_remove_admin_id');
        $text = "<tg-emoji emoji-id=\"5922712343011135025\">👑</tg-emoji> <b>请输入要移除其管理员权限的用户 ID</b>\n\n<tg-emoji emoji-id=\"5881702736843511327\">⚠️</tg-emoji> 您不能移除自己或主管理员。";
        $markup = [
            'inline_keyboard' => [
                [['text' => '取消', 'callback_data' => 'admin_view_admins', 'icon_custom_emoji_id' => '5877629862306385808']]
            ]
        ];
        editTelegramMessage($admin_id, $message_id, $text, 'HTML', $markup);
    }
    
    // --- 用户封禁---
elseif (preg_match('/^ban_(\d+)$/', $callback_data, $matches)) {
        $target_user_id = (int)$matches[1];
        if ($conn && updateUserRole($conn, $target_user_id, 'ban')) {
            answerCallbackQuery($callback_query_id, "用户 ID: {$target_user_id} 已被封禁！", true);
            $ban_message = "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> <b>您已被管理员封禁。</b>\n\n您发送的消息将不会被转发给管理员。";
            sendTelegramMessage($target_user_id, $ban_message, 'HTML');
        } else {
            answerCallbackQuery($callback_query_id, "操作失败！", true);
        }
    }


    if (isset($conn) && $conn) $conn->close();
    exit();
}

elseif (isset($update['callback_query'])) {
    answerCallbackQuery($update['callback_query']['id']);
    if (isset($conn) && $conn) $conn->close();
    exit();
}



if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = $message['text'] ?? '';

    $username = $message['from']['username'] ?? null;
    $first_name = $message['from']['first_name'] ?? 'Guest';
    $last_name = $message['from']['last_name'] ?? null;

if ($user_id) {
if ($user_id) {
    $is_start_command = (strtolower(trim($text)) === '/start');
    
    if ($is_start_command) {
        registerUser($conn, $user_id, $username, $first_name, $last_name);
        $user_role = getUserRole($conn, $user_id);
    } elseif (isUserRegistered($conn, $user_id)) {
        registerUser($conn, $user_id, $username, $first_name, $last_name);
        $user_role = getUserRole($conn, $user_id);
    } else {
        $user_role = 'unregistered';
    }
    } else {
        $user_role = 'unregistered';
    }
}

    $current_state = $user_role === 'admin' ? getUserState($conn, $user_id) : 'none';


if ($user_role === 'admin' && $current_state !== 'none' && strtolower(trim($text)) !== '/start') {
    if (in_array($current_state, ['awaiting_start_text', 'awaiting_start_buttons', 'awaiting_start_media'])) {
        $success = false;
        
        if ($current_state === 'awaiting_start_text') {
            $entities = $update['message']['entities'] ?? [];
            $processed_text = formatTextWithEntities($text, $entities);
            $success = updateStartMessageInConfig($processed_text);
            
            if ($success) {
                sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 启动文本已更新（已保留会员表情）。", 'HTML');
                setUserState($conn, $user_id, 'none');
            } else {
                sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 启动文本更新失败。", 'HTML');
            }
        } 
elseif ($current_state === 'awaiting_start_buttons') {
    $entities = $update['message']['entities'] ?? [];
    if (strtolower(trim($text)) === 'none') {
        $final_content = '';
    } else {
        $final_content = convertEntitiesToConfigFormat($text, $entities);
    }
    
    $success = writeAnnniuFileContent($final_content);
    
    if ($success) {
        sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 启动按钮已更新并记录图标 ID。", 'HTML');
        setUserState($conn, $user_id, 'none');
    }
} 
        elseif ($current_state === 'awaiting_start_media') {
            $success = updateStartImageInConfig((strtolower(trim($text)) === 'none') ? '' : trim($text));
            if ($success) {
                sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 启动媒体已更新。", 'HTML');
                setUserState($conn, $user_id, 'none');
            }
        }
    } 
    elseif ($current_state === 'awaiting_keyboard') {
        $text_with_ids = convertEntitiesToConfigFormat($text, $update['message']['entities'] ?? []);
        $keyboard_structure = processRawKeyboardText($text_with_ids);
        setUserState($conn, $user_id, 'none');

        $message = $keyboard_structure 
            ? "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 底部按钮已更新。"
            : "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 格式识别失败，请检查输入。";

        if ($keyboard_structure) {
            $data = array_merge($keyboard_structure, ['raw_text' => $text_with_ids]);
            writeJianpanFileContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        sendTelegramMessage($chat_id, $message, 'HTML');
    }
elseif (strpos($current_state, 'awaiting_keyword_text_') === 0) {
    $encoded_kw = substr($current_state, 22); // 'awaiting_keyword_text_'
    $processed = formatTextWithEntities($text, $update['message']['entities'] ?? []);
    
    $success = updateOrAddKeyword(base64_decode($encoded_kw), 'text', $processed);
    setUserState($conn, $user_id, 'none');

    $message = $success ? "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 文本更新成功！" : "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 操作失败！";
    $markup = $success ? ['inline_keyboard' => [[
        ['text' => '返回', 'callback_data' => 'keyword_edit_' . $encoded_kw, 'icon_custom_emoji_id' => '5877629862306385808']
    ]]] : null;

    sendTelegramMessage($chat_id, $message, 'HTML', $markup);
}
elseif (strpos($current_state, 'awaiting_keyword_url_') === 0) {
    $encoded_kw = substr($current_state, 21); 
    $value = (strtolower(trim($text)) === 'none') ? '' : $text;
    
    $success = updateOrAddKeyword(base64_decode($encoded_kw), 'url', $value);
    setUserState($conn, $user_id, 'none');

    $message = $success ? "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 媒体更新成功！" : "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 操作失败！";
    $markup = $success ? ['inline_keyboard' => [[
        ['text' => '返回', 'callback_data' => 'keyword_edit_' . $encoded_kw, 'icon_custom_emoji_id' => '5877629862306385808']
    ]]] : null;

    sendTelegramMessage($chat_id, $message, 'HTML', $markup);
}
elseif (strpos($current_state, 'awaiting_keyword_buttons_') === 0) {
    $encoded_kw = substr($current_state, 25);
    $is_none = strtolower(trim($text)) === 'none';
    if (!$is_none) {
        $processed_text = convertEntitiesToRawIdString($text, $update['message']['entities'] ?? []);
        $buttons_data = explode("\n", $processed_text);
    } else {
        $buttons_data = [];
    }
    
    $success = updateOrAddKeyword(base64_decode($encoded_kw), 'buttons_raw', $buttons_data);
    setUserState($conn, $user_id, 'none');

    $message = $success ? "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 按钮更新成功！" : "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 操作失败！";
    $markup = $success ? ['inline_keyboard' => [[
        ['text' => '返回', 'callback_data' => 'keyword_edit_' . $encoded_kw, 'icon_custom_emoji_id' => '5877629862306385808']
    ]]] : null;

    sendTelegramMessage($chat_id, $message, 'HTML', $markup);
}

        // --- 关键词添加---
elseif ($current_state === 'awaiting_keyword_new_word') {
    $success = updateOrAddKeyword($text, 'text', '【未设置】');
    setUserState($conn, $user_id, 'none');

    $message = $success 
        ? " <tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 关键词 <code>" . htmlspecialchars($text) . "</code> 已成功创建。\n\n您现在可以从列表中选择它进行编辑。"
        : "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 添加关键词失败。可能已存在或写入错误。";

    $markup = $success ? [
        'inline_keyboard' => [[
            ['text' => '返回列表', 'callback_data' => 'menu_keywords_list', 'icon_custom_emoji_id' => '5877629862306385808']
        ]]
    ] : null;

    sendTelegramMessage($chat_id, $message, 'HTML', $markup);
}

        // --- 用户管理 ---
elseif ($current_state === 'awaiting_add_admin_id') {
            if (is_numeric($text)) {
                $target_user_id = (int)trim($text);
                if (isUserRegistered($conn, $target_user_id)) {
                    if (updateUserRole($conn, $target_user_id, 'admin')) {
                        sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 用户 <code>{$target_user_id}</code> 已设为管理员。", 'HTML');
                        sendTelegramMessage($target_user_id, "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 您已被设为机器人管理员。发送 /start 查看菜单。", 'HTML');
                    }
                } else { 
                    sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 用户不存在或未启动机器人。", 'HTML'); 
                }
            } else { 
                sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 输入无效，请输入纯数字ID。", 'HTML'); 
            }
            setUserState($conn, $user_id, 'none');
        }
        elseif ($current_state === 'awaiting_remove_admin_id') {
            if (is_numeric($text)) {
                $target_user_id = (int)trim($text);
                if ($target_user_id === $user_id) { 
                    sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 您不能移除自己。", 'HTML'); 
                }
                elseif ($target_user_id === (int)SUB_BOT_ADMIN_ID) { 
                    sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 您不能移除主管理员。", 'HTML'); 
                }
                else {
                    if (updateUserRole($conn, $target_user_id, 'user')) {
                        sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 用户 <code>{$target_user_id}</code> 管理权限已移除。", 'HTML');
                        sendTelegramMessage($target_user_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 您的机器人管理员权限已被移除。", 'HTML');
                    }
                }
            } else { 
                sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 输入无效，请输入纯数字ID。", 'HTML'); 
            }
            setUserState($conn, $user_id, 'none');
        }

        if (isset($conn) && $conn) $conn->close();
         exit();
    }

    // --- 处理管理员回复消息 ---
if (isset($message['reply_to_message'])) {
    $reply_to_message = $message['reply_to_message'];
    $replied_text = $reply_to_message['text'] ?? $reply_to_message['caption'] ?? '';
    if ($user_role === 'admin' && preg_match('/ID: (\d+)/', $replied_text, $matches)) {
        $target_user_id = (int)$matches[1];
        if (copyTelegramMessage($target_user_id, $chat_id, $message['message_id'])) {
            $success_msg = "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> <b>回复成功</b>\n";
            
            sendTelegramMessage($chat_id, $success_msg, 'HTML');
        } else {
            $fail_msg = "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> <b>发送失败</b>\n原因：用户可能已屏蔽机器人或 ID 无效。";
            
            sendTelegramMessage($chat_id, $fail_msg, 'HTML');
        }

        if (isset($conn) && $conn) $conn->close();
        exit(); 
    }
}
        // --- /start ---
if (strtolower(trim($text)) === '/start') {
    if ($user_id) setUserState($conn, $user_id, 'none');
    
    $reply_keyboard_markup = parseJianpanFile();
    $inline_keyboard_markup = parseAnnniuFile();
    $start_img_url = getConfigValue('STARTIMG');
    $start_message = str_replace("\\n", "\n", getConfigValue('STARTMESSAGE') ?? "");
    
    // 替换变量
    $user_info = ['id' => $user_id, 'username' => $username, 'first_name' => $first_name, 'last_name' => $last_name];
    $start_message = replaceUserVariables($start_message, $user_info);

    $ads_value = getConfigValue('ADS'); 
    
    if ($ads_value && getBotCostStatus($conn) === 'free') {
        $start_message .= "\n\n" . $ads_value; 
    }

    // 发送给启动用户的响应
    sendResponse($chat_id, $start_message, $start_img_url, $inline_keyboard_markup, $reply_keyboard_markup);

    // 管理员
    if ($user_role === 'admin') {
        $admin_menu = getAdminMainMenu($conn);
        sendTelegramMessage($chat_id, $admin_menu['text'], null, $admin_menu['markup']);
    } 
    // 普通用户启动，通知所有管理员
    elseif ($user_role === 'user') {
        $username_display = $username ? "@{$username}" : trim($first_name . " " . $last_name);
        $admin_notification = "<tg-emoji emoji-id=\"5922612721244704425\">👀</tg-emoji><b>新用户启动通知</b>\n" .
                              "<tg-emoji emoji-id=\"5920344347152224466\">👤</tg-emoji>用户: {$username_display}\n" .
                              "<tg-emoji emoji-id=\"5846008814129649022\">🆔</tg-emoji>ID: <code>{$user_id}</code>\n\n" .
                              "<tg-emoji emoji-id=\"5877468380125990242\">💬</tg-emoji><i>请回复此条消息来回复客户。</i>";
        
        $admin_ids = getAllAdmins($conn); 
        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => '永久封禁该用户', 
                    'callback_data' => "ban_{$user_id}",
                    'icon_custom_emoji_id' => '5922712343011135025' 
                ]
            ]]
        ];

        foreach ($admin_ids as $admin_id) {
            if ((int)$admin_id !== (int)$user_id) {
                sendTelegramMessage($admin_id, $admin_notification, 'HTML', $keyboard);
            }
        }
    }
}


    
elseif ($user_role === 'admin' && strtolower(substr(trim($text), 0, 4)) === '/ban') {
        $parts = explode(' ', $text);
        if (count($parts) === 2 && is_numeric($parts[1])) {
            $target_user_id = (int)$parts[1];
            if (updateUserRole($conn, $target_user_id, 'ban')) {
                sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 用户 <code>{$target_user_id}</code> 已被封禁。", 'HTML');
                sendTelegramMessage($target_user_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji>您已被管理员封禁。您发送的消息将不会被转发给管理员。", 'HTML');
            } else {
                 sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 操作失败，可能用户不存在或数据库错误。", 'HTML');
            }
        } else {
            sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 命令格式错误。请使用 <code>/ban 用户ID</code>。", 'HTML');
        }
        if (isset($conn) && $conn) $conn->close();
        exit();
    }
    elseif ($user_role === 'admin' && strtolower(substr(trim($text), 0, 6)) === '/unban') {
        $parts = explode(' ', $text);
        if (count($parts) === 2 && is_numeric($parts[1])) {
            $target_user_id = (int)$parts[1];
            if (updateUserRole($conn, $target_user_id, 'user')) {
                sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji> 用户 <code>{$target_user_id}</code> 已解除封禁。", 'HTML');
                sendTelegramMessage($target_user_id, "<tg-emoji emoji-id=\"5776375003280838798\">✅</tg-emoji>您的封禁已被解除。", 'HTML');
            }
        } else {
            sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5778527486270770928\">❌</tg-emoji> 命令格式错误。请使用 <code>/unban 用户ID</code>。", 'HTML');
        }
        if (isset($conn) && $conn) $conn->close();
        exit();
    }
    
// 广播逻辑
elseif ($user_role === 'admin' && (
    (isset($message['text']) && strpos(trim($message['text']), '/gb') === 0) || 
    (isset($message['caption']) && strpos(trim($message['caption']), '/gb') === 0)
)) {
    $broadcast_text = '';
    $broadcast_photo_id = null;

    $convert_to_html = function($msg_obj) {
        $raw_text = $msg_obj['text'] ?? $msg_obj['caption'] ?? '';
        if (empty($raw_text)) return '';
        
        $raw_text = trim($raw_text);
        $entities = $msg_obj['entities'] ?? $msg_obj['caption_entities'] ?? [];
        $command_length = (mb_substr($raw_text, 3, 1) === ' ') ? 4 : 3;

        $emoji_entities = array_filter($entities, function($e) use ($command_length) {
            return $e['type'] === 'custom_emoji' && $e['offset'] >= $command_length;
        });

        if (empty($emoji_entities)) {
            $pure_text = mb_substr($raw_text, $command_length, null, 'UTF-8');
            return htmlspecialchars($pure_text, ENT_QUOTES, 'UTF-8');
        }

        usort($emoji_entities, function($a, $b) {
            return $b['offset'] - $a['offset'];
        });

        $text_16 = mb_convert_encoding($raw_text, 'UTF-16BE', 'UTF-8');

        foreach ($emoji_entities as $entity) {
            $off = $entity['offset'];
            $len = $entity['length'];
            $eid = $entity['custom_emoji_id'];

            $before_16 = substr($text_16, 0, $off * 2);
            $target_16 = substr($text_16, $off * 2, $len * 2);
            $after_16 = substr($text_16, ($off + $len) * 2);

            $target_8 = mb_convert_encoding($target_16, 'UTF-8', 'UTF-16BE');
            $html_emoji = "<tg-emoji emoji-id=\"{$eid}\">" . htmlspecialchars($target_8, ENT_QUOTES, 'UTF-8') . "</tg-emoji>";
            
            $text_16 = $before_16 . mb_convert_encoding($html_emoji, 'UTF-16BE', 'UTF-8') . $after_16;
        }

        $processed_text = mb_convert_encoding($text_16, 'UTF-8', 'UTF-16BE');
        return mb_substr($processed_text, $command_length, null, 'UTF-8');
    };

    $broadcast_text = $convert_to_html($message);

    if (isset($message['photo'])) {
        $broadcast_photo_id = $message['photo'][count($message['photo']) - 1]['file_id'];
    }

    if (empty(trim(strip_tags($broadcast_text))) && $broadcast_photo_id === null) {
        sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5881702736843511327\">⚠️</tg-emoji> 广播内容不能为空。");
        if (isset($conn) && $conn) $conn->close();
        exit();
    }

    $all_user_ids = array_diff(getAllUserIds($conn), [$user_id]);
    $total_users = count($all_user_ids);
    
    if ($total_users === 0) {
        sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5881702736843511327\">⚠️</tg-emoji> 数据库中没有其他用户。");
        if (isset($conn) && $conn) $conn->close();
        exit();
    }

    sendTelegramMessage($chat_id, "<tg-emoji emoji-id=\"5877540355187937244\">📤</tg-emoji> 广播任务已提交...\n<tg-emoji emoji-id=\"5942877472163892475\">👥</tg-emoji>目标: {$total_users} 人。", "HTML");

    $post_data = [
        'token' => BOT_TOKEN,
        'text' => $broadcast_text,
        'photo' => $broadcast_photo_id ?? '',
        'users' => json_encode($all_user_ids),
        'admin_id' => $chat_id,
        'parse_mode' => 'HTML' 
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, BROADCAST_SCRIPT_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    curl_exec($ch);
    curl_close($ch);
    
    if (isset($conn) && $conn) $conn->close();
    exit();
}
// 验证角色
elseif ($user_role === 'admin' || $user_role === 'user') {

    if (!empty($text)) {
        $keyword_responses = parseGuanjianciFile();
        $user_input_normalized = strtolower(str_replace(' ', '', $text));
        
        if ($keyword_responses) {
            foreach($keyword_responses as $keyword => $response_config) {
                if (strpos($user_input_normalized, (string)$keyword) !== false) {
                    $user_info = ['id' => $user_id, 'username' => $username, 'first_name' => $first_name, 'last_name' => $last_name];
                    $response_config['text'] = replaceKeywordVariables($response_config['text'], $user_info);
                    if (!empty($response_config['url']) && filter_var($response_config['url'], FILTER_VALIDATE_URL)) {
                        // 有媒体URL
                        $path = parse_url($response_config['url'], PHP_URL_PATH);
                        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        
                        if (in_array($extension, ['mp4', 'mov'])) {
                            sendTelegramVideo($chat_id, $response_config['url'], $response_config['text'], $response_config['markup'], 'HTML');
                        } else {
                            sendTelegramPhoto($chat_id, $response_config['url'], $response_config['text'], $response_config['markup'], 'HTML');
                        }
                    } else {
                        // 纯文本或仅按钮
                        sendTelegramMessage($chat_id, $response_config['text'], 'HTML', $response_config['markup']);
                    }
                }
            }
        }
    }
    
if ($user_role === 'user' && $user_id && isUserRegistered($conn, $user_id)) {
        $admin_ids = getAllAdmins($conn);
        if (!empty($admin_ids)) {
            $metadata_message = "<tg-emoji emoji-id=\"5954175920506933873\">👤</tg-emoji>回复目标\n上一条消息是客户的原消息.\n<tg-emoji emoji-id=\"5877468380125990242\">💬</tg-emoji>请回复此条消息来回复客户.\n客户 ID: {$user_id}"; 
            foreach ($admin_ids as $admin_id) {
                forwardTelegramMessage($admin_id, $chat_id, $message['message_id']);
                sendTelegramMessage($admin_id, $metadata_message, HTML);
            }
        }
    }
}
    
    if (isset($conn) && $conn) $conn->close();
}
?>

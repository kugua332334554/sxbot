<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config = [
        'secret_token' => trim($_POST['secret_token'] ?? ''),
        'bot_token' => trim($_POST['bot_token'] ?? ''),
        'main_domain' => trim($_POST['main_domain'] ?? ''),
        'db_user' => trim($_POST['db_user'] ?? ''),
        'db_pass' => trim($_POST['db_pass'] ?? ''),
        'db_name' => trim($_POST['db_name'] ?? ''),
        'config_dir' => trim($_POST['config_dir'] ?? ''),
        'bot_username' => trim($_POST['bot_username'] ?? '') 
    ];

    $required = ['bot_token', 'main_domain', 'db_user', 'db_name', 'config_dir', 'bot_username']; 
    foreach ($required as $field) {
        if (empty($config[$field])) {
            die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：' . htmlspecialchars($field) . ' 为必填项，请返回重新填写</h2></div>');
        }
    }

    if (empty($config['secret_token'])) {
        $config['secret_token'] = bin2hex(random_bytes(16));
    }
    
    $move_warning = '';
    $configDir = $config['config_dir'];
    
    if (!is_dir($configDir)) {
        if (!mkdir($configDir, 0777, true)) {
            die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：无法创建配置目录 "' . htmlspecialchars($configDir) . '"，请检查服务器文件权限</h2></div>');
        }
    }

    $sourceConfigFile = 'config.txt';
    $targetConfigFile = $configDir . '/' . basename($sourceConfigFile);

    if (file_exists($sourceConfigFile)) {
        if (!rename($sourceConfigFile, $targetConfigFile)) {
            die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：配置目录已创建，但无法移动 config.txt 到 "' . htmlspecialchars($configDir) . '"。请检查文件权限或手动移动！</h2></div>');
        }
    } else {
        $move_warning = '<p class="mdui-text-color-orange">注意：根目录下未找到 config.txt 文件。假设您已手动移动或该文件不存在。</p>';
    }

    $botFile = 'bot.php';
    if (!file_exists($botFile)) {
        die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：未找到bot.php文件，请确保该文件与配置工具在同一目录</h2></div>');
    }

    $content = file_get_contents($botFile);
    if ($content === false) {
        die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：无法读取bot.php文件</h2></div>');
    }

    $replacements = [
        '你的密钥' => $config['secret_token'],
        '你的TOKEN' => $config['bot_token'],
        '你的根域名' => $config['main_domain'],
        '数据库名' => $config['db_name'],
        '数据库密码' => $config['db_pass'],
        '数据库用户' => $config['db_user'],
        '你的目录' => $config['config_dir']
    ];

    $newContent = str_replace(array_keys($replacements), array_values($replacements), $content);

    if (file_put_contents($botFile, $newContent) === false) {
        die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：无法写入bot.php文件，请检查文件权限</h2></div>');
    }
    
    $copyBotFile = 'copy/bot.php';
    $copyBotMessage = '';
    $copyBotSuccess = false;

    if (file_exists($copyBotFile)) {
        $copyContent = file_get_contents($copyBotFile);
        if ($copyContent === false) {
            $copyBotMessage = '错误：无法读取 copy/bot.php 文件。';
        } else {
            $mainDomainClean = rtrim($config['main_domain'], '/');
            
            $copyReplacements = [
                "define('DB_HOST', 'localhost');" => "define('DB_HOST', 'localhost');", 
                "define('DB_USER', '数据库名');" => "define('DB_USER', '{$config['db_user']}');",
                "define('DB_PASS', '数据库密码');" => "define('DB_PASS', '{$config['db_pass']}');",
                "define('DB_NAME', '数据库名');" => "define('DB_NAME', '{$config['db_name']}');",
                
                "define('REMOTE_ADS_CONFIG_URL', '你的域名/ads.txt');" => "define('REMOTE_ADS_CONFIG_URL', '{$mainDomainClean}/ads.txt');",
                "define('BROADCAST_SCRIPT_URL', 'https://你的域名/broadcast.php');" => "define('BROADCAST_SCRIPT_URL', '{$mainDomainClean}/broadcast.php');",
                "\$markup['inline_keyboard'][] = [['text' => '🔐 去解锁高级功能', 'url' => 'https://t.me/你的主Bot用户名']];" => "\$markup['inline_keyboard'][] = [['text' => '🔐 去解锁高级功能', 'url' => 'https://t.me/{$config['bot_username']}']];",
            ];
            
            $newCopyContent = str_replace(array_keys($copyReplacements), array_values($copyReplacements), $copyContent, $count);
            
            if (file_put_contents($copyBotFile, $newCopyContent) === false) {
                $copyBotMessage = '错误：无法写入 copy/bot.php 文件，请检查文件权限。';
            } else {
                $copyBotSuccess = true;
                $copyBotMessage = 'copy/bot.php 文件已成功配置。';
            }
        }
    } else {
        $copyBotMessage = '警告：未找到 <code>copy/bot.php</code> 文件，跳过配置。';
        $copyBotSuccess = true;
    }
    
    if (!$copyBotSuccess && strpos($copyBotMessage, '警告：') === false) {
        die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">copy/bot.php 配置错误：</h2><p>' . $copyBotMessage . '</p></div>');
    }

    $sqlFile = 'db.sql';
    $dbImportSuccess = false;
    $dbImportMessage = '';
    $dbHost = 'localhost'; 

    if (file_exists($sqlFile)) {
        $sqlContent = file_get_contents($sqlFile);
        
        $mysqli = @new mysqli($dbHost, $config['db_user'], $config['db_pass'], $config['db_name']);

        if ($mysqli->connect_error) {
            $dbImportMessage = '连接数据库失败！请检查数据库信息和权限是否正确。错误信息: ' . $mysqli->connect_error;
        } else {
            $mysqli->set_charset('utf8mb4');
            
            if ($mysqli->multi_query($sqlContent)) {
                $dbImportSuccess = true;
                $dbImportMessage = 'db.sql 文件已成功导入到数据库。';
                
                do {
                    if ($result = $mysqli->store_result()) {
                        $result->free();
                    }
                } while ($mysqli->more_results() && $mysqli->next_result());
                
            } else {
                $dbImportMessage = 'SQL 导入失败！请检查 db.sql 文件格式或数据库用户权限。错误信息: ' . $mysqli->error;
            }
            $mysqli->close();
        }
    } else {
        $dbImportMessage = '警告：根目录下未找到 <code>db.sql</code> 文件，跳过数据库导入。';
        $dbImportSuccess = true; 
    }
    
    if (!$dbImportSuccess && strpos($dbImportMessage, '警告：') === false) {
        die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">数据库导入错误：</h2><p>' . $dbImportMessage . '</p></div>');
    }

    $webhookEndpoint = rtrim($config['main_domain'], '/') . '/bot.php';
    
    $encodedWebhookEndpoint = rawurlencode($webhookEndpoint);

    $registrationUrl = 'https://api.telegram.org/bot' . 
                       $config['bot_token'] . 
                       '/setWebhook?url=' . 
                       $encodedWebhookEndpoint . 
                       '&secret_token=' . 
                       rawurlencode($config['secret_token']);

    echo '<!DOCTYPE html><html><head><title>配置成功</title><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><link rel="stylesheet" href="https://unpkg.com/mdui@1.0.2/dist/css/mdui.min.css" /></head><body class="mdui-theme-primary-indigo mdui-theme-accent-pink mdui-container mdui-typo mdui-p-t-2">';
    echo '<div class="mdui-card mdui-shadow-2 mdui-p-a-3"><h2 class="mdui-text-color-theme">🎉 配置成功！</h2>';
    
    echo '<h3>1.注册 Webhook</h3>';
    echo '<p>请复制以下链接，并在浏览器中打开此链接完成注册：</p>';
    echo '<div class="mdui-textfield mdui-textfield-disabled"><label class="mdui-textfield-label">url</label><input class="mdui-textfield-input" type="text" value="' . htmlspecialchars($registrationUrl) . '"/></div>';
    echo '<p class="mdui-text-color-red">现在请删除de.php文件</p><hr>';
    
    echo '<h3>2.数据库导入</h3>';
    if ($dbImportSuccess && strpos($dbImportMessage, '警告：') === false) {
        echo '<p class="mdui-text-color-green mdui-typo-subheading">🎉 数据库导入完成！</p>';
    } else {
        echo '<p class="mdui-text-color-red mdui-typo-subheading">⚠️ 数据库导入失败或警告！</p>';
    }
    echo '<p>' . $dbImportMessage . '</p>';
    echo '<hr>';
    
    echo '<h3>3.复制文件配置结果</h3>';
    if ($copyBotSuccess && strpos($copyBotMessage, '警告：') === false) {
        echo '<p class="mdui-text-color-green mdui-typo-subheading">🎉 ' . $copyBotMessage . '</p>';
    } else {
        echo '<p class="mdui-text-color-red mdui-typo-subheading">⚠️ ' . $copyBotMessage . '</p>';
    }
    echo '<hr>';

    echo '<h3>4.配置文件移动结果</h3>';
    echo '<p>配置目录 <code>' . htmlspecialchars($configDir) . '</code> 已创建。</p>';
    if (!empty($move_warning)) {
        echo $move_warning;
    } else {
        echo '<p class="mdui-text-color-green">文件 <code>config.txt</code> 已成功移动到新目录。</p>';
    }
    echo '<hr>';

    echo '</div></body></html>';
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>sxBot 配置</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://unpkg.com/mdui@1.0.2/dist/css/mdui.min.css" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body class="mdui-theme-primary-indigo mdui-theme-accent-pink">

    <header class="mdui-appbar mdui-color-theme">
        <div class="mdui-toolbar">
            <a href="javascript:;" class="mdui-typo-title">sxBot 配置工具</a>
        </div>
    </header>

    <div class="mdui-container mdui-p-t-3">
        <div class="mdui-card mdui-shadow-2 mdui-p-a-3">
            <div class="mdui-typo-headline mdui-m-b-2">配置信息填写</div>
            <form method="post">
                
                <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                    <label class="mdui-textfield-label" for="secret_token">密钥（可选，留空自动生成，用于 Webhook 校验）</label>
                    <input class="mdui-textfield-input" type="text" id="secret_token" name="secret_token">
                </div>

                <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                    <label class="mdui-textfield-label" for="bot_token">你的 TOKEN（必填，主 Bot Token）</label>
                    <input class="mdui-textfield-input" type="text" id="bot_token" name="bot_token" required>
                </div>

                <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                    <label class="mdui-textfield-label" for="main_domain">你的根域名（必填）</label>
                    <input class="mdui-textfield-input" type="text" id="main_domain" name="main_domain" required>
                </div>

                <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                    <label class="mdui-textfield-label" for="bot_username">你的主 Bot 用户名（必填，不带@，用于升级高级版链接）</label>
                    <input class="mdui-textfield-input" type="text" id="bot_username" name="bot_username" required>
                </div>

                <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                    <label class="mdui-textfield-label" for="db_user">数据库用户（必填）</label>
                    <input class="mdui-textfield-input" type="text" id="db_user" name="db_user" required>
                </div>

                <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                    <label class="mdui-textfield-label" for="db_pass">数据库密码</label>
                    <input class="mdui-textfield-input" type="password" id="db_pass" name="db_pass">
                </div>

                <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                    <label class="mdui-textfield-label" for="db_name">数据库名（必填）</label>
                    <input class="mdui-textfield-input" type="text" id="db_name" name="db_name" required>
                </div>

                <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                    <label class="mdui-textfield-label" for="config_dir">你的目录（必填）</label>
                    <input class="mdui-textfield-input" type="text" id="config_dir" name="config_dir" required>
                </div>
                
                <div class="mdui-m-t-4 mdui-m-b-2">
                    <button type="submit" class="mdui-btn mdui-btn-raised mdui-ripple mdui-color-theme-accent">开始配置</button>
                </div>
            </form>
        </div>
         

    
    <script src="https://unpkg.com/mdui@1.0.2/dist/js/mdui.min.js"></script>
</body>
</html>
<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 确保使用 HTTPS
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    if (!$isSecure) {
        die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：无法继续配置！</h2><p>Telegram Webhook 必须使用 HTTPS。请使用 HTTPS 重新访问此配置页面。</p></div>');
    }

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

    // 必填项检查
    $required = ['bot_token', 'main_domain', 'db_user', 'db_name', 'config_dir', 'bot_username']; 
    foreach ($required as $field) {
        if (empty($config[$field])) {
            die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：' . htmlspecialchars($field) . ' 为必填项，请返回重新填写</h2></div>');
        }
    }

    // 密钥自动生成
    if (empty($config['secret_token'])) {
        // 使用 16 字节的安全随机数生成 32 字符的十六进制密钥
        $config['secret_token'] = bin2hex(random_bytes(16));
    }
    
    $configDir = $config['config_dir'];
    
    // 检查并创建配置目录
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
    }

    // 处理 bot.php 文件配置替换
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
    $copyBotSuccess = false;
    $copyBotMessage = '';

    if (file_exists($copyBotFile)) {
        $copyContent = file_get_contents($copyBotFile);
        if ($copyContent === false) {
            die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：无法读取 copy/bot.php 文件。</h2></div>');
        } else {
            $mainDomainClean = rtrim($config['main_domain'], '/');
            
            $copyReplacements = [
                "define('DB_HOST', 'localhost');" => "define('DB_HOST', 'localhost');", 
                "define('DB_USER', '数据库名');" => "define('DB_USER', '{$config['db_user']}');",
                "define('DB_PASS', '数据库密码');" => "define('DB_PASS', '{$config['db_pass']}');",
                "define('DB_NAME', '数据库名');" => "define('DB_NAME', '{$config['db_name']}');",
                
                "define('REMOTE_ADS_CONFIG_URL', '你的域名/ads.txt');" => "define('REMOTE_ADS_CONFIG_URL', '{$mainDomainClean}/ads.txt');",
                "define('BROADCAST_SCRIPT_URL', 'https://你的域名/broadcast.php');" => "define('BROADCAST_SCRIPT_URL', '{$mainDomainClean}/broadcast.php');",
                "https://t.me/你的主Bot用户名']];" => "https://t.me/{$config['bot_username']}']];",
            ];
            
            $newCopyContent = str_replace(array_keys($copyReplacements), array_values($copyReplacements), $copyContent, $count);
            
            if (file_put_contents($copyBotFile, $newCopyContent) === false) {
                die('<div class="mdui-typo mdui-container mdui-+p-a-3"><h2 class="mdui-text-color-red">错误：无法写入 copy/bot.php 文件，请检查文件权限。</h2></div>');
            } else {
                $copyBotSuccess = true;
            }
        }
    } else {
        $copyBotSuccess = true;
    }
    

    $massUpdateFile = 'mass_update.php';
    $targetMassUpdateFile = $configDir . '/' . basename($massUpdateFile); 

    if (file_exists($massUpdateFile)) {
        $massContent = file_get_contents($massUpdateFile);
        if ($massContent === false) {
            die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：无法读取 mass_update.php 文件</h2></div>');
        }

        $massReplacements = [
            "define('DB_USER', '数据库名');" => "define('DB_USER', '{$config['db_user']}');",
            "define('DB_PASS', '数据库密码');" => "define('DB_PASS', '{$config['db_pass']}');",
            "define('DB_NAME', '数据库名');" => "define('DB_NAME', '{$config['db_name']}');",
            "define('BOT_TOKEN', '你的TOKEN');" => "define('BOT_TOKEN', '{$config['bot_token']}');",
            "define('MAIN_BOT_DOMAIN', '你的根域名');" => "define('MAIN_BOT_DOMAIN', '{$config['main_domain']}');",
        ];

        $newMassContent = str_replace(array_keys($massReplacements), array_values($massReplacements), $massContent);

        if (file_put_contents($massUpdateFile, $newMassContent) === false) {
            die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：无法写入 mass_update.php 文件（配置替换失败），请检查文件权限</h2></div>');
        }

   //     if (!rename($massUpdateFile, $targetMassUpdateFile)) {
   //         die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">错误：mass_update.php 已配置，但无法移动到 "' . htmlspecialchars($configDir) . '"。请检查文件权限或手动移动！</h2></div>');
   //     }
    }

    
    // 数据库导入逻辑
    $sqlFile = 'db.sql';
    $dbImportSuccess = false;
    $dbImportMessage = '';
    $dbHost = 'localhost'; 

    if (file_exists($sqlFile)) {
        $sqlContent = file_get_contents($sqlFile);
        
        $mysqli = @new mysqli($dbHost, $config['db_user'], $config['db_pass'], $config['db_name']);

        if ($mysqli->connect_error) {
            die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">数据库连接错误：</h2><p>连接数据库失败！请检查数据库信息和权限是否正确。错误信息: ' . $mysqli->connect_error . '</p></div>');
        } else {
            $mysqli->set_charset('utf8mb4');
            
            if ($mysqli->multi_query($sqlContent)) {
                $dbImportSuccess = true;
                
                do {
                    if ($result = $mysqli->store_result()) {
                        $result->free();
                    }
                } while ($mysqli->more_results() && $mysqli->next_result());
                
                if (!unlink($sqlFile)) {
                    die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">文件清理错误：</h2><p>db.sql 文件已成功导入，但自动删除失败，请手动删除！</p></div>');
                }
                
            } else {
                die('<div class="mdui-typo mdui-container mdui-p-a-3"><h2 class="mdui-text-color-red">数据库导入错误：</h2><p>SQL 导入失败！请检查 db.sql 文件格式或数据库用户权限。错误信息: ' . $mysqli->error . '</p></div>');
            }
            $mysqli->close();
        }
    } else {
        $dbImportSuccess = true; 
    }
    
    // Webhook 注册和成功页面输出
    $webhookEndpoint = rtrim($config['main_domain'], '/') . '/bot.php';
    $encodedWebhookEndpoint = rawurlencode($webhookEndpoint);

    $registrationUrl = 'https://api.telegram.org/bot' . 
                       $config['bot_token'] . 
                       '/setWebhook?url=' . 
                       $encodedWebhookEndpoint . 
                       '&secret_token=' . 
                       rawurlencode($config['secret_token']);

    echo '<!DOCTYPE html><html><head><title>配置成功</title><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><link rel="stylesheet" href="https://unpkg.com/mdui@1.0.2/dist/css/mdui.min.css" /></head><body class="mdui-theme-primary-indigo mdui-theme-accent-pink mdui-container mdui-typo mdui-p-t-5">';
    echo '<div class="mdui-card mdui-shadow-8 mdui-p-a-4 mdui-m-y-5" style="max-width: 600px; margin-left: auto; margin-right: auto; border-radius: 12px;">';
    
    echo '<h1 class="mdui-text-color-green mdui-text-center mdui-m-b-3"><i class="mdui-icon material-icons mdui-text-center" style="font-size: 48px;">check_circle</i><br>配置成功！</h1>';
    echo '<p class="mdui-text-center mdui-typo-subheading">Bot 配置文件和数据库已完成配置与清理。</p>';
    
    echo '<hr class="mdui-m-y-4">';
    
    echo '<h3 class="mdui-m-b-2">下一步：注册 Webhook</h3>';
    echo '<p>请点击下方按钮，在浏览器中打开 Webhook 注册链接，完成最后一步。</p>';

    echo '<div class="mdui-textfield mdui-textfield-disabled mdui-m-b-2"><label class="mdui-textfield-label">Webhook 注册链接</label><input class="mdui-textfield-input" type="text" id="webhook_url" value="' . htmlspecialchars($registrationUrl) . '"/></div>';
    
    echo '<a href="' . htmlspecialchars($registrationUrl) . '" target="_blank" class="mdui-btn mdui-btn-raised mdui-ripple mdui-color-theme-accent mdui-m-r-2" style="width: calc(50% - 10px);">一键打开注册链接</a>';
    
    echo '<button class="mdui-btn mdui-btn-raised mdui-ripple mdui-color-blue" onclick="copyWebhookUrl()" style="width: calc(50% - 10px);">复制链接</button>';
    
    echo '<p class="mdui-text-color-red mdui-m-t-4 mdui-text-center">⚠️ 配置完成后请立即删除`de.php` 文件以确保安全！管理员需要到数据库user表中identity改为admin。</p>';
    
    echo '</div>';
    
    echo '<script>
        function copyWebhookUrl() {
            var copyText = document.getElementById("webhook_url");
            copyText.removeAttribute("disabled"); 
            copyText.select();
            copyText.setSelectionRange(0, 99999); 
            document.execCommand("copy");
            copyText.setAttribute("disabled", "disabled"); 
            
            mdui.snackbar({
              message: "Webhook 注册链接已复制到剪贴板！",
              timeout: 2000
            });
        }
    </script>';
    
    echo '<script src="https://unpkg.com/mdui@1.0.2/dist/js/mdui.min.js"></script>';
    echo '</body></html>';
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
    <style>
        .status-card {
            border-radius: 8px; 
            background-color: #fff; 
            border-left: 5px solid; 
            transition: border-color 0.3s ease;
        }

        .border-green {
            border-color: #4caf50 !important; 
        }
        .border-red {
            border-color: #f44336 !important; 
        }
        .border-orange {
            border-color: #ff9800 !important; 
        }
        
        .status-col {
            margin-bottom: 16px; 
        }
        .status-card .mdui-list-item {
            padding: 8px 16px; 
        }
        .status-card .mdui-list-item-content {
             padding: 0;
        }

        @media (min-width: 960px) { 
            .main-content {
                padding-right: 8px; 
            }
            .sidebar {
                padding-left: 8px; 
            }
        }

        @media (max-width: 959px) { 
            .main-content, .sidebar {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
        }

    </style>
</head>
<body class="mdui-theme-primary-indigo mdui-theme-accent-pink">

    <header class="mdui-appbar mdui-color-theme">
        <div class="mdui-toolbar">
            <a href="javascript:;" class="mdui-typo-title">sxBot 配置工具</a>
            <div class="mdui-toolbar-spacer"></div>
            <a href="javascript:;" class="mdui-btn mdui-btn-icon" onclick="showCopyright()">
                <i class="mdui-icon material-icons">info_outline</i>
            </a>
        </div>
    </header>

    <div class="mdui-container mdui-p-t-3 mdui-p-b-3">
        <?php
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        if ($isSecure) {
            $borderColorClass = 'border-green';
            $iconColorClass = 'mdui-text-color-green';
            $icon = 'lock'; 
            $mainStatusText = 'HTTPS 已启用';
            $messageDetail = '安全连接已建立。';
        } else {
            $borderColorClass = 'border-red';
            $iconColorClass = 'mdui-text-color-red';
            $icon = 'lock_open'; 
            $mainStatusText = 'HTTPS 未启用';
            $messageDetail = '请使用 HTTPS 访问。';
        }
        
        $phpVersion = PHP_VERSION;
        $isRecommendedPhp = version_compare($phpVersion, '7.2.0', '>=') && version_compare($phpVersion, '8.0.0', '<'); 

        if ($isRecommendedPhp) {
            $phpBorderClass = 'border-green';
            $phpIconColorClass = 'mdui-text-color-green';
            $phpIcon = 'check_circle'; 
            $phpStatusText = 'PHP 版本推荐';
            $phpMessageDetail = '当前版本 ' . $phpVersion . ' (7.2 - 8.0)。';
        } else {
            $phpBorderClass = 'border-orange';
            $phpIconColorClass = 'mdui-text-color-orange';
            $phpIcon = 'warning'; 
            $phpStatusText = 'PHP 版本警告';
            $phpMessageDetail = '当前版本 ' . $phpVersion . ' (不推荐的PHP很可能造成故障)(推荐版本7.2 - 8.0)。';
        }
        
        $disableForm = !$isSecure;

        $protocol = $isSecure ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
        
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/';
        $baseDir = dirname($scriptPath); 
        
        $pathComponent = ($baseDir === '/' || $baseDir === '.') ? '' : $baseDir;

        $suggestedMainDomain = $protocol . $host . $pathComponent;
        ?>
        
        <div class="mdui-row mdui-row-gap-0">
            
            <div class="mdui-col-xs-12 mdui-col-md-8 main-content">
                
                <div class="mdui-row mdui-row-gap-0">
                    <div class="mdui-col-xs-12 mdui-col-sm-6 status-col mdui-p-r-1">
                        <div class="mdui-card mdui-shadow-2 status-card <?php echo $borderColorClass; ?>">
                            <div class="mdui-list-item">
                                <i class="mdui-list-item-icon mdui-icon material-icons <?php echo $iconColorClass; ?> mdui-m-r-3"><?php echo $icon; ?></i>
                                
                                <div class="mdui-list-item-content">
                                    <div class="mdui-text-color-theme-secondary mdui-typo-caption mdui-m-b-0">
                                        SSL 连接状态
                                    </div>
                                    <div class="mdui-typo-subheading mdui-text-color-black mdui-m-t-0 mdui-m-b-0">
                                        <?php echo $mainStatusText; ?>
                                    </div>
                                    <div class="mdui-typo-caption mdui-text-color-theme-secondary">
                                        <?php echo $messageDetail; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mdui-col-xs-12 mdui-col-sm-6 status-col mdui-p-l-1">
                        <div class="mdui-card mdui-shadow-2 status-card <?php echo $phpBorderClass; ?>">
                            <div class="mdui-list-item">
                                <i class="mdui-list-item-icon mdui-icon material-icons <?php echo $phpIconColorClass; ?> mdui-m-r-3"><?php echo $phpIcon; ?></i>
                                
                                <div class="mdui-list-item-content">
                                    <div class="mdui-text-color-theme-secondary mdui-typo-caption mdui-m-b-0">
                                        PHP 运行环境
                                    </div>
                                    <div class="mdui-typo-subheading mdui-text-color-black mdui-m-t-0 mdui-m-b-0">
                                        <?php echo $phpStatusText; ?>
                                    </div>
                                    <div class="mdui-typo-caption mdui-text-color-theme-secondary">
                                        <?php echo $phpMessageDetail; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mdui-card mdui-shadow-2 mdui-p-a-3 mdui-m-t-2" style="<?php echo $disableForm ? 'opacity: 0.5; pointer-events: none;' : ''; ?>">
                    <div class="mdui-typo-headline mdui-m-b-2">配置信息填写</div>
                    <form method="post" id="configForm">
                        
                        <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                            <label class="mdui-textfield-label" for="secret_token">密钥（可选，留空自动生成）</label>
                            <input class="mdui-textfield-input" type="text" id="secret_token" name="secret_token" <?php echo $disableForm ? 'disabled' : ''; ?>>
                        </div>

                        <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                            <label class="mdui-textfield-label" for="bot_token">你的 TOKEN（必填，主 Bot Token）</label>
                            <input class="mdui-textfield-input" type="text" id="bot_token" name="bot_token" required <?php echo $disableForm ? 'disabled' : ''; ?>>
                        </div>

                        <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                            <label class="mdui-textfield-label" for="main_domain">你的根域名（必填）</label>
                            <input class="mdui-textfield-input" type="text" id="main_domain" name="main_domain" value="<?php echo htmlspecialchars($suggestedMainDomain); ?>" required <?php echo $disableForm ? 'disabled' : ''; ?>>
                        </div>

                        <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                            <label class="mdui-textfield-label" for="bot_username">你的主 Bot 用户名（必填，不带@，用于升级高级版链接）</label>
                            <input class="mdui-textfield-input" type="text" id="bot_username" name="bot_username" required <?php echo $disableForm ? 'disabled' : ''; ?>>
                        </div>

                        <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                            <label class="mdui-textfield-label" for="db_user">数据库用户（必填）</label>
                            <input class="mdui-textfield-input" type="text" id="db_user" name="db_user" required <?php echo $disableForm ? 'disabled' : ''; ?>>
                        </div>

                        <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                            <label class="mdui-textfield-label" for="db_pass">数据库密码</label>
                            <input class="mdui-textfield-input" type="password" id="db_pass" name="db_pass" <?php echo $disableForm ? 'disabled' : ''; ?>>
                        </div>

                        <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                            <label class="mdui-textfield-label" for="db_name">数据库名（必填）</label>
                            <input class="mdui-textfield-input" type="text" id="db_name" name="db_name" required <?php echo $disableForm ? 'disabled' : ''; ?>>
                        </div>

                        <div class="mdui-textfield mdui-textfield-floating-label mdui-m-y-2">
                            <label class="mdui-textfield-label" for="config_dir">验证目录（必填）</label>
                            <input class="mdui-textfield-input" type="text" id="config_dir" name="config_dir" required <?php echo $disableForm ? 'disabled' : ''; ?>>
                        </div>
                        
                        <div class="mdui-m-t-4 mdui-m-b-2">
                            <button type="submit" class="mdui-btn mdui-btn-raised mdui-ripple mdui-color-theme-accent" id="submitButton" <?php echo $disableForm ? 'disabled' : ''; ?>>开始配置</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="mdui-col-xs-12 mdui-col-md-4 sidebar mdui-hidden-sm-down">
                 <div class="mdui-card mdui-shadow-2 mdui-p-a-3"> 
                    <div class="mdui-typo-title mdui-m-b-3">GitHub</div>
                    
                    <div class="mdui-card mdui-shadow-0 mdui-p-a-3 border-orange status-card" style="margin-bottom: 16px;">
                        <div class="mdui-typo-subheading mdui-text-color-orange mdui-m-b-1">
                            <i class="mdui-icon material-icons">open_in_new</i> 打开链接
                        </div>
                        <p class="mdui-typo-body-1 mdui-text-color-theme-secondary mdui-m-t-1 mdui-m-b-1">
                            为了防止别有用心的人利用此项目进行圈钱，特此添加此条信息。
                        </p>
                        <p class="mdui-typo-body-1 mdui-text-color-theme-secondary mdui-m-b-0">
                            二次开发者可以删除此消息。
                        </p>
                    </div>

                    <div class="mdui-m-t-3 mdui-text-center">
                        <a href="https://github.com/kugua332334554/sxbot" target="_blank" class="mdui-btn mdui-btn-raised mdui-ripple mdui-color-indigo mdui-btn-block">
                            Github Link
                        </a>
                    </div>
                </div>
            </div>
            
        </div>
    <script src="https://unpkg.com/mdui@1.0.2/dist/js/mdui.min.js"></script>
    <script>
        function showCopyright() {
            mdui.dialog({
                title: 'about',
                content: '本软体由 Sakura 开发，并且公开于GitHub仓库，不收取任何费用。<br><br> Github:https://github.com/kugua332334554/sxbot <br><br> 其他项目 postbot:https://github.com/kugua332334554/postbot<br><br>版权所有 &copy; Sakura。',
                buttons: [
                    {
                        text: 'close'
                    }
                ]
            });
        }
    </script>
</body>
</html>

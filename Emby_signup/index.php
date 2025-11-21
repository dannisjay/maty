<?php
header("Content-Type: text/html; charset=utf-8");
session_start();

// 配置区域
$config = [
    'admin_token' => 'token',  #emby_api_token
    'server_url' => 'http://emby:8096',  #emby地址
    'preset_userid' => 'emby_id',   # 普通用户ID
    //  访问 http://你的IP:8096/emby/system/info/public 获取你的id(非管理员账户)
    'invite_file' => 'invite_codes.json',     #自动生成，记得给权限
    'emby_login_url' => 'https://emby.com', #emby公网地址
    'admin_password' => 'admin',  #管理员密码
    // 自定义图片URL - 替换为你想要的图片链接
    'custom_image' => 'https://www.loliapi.com/acg/pe/'
];

// 邀请码管理函数
function generateInviteCode($length = 8) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $code;
}

function loadInviteCodes() {
    global $config;
    if (file_exists($config['invite_file'])) {
        $data = file_get_contents($config['invite_file']);
        return json_decode($data, true) ?: [];
    }
    return [];
}

function saveInviteCodes($codes) {
    global $config;
    file_put_contents($config['invite_file'], json_encode($codes, JSON_PRETTY_PRINT));
}

function validateInviteCode($code) {
    global $config;
    $codes = loadInviteCodes();
    $code = strtoupper(trim($code));
    if (isset($codes[$code]) && $codes[$code]['used'] === false) {
        $codes[$code]['used'] = true;
        $codes[$code]['used_at'] = date('Y-m-d H:i:s');
        saveInviteCodes($codes);
        return true;
    }
    return false;
}

function createInviteCode($note = '') {
    $code = generateInviteCode();
    $codes = loadInviteCodes();
    
    $codes[$code] = [
        'created_at' => date('Y-m-d H:i:s'),
        'used' => false,
        'used_at' => null,
        'note' => $note
    ];
    
    saveInviteCodes($codes);
    return $code;
}

function deleteInviteCode($code) {
    $codes = loadInviteCodes();
    if (isset($codes[$code])) {
        unset($codes[$code]);
        saveInviteCodes($codes);
        return true;
    }
    return false;
}

// 处理管理员登录
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION['admin_logged_in'] = false;
    $is_admin = false;
    header('Location: index.php');
    exit;
}

if (isset($_POST['admin_login'])) {
    if ($_POST['admin_password'] === $config['admin_password']) {
        $_SESSION['admin_logged_in'] = true;
        $is_admin = true;
    } else {
        $message = '管理员密码错误！';
    }
}

// 处理管理操作
$new_code = '';
$invite_link = '';
if ($is_admin && isset($_GET['action'])) {
    if ($_GET['action'] === 'generate') {
        $note = $_POST['note'] ?? '';
        $new_code = createInviteCode($note);
        // 生成包含邀请码的注册链接
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $base_url = preg_replace('/\?.*/', '', $base_url); // 移除参数
        $invite_link = $base_url . "?invite_code=" . $new_code;
        $message = "新邀请码生成成功：<strong>{$new_code}</strong>";
    } elseif ($_GET['action'] === 'delete' && isset($_GET['code'])) {
        if (deleteInviteCode($_GET['code'])) {
            $message = "邀请码删除成功";
        } else {
            $message = "邀请码不存在";
        }
    }
}

// 处理注册请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $invite_code = $_POST['invite_code'];
    $username = htmlspecialchars($_POST['username']);
    $passwd = $_POST['passwd'];
    $confirm_passwd = $_POST['confirm_passwd'];
    
    // 验证邀请码
    if (!validateInviteCode($invite_code)) {
        $message = '邀请码无效或已被使用！';
    }
    // 输入验证
    else if (!preg_match("/^[a-zA-Z0-9]{4,}$/", $username)) {
        $message = '用户名只允许包含数字和字母且至少需要4位！';
    } else if ($passwd !== $confirm_passwd) {
        $message = '两次输入的密码不一致！';
    } else if (!preg_match("/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*#?&]{8,}$/", $passwd)) {
        $message = '密码至少需要8位且必须包含数字和字母！';
    } else {
        // 注册账号
        $url1 = "{$config['server_url']}/emby/Users/New?X-Emby-Token={$config['admin_token']}";
        $data1 = array('Name' => $username, 'CopyFromUserId' => $config['preset_userid'], 'UserCopyOptions' => 'UserPolicy,UserConfiguration');
        $options1 = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data1)
            )
        );
        $context1  = stream_context_create($options1);
        $result1 = file_get_contents($url1, false, $context1);
        
        if ($result1 === FALSE) {
            $message = '服务器连接失败！';
        } else {
            $response1 = json_decode($result1, true);
            $userid = $response1['Id'];
            
            if ($userid === NULL) {
                $message = "用户名已存在！";
            } else {
                $url2 = "{$config['server_url']}/emby/Users/{$userid}/Password?X-Emby-Token={$config['admin_token']}";
                $data2 = array('NewPw' => $passwd);
                $options2 = array(
                    'http' => array(
                        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                        'method'  => 'POST',
                        'content' => http_build_query($data2)
                    )
                );
                $context2  = stream_context_create($options2);
                $result2 = file_get_contents($url2, false, $context2);
                
                $message = '注册完成！';
            }
        }
    }
}

$invite_codes = loadInviteCodes();

// 如果是管理员模式且未登录，显示登录页面
if (isset($_GET['admin']) && $_GET['admin'] == '1' && !$is_admin) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>管理员登录</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body { 
                font-family: 'Inter', Arial; 
                display: flex; 
                justify-content: center; 
                align-items: center; 
                height: 100vh; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                margin: 0;
            }
            .login-container {
                background: white;
                padding: 40px;
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
                text-align: center;
                width: 100%;
                max-width: 400px;
            }
            h3 { 
                margin-bottom: 30px; 
                color: #374151;
            }
            input { 
                margin: 10px 0; 
                padding: 16px; 
                width: 100%; 
                border: 2px solid #e5e7eb;
                border-radius: 12px;
                font-size: 16px;
                box-sizing: border-box;
            }
            button { 
                padding: 16px; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                color: white; 
                border: none; 
                border-radius: 12px; 
                width: 100%;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                margin-top: 10px;
            }
            button:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            }
            .back-link {
                margin-top: 20px;
            }
            .back-link a {
                color: #667eea;
                text-decoration: none;
            }
            .error {
                color: #ef4444;
                margin-bottom: 15px;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h3>管理员登录</h3>
            <?php if (isset($message)): ?>
                <div class="error"><?php echo $message; ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="password" name="admin_password" placeholder="请输入管理员密码" required>
                <br>
                <button type="submit" name="admin_login">登录</button>
            </form>
            <div class="back-link">
                <a href="index.php">← 返回注册页面</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 如果是管理员模式且已登录，显示管理界面
if (isset($_GET['admin']) && $_GET['admin'] == '1' && $is_admin) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>邀请码管理</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
                color: #333;
            }

            .container {
                max-width: 800px;
                margin: 0 auto;
            }

            .header {
                text-align: center;
                margin-bottom: 30px;
                color: white;
            }

            .header h1 {
                font-size: 32px;
                margin-bottom: 10px;
            }

            .header p {
                opacity: 0.8;
            }

            .admin-panel {
                background: white;
                border-radius: 20px;
                padding: 40px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
                margin-bottom: 20px;
            }

            .admin-section {
                margin-bottom: 40px;
            }

            .admin-section h3 {
                margin-bottom: 20px;
                color: #374151;
                padding-bottom: 10px;
                border-bottom: 2px solid #e5e7eb;
            }

            .form-group {
                margin-bottom: 20px;
            }

            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 500;
                color: #374151;
            }

            .form-group input, .form-group textarea {
                width: 100%;
                padding: 12px;
                border: 2px solid #e5e7eb;
                border-radius: 8px;
                font-size: 16px;
                background: #f9fafb;
                font-family: inherit;
            }

            .btn {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
                text-decoration: none;
                display: inline-block;
            }

            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            }

            .invite-codes {
                max-height: 400px;
                overflow-y: auto;
            }

            .invite-code-item {
                display: flex;
                align-items: center;
                padding: 15px;
                border-bottom: 1px solid #e5e7eb;
                background: #f9fafb;
                margin-bottom: 10px;
                border-radius: 8px;
            }

            .invite-code-item:last-child {
                border-bottom: none;
            }

            .code {
                font-weight: bold;
                color: #667eea;
                font-size: 18px;
                min-width: 100px;
            }

            .status {
                font-size: 12px;
                padding: 4px 12px;
                border-radius: 12px;
                margin-left: 15px;
            }

            .status.used {
                background: #fee2e2;
                color: #dc2626;
            }

            .status.unused {
                background: #d1fae5;
                color: #065f46;
            }

            .delete-btn {
                background: #ef4444;
                color: white;
                border: none;
                padding: 6px 12px;
                border-radius: 6px;
                cursor: pointer;
                margin-left: auto;
                text-decoration: none;
                font-size: 12px;
            }

            .delete-btn:hover {
                background: #dc2626;
            }

            .code-info {
                margin-left: 20px;
                flex-grow: 1;
            }

            .code-info small {
                color: #6b7280;
                display: block;
            }

            .back-link {
                text-align: center;
                margin-top: 20px;
            }

            .back-link a {
                color: white;
                text-decoration: none;
                background: rgba(255,255,255,0.2);
                padding: 10px 20px;
                border-radius: 20px;
                transition: all 0.3s ease;
            }

            .back-link a:hover {
                background: rgba(255,255,255,0.3);
            }

            .message {
                background: #d1fae5;
                border: 1px solid #a7f3d0;
                color: #065f46;
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 20px;
            }

            .invite-link-section {
                margin-top: 20px;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 12px;
                border: 1px solid #e5e7eb;
            }

            .link-container {
                display: flex;
                gap: 10px;
                margin: 15px 0;
            }

            .link-container input {
                flex: 1;
                padding: 12px;
                border: 2px solid #e5e7eb;
                border-radius: 8px;
                background: white;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>邀请码管理系统</h1>
                <p>生成和管理Emby注册邀请码</p>
            </div>

            <?php if (isset($message)): ?>
                <div class="message">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="admin-panel">
                <div class="admin-section">
                    <h3>生成新邀请码</h3>
                    <form method="post" action="?admin=1&action=generate">
                        <div class="form-group">
                            <label for="note">备注（可选）</label>
                            <input type="text" id="note" name="note" placeholder="为这个邀请码添加备注">
                        </div>
                        <button type="submit" class="btn">生成邀请码</button>
                    </form>
                    
                    <?php if (!empty($new_code)): ?>
                    <div class="invite-link-section">
                        <h4>邀请链接</h4>
                        <p>复制以下链接发送给用户，打开后邀请码会自动填入：</p>
                        <div class="link-container">
                            <input type="text" id="inviteLink" value="<?php echo $invite_link; ?>" readonly>
                            <button onclick="copyInviteLink()" class="btn" style="width: auto; padding: 12px 20px;">复制链接</button>
                        </div>
                        <small style="color: #6b7280;">用户打开链接后，邀请码字段会自动填充</small>
                    </div>
                    <script>
                    function copyInviteLink() {
                        var copyText = document.getElementById("inviteLink");
                        copyText.select();
                        copyText.setSelectionRange(0, 99999);
                        document.execCommand("copy");
                        alert("邀请链接已复制到剪贴板！");
                    }
                    </script>
                    <?php endif; ?>
                </div>

                <div class="admin-section">
                    <h3>邀请码列表</h3>
                    <div class="invite-codes">
                        <?php if (empty($invite_codes)): ?>
                            <p style="text-align: center; color: #6b7280; padding: 20px;">暂无邀请码</p>
                        <?php else: ?>
                            <?php foreach ($invite_codes as $code => $info): ?>
                                <div class="invite-code-item">
                                    <span class="code"><?php echo $code; ?></span>
                                    <span class="status <?php echo $info['used'] ? 'used' : 'unused'; ?>">
                                        <?php echo $info['used'] ? '已使用' : '未使用'; ?>
                                    </span>
                                    <div class="code-info">
                                        <small>创建时间: <?php echo $info['created_at']; ?></small>
                                        <?php if ($info['used'] && $info['used_at']): ?>
                                            <small>使用时间: <?php echo $info['used_at']; ?></small>
                                        <?php endif; ?>
                                        <?php if ($info['note']): ?>
                                            <small>备注: <?php echo htmlspecialchars($info['note']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!$info['used']): ?>
                                        <a href="?admin=1&action=delete&code=<?php echo $code; ?>" class="delete-btn" onclick="return confirm('确定删除邀请码 <?php echo $code; ?>？')">删除</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="back-link">
                <a href="?action=logout">← 退出管理</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Embydada Signup</title>
    <link rel="icon" type="image/png" href="https://emby.media/favicon-32x32.png" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            color: #333;
        }

        .container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        .image-section {
            flex: 1;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%);
            position: relative;
            overflow: hidden;
        }

        .background-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            top: 0;
            left: 0;
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px;
            color: white;
            text-align: center;
            z-index: 10;
        }

        .form-section {
            flex: 0 0 500px;
            background: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 40px;
            overflow-y: auto;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo-section img {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .logo-section h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: 8px;
        }

        .logo-section p {
            font-size: 1rem;
            color: #6b7280;
            font-weight: 400;
        }

        .form-container {
            width: 100%;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f9fafb;
            font-family: inherit;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group input[type="submit"], .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            cursor: pointer;
            font-weight: 600;
            border: none;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            padding: 16px 24px;
            border-radius: 12px;
            width: 100%;
        }

        .form-group input[type="submit"]:hover, .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .login-link {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }

        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .login-link a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .admin-link {
            text-align: center;
            margin-top: 15px;
        }

        .admin-btn {
            display: inline-block;
            background: rgba(110, 126, 234, 1);
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .admin-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: white;
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                position: relative;
                min-height: 100vh;
            }
        
            .image-section {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 1;
            }
         
            .form-section {
                position: relative;
                z-index: 2;
                background: rgba(255, 255, 255, 0.4);
                backdrop-filter: blur(10px);
                margin: 20px;
                border-radius: 20px;
                flex: none;
                padding: 30px;
            }
        
            .brand-text {
                top: 20px;
                right: 20px;
                font-size: 1.5rem;
            }
        
            .image-overlay {
                background: rgba(0, 0, 0, 0.2);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- 左侧图片区域 -->
        <div class="image-section">
            <img src="<?php echo $config['custom_image']; ?>" alt="背景图片" class="background-image">
            <div class="image-overlay">
                <!-- 删除文字，只保留背景图片 -->
            </div>
        </div>

        <!-- 右侧表单区域 -->
        <div class="form-section">
            <div class="logo-section">
                <img src="https://emby.media/favicon-96x96.png" alt="Emby Logo">
                <h1>Embydada</h1>
                <p>创建您的媒体账户</p>
            </div>

            <div class="form-container">
                <form method="post" action="">
                    <div class="form-group">
                        <label for="invite_code">邀请码</label>
                        <input type="text" id="invite_code" name="invite_code" required placeholder="请输入邀请码" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label for="username">用户名</label>
                        <input type="text" id="username" name="username" required placeholder="请输入用户名" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="passwd">密码</label>
                        <input type="password" id="passwd" name="passwd" required placeholder="请输入密码">
                    </div>
                    <div class="form-group">
                        <label for="confirm_passwd">确认密码</label>
                        <input type="password" id="confirm_passwd" name="confirm_passwd" required placeholder="请再次输入密码">
                    </div>
                    <div class="form-group">
                        <input type="submit" value="创建账户">
                    </div>
                </form>

                <!-- 登录链接 -->
                <div class="login-link">
                    <a href="<?php echo $config['emby_login_url']; ?>">已有账户？点击登录</a>
                </div>

                <!-- 管理员入口 -->
                <div class="admin-link">
                    <a href="?admin=1" class="admin-btn">🔑 Admin</a>
                </div>
            </div>
        </div>
    </div>

    <script>
    // 自动填充邀请码
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const inviteCode = urlParams.get('invite_code');
        if (inviteCode) {
            document.getElementById('invite_code').value = inviteCode.toUpperCase();
        }
    });
    </script>
</body>
</html>

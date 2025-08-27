<?php
session_start();
require_once __DIR__ . '/includes/db.php'; // ✅ 用你的 db.php

// ===== 基础配置 =====
define('MAIL_HOST', 'YOUR_SMTP_HOST');
define('MAIL_PORT', YOUR_SMTP_PORT);
define('MAIL_USER', 'your_email@example.com');
define('MAIL_PASS', 'YOUR_SMTP_PASSWORD');
define('MAIL_NICKNAME', 'your_nickname');

header('Content-Type: application/json; charset=utf-8');

function json_response($success, $message = '') {
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// 只允许 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, '非法请求');
}

// 获取参数
$email    = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? ''); // ✅ 可选：前端一起传来可同步查重

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(false, '邮箱格式不正确');
}

try {
    $pdo = get_pdo('db_aeroview');

    // ===== 查邮箱是否已存在 =====
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ((int)$stmt->fetchColumn() > 0) {
        json_response(false, '该邮箱已被注册');
    }

    // =====（可选）查用户名是否已存在 =====
    if ($username !== '') {
        // 与注册页保持一致的用户名规则（如有不同可调整）
        if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
            json_response(false, '用户名不合法（4-20位字母/数字/下划线）');
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ((int)$stmt->fetchColumn() > 0) {
            json_response(false, '该用户名已被注册');
        }
    }

    // ===== 简单频率限制（后端也兜底一层，防止刷接口）=====
    $now = time();
    $last = $_SESSION['email_code_last_send_ts'][$email] ?? 0;
    if ($now - $last < 60) { // 60秒内仅允许一次
        json_response(false, '发送过于频繁，请稍后再试');
    }

    // 生成 6 位验证码
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // 保存到 session（有效期 10 分钟）
    $_SESSION['email_code']        = $code;
    $_SESSION['email_code_email']  = $email;
    $_SESSION['email_code_expire'] = $now + 600;
    $_SESSION['email_code_last_send_ts'][$email] = $now;

    // ===== 发送邮件 =====
    require __DIR__ . '/vendor/autoload.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    $mail->CharSet    = 'UTF-8';
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_USER, MAIL_NICKNAME);
    $mail->addAddress($email);

$mail->isHTML(true);
$mail->Subject = '【AeroSpot】您的注册验证码';
$mail->Body    = "
<!DOCTYPE html>
<html lang=\"zh-CN\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>AeroSpot 注册验证码</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f9f9f9;
        }
        .container {
            background-color: #fff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        .header {
            text-align: center;
            margin-bottom: 25px;
        }
        .logo {
            color: #0d6efd;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .code {
            background-color: #f8f9fa;
            border: 1px dashed #dee2e6;
            padding: 15px;
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            color: #0d6efd;
            margin: 20px 0;
            border-radius: 6px;
            letter-spacing: 3px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 12px;
            color: #999;
            text-align: center;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: #0d6efd;
            color: white !important;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 15px;
        }
        .highlight {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class=\"container\">
        <div class=\"header\">
            <div class=\"logo\">AeroSpot</div>
            <h2>您的注册验证码</h2>
        </div>
        
        <p>尊敬的 <b>{$username}</b>，您好！</p>
        
        <p>感谢您注册 AeroSpot 账户，请使用以下验证码完成注册：</p>
        
        <div class=\"code\">{$code}</div>
        
        <p>验证码有效期为 <span class=\"highlight\">10 分钟</span>，请及时使用。</p>
        
        <p>如果您没有进行注册操作，请忽略此邮件或<a href=\"mailto:support@aviationvox.com\">联系客服</a>。</p>
        
        <div class=\"footer\">
            <p>此邮件为系统自动发送，请勿直接回复。</p>
            <p>© ".date('Y')." AeroSpot 版权所有</p>
            <p>如需帮助，请联系: <a href=\"mailto:support@aviationvox.com\">support@aviationvox.com</a></p>
        </div>
    </div>
</body>
</html>
";

// 设置纯文本备用内容
$mail->AltBody = "AeroSpot 注册验证码\n\n您好！\n\n您正在注册 AeroSpot 账户，验证码为: {$code}\n\n验证码有效期为 10 分钟。\n\n如果不是您本人操作，请忽略本邮件。\n\n此邮件由系统自动发送，请勿直接回复。";

    $mail->send();
    json_response(true, '验证码已发送');
} catch (Throwable $e) {
    json_response(false, '邮件发送失败：' . $e->getMessage());
}

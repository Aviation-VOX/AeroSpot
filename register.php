<?php
// 启动 Session
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php'; // 假设这里包含getUserIP等函数

// 初始化变量
$formData = [
    'username' => '',
    'email' => '',
    'code' => ''
];
$register_success = false;
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = get_pdo('db_aeroview');

    // 获取并清理表单数据
    $formData = [
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'code' => trim($_POST['code'] ?? '')
    ];
    $password = $_POST['password'] ?? '';

    // 验证表单数据
    if (empty($formData['username'])) {
        $error_message = '请输入用户名';
    } elseif (strlen($formData['username']) < 4 || strlen($formData['username']) > 20) {
        $error_message = '用户名长度应在4-20个字符之间';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $formData['username'])) {
        $error_message = '用户名只能包含字母、数字和下划线';
    } elseif (empty($formData['email'])) {
        $error_message = '请输入邮箱地址';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $error_message = '邮箱格式不正确';
    } elseif (empty($password)) {
        $error_message = '请输入密码';
    } elseif (strlen($password) < 8) {
        $error_message = '密码长度至少为8个字符';
    } elseif (empty($formData['code'])) {
        $error_message = '请输入验证码';
    } elseif (!isset($_SESSION['email_code']) || $_SESSION['email_code'] !== $formData['code'] || $_SESSION['email_code_email'] !== $formData['email']) {
        $error_message = '验证码错误或已过期';
    } else {
        // 检查用户名和邮箱是否已存在
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username OR email = :email");
        $stmt->execute([':username' => $formData['username'], ':email' => $formData['email']]);
        if ($stmt->fetchColumn() > 0) {
            $error_message = '用户名或邮箱已被注册';
        } else {
            // 创建用户
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $ipAddr = getUserIP();

            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, hashed_password, is_verified, ip_addr, created_at, last_login_time) 
                VALUES (:username, :email, :hashed_password, 1, :ip_addr, NOW(), NOW())
            ");
            $stmt->execute([
                ':username'        => $formData['username'],
                ':email'           => $formData['email'],
                ':hashed_password' => $hashedPassword,
                ':ip_addr'         => $ipAddr
            ]);

            // 自动登录用户
            $userId = $pdo->lastInsertId();
            $_SESSION['user'] = [
                'id' => $userId,
                'username' => $formData['username'],
                'email' => $formData['email']
            ];

            unset($_SESSION['email_code'], $_SESSION['email_code_email']);
            $register_success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>注册 - AeroSpot</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="注册AeroSpot账户，享受优质服务">
    
    <!-- 使用preload优化资源加载 -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" as="style">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" as="style">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" as="style">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --primary-color: #0d6efd;
            --hover-color: #0b5ed7;
        }
        
        body {
            background-color: #f8f9fa;
            background-image: linear-gradient(135deg, #f5f7fa 0%, #e4f1ff 100%);
            min-height: 100vh;
        }
        
        .register-card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .register-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 20px rgba(0, 0, 0, 0.1);
        }
        
        .form-control {
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border: none;
            padding: 12px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background-color: var(--hover-color);
            transform: translateY(-2px);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo i {
            font-size: 2.5rem;
            color: var(--primary-color);
        }
        
        .password-strength {
            height: 4px;
            background-color: #e9ecef;
            margin-top: 5px;
            border-radius: 2px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        
        .input-group-text {
            background-color: #f8f9fa;
        }
        
        .countdown-btn {
            min-width: 120px;
        }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6 col-xl-5">
            <div class="card register-card">
                <div class="card-body p-4 p-md-5">
                    <div class="logo">
                        <i class="bi bi-airplane-engines"></i>
                        <h2 class="mt-3 text-primary">AeroSpot</h2>
                    </div>
                    
                    <h3 class="text-center mb-4">创建您的账户</h3>
                    
                    <form method="POST" id="registerForm" novalidate>
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" id="username" name="username" class="form-control" 
                                       value="<?= htmlspecialchars($formData['username']) ?>" 
                                       placeholder="4-20个字符，仅限字母、数字和下划线" required>
                            </div>
                            <div class="form-text">用户名将用于登录和显示</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">邮箱地址</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" id="email" name="email" class="form-control" 
                                       value="<?= htmlspecialchars($formData['email']) ?>" 
                                       placeholder="请输入有效的邮箱地址" required>
                                <button type="button" class="btn btn-outline-primary countdown-btn" id="sendCodeBtn">
                                    <span id="btnText">发送验证码</span>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="code" class="form-label">验证码</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
                                <input type="text" id="code" name="code" class="form-control" 
                                       value="<?= htmlspecialchars($formData['code']) ?>" 
                                       placeholder="请输入6位验证码" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" id="password" name="password" class="form-control" 
                                       placeholder="至少8个字符" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength mt-2">
                                <div class="password-strength-bar" id="passwordStrengthBar"></div>
                            </div>
                            <div class="form-text">密码强度: <span id="passwordStrengthText">弱</span></div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="agreeTerms" required>
                            <label class="form-check-label" for="agreeTerms">我已阅读并同意<a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">《用户协议》</a></label>
                        </div>
                        
                        <div class="d-grid gap-2 mb-3">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-person-plus"></i> 立即注册
                            </button>
                        </div>
                        
                        <div class="text-center mt-4">
                            <p>已有账户？ <a href="login.php" class="fw-bold">立即登录</a></p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 用户协议模态框 -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel">用户协议</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- 这里放置用户协议内容 -->
                <p>请仔细阅读以下用户协议...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">我已阅读</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// DOM加载完成后执行
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($error_message): ?>
    showError('<?= addslashes($error_message) ?>');
    <?php elseif ($register_success): ?>
    showSuccess();
    <?php endif; ?>
    
    // 初始化表单验证
    initFormValidation();
    
    // 密码强度检测
    initPasswordStrengthMeter();
    
    // 密码显示/隐藏切换
    initTogglePassword();
});

function showError(message) {
    Swal.fire({
        icon: 'error',
        title: '注册失败',
        text: message,
        confirmButtonColor: '#0d6efd'
    });
}

function showSuccess() {
    Swal.fire({
        icon: 'success',
        title: '注册成功',
        text: '正在跳转到首页...',
        showConfirmButton: false,
        timer: 2000,
        timerProgressBar: true,
        willClose: () => {
            window.location.href = 'index.php';
        }
    });
}

function initFormValidation() {
    const form = document.getElementById('registerForm');
    
    form.addEventListener('submit', function(e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            
            // 查找第一个无效字段
            const invalidField = form.querySelector(':invalid');
            if (invalidField) {
                invalidField.focus();
                showError('请填写所有必填字段');
            }
        }
        
        form.classList.add('was-validated');
    }, false);
}

function initPasswordStrengthMeter() {
    const passwordInput = document.getElementById('password');
    const strengthBar = document.getElementById('passwordStrengthBar');
    const strengthText = document.getElementById('passwordStrengthText');
    
    passwordInput.addEventListener('input', function() {
        const password = this.value;
        let strength = 0;
        
        // 长度检查
        if (password.length >= 8) strength += 1;
        if (password.length >= 12) strength += 1;
        
        // 复杂度检查
        if (/[A-Z]/.test(password)) strength += 1;
        if (/[0-9]/.test(password)) strength += 1;
        if (/[^A-Za-z0-9]/.test(password)) strength += 1;
        
        // 更新UI
        let width = 0;
        let color = '#dc3545';
        let text = '弱';
        
        if (strength >= 4) {
            width = 100;
            color = '#198754';
            text = '强';
        } else if (strength >= 2) {
            width = 66;
            color = '#fd7e14';
            text = '中等';
        } else if (password.length > 0) {
            width = 33;
        }
        
        strengthBar.style.width = width + '%';
        strengthBar.style.backgroundColor = color;
        strengthText.textContent = text;
        strengthText.style.color = color;
    });
}

function initTogglePassword() {
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const passwordInput = this.parentElement.querySelector('input');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });
    });
}

// 发送验证码功能
document.getElementById('sendCodeBtn').addEventListener('click', function() {
    const email = document.getElementById('email').value.trim();
    const btn = this;
    const btnText = document.getElementById('btnText');
    
    if (!email) {
        Swal.fire({
            icon: 'warning',
            title: '请输入邮箱地址',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }
    
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        Swal.fire({
            icon: 'warning',
            title: '邮箱格式不正确',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }
    
    // 显示加载状态
    btn.disabled = true;
    btnText.textContent = '发送中...';
    
    fetch('send_code.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({ 
          email: email, 
          username: document.getElementById('username').value.trim()
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '验证码已发送',
                text: '请检查您的邮箱',
                confirmButtonColor: '#0d6efd'
            });
            startCountdown();
        } else {
            Swal.fire({
                icon: 'error',
                title: '发送失败',
                text: data.message || '请稍后再试',
                confirmButtonColor: '#0d6efd'
            });
            btn.disabled = false;
            btnText.textContent = '发送验证码';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: '网络错误',
            text: '请检查网络连接后重试',
            confirmButtonColor: '#0d6efd'
        });
        btn.disabled = false;
        btnText.textContent = '发送验证码';
    });
});

// 60秒倒计时
function startCountdown() {
    const btn = document.getElementById('sendCodeBtn');
    const btnText = document.getElementById('btnText');
    let timeLeft = 60;
    
    btn.disabled = true;
    
    const timer = setInterval(() => {
        timeLeft--;
        btnText.textContent = `${timeLeft}秒后重发`;
        
        if (timeLeft <= 0) {
            clearInterval(timer);
            btn.disabled = false;
            btnText.textContent = '发送验证码';
        }
    }, 1000);
}
</script>
</body>
</html>
<?php
// 设置 Session Cookie 参数：仅会话期有效（关浏览器即失效）
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,   // 0 = 仅本次浏览会话有效
    'path'     => '/',
    'secure'   => $secure,   // HTTPS 下为 true
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();
require_once __DIR__ . '/includes/db.php';

$pdo = get_pdo('db_aeroview');

$error = '';
$usernameOrEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($usernameOrEmail === '' || $password === '') {
        $error = '请输入用户名或邮箱和密码';
    } else {
        // 查询用户（支持用户名或邮箱登录）
        $stmt = $pdo->prepare("
            SELECT id, username, email, hashed_password, is_banned
            FROM users
            WHERE username = :ue OR email = :ue
            LIMIT 1
        ");
        $stmt->execute([':ue' => $usernameOrEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // 封禁检查
            if ((int)$user['is_banned'] === 1) {
                $error = '您的账户已被封禁，请联系管理员 admin@aviationvox.com';
            } elseif (password_verify($password, $user['hashed_password'])) {
                // 登录成功
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'       => $user['id'],
                    'username' => $user['username'],
                    'email'    => $user['email']
                ];

                // 固定跳转首页，避免 444
                header('Location: /index.php');
                exit;


                $redirect = $_SESSION['redirect_url'] ?? 'index.php';
                unset($_SESSION['redirect_url']);
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = '用户名/邮箱或密码错误';
            }
        } else {
            $error = '用户名/邮箱或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - AeroSpot</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            background-image: linear-gradient(to bottom, #e3f2fd, #f8f9fa);
            min-height: 100vh;
        }
        .login-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .login-card:hover {
            transform: translateY(-5px);
        }
        .form-control:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .btn-primary {
            background-color: #0d6efd;
            border: none;
            padding: 10px;
            font-weight: 500;
        }
        .btn-primary:hover {
            background-color: #0b5ed7;
        }
        .logo {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .logo img {
            height: 50px;
        }
        .divider {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
        }
        .divider::before, .divider::after {
            content: "";
            flex: 1;
            border-bottom: 1px solid #dee2e6;
        }
        .divider-text {
            padding: 0 10px;
            color: #6c757d;
        }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6 col-xl-5">
            <div class="card login-card">
                <div class="card-body p-4 p-md-5">
                    <div class="logo">
                        <h2 class="text-primary"><i class="bi bi-airplane"></i> AeroSpot</h2>
                    </div>
                    <h3 class="text-center mb-4">欢迎回来</h3>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" novalidate>
                        <div class="mb-3">
                            <label for="username" class="form-label">用户名或邮箱</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" id="username" name="username" class="form-control" 
                                       value="<?= htmlspecialchars($usernameOrEmail) ?>" 
                                       placeholder="请输入用户名或邮箱" required autofocus>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">密码</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" id="password" name="password" class="form-control" 
                                       placeholder="请输入密码" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember">
                            <label class="form-check-label" for="remember">记住我</label>
                            <a href="forgot_password.php" class="float-end">忘记密码？</a>
                        </div>
                        <div class="d-grid gap-2 mb-3">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-box-arrow-in-right"></i> 登录
                            </button>
                        </div>
                    </form>
                    
                    <div class="text-center mt-4">
                        <p>还没有账号？ <a href="register.php" class="fw-bold">立即注册</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // 切换密码可见性
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
    
    // 自动关闭警告框
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            new bootstrap.Alert(alert).close();
        });
    }, 5000);
</script>
</body>
</html>
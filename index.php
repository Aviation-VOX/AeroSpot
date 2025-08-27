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

// 初始化变量
$totalUsers = 0;
$totalSpots = 0;
$pendingSpots = 0;
$latestSpots = [];
$hotAirports = [];
$latestAnnouncements = [];

try {
    $pdoUser = get_pdo('db_aeroview');
    $pdoSpot = get_pdo('db_spot');

    // 核心统计
    $totalUsers = $pdoUser->query("SELECT COUNT(*) FROM users")->fetchColumn() ?? 0;
    $totalSpots = $pdoSpot->query("SELECT COUNT(*) FROM spots WHERE review_status = 'approved'")->fetchColumn() ?? 0;
    $pendingSpots = $pdoSpot->query("SELECT COUNT(*) FROM spots WHERE review_status = 'pending'")->fetchColumn() ?? 0;

    // 最新公告
    $latestAnnouncements = $pdoSpot->query("
        SELECT id, title, content, created_at 
        FROM announcements
        WHERE status = 1
        ORDER BY created_at DESC
        LIMIT 3
    ")->fetchAll() ?: [];

    // 最新机位
    $latestSpots = $pdoSpot->query("
        SELECT id, title, airport_id, lat, lon,
               (SELECT photo_url FROM spot_photos WHERE spot_id = spots.id LIMIT 1) AS photo_url
        FROM spots
        WHERE review_status = 'approved'
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll() ?: [];

    // 机场信息
    $airportIds = array_column($latestSpots, 'airport_id');
    $airportInfo = [];
    if (!empty($airportIds)) {
        $inQuery = implode(',', array_map('intval', $airportIds));
        $airportRows = $pdoUser->query("SELECT id, name_zh FROM airport_data WHERE id IN ($inQuery)")->fetchAll() ?: [];
        foreach ($airportRows as $row) {
            $airportInfo[$row['id']] = $row['name_zh'];
        }
    }
    foreach ($latestSpots as &$spot) {
        $spot['airport_name'] = $airportInfo[$spot['airport_id']] ?? '未知机场';
        $spot['photo_url'] = $spot['photo_url'] ?? 'https://images.unsplash.com/photo-1556388158-158ea5ccacbd?auto=format&fit=crop&w=2070&q=80';
    }

    // 热门机场
    $hotRows = $pdoSpot->query("
        SELECT airport_id, COUNT(*) AS spot_count
        FROM spots
        WHERE review_status = 'approved'
        GROUP BY airport_id
        ORDER BY spot_count DESC
        LIMIT 6
    ")->fetchAll() ?: [];
    $airportIdsHot = array_column($hotRows, 'airport_id');
    $airportInfoHot = [];
    if (!empty($airportIdsHot)) {
        $inQuery = implode(',', array_map('intval', $airportIdsHot));
        $airportRowsHot = $pdoUser->query("SELECT id, name_zh, city FROM airport_data WHERE id IN ($inQuery)")->fetchAll() ?: [];
        foreach ($airportRowsHot as $row) {
            $airportInfoHot[$row['id']] = [
                'name_zh' => $row['name_zh'],
                'city' => $row['city']
            ];
        }
    }
    foreach ($hotRows as $row) {
        $info = $airportInfoHot[$row['airport_id']] ?? ['name_zh' => '未知机场', 'city' => ''];
        $hotAirports[] = [
            'airport_id' => $row['airport_id'],
            'airport_name' => $info['name_zh'],
            'city' => $info['city'],
            'spot_count' => $row['spot_count']
        ];
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $errorMessage = "系统暂时不可用，请稍后再试";
}
?>


<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AeroSpot</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --accent: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #4cc9f0;
            --warning: #f72585;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--dark);
        }
        
        .hero {
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.9), rgba(63, 55, 201, 0.9)), 
                        url('https://images.unsplash.com/photo-1436491865332-7a61a109cc05?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80') center/cover no-repeat;
            color: white;
            padding: 100px 20px;
            text-align: center;
            position: relative;
            margin-bottom: 3rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .hero::after {
            content: '';
            position: absolute;
            bottom: -50px;
            left: 0;
            right: 0;
            height: 50px;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none"><path d="M0,0V46.29c47.79,22.2,103.59,32.17,158,28,70.36-5.37,136.33-33.31,206.8-37.5C438.64,32.43,512.34,53.67,583,72.05c69.27,18,138.3,24.88,209.4,13.08,36.15-6,69.85-17.84,104.45-29.34C989.49,25,1113-14.29,1200,52.47V0Z" opacity=".25" fill="%23f8f9fa"/><path d="M0,0V15.81C13,36.92,27.64,56.86,47.69,72.05,99.41,111.27,165,111,224.58,91.58c31.15-10.15,60.09-26.07,89.67-39.8,40.92-19,84.73-46,130.83-49.67,36.26-2.85,70.9,9.42,98.6,31.56,31.77,25.39,62.32,62,103.63,73,40.44,10.79,81.35-6.69,119.13-24.28s75.16-39,116.92-43.05c59.73-5.85,113.28,22.88,168.9,38.84,30.2,8.66,59,6.17,87.09-7.5,22.43-10.89,48-26.93,60.65-49.24V0Z" opacity=".5" fill="%23f8f9fa"/><path d="M0,0V5.63C149.93,59,314.09,71.32,475.83,42.57c43-7.64,84.23-20.12,127.61-26.46,59-8.63,112.48,12.24,165.56,35.4C827.93,77.22,886,95.24,951.2,90c86.53-7,172.46-45.71,248.8-84.81V0Z" fill="%23f8f9fa"/></svg>') no-repeat;
            background-size: cover;
            z-index: 1;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .stat-card {
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            color: white;
            transition: transform 0.3s ease;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border: none;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .spot-card {
            border-radius: 16px;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            height: 100%;
        }
        
        .spot-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        
        .spot-thumb {
            height: 200px;
            object-fit: cover;
            width: 100%;
            transition: transform 0.5s ease;
        }
        
        .spot-card:hover .spot-thumb {
            transform: scale(1.05);
        }
        
        .airport-card {
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            height: 100%;
            background: white;
            position: relative;
            overflow: hidden;
        }
        
        .airport-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }
        
        .airport-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        
        .section-title {
            position: relative;
            padding-bottom: 10px;
            margin-bottom: 30px;
            font-weight: 600;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }
        
        .badge-custom {
            background-color: var(--accent);
            color: white;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 20px;
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background-color: var(--secondary);
            border-color: var(--secondary);
        }
        
        .container {
            position: relative;
            z-index: 2;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .card-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .text-muted {
            color: #6c757d !important;
        }
        
        @media (max-width: 768px) {
            .hero {
                padding: 80px 20px;
            }
            
            .hero h1 {
                font-size: 2rem;
            }
            
            .hero p {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-content">
        <h1 class="display-4 fw-bold mb-4">探索全球拍机点位</h1>
        <p class="lead mb-4">分享你的视角，发现更多航空摄影好去处</p>
        <div class="d-flex gap-3 justify-content-center flex-wrap">
            <!-- 探索机位 -->
            <a href="maps.php" class="btn btn-primary btn-lg px-4">
                <i class="bi bi-search me-2"></i>探索机位
            </a>

            <!-- 添加机位 -->
            <a href="maps.php" class="btn btn-outline-light btn-lg px-4">
                <i class="bi bi-plus-circle me-2"></i>添加机位
            </a>

            <!-- 加入交流群 -->
            <a href="join_group.html" class="btn btn-success btn-lg px-4">
                <i class="bi bi-people-fill me-2"></i>加入交流群
            </a>
            
            <!-- 加入交流群 -->
            <a href="https://www.aviationvox.com" class="btn btn-success btn-lg px-4">
                <i class="bi bi-collection me-2"></i>图库
            </a>
            
            <!-- 雷达 -->
            <a href="https://radar.aviationvox.com" class="btn btn-success btn-lg px-4">
                <i class="bi bi-radar me-2"></i>雷达
            </a>

            <?php if (!isset($_SESSION['user'])): ?>
                <!-- 未登录显示 -->
                <a href="/login.php" class="btn btn-light btn-lg px-4">
                    <i class="bi bi-box-arrow-in-right me-2"></i>登录
                </a>
                <a href="/register.php" class="btn btn-warning btn-lg px-4">
                    <i class="bi bi-person-plus me-2"></i>注册
                </a>
            <?php else: ?>
                <!-- 已登录显示 -->
                <a href="/profile.php" class="btn btn-light btn-lg px-4">
                    <i class="bi bi-person-circle me-2"></i>个人中心
                </a>
                <a href="/logout.php" class="btn btn-danger btn-lg px-4">
                    <i class="bi bi-box-arrow-right me-2"></i>退出
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="container my-5">
    <!-- 核心统计卡片 -->
    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="stat-card" style="background: linear-gradient(135deg, #36D1DC, #5B86E5);">
                <i class="bi bi-people-fill fs-1 mb-3"></i>
                <div class="display-5 fw-bold mb-2"><?= number_format($totalUsers) ?></div>
                <div class="fs-5">注册用户总数</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card" style="background: linear-gradient(135deg, #F7971E, #FFD200);">
                <i class="bi bi-camera-fill fs-1 mb-3"></i>
                <div class="display-5 fw-bold mb-2"><?= number_format($totalSpots) ?></div>
                <div class="fs-5">已收录机位</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card" style="background: linear-gradient(135deg, #FF512F, #DD2476);">
                <i class="bi bi-clock-fill fs-1 mb-3"></i>
                <div class="display-5 fw-bold mb-2"><?= number_format($pendingSpots) ?></div>
                <div class="fs-5">待审核机位</div>
            </div>
        </div>
    </div>

<!-- 最新公告 -->
<?php if (!empty($latestAnnouncements)): ?>
<div class="mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="section-title">最新公告</h2>
        <a href="#" class="text-decoration-none">查看全部 <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="list-group">
        <?php foreach ($latestAnnouncements as $ann): ?>
            <a href="#" class="list-group-item list-group-item-action">
                <div class="d-flex w-100 justify-content-between">
                    <h5 class="mb-1"><?= htmlspecialchars($ann['title']) ?></h5>
                    <small class="text-muted"><?= date('Y-m-d', strtotime($ann['created_at'])) ?></small>
                </div>
                <p class="mb-1 text-muted"><?= htmlspecialchars(mb_substr(strip_tags($ann['content']), 0, 60)) ?>...</p>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- 最新机位 -->
<div class="mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="section-title">最新机位</h2>
        <a href="#" class="text-decoration-none">查看全部 <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="row g-4">
        <?php foreach ($latestSpots as $spot): 
            $title = isset($spot['title']) ? (string)$spot['title'] : '未命名机位';
            $photo = !empty($spot['photo_url'])
                ? $spot['photo_url']
                : 'https://images.unsplash.com/photo-1556388158-158ea5ccacbd?auto=format&fit=crop&w=2070&q=80';
            $airportName = $spot['airport_name'] ?? '未知机场';
        ?>
            <div class="col-md-4 col-lg-3">
                <div class="spot-card card h-100">
                    <div class="overflow-hidden">
                        <img src="<?= htmlspecialchars($photo) ?>" 
                             class="spot-thumb" alt="<?= htmlspecialchars($title) ?>">
                    </div>
                    <div class="card-body">
                        <h5 class="card-title"><?= htmlspecialchars($title) ?></h5>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">
                                <i class="bi bi-geo-alt-fill me-1"></i>
                                <?= htmlspecialchars($airportName) ?>
                            </span>
                            <span class="badge bg-primary rounded-pill">新</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

    <!-- 热门机场 -->
    <div class="mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="section-title">热门机场</h2>
            <a href="#" class="text-decoration-none">查看全部 <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="row g-4">
            <?php foreach ($hotAirports as $airport): ?>
                <div class="col-md-4 col-lg-2">
                    <div class="airport-card">
                        <div class="d-flex flex-column h-100">
                            <div class="mb-3">
                                <i class="bi bi-airplane-engines fs-4 text-primary"></i>
                            </div>
                            <h5 class="mb-2"><?= htmlspecialchars($airport['airport_name']) ?></h5>
                            <p class="text-muted small mb-2">
                                <i class="bi bi-geo-alt me-1"></i>
                                <?= htmlspecialchars($airport['city']) ?>
                            </p>
                            <div class="mt-auto d-flex justify-content-between align-items-center">
                                <span class="text-primary fw-bold"><?= $airport['spot_count'] ?>个</span>
                                <a href="#" class="text-decoration-none">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- CTA Section -->
    <div class="row g-0 bg-primary rounded-4 overflow-hidden mb-5">
        <div class="col-md-6 p-5 d-flex flex-column justify-content-center">
            <h2 class="text-white mb-3">分享你的拍机点</h2>
            <p class="text-white-50 mb-4">帮助更多航空摄影爱好者发现最佳拍摄位置</p>
            <a href="#" class="btn btn-light rounded-pill align-self-start px-4">
                <i class="bi bi-plus-circle me-2"></i>添加机位
            </a>
        </div>
        <div class="col-md-6">
            <img src="https://images.unsplash.com/photo-1556388158-158ea5ccacbd?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80" 
                 alt="航空摄影" class="img-fluid h-100 object-fit-cover">
        </div>
    </div>
</div>

<footer class="bg-dark text-white py-5">
    <div class="container">
        <div class="row">
            <div class="col-md-4 mb-4">
                <h5 class="mb-3">AeroSpot</h5>
                <p class="text-muted">Aviation VOX旗下航空摄影社区，分享和发现最佳拍机点。</p>
            </div>
            <div class="col-md-2 mb-4">
                <h5 class="mb-3">导航</h5>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="index.php" class="text-decoration-none text-muted">首页</a></li>
                    <li class="mb-2"><a href="maps.php" class="text-decoration-none text-muted">机位地图</a></li>
                    <li class="mb-2"><a href="https://www.aviationvox.com" target="_blank" class="text-decoration-none text-muted">图库</a></li>
                    <li class="mb-2"><a href="https://radar.aviationvox.com" target="_blank" class="text-decoration-none text-muted">雷达</a></li>
                    <li class="mb-2"><a href="#" class="text-decoration-none text-muted">热门机场</a></li>
                    <li class="mb-2"><a href="#" class="text-decoration-none text-muted">最新机位</a></li>
                </ul>
            </div>
            <div class="col-md-2 mb-4">
                <h5 class="mb-3">社区</h5>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="maps.php" class="text-decoration-none text-muted">添加机位</a></li>
                    <li class="mb-2"><a href="terms.html" class="text-decoration-none text-muted">用户协议</a></li>
                    <li class="mb-2"><a href="privacy.html" class="text-decoration-none text-muted">隐私政策</a></li>
                    <li class="mb-2"><a href="https://bbs.aviationvox.com" class="text-decoration-none text-muted">论坛</a></li>
                </ul>
            </div>
            <div class="col-md-4 mb-4">
                <h5 class="mb-3">联系我们</h5>
                <ul class="list-unstyled text-muted">
                    <li class="mb-2"><i class="bi bi-envelope me-2"></i> support@aviationvox.com</li>
                    <li class="mb-2"><i class="bi bi-wechat me-2"></i> Aviation VOX</li>
                </ul>
                <div class="d-flex gap-3 mt-3">
                    <a href="#" class="text-white"><i class="bi bi-weibo fs-4"></i></a>
                    <a href="#" class="text-white"><i class="bi bi-wechat fs-4"></i></a>
                    <a href="#" class="text-white"><i class="bi bi-bilibili fs-4"></i></a>
                </div>
            </div>
        </div>
        <hr class="my-4 bg-secondary">
        <div class="text-center text-muted">
            <small>© 2023 - 2025 AeroSpot. 保留所有权利.</small>
        </div>
    </div>
</footer>

<!-- 悬浮铃铛按钮 -->
<button id="announcementBell" 
        class="btn btn-primary rounded-circle shadow-lg position-fixed" 
        style="bottom: 20px; right: 20px; width: 60px; height: 60px; z-index: 1050;">
    <i class="bi bi-bell fs-3"></i>
</button>

<!-- 公告弹窗 -->
<div class="modal fade" id="announcementModal" tabindex="-1" aria-labelledby="announcementModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="announcementModalLabel">
          <?php if (!empty($latestAnnouncements)): ?>
            <?= htmlspecialchars($latestAnnouncements[0]['title']) ?>
          <?php else: ?>
            公告
          <?php endif; ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <?php if (!empty($latestAnnouncements)): ?>
          <small class="text-muted">
              <?= date('Y-m-d H:i', strtotime($latestAnnouncements[0]['created_at'])) ?>
          </small>
          <hr>
          <?= nl2br(htmlspecialchars($latestAnnouncements[0]['content'])) ?>
        <?php else: ?>
          暂无公告
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var annModal = new bootstrap.Modal(document.getElementById('announcementModal'));

    // 如果有公告则刷新时自动弹出
    <?php if (!empty($latestAnnouncements)): ?>
        annModal.show();
    <?php endif; ?>

    // 点击铃铛弹出
    document.getElementById('announcementBell').addEventListener('click', function () {
        annModal.show();
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
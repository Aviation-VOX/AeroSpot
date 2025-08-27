<?php
session_start();
require_once __DIR__ . '/includes/guards.php';
/**
 * === 配置 ===
 */
$AMAP_KEY = 'YOUR_AMAP_KEY';
$IP2LOCATION_KEY = 'YOUR_IP2LOCATION_KEY';

require_once __DIR__ . '/includes/db.php';
$pdo = get_pdo('db_user_data');   // 主库（airport_data / airport_photos）
$pdoSpot = null;
try { $pdoSpot = get_pdo('db_spot_data'); } catch (Throwable $e) { $pdoSpot = null; }

if (empty($_SESSION['user'])) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

if (!can_access_spot_map()) {
    http_response_code(403);
    $cnt = (int)($_SESSION['guard_cache']['map_gate']['cnt'] ?? 0);
    ?>
    <!doctype html>
    <html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <title>访问受限</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {font-family: system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"PingFang SC","Microsoft YaHei",sans-serif; background:#fafafa; padding:40px;}
            .card {max-width:640px; margin:0 auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 6px 24px rgba(0,0,0,.06);}
            .title {font-size:20px; font-weight:700; margin-bottom:8px;}
            .desc {color:#555; line-height:1.7; margin-bottom:14px;}
            .btn {display:inline-block; padding:10px 16px; border-radius:10px; background:#0d6efd; color:#fff; text-decoration:none;}
            .muted {color:#888; font-size:13px;}
        </style>
    </head>
    <body>
        <div class="card">
            <div class="title">机位地图暂未解锁</div>
            <div class="desc">
                根据站内规则：主站图库需通过 <b>≥ 50 张</b> 照片方可解锁机位地图。<br>
                为什么要设计这个规则：防止毁机位者肆意上传，并且也是考验提交者水平<br>
                你当前已通过：<b><?= $cnt ?></b> 张。
            </div>
            <a class="btn" href="https://www.aviationvox.com">去上传照片</a>
            <div class="muted" style="margin-top:12px;">通过审核后将自动解锁，无需再次申请。</div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
/* -------------------- 内嵌接口（同文件） --------------------
   1) 照片列表：GET ?ajax=list&airport_id=xxx
      - 仅返回 approved=1 的图片，对任何人都一样
   2) 照片上传：POST ajax=upload + airport_id + photos[]
      - 需要登录；保存到 /airportphotos，插入 airport_photos(approved=0)
   3) 机位列表：GET ?ajax=spot_list&airport_id=xxx
      - 仅返回审核通过的机位（review_status='approved'）
   4) 机位全集：GET ?ajax=spot_all
      - 返回所有审核通过机位（review_status='approved'），用于全图渲染
------------------------------------------------------------ */

if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $airportId = isset($_GET['airport_id']) ? (int)$_GET['airport_id'] : 0;
    if ($airportId <= 0) { echo json_encode(['success'=>false,'message'=>'invalid airport_id']); exit; }
    try {
        $sql = "SELECT id, filepath, title
                FROM airport_photos
                WHERE airport_id=? AND approved=1
                ORDER BY id DESC
                LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute([$airportId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $list = array_map(function($r){
            $rel = ltrim($r['filepath'],'/');
            $url = '/'.$rel;
            return ['id'=>(int)$r['id'], 'url'=>$url, 'title'=>$r['title'] ?? ''];
        }, $rows);
        echo json_encode(['success'=>true,'list'=>$list]); exit;
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
    }
}

if (isset($_POST['ajax']) && $_POST['ajax'] === 'upload') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_SESSION['user']['id'])) {
        echo json_encode(['success'=>false,'message'=>'请先登录']); exit;
    }
    $userId = (int)$_SESSION['user']['id'];

    $airportId = isset($_POST['airport_id']) ? (int)$_POST['airport_id'] : 0;
    if ($airportId <= 0) { echo json_encode(['success'=>false,'message'=>'invalid airport_id']); exit; }

    try {
        $ck = $pdo->prepare("SELECT 1 FROM airport_data WHERE id=? LIMIT 1");
        $ck->execute([$airportId]);
        if (!$ck->fetchColumn()) { echo json_encode(['success'=>false,'message'=>'机场不存在']); exit; }
    } catch (Throwable $e) { echo json_encode(['success'=>false,'message'=>'数据库错误']); exit; }

    $root = __DIR__;
    $saveDir = $root . '/airportphotos';
    if (!is_dir($saveDir)) { @mkdir($saveDir, 0755, true); }

    $allowExt = ['jpg','jpeg','png','webp'];
    $allowMime = ['image/jpeg','image/png','image/webp'];
    $maxSize = 20 * 1024 * 1024;

    if (empty($_FILES['photos'])) { echo json_encode(['success'=>false,'message'=>'没有选择文件']); exit; }

    $files = $_FILES['photos'];
    $count = is_array($files['name']) ? count($files['name']) : 0;
    if ($count <= 0) { echo json_encode(['success'=>false,'message'=>'没有有效文件']); exit; }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $ok = 0;

    $pdo->beginTransaction();
    try {
        for ($i=0; $i<$count; $i++) {
            $name = $files['name'][$i] ?? '';
            $tmp  = $files['tmp_name'][$i] ?? '';
            $err  = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            $size = (int)($files['size'][$i] ?? 0);

            if ($err !== UPLOAD_ERR_OK) continue;
            if (!is_uploaded_file($tmp)) continue;
            if ($size <= 0 || $size > $maxSize) continue;

            $mime = $finfo->file($tmp) ?: '';
            if (!in_array($mime, $allowMime, true)) continue;

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowExt, true)) {
                $ext = $mime==='image/webp' ? 'webp' : ($mime==='image/png' ? 'png' : 'jpg');
            }

            $fname = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest = $saveDir . '/' . $fname;
            if (!move_uploaded_file($tmp, $dest)) continue;

            $rel = 'airportphotos/' . $fname;

            $ins = $pdo->prepare("INSERT INTO airport_photos (airport_id, user_id, title, filepath, approved, created_at)
                                  VALUES (?, ?, ?, ?, 0, NOW())");
            $ins->execute([$airportId, $userId, '', $rel]);
            $ok++;
        }
        $pdo->commit();
        echo json_encode(['success'=>true,'count'=>$ok]); exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'spot_list') {
    header('Content-Type: application/json; charset=utf-8');
    $airportId = isset($_GET['airport_id']) ? (int)$_GET['airport_id'] : 0;
    if ($airportId <= 0) { echo json_encode(['success'=>false,'message'=>'invalid airport_id']); exit; }

    if (!$pdoSpot) { echo json_encode(['success'=>false,'message'=>'机位库连接失败']); exit; }

    try {
        $sql = "SELECT id,
                       title,
                       CAST(lat AS DECIMAL(9,6))  AS lat,
                       CAST(lon AS DECIMAL(9,6))  AS lng
                FROM spots
                WHERE review_status = 'approved'
                  AND airport_id = ?
                ORDER BY id DESC
                LIMIT 300";
        $st = $pdoSpot->prepare($sql);
        $st->execute([$airportId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $list = array_map(function($r){
            return [
                'id'   => (int)$r['id'],
                'name' => (string)($r['title'] ?? ''),
                'lat'  => isset($r['lat']) ? (float)$r['lat'] : null,
                'lng'  => isset($r['lng']) ? (float)$r['lng'] : null,
            ];
        }, $rows);

        echo json_encode(['success'=>true,'list'=>$list]); exit;
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'message'=>'获取机位失败']); exit;
    }
}

/* ========== 新增：机位全集（全图渲染用） ========== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'spot_all') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pdoSpot) { echo json_encode(['success'=>false,'message'=>'机位库连接失败']); exit; }
    try {
        $sql = "SELECT id,
                       airport_id,
                       title,
                       CAST(lat AS DECIMAL(9,6))  AS lat,
                       CAST(lon AS DECIMAL(9,6))  AS lng
                FROM spots
                WHERE review_status = 'approved'
                  AND lat IS NOT NULL AND lon IS NOT NULL
                ORDER BY id DESC
                LIMIT 5000";
        $rows = $pdoSpot->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $list = array_map(function($r){
            return [
                'id'         => (int)$r['id'],
                'airport_id' => (int)$r['airport_id'],
                'name'       => (string)($r['title'] ?? ''),
                'lat'        => isset($r['lat']) ? (float)$r['lat'] : null,
                'lng'        => isset($r['lng']) ? (float)$r['lng'] : null,
            ];
        }, $rows);
        echo json_encode(['success'=>true,'list'=>$list]); exit;
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'message'=>'获取机位失败']); exit;
    }
}

/* ========== 新增：机位创建 ========== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'spot_create') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_SESSION['user']['id'])) {
        echo json_encode(['success'=>false,'message'=>'请先登录']); exit;
    }
    $uid = (int)$_SESSION['user']['id'];
    $uname = (string)($_SESSION['user']['username'] ?? '');

    if (!$pdoSpot) { echo json_encode(['success'=>false,'message'=>'机位库连接失败']); exit; }

    $airportId = (int)($_POST['airport_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $lat       = (string)($_POST['lat'] ?? '');
    $lon       = (string)($_POST['lon'] ?? '');
    $elev      = $_POST['elevation_m'] !== '' ? (int)$_POST['elevation_m'] : null;

    $type          = $_POST['type'] ?: 'other';
    $access        = $_POST['accessibility'] ?: 'public';
    $light_morning = isset($_POST['light_morning']) ? 1 : 0;
    $light_noon    = isset($_POST['light_noon']) ? 1 : 0;
    $light_evening = isset($_POST['light_evening']) ? 1 : 0;

    $bestMonthsArr = isset($_POST['best_months']) && is_array($_POST['best_months']) ? $_POST['best_months'] : [];
    $bestMonthsArr = array_values(array_filter($bestMonthsArr, fn($m)=>preg_match('/^(?:1[0-2]|[1-9])$/',$m)));
    $best_months   = $bestMonthsArr ? implode(',', $bestMonthsArr) : null;

    $focal_min = (int)($_POST['focal_min'] ?? 70);
    $focal_max = (int)($_POST['focal_max'] ?? 400);
    $subject   = $_POST['subject'] ?: 'mixed';

    $parking_note   = trim($_POST['parking_note'] ?? '');
    $transport_note = trim($_POST['transport_note'] ?? '');
    $safety_note    = trim($_POST['safety_note'] ?? '');
    $tips           = trim($_POST['tips'] ?? '');

    if ($airportId <= 0) { echo json_encode(['success'=>false,'message'=>'缺少 airport_id']); exit; }
    if ($title === '')  { echo json_encode(['success'=>false,'message'=>'请填写机位标题']); exit; }
    if ($lat === '' || $lon === '') { echo json_encode(['success'=>false,'message'=>'请填写坐标']); exit; }
    if (!is_numeric($lat) || !is_numeric($lon)) { echo json_encode(['success'=>false,'message'=>'坐标格式不正确']); exit; }

    if (empty($_FILES['photos']) || (is_array($_FILES['photos']['name']) && count(array_filter($_FILES['photos']['name'])) < 1)) {
        echo json_encode(['success'=>false,'message'=>'请至少上传一张机位照片']); exit;
    }

    $root = __DIR__;
    $saveDir = $root . '/spotpointphotos';
    if (!is_dir($saveDir)) { @mkdir($saveDir, 0755, true); }

    $allowExt  = ['jpg','jpeg','png','webp'];
    $allowMime = ['image/jpeg','image/png','image/webp'];
    $maxSize   = 20 * 1024 * 1024;
    $finfo     = new finfo(FILEINFO_MIME_TYPE);

    try {
        $pdoSpot->beginTransaction();

        $sql = "INSERT INTO spots (
                    airport_id, uploader, title, lat, lon, elevation_m,
                    type, accessibility,
                    light_morning, light_noon, light_evening,
                    best_months, focal_min, focal_max, subject,
                    parking_note, transport_note, safety_note, tips,
                    review_status, reviewed_by, reviewed_at, review_note,
                    created_by, updated_by, created_at, updated_at
                ) VALUES (
                    :airport_id, :uploader, :title, :lat, :lon, :elevation_m,
                    :type, :accessibility,
                    :light_morning, :light_noon, :light_evening,
                    :best_months, :focal_min, :focal_max, :subject,
                    :parking_note, :transport_note, :safety_note, :tips,
                    'pending', NULL, NULL, NULL,
                    :created_by, :updated_by, NOW(), NOW()
                )";
        $st = $pdoSpot->prepare($sql);
        $st->execute([
            ':airport_id'     => $airportId,
            ':uploader'       => $uid,
            ':title'          => $title,
            ':lat'            => number_format((float)$lat, 6, '.', ''),
            ':lon'            => number_format((float)$lon, 6, '.', ''),
            ':elevation_m'    => $elev,
            ':type'           => $type,
            ':accessibility'  => $access,
            ':light_morning'  => $light_morning,
            ':light_noon'     => $light_noon,
            ':light_evening'  => $light_evening,
            ':best_months'    => $best_months,
            ':focal_min'      => $focal_min,
            ':focal_max'      => $focal_max,
            ':subject'        => $subject,
            ':parking_note'   => $parking_note ?: null,
            ':transport_note' => $transport_note ?: null,
            ':safety_note'    => $safety_note ?: null,
            ':tips'           => $tips ?: null,
            ':created_by'     => $uid,
            ':updated_by'     => $uid,
        ]);
        $spotId = (int)$pdoSpot->lastInsertId();

        $files = $_FILES['photos'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $okPhotos = 0;

// 新机位配图默认待审核 approved=0
$stPhoto = $pdoSpot->prepare(
    "INSERT INTO spot_photos (spot_id, photo_url, credit, approved, created_at)
     VALUES (?, ?, ?, 0, NOW())"
);

        for ($i=0; $i<$count; $i++) {
            $name = $files['name'][$i] ?? '';
            $tmp  = $files['tmp_name'][$i] ?? '';
            $err  = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            $size = (int)($files['size'][$i] ?? 0);

            if ($err !== UPLOAD_ERR_OK) continue;
            if (!is_uploaded_file($tmp)) continue;
            if ($size <= 0 || $size > $maxSize) continue;

            $mime = $finfo->file($tmp) ?: '';
            if (!in_array($mime, $allowMime, true)) continue;

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowExt, true)) {
                $ext = $mime==='image/webp' ? 'webp' : ($mime==='image/png' ? 'png' : 'jpg');
            }

            $fname = 'spot_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest  = $saveDir . '/' . $fname;
            if (!move_uploaded_file($tmp, $dest)) continue;

            $rel = 'spotpointphotos/' . $fname;
            $credit = $uname ?: null;

            $stPhoto->execute([$spotId, $rel, $credit]);
            $okPhotos++;
        }

        if ($okPhotos < 1) {
            $pdoSpot->rollBack();
            echo json_encode(['success'=>false,'message'=>'至少需成功上传 1 张机位照片']); exit;
        }

        $pdoSpot->commit();
        echo json_encode(['success'=>true,'spot_id'=>$spotId,'photo_count'=>$okPhotos]); exit;
    } catch (Throwable $e) {
        if ($pdoSpot->inTransaction()) $pdoSpot->rollBack();
        echo json_encode(['success'=>false,'message'=>'创建失败：'.$e->getMessage()]); exit;
    }
}

/* ========== 新增：机位详情（仅返回审核通过的机位） ========== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'spot_detail') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$pdoSpot) { echo json_encode(['success'=>false,'message'=>'机位库连接失败']); exit; }

    $spotId = isset($_GET['spot_id']) ? (int)$_GET['spot_id'] : 0;
    if ($spotId <= 0) { echo json_encode(['success'=>false,'message'=>'invalid spot_id']); exit; }

    try {
        // 机位主体
        $sql = "SELECT s.id, s.airport_id, s.title, 
                       CAST(s.lat AS DECIMAL(9,6))  AS lat,
                       CAST(s.lon AS DECIMAL(9,6))  AS lng,
                       s.elevation_m, s.type, s.accessibility,
                       s.light_morning, s.light_noon, s.light_evening,
                       s.best_months, s.focal_min, s.focal_max, s.subject,
                       s.parking_note, s.transport_note, s.safety_note, s.tips,
                       s.review_status, s.created_at, s.updated_at,
                       s.uploader
                FROM spots s
                WHERE s.id = ? AND s.review_status = 'approved'
                LIMIT 1";
        $st = $pdoSpot->prepare($sql);
        $st->execute([$spotId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success'=>false,'message'=>'机位不存在或未通过审核']); exit; }

// 机位照片（仅展示已审核通过的）
$ph = $pdoSpot->prepare(
    "SELECT id, photo_url, credit, created_at
     FROM spot_photos
     WHERE spot_id = ? AND approved = 1
     ORDER BY id ASC
     LIMIT 20"
);
$ph->execute([$spotId]);
$photos = [];
foreach ($ph->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $rel = ltrim($r['photo_url'], '/');
    $photos[] = [
        'id'    => (int)$r['id'],
        'url'   => '/'.$rel,
        'credit'=> $r['credit'] ?? null,
        'time'  => $r['created_at'] ?? null,
    ];
}


        // 统一结构
        $detail = [
            'id'            => (int)$row['id'],
            'airport_id'    => (int)$row['airport_id'],
            'title'         => (string)($row['title'] ?? ''),
            'lat'           => isset($row['lat']) ? (float)$row['lat'] : null,
            'lng'           => isset($row['lng']) ? (float)$row['lng'] : null,
            'elevation_m'   => isset($row['elevation_m']) ? (int)$row['elevation_m'] : null,
            'type'          => $row['type'] ?? null,
            'accessibility' => $row['accessibility'] ?? null,
            'light'         => [
                'morning' => (int)($row['light_morning'] ?? 0),
                'noon'    => (int)($row['light_noon'] ?? 0),
                'evening' => (int)($row['light_evening'] ?? 0),
            ],
            'best_months'   => $row['best_months'] ?? null,
            'focal_min'     => isset($row['focal_min']) ? (int)$row['focal_min'] : null,
            'focal_max'     => isset($row['focal_max']) ? (int)$row['focal_max'] : null,
            'subject'       => $row['subject'] ?? null,
            'parking_note'  => $row['parking_note'] ?: null,
            'transport_note'=> $row['transport_note'] ?: null,
            'safety_note'   => $row['safety_note'] ?: null,
            'tips'          => $row['tips'] ?: null,
            'photos'        => $photos,
        ];

        echo json_encode(['success'=>true, 'detail'=>$detail]); exit;
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'message'=>'获取机位详情失败']); exit;
    }
}

/* ========== 新增：机位补充照片 ========== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'spot_photo_upload') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_SESSION['user']['id'])) {
        echo json_encode(['success'=>false,'message'=>'请先登录']); exit;
    }
    if (!$pdoSpot) { echo json_encode(['success'=>false,'message'=>'机位库连接失败']); exit; }

    $uid   = (int)$_SESSION['user']['id'];
    $uname = (string)($_SESSION['user']['username'] ?? '');
    $spotId = (int)($_POST['spot_id'] ?? 0);
    if ($spotId <= 0) { echo json_encode(['success'=>false,'message'=>'invalid spot_id']); exit; }

    try {
        // 仅允许给“审核通过”的机位补充照片
        $st = $pdoSpot->prepare("SELECT id FROM spots WHERE id=? AND review_status='approved' LIMIT 1");
        $st->execute([$spotId]);
        if (!$st->fetchColumn()) {
            echo json_encode(['success'=>false,'message'=>'机位不存在或未通过审核']); exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'message'=>'数据库错误']); exit;
    }

    if (empty($_FILES['photos'])) { echo json_encode(['success'=>false,'message'=>'没有选择文件']); exit; }

    $files = $_FILES['photos'];
    $count = is_array($files['name']) ? count($files['name']) : 0;
    if ($count <= 0) { echo json_encode(['success'=>false,'message'=>'没有有效文件']); exit; }

    $root = __DIR__;
    $saveDir = $root . '/spotpointphotos';
    if (!is_dir($saveDir)) { @mkdir($saveDir, 0755, true); }

    $allowExt  = ['jpg','jpeg','png','webp'];
    $allowMime = ['image/jpeg','image/png','image/webp'];
    $maxSize   = 20 * 1024 * 1024;
    $finfo     = new finfo(FILEINFO_MIME_TYPE);

    $ok = 0;
    try {
        $pdoSpot->beginTransaction();

        // 新机位配图默认待审核 approved=0
$stPhoto = $pdoSpot->prepare(
    "INSERT INTO spot_photos (spot_id, photo_url, credit, approved, created_at)
     VALUES (?, ?, ?, 0, NOW())"
);

        for ($i=0; $i<$count; $i++) {
            $name = $files['name'][$i] ?? '';
            $tmp  = $files['tmp_name'][$i] ?? '';
            $err  = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            $size = (int)($files['size'][$i] ?? 0);

            if ($err !== UPLOAD_ERR_OK) continue;
            if (!is_uploaded_file($tmp)) continue;
            if ($size <= 0 || $size > $maxSize) continue;

            $mime = $finfo->file($tmp) ?: '';
            if (!in_array($mime, $allowMime, true)) continue;

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowExt, true)) {
                $ext = $mime==='image/webp' ? 'webp' : ($mime==='image/png' ? 'png' : 'jpg');
            }

            $fname = 'spot_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest  = $saveDir . '/' . $fname;
            if (!move_uploaded_file($tmp, $dest)) continue;

            $rel = 'spotpointphotos/' . $fname;
            $credit = $uname ?: null;

            $stPhoto->execute([$spotId, $rel, $credit]);
            $ok++;
        }

        if ($ok < 1) {
            $pdoSpot->rollBack();
            echo json_encode(['success'=>false,'message'=>'至少需成功上传 1 张图片']); exit;
        }

        $pdoSpot->commit();
        echo json_encode(['success'=>true,'count'=>$ok]);
        exit;

    } catch (Throwable $e) {
        if ($pdoSpot->inTransaction()) $pdoSpot->rollBack();
        echo json_encode(['success'=>false,'message'=>'上传失败：'.$e->getMessage()]);
        exit;
    }
}

/* -------------------- 正常页面渲染 -------------------- */

$sql = "
  SELECT id, iata_code, icao_code, airport_name, 
         CAST(latitude AS DECIMAL(10,6)) AS latitude,
         CAST(longitude AS DECIMAL(10,6)) AS longitude
  FROM airport_data
  WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    AND latitude <> 0 AND longitude <> 0
  ORDER BY id ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$points = [];
foreach ($rows as $r) {
    $points[] = [
        'id'   => (int)$r['id'],
        'name' => $r['airport_name'] ?? '',
        'iata' => $r['iata_code'] ?? '',
        'icao' => $r['icao_code'] ?? '',
        'lng'  => isset($r['longitude']) ? (float)$r['longitude'] : null,
        'lat'  => isset($r['latitude'])  ? (float)$r['latitude']  : null,
    ];
}

function getUserIP(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ipList[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function getGeoByIp(string $ip, string $apiKey): array {
    $url = "https://api.ip2location.io/?key={$apiKey}&ip=" . urlencode($ip);
    $resp = @file_get_contents($url);
    if ($resp === false) return [];
    $data = json_decode($resp, true);
    return is_array($data) ? $data : [];
}

$ip  = getUserIP();
$geo = getGeoByIp($ip, $IP2LOCATION_KEY);

$isChinaMainland = false;
$lat = 34.5;
$lng = 104.0;
if (!empty($geo)) {
    $countryCode = strtoupper($geo['country_code'] ?? '');
    $regionName  = trim($geo['region_name'] ?? '');
    $lat = is_numeric($geo['latitude'] ?? null) ? (float)$geo['latitude'] : $lat;
    $lng = is_numeric($geo['longitude'] ?? null) ? (float)$geo['longitude'] : $lng;
    $nonMainland = ['Hong Kong','Macau','Macao','Taiwan','香港','澳门','台湾'];
    if ($countryCode === 'CN' && !in_array($regionName, $nonMainland, true)) {
        $isChinaMainland = true;
    }
}

$isLoggedIn = isset($_SESSION['user']) && !empty($_SESSION['user']['id']);

$bootstrap = [
    'amapKey' => $AMAP_KEY,
    'isChinaMainland' => $isChinaMainland,
    'center' => ['lng' => $lng, 'lat' => $lat],
    'points' => $points,
    'isLoggedIn' => $isLoggedIn ? 1 : 0,
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
<title>AeroSpot - 机位地图</title>
<style>
html,body{height:100%;margin:0;}
#app{position:relative;height:100%;}
#map{position:absolute;inset:0;}

/* 顶部右侧切换条 */
.switcher{
  position:absolute;right:12px;top:12px;z-index:9999;
  display:flex;gap:6px;flex-wrap:wrap;
  background:rgba(255,255,255,.9);border-radius:12px;padding:8px;
  box-shadow:0 4px 16px rgba(0,0,0,.12);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,PingFang SC,Microsoft Yahei;
}
.switcher button{
  border:0;border-radius:8px;padding:6px 10px;cursor:pointer;
  background:#1677ff;color:#fff;font-weight:600;font-size:12px;
}
.switcher .tag{font-size:12px;padding:4px 6px;border-radius:6px;background:#f2f3f5;color:#333;}

/* 左侧信息侧边栏 */
.sidebar{
  position:absolute;left:0;top:0;height:100%;z-index:9998;
  width:360px;max-width:92vw;
  background:#fff;border-right:1px solid #e5e6eb;
  transform:translateX(-100%);transition:transform .25s ease;
  box-shadow:0 10px 30px rgba(0,0,0,.08);
  display:flex;flex-direction:column;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,PingFang SC,Microsoft Yahei;
}
.sidebar.open{ transform:translateX(0); }

.sidebar-header{
  padding:14px 16px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;
}
.sidebar-title{
  font-size:16px;font-weight:700;line-height:1.2;flex:1;word-break:break-all;
}
.sidebar-close{
  appearance:none;border:0;background:#f5f6f7;border-radius:8px;padding:6px 10px;cursor:pointer;font-weight:600;
}

.sidebar-body{padding:12px 16px;overflow:auto;}
.sidebar-body .row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed #eee;}
.sidebar-body .label{color:#666;}
.sidebar-body .value{font-weight:600;color:#222;}

/* 机场照片区 */
.sb-photos { padding: 12px 16px; border-top: 1px solid #f0f0f0; }
.sb-photos-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
.sb-photos-title { font-weight:700; font-size:14px; }
.sb-photos-actions { display:flex; gap:8px; flex-wrap:wrap; }
.sb-photos-actions .btn { appearance:none; border:0; border-radius:10px; padding:6px 10px; cursor:pointer; font-weight:700; }
.sb-photos-actions .primary { background:#1677ff; color:#fff; }
.sb-photos-actions .ghost { background:#f2f3f5; color:#333; }

.sb-gallery { position:relative; width:100%; aspect-ratio: 4 / 3; background:#fafafa; border:1px solid #eee; border-radius:10px; overflow:hidden; }
.sb-gallery .empty { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#888; font-size:13px; padding:12px; text-align:center; }
.sb-gallery img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:none; }
.sb-gallery img.active { display:block; }

.sb-gallery .nav {
  position:absolute; top:50%; transform:translateY(-50%);
  width:36px; height:36px; display:flex; align-items:center; justify-content:center;
  background:rgba(0,0,0,.45); color:#fff; border-radius:50%; cursor:pointer; user-select:none;
}
.sb-gallery .prev { left:8px; }
.sb-gallery .next { right:8px; }

.sb-thumbs { display:flex; gap:8px; margin-top:8px; overflow-x:auto; padding-bottom:4px; }
.sb-thumbs img { width:72px; height:54px; object-fit:cover; border:2px solid transparent; border-radius:8px; cursor:pointer; flex:0 0 auto; }
.sb-thumbs img.active { border-color:#1677ff; }

.sb-upload-tip { margin-top:8px; font-size:12px; color:#666; }

/* === 机位区 === */
.sb-spots { padding: 12px 16px; border-top: 1px solid #f0f0f0; }
.sb-spots-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
.sb-spots-title { font-weight:700; font-size:14px; }
.sb-spots-actions { display:flex; gap:8px; flex-wrap:wrap; }
.sb-spots-actions .btn { appearance:none; border:0; border-radius:10px; padding:6px 10px; cursor:pointer; font-weight:700; }
.sb-spots-actions .primary { background:#1677ff; color:#fff; }
.sb-spot-list { display:flex; flex-direction:column; gap:8px; max-height:220px; overflow:auto; }
.sb-spot-empty { color:#888; font-size:13px; }
.sb-spot-item { border:1px solid #eee; border-radius:10px; padding:10px; display:flex; justify-content:space-between; align-items:center; }
.sb-spot-item .meta { display:flex; flex-direction:column; gap:4px; }
.sb-spot-item .name { font-weight:700; }
.sb-spot-item .coord { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:12px; color:#555; }
.sb-spot-item .go { appearance:none; border:0; border-radius:8px; padding:6px 10px; cursor:pointer; background:#f2f3f5; font-weight:700; }

.sidebar-actions{padding:12px 16px;border-top:1px solid #f0f0f0;display:flex;gap:8px;flex-wrap:wrap;}
.sidebar-actions button{
  appearance:none;border:0;background:#1677ff;color:#fff;border-radius:10px;padding:8px 12px;cursor:pointer;font-weight:700;
}
.sidebar-actions .ghost{background:#f2f3f5;color:#333;font-weight:600}

/* 侧栏打开给地图让位 */
#app.with-sidebar #map{ left:360px; }
@media (max-width: 768px){
  #app.with-sidebar #map{ left:80vw; }
  .sidebar{ width:80vw; }
}

/* ====== 站内弹窗（Modal） ====== */
.modal{
  position:fixed;inset:0;z-index:10000;display:none;
}
.modal.open{ display:block; }
.modal-mask{
  position:absolute;inset:0;background:rgba(0,0,0,.35);
  backdrop-filter:saturate(180%) blur(2px);
}
.modal-panel{
  position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);
  width:420px;max-width:90vw;background:#fff;border-radius:14px;overflow:hidden;
  box-shadow:0 24px 64px rgba(0,0,0,.2);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,PingFang SC,Microsoft Yahei;
}
.modal-header{display:flex;align-items:center;gap:8px;padding:14px 16px;border-bottom:1px solid #f0f0f0;}
.modal-title{font-weight:700;font-size:16px;line-height:1.2;flex:1;}
.modal-close{appearance:none;border:0;background:#f5f6f7;border-radius:8px;padding:6px 10px;cursor:pointer;font-weight:700;}
.modal-body{padding:16px;color:#222;font-size:14px;line-height:1.7;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;padding:12px 16px;border-top:1px solid #f0f0f0;}
.modal-actions .btn{appearance:none;border:0;border-radius:10px;padding:8px 14px;cursor:pointer;font-weight:700;}
.modal-actions .primary{background:#1677ff;color:#fff;}
.modal-actions .ghost{background:#f2f3f5;color:#333;}

/* 返回按钮（调低层级，侧栏打开时在下面） */
.home-btn {
  position: absolute;
  left: 52px;
  top: 12px;
  z-index: 9997;
  background: #1677ff;
  color: #fff;
  font-weight: 600;
  font-size: 14px;
  text-decoration: none;
  padding: 8px 24px;
  border-radius: 8px;
  box-shadow: 0 4px 10px rgba(0,0,0,.15);
  font-family: system-ui, -apple-system, Segoe UI, Roboto, PingFang SC, Microsoft Yahei;
  transition: left 0.25s ease;
}
.home-btn:hover { background: #0f5ec7; }
#app.with-sidebar .home-btn { left: 412px; }
@media (max-width: 768px){
  #app.with-sidebar .home-btn { left: calc(80vw + 52px); }
}

/* 选点模式 */
.modal.pick-mode .modal-mask{ pointer-events: none; background: rgba(0,0,0,0.15); }
.pick-overlay{ position:absolute; left:50%; top:16px; transform:translateX(-50%); z-index:10001; display:flex; gap:8px; align-items:center; pointer-events:auto; }
.pick-pill{ background:#fff; border:1px solid #e5e6eb; border-radius:999px; padding:8px 12px; box-shadow:0 6px 20px rgba(0,0,0,.12); font-size:13px; }
.pick-done{ appearance:none; border:0; border-radius:999px; padding:8px 14px; cursor:pointer; background:#1677ff; color:#fff; font-weight:700; box-shadow:0 6px 20px rgba(0,0,0,.12); }

/* === 自定义地图标记（机场/机位） === */
.mk{ width:14px; height:14px; border-radius:50%; border:2px solid #fff; box-shadow:0 2px 8px rgba(0,0,0,.25); }
.mk-airport{ background:#1677ff; } /* 蓝：机场 */
.mk-spot{ background:#ff7a00; }    /* 橙：机位 */

/* 统一盒模型，杜绝横向溢出与层级修正 */
.modal, .modal * { box-sizing: border-box; }
.modal{ z-index: 10020; }
.modal-panel{ width: 420px; max-width: 90vw; max-height: 86vh; display: flex; flex-direction: column; }
.modal-header, .modal-actions{ position: sticky; z-index: 1; background:#fff; }
.modal-header{ top: 0; }
.modal-actions{ bottom: 0; }
.modal-body{ overflow:auto; padding:16px; }
@media (max-width: 480px){
  .modal-body form > div { grid-template-columns: 1fr !important; }
}
.modal-panel *{ min-width:0; max-width:100%; }
/* —— 最佳月份横排 —— */
.months-row{
  grid-column:1/-1;
  display:flex;
  gap:10px;
  overflow-x:auto;
  padding:6px 2px;
  white-space:nowrap;
}
.months-row label{
  display:inline-flex;
  align-items:center;
  gap:6px;
  background:#f7f8fa;
  border:1px solid #e5e6eb;
  border-radius:999px;
  padding:6px 10px;
  font-size:13px;
  color:#333;
  flex:0 0 auto;
  user-select:none;
}
.months-row input[type="checkbox"]{
  accent-color:#1677ff;
}
</style>
</head>
<body>
<div id="app">
  <a href="/" class="home-btn" title="返回首页">返回</a>
  <div id="map"></div>

  <!-- 左侧机场信息侧边栏 -->
  <aside id="sidebar" class="sidebar" aria-hidden="true">
    <div class="sidebar-header">
      <div class="sidebar-title" id="sb-title">-</div>
      <button class="sidebar-close" id="sb-close" title="关闭">关闭</button>
    </div>
    <div class="sidebar-body">
      <div class="row"><div class="label">IATA</div><div class="value" id="sb-iata">-</div></div>
      <div class="row"><div class="label">ICAO</div><div class="value" id="sb-icao">-</div></div>
      <div class="row"><div class="label">经度</div><div class="value" id="sb-lng">-</div></div>
      <div class="row"><div class="label">纬度</div><div class="value" id="sb-lat">-</div></div>
      <div class="row"><div class="label">内部ID</div><div class="value" id="sb-id">-</div></div>
    </div>

    <!-- 机场照片区 -->
    <div class="sb-photos">
      <div class="sb-photos-header">
        <div class="sb-photos-title">机场照片</div>
        <div class="sb-photos-actions">
          <button class="btn primary" id="sb-upload-btn">上传照片</button>
          <input type="file" id="sb-file" accept="image/*" multiple style="display:none;">
        </div>
      </div>

      <div class="sb-gallery" id="sb-gallery">
        <div class="empty" id="sb-gallery-empty">暂无图片，快来上传第一张吧～（通过审核后显示）</div>
        <div class="nav prev" id="sb-prev" title="上一张">‹</div>
        <div class="nav next" id="sb-next" title="下一张">›</div>
      </div>
      <div class="sb-thumbs" id="sb-thumbs"></div>

      <div class="sb-upload-tip">说明：支持多图上传，上传后需审核通过才会对所有人展示。</div>
    </div>

    <!-- 机位展示区（只展示审核通过的机位） -->
    <div class="sb-spots">
      <div class="sb-spots-header">
        <div class="sb-spots-title">机位（Spotter Points）</div>
        <div class="sb-spots-actions">
          <button class="btn primary" id="sb-add-spot">添加机位</button>
        </div>
      </div>
      <div class="sb-spot-list" id="sb-spot-list">
        <div class="sb-spot-empty" id="sb-spot-empty">暂无机位，欢迎补充～</div>
      </div>
    </div>

    <div class="sidebar-actions">
      <button id="sb-zoom">居中并放大</button>
      <button class="ghost" id="sb-copy">复制坐标</button>
    </div>
  </aside>

  <!-- 顶部右侧切换条 -->
  <div class="switcher">
    <span class="tag">当前：<b id="current-provider">-</b></span>
    <button id="btn-amap">高德</button>
    <button id="btn-osm">OSM</button>
    <button id="btn-base-normal">标准</button>
    <button id="btn-base-sat">卫星</button>
  </div>
</div>

<!-- 站内弹窗 -->
<div class="modal" id="site-modal" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-mask" id="modal-mask"></div>
  <div class="modal-panel" role="document">
    <div class="modal-header">
      <div class="modal-title" id="modal-title">提示</div>
      <button class="modal-close" id="modal-x">关闭</button>
    </div>
    <div class="modal-body" id="modal-body">-</div>
    <div class="modal-actions" id="modal-actions"></div>
  </div>
</div>

<script>
const BOOT = <?php echo json_encode($bootstrap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

// ============== 工具 ==============
function loadScript(src){
  return new Promise((res,rej)=>{
    const s=document.createElement('script');
    s.src=src;s.async=true;
    s.onload=()=>res();s.onerror=()=>rej(new Error('Failed '+src));
    document.head.appendChild(s);
  });
}
function loadCSS(href){
  return new Promise((res, rej)=>{
    const l = document.createElement('link');
    l.rel = 'stylesheet';
    l.href = href;
    l.onload = ()=>res();
    l.onerror = ()=>rej(new Error('Failed '+href));
    document.head.appendChild(l);
  });
}
function fmt6(n){ return (typeof n==='number' ? n.toFixed(6) : '-'); }
const API_URL = window.location.pathname; // 同文件内的 ajax 接口

// ============== 站内弹窗工具 ==============
const Modal = (()=> {
  const root = document.getElementById('site-modal');
  const titleEl = document.getElementById('modal-title');
  const bodyEl = document.getElementById('modal-body');
  const actionsEl = document.getElementById('modal-actions');
  const xBtn = document.getElementById('modal-x');
  const mask = document.getElementById('modal-mask');

  function close(){
    root.classList.remove('open');
    root.classList.remove('pick-mode');
    root.setAttribute('aria-hidden','true');
    actionsEl.innerHTML = '';
    mask.onclick = null;
  }

  function open({title='提示', html='', actions=[], showClose=true, dismissible=true}={}){
    titleEl.textContent = title;
    bodyEl.innerHTML = html;
    actionsEl.innerHTML = '';
    (actions || []).forEach(a=>{
      const b = document.createElement('button');
      b.className = 'btn ' + (a.primary ? 'primary' : 'ghost');
      b.textContent = a.text || '确定';
      b.addEventListener('click', ()=>{ try{ a.onClick && a.onClick(); } finally { if(!a.keepOpen) close(); } });
      actionsEl.appendChild(b);
    });
    xBtn.style.display = showClose ? '' : 'none';
    xBtn.onclick = close;
    mask.onclick = dismissible ? ()=>{ if (!window.spotPickState || !window.spotPickState.active) close(); } : null;
    root.classList.add('open');
    root.setAttribute('aria-hidden','false');
  }
  return { open, close };
})();

// ====== 全局选点状态 ======
window.spotPickState = window.spotPickState || {
  active:false, marker:null, mapClickHandler:null, markerDragHandler:null,
  overlayEl:null, draft:null
};

// ====== 表单序列化/反填（略，保持原有） ======
// === 替换：getSpotFormDraft()（新增收集 best_months）===
function getSpotFormDraft($form){
  const months = [...$form.querySelectorAll('input[name="best_months[]"]:checked')].map(el=>el.value);
  const d = {
    airport_id: String(selectedPoint?.id ?? ''),
    title: $form.title.value.trim(),
    lat: $form.lat.value.trim(),
    lon: $form.lon.value.trim(),
    elevation_m: $form.elevation_m.value.trim(),
    type: $form.type.value,
    accessibility: $form.accessibility.value,
    light_morning: $form.light_morning.checked ? 1 : 0,
    light_noon: $form.light_noon.checked ? 1 : 0,
    light_evening: $form.light_evening.checked ? 1 : 0,
    best_months: months,                 // ★ 新增
    focal_min: $form.focal_min.value.trim(),
    focal_max: $form.focal_max.value.trim(),
    subject: $form.subject.value,
    parking_note: $form.parking_note.value.trim(),
    transport_note: $form.transport_note.value.trim(),
    safety_note: $form.safety_note.value.trim(),
    tips: $form.tips.value.trim(),
  };
  return d;
}

// === 替换：applySpotFormDraft()（新增回填 best_months）===
function applySpotFormDraft($form, draft={}){
  const set = (name, v)=>{ if($form[name]!==undefined) $form[name].value = (v ?? ''); };
  set('title', draft.title);
  set('lat', draft.lat);
  set('lon', draft.lon);
  set('elevation_m', draft.elevation_m);
  if($form.type) $form.type.value = draft.type ?? 'other';
  if($form.accessibility) $form.accessibility.value = draft.accessibility ?? 'public';
  if($form.subject) $form.subject.value = draft.subject ?? 'mixed';
  if($form.light_morning) $form.light_morning.checked = !!draft.light_morning;
  if($form.light_noon) $form.light_noon.checked = !!draft.light_noon;
  if($form.light_evening) $form.light_evening.checked = !!draft.light_evening;
  set('focal_min', draft.focal_min ?? '70');
  set('focal_max', draft.focal_max ?? '400');
  set('parking_note', draft.parking_note);
  set('transport_note', draft.transport_note);
  set('safety_note', draft.safety_note);
  set('tips', draft.tips);

  // ★ 回填最佳月份
  if (Array.isArray(draft.best_months)) {
    const setMonths = new Set(draft.best_months.map(String));
    [...$form.querySelectorAll('input[name="best_months[]"]')].forEach(el=>{
      el.checked = setMonths.has(el.value);
    });
  }
}


// ====== 表单顶部错误提示 ======
function showFormError(msg){
  const body = document.getElementById('modal-body');
  let box = body.querySelector('#form-error');
  if(!box){
    box = document.createElement('div');
    box.id = 'form-error';
    box.style.cssText = 'margin:0 0 10px 0;padding:8px 10px;border-radius:8px;background:#fff7e6;color:#ad4e00;border:1px solid #ffe58f;';
    body.prepend(box);
  }
  box.textContent = msg;
}

// —— 偏移与阈值（可按需调整）——
const DEFAULT_OFFSET_M = 30;   // 默认上移 30 米，避免与机场点重合
const NEAR_DUP_THRESHOLD_M = 25; // 若与机场点距离 <= 25 米则视为“几乎重合”

function metersToLatDeg(m){ return m / 111111; } // 1°≈111111m
function offsetLatNorth(lat, meters = DEFAULT_OFFSET_M){
  return (Number(lat) || 0) + metersToLatDeg(meters);
}

// ====== 打开机位表单（保持原有） ======
function openSpotForm(draft){
  // 若无 draft 坐标，则默认在机场点基础上向北小幅偏移
  const hasPreset = draft && !isNaN(parseFloat(draft.lat)) && !isNaN(parseFloat(draft.lon));
  const defLat = hasPreset ? parseFloat(draft.lat) : offsetLatNorth(selectedPoint.lat, DEFAULT_OFFSET_M);
  const defLng = hasPreset ? parseFloat(draft.lon) : Number(selectedPoint.lng);

  const html = `
  <form id="spot-form">
    <input type="hidden" name="airport_id" value="${String(selectedPoint.id)}" />
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <div style="grid-column:1/-1;font-weight:700;margin-bottom:2px;">基本信息</div>
      <label style="display:flex;flex-direction:column;gap:6px;">
        <span>机位标题（必填）</span>
        <input name="title" type="text" required placeholder="例如：RWY 14L 外侧土坡" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
      </label>

      <div style="grid-column:1/-1;font-weight:700;margin:8px 0 2px;">机位坐标</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <label style="display:flex;flex-direction:column;gap:6px;">
          <span>纬度 Lat（必填）</span>
          <input name="lat" type="text" required placeholder="${fmt6(defLat)}" value="${fmt6(defLat)}" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
        </label>
        <label style="display:flex;flex-direction:column;gap:6px;">
          <span>经度 Lng（必填）</span>
          <input name="lon" type="text" required placeholder="${fmt6(defLng)}" value="${fmt6(defLng)}" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
        </label>
      </div>
      <div style="grid-column:1/-1;display:flex;gap:8px;margin-top:8px;">
        <button type="button" id="btn-fill-airport" class="btn ghost" style="appearance:none;border:0;border-radius:10px;padding:8px 12px;background:#f2f3f5;font-weight:700;cursor:pointer;">用机场坐标填入</button>
        <button type="button" id="btn-fill-center"  class="btn ghost" style="appearance:none;border:0;border-radius:10px;padding:8px 12px;background:#f2f3f5;font-weight:700;cursor:pointer;">用当前地图中心填入</button>
        <button type="button" id="btn-pick-map"    class="btn ghost" style="appearance:none;border:0;border-radius:10px;padding:8px 12px;background:#f2f3f5;font-weight:700;cursor:pointer;">从地图选点</button>
      </div>

      <label style="display:flex;flex-direction:column;gap:6px;">
        <span>海拔高度（米，可选）</span>
        <input name="elevation_m" type="number" step="1" min="0" placeholder="如不确定可留空" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
      </label>

      <div style="grid-column:1/-1;font-weight:700;margin:8px 0 2px;">类型 & 可达性</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <label style="display:flex;flex-direction:column;gap:6px;">
          <span>机位类型</span>
          <select name="type" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
            <option value="fence">围栏边</option>
            <option value="hill">小山坡</option>
            <option value="garage">停车场</option>
            <option value="rooftop">楼顶</option>
            <option value="park">公园</option>
            <option value="roadside">路边</option>
            <option value="terminal">航站楼</option>
            <option value="other" selected>其他</option>
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:6px;">
          <span>可达性</span>
          <select name="accessibility" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
            <option value="public" selected>公共</option>
            <option value="ticketed">需购票</option>
            <option value="restricted">限制</option>
            <option value="paid">付费</option>
          </select>
        </label>
      </div>

      <div style="grid-column:1/-1;font-weight:700;margin:8px 0 2px;">光线 & 器材</div>
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
        <label><input type="checkbox" name="light_morning"> 早晨顺光</label>
        <label><input type="checkbox" name="light_noon"> 中午顺光</label>
        <label><input type="checkbox" name="light_evening"> 傍晚顺光</label>
      </div>

      <div style="grid-column:1/-1;font-weight:700;margin:8px 0 6px;">最佳月份</div>
      <div class="months-row">
        ${[1,2,3,4,5,6,7,8,9,10,11,12].map(m => `
          <label><input type="checkbox" name="best_months[]" value="${m}"> ${m}月</label>
        `).join('')}
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <label style="display:flex;flex-direction:column;gap:6px;">
          <span>建议最小焦段（mm）</span>
          <input name="focal_min" type="number" step="1" min="10" value="70" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
        </label>
        <label style="display:flex;flex-direction:column;gap:6px;">
          <span>建议最大焦段（mm）</span>
          <input name="focal_max" type="number" step="1" min="70" value="400" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
        </label>
      </div>

      <label style="display:flex;flex-direction:column;gap:6px;">
        <span>拍摄主体</span>
        <select name="subject" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
          <option value="landing">进近/降落</option>
          <option value="takeoff">起飞</option>
          <option value="taxi">滑行</option>
          <option value="apron">停机坪</option>
          <option value="air">空中</option>
          <option value="mixed" selected>混合</option>
        </select>
      </label>

      <div style="grid-column:1/-1;font-weight:700;margin:8px 0 2px;">补充说明（可选）</div>
      <label style="display:flex;flex-direction:column;gap:6px;">
        <span>停车信息</span>
        <input name="parking_note" type="text" placeholder="附近是否方便停车" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
      </label>
      <label style="display:flex;flex-direction:column;gap:6px;">
        <span>交通信息</span>
        <input name="transport_note" type="text" placeholder="公交/地铁/步行路径等" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
      </label>
      <label style="display:flex;flex-direction:column;gap:6px;">
        <span>安全提示</span>
        <input name="safety_note" type="text" placeholder="是否靠近禁区、注意事项" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
      </label>
      <label style="display:flex;flex-direction:column;gap:6px;">
        <span>其他建议</span>
        <textarea name="tips" rows="3" placeholder="风向季节、航线时刻等" style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;"></textarea>
      </label>

      <div style="grid-column:1/-1;font-weight:700;margin:8px 0 2px;">机位照片（至少 1 张）</div>
      <input id="spot-photos" name="photos[]" type="file" accept="image/*" multiple required style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
    </div>
  </form>`;

  Modal.open({
    title:`添加机位 - ${selectedPoint?.name || '-'}`,
    html,
    actions:[
      { text:'取消', onClick:()=>{}, keepOpen:false },
      { text:'提交', primary:true, onClick: submitSpotForm, keepOpen:true }
    ],
    showClose: false,
    dismissible: false
  });

  const $form = document.getElementById('spot-form');
  if (draft) applySpotFormDraft($form, draft);

  // “用机场坐标填入” 也给个默认上移偏移，避免再次重合
  $form.querySelector('#btn-fill-airport').addEventListener('click', ()=>{
    $form.lat.value = String(offsetLatNorth(selectedPoint.lat, DEFAULT_OFFSET_M).toFixed(6));
    $form.lon.value = String(Number(selectedPoint.lng).toFixed(6));
  });

  // 地图中心：尊重用户当前意图，不加偏移
  $form.querySelector('#btn-fill-center').addEventListener('click', ()=>{
    if(currentProvider==='osm' && osmMap){
      const c = osmMap.getCenter();
      $form.lat.value = c.lat.toFixed(6);
      $form.lon.value = c.lng.toFixed(6);
    }else if(currentProvider==='amap' && amap){
      const c = amap.getCenter();
      $form.lat.value = c.getLat().toFixed(6);
      $form.lon.value = c.getLng().toFixed(6);
    }
  });

  $form.querySelector('#btn-pick-map').addEventListener('click', ()=>{
    const files = (document.getElementById('spot-photos')?.files || []).length;
    if (files>0 && !window.confirm('开始选点将暂时关闭弹窗，已选择的图片不会保留，稍后需重新选择。继续吗？')) return;
    const draftNow = getSpotFormDraft($form);
    Modal.close();
    enableSpotPickerOverlay(draftNow);
  });
}



// ====== 选点启停（保持原有） ======
function enableSpotPickerOverlay(draft){
  if(window.spotPickState.active) return;
  window.spotPickState.active = true;
  window.spotPickState.draft = { ...(draft||{}) };

  const overlay = document.createElement('div');
  overlay.className = 'pick-overlay';
  overlay.innerHTML = `
    <div class="pick-pill" id="pick-hint">选点模式：点击地图或拖动标记设置坐标</div>
    <button class="pick-done" id="pick-done">结束选点</button>
  `;
  document.getElementById('app').appendChild(overlay);
  window.spotPickState.overlayEl = overlay;

  const $hint = overlay.querySelector('#pick-hint');
  const updateHint = (lat, lng)=>{
    $hint.textContent = `选点模式：${lat.toFixed(6)}, ${lng.toFixed(6)} (点击地图或拖动标记修改)`;
  };

  const hasPreset = !isNaN(parseFloat(draft?.lat)) && !isNaN(parseFloat(draft?.lon));

  // ===== OSM 分支 =====
  if (currentProvider === 'osm' && osmMap) {
    const c0 = osmMap.getCenter();
    const c  = hasPreset 
      ? L.latLng(parseFloat(draft.lat), parseFloat(draft.lon)) 
      : L.latLng(c0.lat + 0.0003, c0.lng);   // ★ 默认偏移纬度 +0.0003

    const marker = L.marker([c.lat, c.lng], { draggable: true }).addTo(osmMap);
    window.spotPickState.marker = marker;

    const onMapClick = (e)=> {
      marker.setLatLng(e.latlng);
      window.spotPickState.draft.lat = String(e.latlng.lat.toFixed(6));
      window.spotPickState.draft.lon = String(e.latlng.lng.toFixed(6));
      updateHint(e.latlng.lat, e.latlng.lng);
    };
    osmMap.on('click', onMapClick);
    window.spotPickState.mapClickHandler = onMapClick;

    const onDragEnd = ()=> {
      const ll = marker.getLatLng();
      window.spotPickState.draft.lat = String(ll.lat.toFixed(6));
      window.spotPickState.draft.lon = String(ll.lng.toFixed(6));
      updateHint(ll.lat, ll.lng);
    };
    marker.on('dragend', onDragEnd);
    window.spotPickState.markerDragHandler = onDragEnd;

    window.spotPickState.draft.lat = String(c.lat.toFixed(6));
    window.spotPickState.draft.lon = String(c.lng.toFixed(6));
    updateHint(c.lat, c.lng);

  // ===== 高德分支 =====
  } else if (currentProvider === 'amap' && amap) {
    const c0 = amap.getCenter();
    const c  = hasPreset
      ? new AMap.LngLat(parseFloat(draft.lon), parseFloat(draft.lat))
      : new AMap.LngLat(c0.getLng(), c0.getLat() + 0.0003); // ★ 默认偏移纬度 +0.0003

    const marker = new AMap.Marker({ position:c, draggable:true, cursor:'move', anchor:'bottom-center' });
    marker.setMap(amap);
    window.spotPickState.marker = marker;

    const onMapClick = (e)=> {
      marker.setPosition(e.lnglat);
      const lat = e.lnglat.getLat(), lng = e.lnglat.getLng();
      window.spotPickState.draft.lat = String(lat.toFixed(6));
      window.spotPickState.draft.lon = String(lng.toFixed(6));
      updateHint(lat, lng);
    };
    amap.on('click', onMapClick);
    window.spotPickState.mapClickHandler = onMapClick;

    const onDragEnd = ()=> {
      const p = marker.getPosition();
      const lat = p.getLat(), lng = p.getLng();
      window.spotPickState.draft.lat = String(lat.toFixed(6));
      window.spotPickState.draft.lon = String(lng.toFixed(6));
      updateHint(lat, lng);
    };
    marker.on('dragend', onDragEnd);
    window.spotPickState.markerDragHandler = onDragEnd;

    const lat0 = (c.getLat ? c.getLat() : c.lat), lng0 = (c.getLng ? c.getLng() : c.lng);
    window.spotPickState.draft.lat = String(Number(lat0).toFixed(6));
    window.spotPickState.draft.lon = String(Number(lng0).toFixed(6));
    updateHint(Number(lat0), Number(lng0));
  }

  overlay.querySelector('#pick-done').addEventListener('click', ()=>{
    const draftNow = { ...(window.spotPickState.draft||{}) };
    disableSpotPicker();
    openSpotForm(draftNow);
  });
}

function disableSpotPicker(){
  if(!window.spotPickState.active) return;
  if (currentProvider === 'osm' && osmMap) {
    if (window.spotPickState.marker) {
      window.spotPickState.marker.off('dragend', window.spotPickState.markerDragHandler);
      osmMap.removeLayer(window.spotPickState.marker);
    }
    if (window.spotPickState.mapClickHandler) osmMap.off('click', window.spotPickState.mapClickHandler);
  } else if (currentProvider === 'amap' && amap) {
    if (window.spotPickState.marker) {
      window.spotPickState.marker.off('dragend', window.spotPickState.markerDragHandler);
      window.spotPickState.marker.setMap(null);
    }
    if (window.spotPickState.mapClickHandler) amap.off('click', window.spotPickState.mapClickHandler);
  }
  if (window.spotPickState.overlayEl && window.spotPickState.overlayEl.parentNode) {
    window.spotPickState.overlayEl.parentNode.removeChild(window.spotPickState.overlayEl);
  }
  window.spotPickState.active = false;
  window.spotPickState.marker = null;
  window.spotPickState.mapClickHandler = null;
  window.spotPickState.markerDragHandler = null;
  window.spotPickState.overlayEl = null;
}

// ============== 全局状态 ==============
let currentProvider=null,amap=null,osmMap=null;
let baseMode='normal';
let osmBaseStd=null,osmBaseSat=null;
let amapTileSat=null,amapTileRoad=null;
const center=[BOOT.center.lng,BOOT.center.lat],POINTS=BOOT.points||[];
const $providerText=document.getElementById('current-provider');
let clickingMarker = false;

// 机场侧边栏绑定元素（保持原有）
let selectedPoint=null;
const $app=document.getElementById('app');
const $sidebar=document.getElementById('sidebar');
const $sbTitle=document.getElementById('sb-title');
const $sbIata=document.getElementById('sb-iata');
const $sbIcao=document.getElementById('sb-icao');
const $sbLng=document.getElementById('sb-lng');
const $sbLat=document.getElementById('sb-lat');
const $sbId=document.getElementById('sb-id');
const $sbClose=document.getElementById('sb-close');
const $sbZoom=document.getElementById('sb-zoom');
const $sbCopy=document.getElementById('sb-copy');

function updateProviderText(){
  $providerText.textContent=currentProvider==='amap'?'高德地图':'OpenStreetMap';
}

// ============== 侧边栏逻辑（机场） ==============
function openSidebar(p){
  selectedPoint = p;
  $sbTitle.textContent = p.name || '未命名';
  $sbIata.textContent = p.iata || '-';
  $sbIcao.textContent = p.icao || '-';
  $sbLng.textContent = fmt6(p.lng);
  $sbLat.textContent = fmt6(p.lat);
  $sbId.textContent  = String(p.id || '-');

  $sidebar.classList.add('open');
  $sidebar.setAttribute('aria-hidden','false');
  $app.classList.add('with-sidebar');

  requestAnimationFrame(()=>{
    if(currentProvider==='osm' && osmMap){
      osmMap.invalidateSize();
      osmMap.panBy([ getSidebarWidth()/2, 0 ], { animate:true });
    }else if(currentProvider==='amap' && amap){
      amap.panBy(getSidebarWidth()/2, 0);
    }
  });
}
function closeSidebar(){
  $sidebar.classList.remove('open');
  $sidebar.setAttribute('aria-hidden','true');
  $app.classList.remove('with-sidebar');
}
function getSidebarWidth(){
  const rect = $sidebar.getBoundingClientRect();
  return rect.width || 360;
}
$sbClose.addEventListener('click', closeSidebar);

// 复制坐标
$sbCopy.addEventListener('click', async ()=>{
  if(!selectedPoint) return;
  const text = `${fmt6(selectedPoint.lat)},${fmt6(selectedPoint.lng)}`;
  try{
    await navigator.clipboard.writeText(text);
    Modal.open({ title:'已复制坐标', html:`<div>坐标已复制到剪贴板：</div><div style="margin-top:8px;font-family:monospace;">${text}</div>` });
  }catch(e){
    Modal.open({ title:'复制失败', html:`<div>浏览器未授权剪贴板访问，请手动复制：</div><div style="margin-top:8px;font-family:monospace;">${text}</div>` });
  }
});

// 居中并放大
$sbZoom.addEventListener('click', ()=>{
  if(!selectedPoint) return;
  focusOnPoint(selectedPoint, true);
});

// ============== 机场照片&机位列表（侧栏内） ==============
const IS_LOGGED_IN = !!BOOT.isLoggedIn;

const $uploadBtn = document.getElementById('sb-upload-btn');
const $file = document.getElementById('sb-file');
const $gallery = document.getElementById('sb-gallery');
const $galleryEmpty = document.getElementById('sb-gallery-empty');
const $thumbs = document.getElementById('sb-thumbs');
const $prev = document.getElementById('sb-prev');
const $next = document.getElementById('sb-next');

let galleryPhotos = []; // {id, url, title}
let galleryIndex = 0;

async function openSidebarWithData(p){
  openSidebar(p);
  await Promise.all([
    loadAirportPhotos(p.id),
    loadAirportSpots(p.id)
  ]);
  renderGallery();
  renderSpotList();
}

async function loadAirportPhotos(airportId){
  try{
    const res = await fetch(`${API_URL}?ajax=list&airport_id=${encodeURIComponent(airportId)}`, {credentials:'include'});
    const data = await res.json();
    if(data && data.success){
      galleryPhotos = (data.list || []).map(row => ({
        id: row.id,
        url: row.url,
        title: row.title || ''
      }));
      galleryIndex = 0;
    }else{
      galleryPhotos = []; galleryIndex = 0;
    }
  }catch(e){
    galleryPhotos = []; galleryIndex = 0;
  }
}

function renderGallery(){
  [...$gallery.querySelectorAll('img.__ph')].forEach(el => el.remove());
  $thumbs.innerHTML = '';

  if(!galleryPhotos.length){
    $galleryEmpty.style.display = 'flex';
    $prev.style.display = 'none';
    $next.style.display = 'none';
    return;
  }
  $galleryEmpty.style.display = 'none';
  if (galleryPhotos.length > 1) { $prev.style.display = ''; $next.style.display = ''; }
  else { $prev.style.display = 'none'; $next.style.display = 'none'; }

  galleryPhotos.forEach((ph, idx)=>{
    const img = document.createElement('img');
    img.className='__ph'+(idx===galleryIndex?' active':'');
    img.dataset.idx = idx;
    img.alt = ph.title || '';
    img.src = ph.url;
    $gallery.appendChild(img);

    const th = document.createElement('img');
    th.src = ph.url;
    th.className = (idx===galleryIndex?'active':'');
    th.title = ph.title || '';
    th.addEventListener('click', ()=>{
      galleryIndex = idx;
      renderGallery();
    });
    $thumbs.appendChild(th);
  });
}
$prev.addEventListener('click', ()=>{
  if(!galleryPhotos.length) return;
  galleryIndex = (galleryIndex - 1 + galleryPhotos.length) % galleryPhotos.length;
  renderGallery();
});
$next.addEventListener('click', ()=>{
  if(!galleryPhotos.length) return;
  galleryIndex = (galleryIndex + 1) % galleryPhotos.length;
  renderGallery();
});

// 上传按钮逻辑
$uploadBtn.addEventListener('click', ()=>{
  if(!selectedPoint) return;
  if(!IS_LOGGED_IN){ window.location.href = '/login.php'; return; }
  $file.click();
});
$file.addEventListener('change', async (e)=>{
  if(!selectedPoint) return;
  const files = Array.from(e.target.files || []).filter(f=>f && f.size > 0);
  if(!files.length) return;

  const fd = new FormData();
  fd.append('ajax','upload');
  fd.append('airport_id', String(selectedPoint.id));
  files.forEach(f => fd.append('photos[]', f));

  $uploadBtn.disabled = true;
  $uploadBtn.textContent = '上传中...';

  try{
    const res = await fetch(API_URL, { method:'POST', body: fd, credentials:'include' });
    const data = await res.json();
    if(!data || !data.success){ throw new Error((data && data.message) || '上传失败'); }
    await loadAirportPhotos(selectedPoint.id);
    renderGallery();
    Modal.open({ title:'上传成功', html:`共上传 ${data.count || 0} 张图片。<br>提示：审核通过后才会对所有用户展示。` });
  }catch(err){
    Modal.open({ title:'上传失败', html:String(err.message || err) });
  }finally{
    $uploadBtn.disabled = false;
    $uploadBtn.textContent = '上传照片';
    $file.value = '';
  }
});

// ============== 机位列表（侧栏内，仅当前机场） ==============
const $spotList = document.getElementById('sb-spot-list');
const $spotEmpty = document.getElementById('sb-spot-empty');
const $btnAddSpot = document.getElementById('sb-add-spot');

let spotList = []; // {id, name, lat, lng}
async function loadAirportSpots(airportId){
  try{
    const res = await fetch(`${API_URL}?ajax=spot_list&airport_id=${encodeURIComponent(airportId)}`, {credentials:'include'});
    const data = await res.json();
    if(data && data.success){
      spotList = (data.list || []).filter(s => typeof s.lat==='number' && typeof s.lng==='number');
    }else{
      spotList = [];
    }
  }catch(e){
    spotList = [];
  }
}

// 根据类型/可达性等转中文
function mapTypeLabel(t){
  const m = { fence:'围栏边', hill:'小山坡', garage:'停车场', rooftop:'楼顶', park:'公园', roadside:'路边', terminal:'航站楼', other:'其他' };
  return m[t] || t || '-';
}
function mapAccessLabel(a){
  const m = { public:'公共', ticketed:'需购票', restricted:'限制', paid:'付费' };
  return m[a] || a || '-';
}
function mapSubjectLabel(s){
  const m = { landing:'进近/降落', takeoff:'起飞', taxi:'滑行', apron:'停机坪', air:'空中', mixed:'混合' };
  return m[s] || s || '-';
}
function monthLabel(csv){
  if(!csv) return '-';
  const arr = String(csv).split(',').map(s=>s.trim()).filter(Boolean);
  return arr.length ? arr.map(x=>x+'月').join('、') : '-';
}

async function openSpotDetail(spotId){
  try{
    const res = await fetch(`${API_URL}?ajax=spot_detail&spot_id=${encodeURIComponent(spotId)}`, { credentials:'include' });
    const data = await res.json();
    if(!data || !data.success){ throw new Error((data && data.message) || '加载失败'); }

    const d = data.detail;

    // 地图先聚焦
    if (typeof d.lat === 'number' && typeof d.lng === 'number') {
      focusOnLatLng(d.lat, d.lng, true);
    }

    // 组图（若有）
    let photosHTML = '';
    if (Array.isArray(d.photos) && d.photos.length) {
      const big = d.photos[0];
      const thumbs = d.photos.map((ph, i)=>`
        <img src="${ph.url}" alt="" data-idx="${i}" style="width:72px;height:54px;object-fit:cover;border-radius:6px;border:2px solid ${i===0?'#1677ff':'transparent'};cursor:pointer;">
      `).join('');
      photosHTML = `
        <div style="margin-top:10px;">
          <div style="position:relative;width:100%;aspect-ratio:4/3;background:#fafafa;border:1px solid #eee;border-radius:10px;overflow:hidden;">
            <img id="spotdlg-main" src="${big.url}" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
          </div>
          <div id="spotdlg-thumbs" style="display:flex;gap:8px;overflow:auto;margin-top:8px;">${thumbs}</div>
          <div style="color:#666;font-size:12px;margin-top:6px;">共 ${d.photos.length} 张</div>
        </div>
      `;
    } else {
      photosHTML = `<div style="margin-top:10px;color:#888;">暂无机位配图</div>`;
    }

    const html = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div style="grid-column:1/-1;font-weight:700;">机位信息</div>
        <div><div style="color:#666">标题</div><div style="font-weight:700">${d.title || '-'}</div></div>
        <div><div style="color:#666">海拔</div><div style="font-weight:700">${(d.elevation_m ?? '-') + (d.elevation_m!=null?' m':'')}</div></div>
        <div><div style="color:#666">类型</div><div style="font-weight:700">${mapTypeLabel(d.type)}</div></div>
        <div><div style="color:#666">可达性</div><div style="font-weight:700">${mapAccessLabel(d.accessibility)}</div></div>
        <div><div style="color:#666">光线</div>
          <div style="font-weight:700">${[
            d.light?.morning? '早':'',
            d.light?.noon? '中':'',
            d.light?.evening? '晚':''
          ].filter(Boolean).join(' / ') || '-'}</div>
        </div>
        <div><div style="color:#666">最佳月份</div><div style="font-weight:700">${monthLabel(d.best_months)}</div></div>
        <div><div style="color:#666">焦段</div><div style="font-weight:700">${(d.focal_min||'-')} - ${(d.focal_max||'-')} mm</div></div>
        <div><div style="color:#666">主体</div><div style="font-weight:700">${mapSubjectLabel(d.subject)}</div></div>

        <div style="grid-column:1/-1;font-weight:700;margin-top:4px;">坐标</div>
        <div style="grid-column:1/-1;font-family:ui-monospace,monospace;">
          <code>${fmt6(d.lat)}, ${fmt6(d.lng)}</code>
        </div>

        <div style="grid-column:1/-1;font-weight:700;margin-top:4px;">提示</div>
        <div style="grid-column:1/-1;color:#333;line-height:1.7">
          ${d.parking_note ? ('<div>停车：'+d.parking_note+'</div>') : ''}
          ${d.transport_note ? ('<div>交通：'+d.transport_note+'</div>') : ''}
          ${d.safety_note ? ('<div>安全：'+d.safety_note+'</div>') : ''}
          ${d.tips ? ('<div>其他：'+d.tips+'</div>') : ''}
          ${(!d.parking_note && !d.transport_note && !d.safety_note && !d.tips) ? '<div style="color:#888">无</div>' : ''}
        </div>

        <div style="grid-column:1/-1;font-weight:700;margin-top:4px;">机位照片</div>
        <div style="grid-column:1/-1;">${photosHTML}</div>
      </div>
    `;

Modal.open({
  title: d.title || '机位详情',
  html,
  actions: [
    // ① 修改信息（需登录）
    {
      text: '补充照片',
      keepOpen: true, // ★ 保持当前 Modal 不关闭
      onClick: () => {
        if (!IS_LOGGED_IN) {
          // 未登录，跳登录页
          window.location.href = '/login.php';
          return;
        }
        openSpotPhotoUploader(d.id); // 打开修改表单（你已实现）
      }
    },

    // ② 复制坐标
    {
      text: '复制坐标',
      onClick: async () => {
        const text = `${fmt6(d.lat)},${fmt6(d.lng)}`;
        try {
          await navigator.clipboard.writeText(text);
          Modal.open({
            title: '已复制坐标',
            html: `<div>坐标已复制：</div><div style="margin-top:8px;font-family:monospace;">${text}</div>`,
            actions: [{ text: '好的', primary: true }]
          });
        } catch {
          Modal.open({
            title: '复制失败',
            html: `<div>请手动复制：</div><div style="margin-top:8px;font-family:monospace;">${text}</div>`,
            actions: [{ text: '好的', primary: true }]
          });
        }
      }
    },

    // ③ 复制机位链接（用于分享）
    {
      text: '复制链接',
      onClick: async () => {
        // 生成分享链接：同页 + 参数
        const link = `${location.origin}${location.pathname}?spot_id=${encodeURIComponent(d.id)}#spot`;
        try {
          await navigator.clipboard.writeText(link);
          Modal.open({
            title: '已复制链接',
            html: `<div>分享链接已复制：</div><div style="margin-top:8px;word-break:break-all;font-family:monospace;">${link}</div>`,
            actions: [{ text: '好的', primary: true }]
          });
        } catch {
          Modal.open({
            title: '复制失败',
            html: `<div>请手动复制：</div><div style="margin-top:8px;word-break:break-all;font-family:monospace;">${link}</div>`,
            actions: [{ text: '好的', primary: true }]
          });
        }
      }
    },

    // ④ 居中放大
    {
      text: '居中放大',
      primary: true,
      onClick: () => focusOnLatLng(d.lat, d.lng, true)
    }
  ],
  showClose: true,
  dismissible: true
});


    // 缩略图点击切换大图
    const thumbsWrap = document.getElementById('spotdlg-thumbs');
    if (thumbsWrap) {
      thumbsWrap.addEventListener('click', (ev)=>{
        const t = ev.target;
        if (t && t.tagName === 'IMG' && t.dataset.idx) {
          const idx = parseInt(t.dataset.idx, 10);
          const main = document.getElementById('spotdlg-main');
          if (d.photos[idx] && main) {
            main.src = d.photos[idx].url;
            // 更新选中边框
            [...thumbsWrap.children].forEach(img=>img.style.borderColor='transparent');
            t.style.borderColor = '#1677ff';
          }
        }
      });
    }

  }catch(err){
    Modal.open({ title:'加载失败', html: String(err.message || err) });
  }
}

function renderSpotList(){
  $spotList.innerHTML = '';
  if(!spotList.length){
    $spotEmpty.style.display = 'block';
    $spotList.appendChild($spotEmpty);
    return;
  }
  $spotEmpty.style.display = 'none';

  spotList.forEach(s=>{
    const item = document.createElement('div');
    item.className = 'sb-spot-item';

    const meta = document.createElement('div');
    meta.className = 'meta';
    const nm = document.createElement('div');
    nm.className = 'name';
    nm.textContent = s.name || '未命名机位';
    const cd = document.createElement('div');
    cd.className = 'coord';
    cd.textContent = `${fmt6(s.lat)}, ${fmt6(s.lng)}`;
    meta.appendChild(nm);
    meta.appendChild(cd);

    const go = document.createElement('button');
    go.className = 'go';
    go.textContent = '查看详情';
    go.title = '查看机位详情并居中放大';
    go.addEventListener('click', ()=>{
      // 地图居中放大 + 详情弹窗
      if (typeof s.lat === 'number' && typeof s.lng === 'number') {
        focusOnLatLng(s.lat, s.lng, true);
      }
      openSpotDetail(s.id);
    });


    item.appendChild(meta);
    item.appendChild(go);
    $spotList.appendChild(item);
  });
}

// ============== 添加机位（表单） ==============
$btnAddSpot.addEventListener('click', ()=>{
  if(!selectedPoint) return;
  if(!IS_LOGGED_IN){ window.location.href='/login.php'; return; }
  openSpotForm();
});

// ====== 提交机位表单 ======
async function submitSpotForm(){
  const $form = document.getElementById('spot-form');
  if(!$form) return;

  if (!$form.reportValidity()) return;

  const title = $form.title.value.trim();
  let   lat   = $form.lat.value.trim();
  let   lon   = $form.lon.value.trim();
  const files = document.getElementById('spot-photos').files;

  if(!title){ showFormError('请填写机位标题'); return; }
  if(!lat || !lon || isNaN(Number(lat)) || isNaN(Number(lon))){
    showFormError('请填写正确的坐标（数字）'); return;
  }
  if(!files || files.length < 1){
    showFormError('至少上传 1 张机位照片'); return;
  }

  // —— 兜底：与机场点“几乎重合”则自动上移 DEFAULT_OFFSET_M 米 ——
  let latNum = Number(lat), lonNum = Number(lon);
  if (selectedPoint && isFinite(latNum) && isFinite(lonNum)) {
    const dLat = latNum - Number(selectedPoint.lat);
    const dLon = lonNum - Number(selectedPoint.lng);
    const meanLat = (latNum + Number(selectedPoint.lat)) / 2 * Math.PI/180;
    const approxMeters = Math.sqrt(
      Math.pow(dLat * 111111, 2) + Math.pow(dLon * 111111 * Math.cos(meanLat), 2)
    );
    if (approxMeters <= NEAR_DUP_THRESHOLD_M) {
      latNum = offsetLatNorth(latNum, DEFAULT_OFFSET_M);
      // 同步回输入框让用户看到真实提交值（也便于再次编辑）
      $form.lat.value = latNum.toFixed(6);
      $form.lon.value = lonNum.toFixed(6);
    }
  }

  disableSpotPicker();

  const fd = new FormData($form);
  // 强制使用修正后的数值
  fd.set('lat', latNum.toFixed(6));
  fd.set('lon', lonNum.toFixed(6));
  fd.append('ajax','spot_create');

  const actionsEl = document.getElementById('modal-actions');
  const btns = [...actionsEl.querySelectorAll('button')];
  btns.forEach(b => b.disabled = true);
  const loading = document.createElement('div');
  loading.textContent = '提交中，请稍候…';
  loading.style.marginRight = 'auto';
  loading.style.display = 'flex';
  loading.style.alignItems = 'center';
  actionsEl.prepend(loading);

  try{
    const res = await fetch(API_URL, { method:'POST', body: fd, credentials:'include' });
    const data = await res.json();
    if(!data || !data.success){ throw new Error((data && data.message) || '提交失败'); }

    await loadAirportSpots(selectedPoint.id);
    renderSpotList();

    Modal.open({
      title: '提交成功',
      html: `机位已提交审核（ID: ${data.spot_id}）。<br>成功上传照片 ${data.photo_count} 张。`,
      actions: [{ text:'好的', primary:true }],
      showClose: false,
      dismissible: true
    });

    try { await refreshAllSpotMarkers(); } catch(_) {}

  }catch(err){
    showFormError('提交失败：' + String(err.message || err));
    btns.forEach(b => b.disabled = false);
  } finally {
    loading.remove();
  }
}


function openSpotPhotoUploader(spotId){
  const html = `
    <form id="spot-photo-form">
      <input type="hidden" name="spot_id" value="${spotId}">
      <div style="display:flex;flex-direction:column;gap:10px;">
        <div style="font-weight:700;">给该机位补充照片</div>
        <input id="spot-photo-files" name="photos[]" type="file" accept="image/*" multiple required
               style="padding:8px;border:1px solid #e5e6eb;border-radius:8px;">
        <div style="font-size:12px;color:#666;">
          支持 JPG/PNG/WebP，单张 ≤ 20MB。提交后需审核通过才会展示。
        </div>
      </div>
    </form>
  `;
  Modal.open({
    title: '补充机位照片',
    html,
    actions: [
      { text: '取消' },
      { text: '上传', primary: true, keepOpen: true, onClick: async ()=>{
          const $form = document.getElementById('spot-photo-form');
          const files = document.getElementById('spot-photo-files').files;
          if (!files || files.length < 1) {
            Modal.open({ title:'请先选择图片', html:'至少选择 1 张图片。', actions:[{text:'好的',primary:true}] });
            return;
          }

          const fd = new FormData($form);
          fd.append('ajax', 'spot_photo_upload');

          const actionsEl = document.getElementById('modal-actions');
          const btns = [...actionsEl.querySelectorAll('button')];
          btns.forEach(b => b.disabled = true);
          const loading = document.createElement('div');
          loading.textContent = '上传中…';
          loading.style.marginRight='auto';
          actionsEl.prepend(loading);

          try{
            const res = await fetch(API_URL, { method:'POST', body: fd, credentials:'include' });
            const data = await res.json();
            if (!data || !data.success) throw new Error((data && data.message) || '上传失败');

            Modal.open({
              title: '上传成功',
              html: `成功上传 ${data.count || 0} 张图片。`,
              actions: [{ text: '好的', primary: true }]
            });

            // 重新打开详情，刷新图集
            setTimeout(()=> openSpotDetail(spotId), 0);

          }catch(err){
            Modal.open({ title:'上传失败', html: String(err.message || err) });
          }finally{
            loading.remove();
            btns.forEach(b => b.disabled = false);
          }
      } }
    ],
    showClose: true,
    dismissible: true
  });
}

// ============== 底图与提供商 ==============
function setBaseLayer(mode){
  baseMode=mode;
  if(currentProvider==='osm')setBaseLayerOSM(mode);
  if(currentProvider==='amap')setBaseLayerAMap(mode);
}
function setBaseLayerOSM(mode){
  if(!osmMap)return;
  if(!osmBaseStd) osmBaseStd=L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:20,attribution:'&copy; OpenStreetMap'});
  if(!osmBaseSat) osmBaseSat=L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:19,attribution:'Imagery &copy; Esri'});
  if(osmMap.hasLayer(osmBaseStd))osmMap.removeLayer(osmBaseStd);
  if(osmMap.hasLayer(osmBaseSat))osmMap.removeLayer(osmBaseSat);
  (mode==='sat'?osmBaseSat:osmBaseStd).addTo(osmMap);
}
function setBaseLayerAMap(mode){
  if(!amap)return;
  if(!amapTileSat) amapTileSat=new AMap.TileLayer.Satellite();
  if(!amapTileRoad) amapTileRoad=new AMap.TileLayer.RoadNet();
  amapTileSat.setMap(null);amapTileRoad.setMap(null);
  if(mode==='sat'){amapTileSat.setMap(amap);amapTileRoad.setMap(amap);}
}

// ============== 机场标记渲染（保留原有） ==============
let amapMarkers=[];
// === 替换：renderAmapMarkers()（只改了 marker 的 click 回调，其他保持不变）===
function renderAmapMarkers(){
  if(!amap || !Array.isArray(POINTS) || POINTS.length===0) return;
  amapMarkers.forEach(m=>m.setMap(null));
  amapMarkers.length=0;

  POINTS.forEach(p=>{
    if(typeof p.lng!=='number' || typeof p.lat!=='number') return;

    const content = '<div class="mk mk-airport" title="'+ (p.name||'') +'"></div>';
    const m = new AMap.Marker({
      position:[p.lng,p.lat],
      anchor:'bottom-center',
      offset: new AMap.Pixel(0, 0),
      content
    });
    m.setExtData(p);
    m.on('click', ()=>{
      clickingMarker = true;               // ★ 防止误触发地图 click 关闭侧栏
      openSidebarWithData(p);
      focusOnPoint(p, true);
      setTimeout(()=>{ clickingMarker = false; }, 0);
    });
    m.setMap(amap);
    amapMarkers.push(m);
  });

  if(!selectedPoint && amapMarkers.length>0){
    amap.setFitView(amapMarkers, true, [60, 60, 60, 60], 11);
  }else if(selectedPoint){
    focusOnPoint(selectedPoint, false);
  }
}


let osmMarkers=[];
function renderLeafletMarkers(){
  if(!osmMap || !Array.isArray(POINTS) || POINTS.length===0) return;
  osmMarkers.forEach(m=>osmMap.removeLayer(m));
  osmMarkers.length=0;

  const bounds=[];
  const airportIcon = L.divIcon({ className:'mk mk-airport', iconSize:[14,14] });

  POINTS.forEach(p=>{
    if(typeof p.lng!=='number' || typeof p.lat!=='number') return;
    const marker=L.marker([p.lat,p.lng], { icon: airportIcon, title: p.name || '' });
    marker.on('click', ()=>{
      openSidebarWithData(p);
      focusOnPoint(p, true);
    });
    marker.addTo(osmMap);
    osmMarkers.push(marker);
    bounds.push([p.lat,p.lng]);
  });

  if(!selectedPoint && bounds.length>0){
    osmMap.fitBounds(bounds,{padding:[60,60]});
  }else if(selectedPoint){
    focusOnPoint(selectedPoint, false);
  }
}

// ============== 机位标记（全图，橙色） ==============
let allSpots = [];          // {id, airport_id, name, lat, lng}
let amapSpotMarkers = [];   // AMap markers
let osmSpotMarkers  = [];   // Leaflet markers

async function loadAllSpots(){
  try{
    const res = await fetch(`${API_URL}?ajax=spot_all`, { credentials:'include' });
    const data = await res.json();
    if(data && data.success){
      allSpots = (data.list || []).filter(s => typeof s.lat==='number' && typeof s.lng==='number');
    }else{
      allSpots = [];
    }
  }catch(e){
    allSpots = [];
  }
}

function clearSpotMarkers(){
  if (amapSpotMarkers.length && amap){
    amapSpotMarkers.forEach(m=>m.setMap(null));
  }
  amapSpotMarkers = [];
  if (osmSpotMarkers.length && osmMap){
    osmSpotMarkers.forEach(m=>osmMap.removeLayer(m));
  }
  osmSpotMarkers = [];
}

function renderAmapSpotMarkers(){
  if(!amap || !allSpots.length) return;
  clearSpotMarkers();

  allSpots.forEach(s=>{
    const content = '<div class="mk mk-spot" title="'+ (s.name||'机位') +'"></div>';
    const m = new AMap.Marker({
      position:[s.lng, s.lat],
      anchor:'bottom-center',
      offset: new AMap.Pixel(0, 0),
      content
    });
    m.setExtData(s);
    m.on('click', ()=>{
      if (typeof s.lat === 'number' && typeof s.lng === 'number') {
        focusOnLatLng(s.lat, s.lng, true);
      }
      openSpotDetail(s.id);
    });
      m.setMap(amap);
      amapSpotMarkers.push(m);
  });
}

function renderLeafletSpotMarkers(){
  if(!osmMap || !allSpots.length) return;
  clearSpotMarkers();

  const spotIcon = L.divIcon({ className:'mk mk-spot', iconSize:[14,14] });
  allSpots.forEach(s=>{
    const marker = L.marker([s.lat, s.lng], { icon: spotIcon, title: s.name || '机位' });
    marker.on('click', ()=>{
      if (typeof s.lat === 'number' && typeof s.lng === 'number') {
        focusOnLatLng(s.lat, s.lng, true);
      }
      openSpotDetail(s.id);
    });
    marker.addTo(osmMap);
    osmSpotMarkers.push(marker);
  });
}

async function refreshAllSpotMarkers(){
  await loadAllSpots();
  if (currentProvider==='osm') renderLeafletSpotMarkers();
  if (currentProvider==='amap') renderAmapSpotMarkers();
}

// ============== 聚焦到机场/机位 ==============
function focusOnPoint(p, withZoom){
  if(currentProvider==='osm' && osmMap){
    const z = Math.max(osmMap.getZoom(), 11);
    osmMap.setView([p.lat, p.lng], withZoom ? Math.max(z, 13) : z, { animate:true });
    setTimeout(()=>{ osmMap.panBy([ getSidebarWidth()/2, 0 ], { animate:true }); }, 250);
  }else if(currentProvider==='amap' && amap){
    const z = Math.max(amap.getZoom(), 11);
    if(withZoom){
      amap.setZoomAndCenter(Math.max(z, 13), [p.lng,p.lat], true, 250);
    }else{
      amap.setCenter([p.lng,p.lat], true);
    }
    setTimeout(()=>{ amap.panBy(getSidebarWidth()/2, 0); }, 260);
  }
}
function focusOnLatLng(lat, lng, withZoom){
  if(currentProvider==='osm' && osmMap){
    const z = Math.max(osmMap.getZoom(), 12);
    osmMap.setView([lat, lng], withZoom ? Math.max(z, 15) : z, { animate:true });
    setTimeout(()=>{ osmMap.panBy([ getSidebarWidth()/2, 0 ], { animate:true }); }, 250);
  }else if(currentProvider==='amap' && amap){
    const z = Math.max(amap.getZoom(), 12);
    if(withZoom){
      amap.setZoomAndCenter(Math.max(z, 15), [lng,lat], true, 250);
    }else{
      amap.setCenter([lng,lat], true);
    }
    setTimeout(()=>{ amap.panBy(getSidebarWidth()/2, 0); }, 260);
  }
}

// ============== 初始化提供商 ==============
// === 替换：initAMap()（新增了 amap.on('click', ...) 关闭侧栏）===
async function initAMap(){
  await loadScript(`https://webapi.amap.com/maps?v=2.0&key=${encodeURIComponent(BOOT.amapKey)}`);
  amap=new AMap.Map('map',{zoom:11,center:center,viewMode:'3D',zooms:[3,20],animateEnable:true});
  currentProvider='amap';updateProviderText();
  setBaseLayer(baseMode);

  // ★ 空白处点击关闭侧栏（选点模式与标记点击时不关闭）
  amap.on('click', ()=>{
    if (window.spotPickState?.active) return;
    if (clickingMarker) return;
    closeSidebar();
  });

  renderAmapMarkers();
  await refreshAllSpotMarkers(); // 加载机位图层
  window.addEventListener('resize', ()=>{ amap && amap.resize(); });
}

async function initOSM(){
  await loadScript('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');
  await loadCSS('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');

  // 使用官方 CDN 图标（不再用默认蓝针，改自定义 divIcon）
  L.Icon.Default.mergeOptions({
    iconRetinaUrl: 'marker-icon-2x.png',
    iconUrl:       'marker-icon.png',
    shadowUrl:     'marker-shadow.png'
  });

  osmMap = L.map('map', { center: [center[1], center[0]], zoom: 11 });

  currentProvider = 'osm';
  updateProviderText();
  setBaseLayer(baseMode);
  renderLeafletMarkers();
  await refreshAllSpotMarkers(); // 加载机位图层

  setTimeout(()=>{ osmMap.invalidateSize(); }, 0);
  osmMap.on('click', ()=>{ closeSidebar(); });
  window.addEventListener('resize', ()=>{ if (osmMap) osmMap.invalidateSize(); });
}

function destroyAMap(){ if(amap){ amap.destroy && amap.destroy(); amap=null; } document.getElementById('map').innerHTML=''; }
function destroyOSM(){ if(osmMap){ osmMap.remove(); osmMap=null; } document.getElementById('map').innerHTML=''; }

async function switchTo(p){
  if (window.spotPickState && window.spotPickState.active) disableSpotPicker();
  if(p===currentProvider) return;
  const wasOpen = $sidebar.classList.contains('open');

  if(p==='amap'){
    destroyOSM();
    try{ await initAMap(); }catch(e){ await switchTo('osm'); return; }
  }else{
    destroyAMap();
    try{ await initOSM(); }catch(e){ await switchTo('amap'); return; }
  }

  if(selectedPoint && wasOpen){
    openSidebarWithData(selectedPoint);
    focusOnPoint(selectedPoint, false);
  }else if(!wasOpen){
    closeSidebar();
  }
}

// ============== 启动 ==============
(async function(){
  if(BOOT.isChinaMainland){
    try{ await initAMap(); }catch(e){ await initOSM(); }
  }else{
    try{ await initOSM(); }catch(e){ await initAMap(); }
  }
})();

// ============== UI 事件 ==============
document.getElementById('btn-amap').onclick=()=>switchTo('amap');
document.getElementById('btn-osm').onclick=()=>switchTo('osm');
document.getElementById('btn-base-normal').onclick=()=>setBaseLayer('normal');
document.getElementById('btn-base-sat').onclick=()=>setBaseLayer('sat');

function bindMapClickToClose(){
  if(currentProvider==='osm' && osmMap){
    osmMap.on('click', ()=>{ closeSidebar(); });
  }else if(currentProvider==='amap' && amap){
    amap.on('click', ()=>{ closeSidebar(); });
  }
}
</script>
</body>
</html>

<?php
/**
 * profile.php 个人主页（重写+增强版，含未通过原因弹窗）
 * 能力：
 * - 最近机位贡献（IATA/ICAO、状态徽章、未通过“查看原因”、可编辑 pending/rejected）
 * - 我的机场照片（全部状态、删除、未通过“查看原因”）
 * - 我的机位照片（按我的机位筛选、删除、未通过“查看原因”；优先 p.review_note，其次 s.review_note）
 * - 分页与每页数量选择
 * - 安全：仅本人可删，编辑 approved 禁止，编辑后回到 pending
 *
 * 依赖：
 * - includes/db.php 提供 get_pdo('db_aeroview') 和 get_pdo('db_spot')
 * - 可能存在的列：airport_photos.review_note、spot_photos.review_note、spots.review_note
 */

session_start();
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

require_once __DIR__ . '/includes/db.php';

$u       = $_SESSION['user'];
$uid     = (int)($u['id'] ?? 0);
$pdoUser = get_pdo('db_aeroview'); // users, airport_photos, airport_data
try { $pdoSpot = get_pdo('db_spot'); } catch (Throwable $e) { $pdoSpot = null; }

/** ========= 工具函数 ========= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function qv($a,$k,$d){ return isset($a[$k]) && $a[$k]!=='' ? $a[$k] : $d; }
function pg_int($v,$min=1){ $n=(int)$v; return $n>=$min?$n:$min; }
function table_exists(PDO $pdo,$t){
    try{
        $stmt=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=?");
        $stmt->execute([$t]); return (bool)$stmt->fetchColumn();
    }catch(Throwable $e){ return false; }
}
function column_exists(PDO $pdo,$table,$column){
    try{
        $stmt=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $stmt->execute([$table,$column]); return (bool)$stmt->fetchColumn();
    }catch(Throwable $e){ return false; }
}
function render_pager($page,$total,$pagesize,$prefix){
    $pages=max(1,(int)ceil($total/$pagesize)); if($pages<=1) return '';
    $qs=$_GET; $mk=function($p,$lab,$dis=false) use (&$qs,$prefix){
        $qs[$prefix.'page']=$p; $u='?'.http_build_query($qs);
        return $dis ? "<span class=\"pg disabled\">".h($lab)."</span>" : "<a class=\"pg\" href=\"{$u}\">".h($lab)."</a>";
    };
    $h='<div class="pager">';
    $h.=$mk(max(1,$page-1),'上一页',$page<=1);
    $st=max(1,$page-2); $ed=min($pages,$page+2);
    if($st>1){ $h.=$mk(1,'1'); if($st>2)$h.='<span class="gap">…</span>'; }
    for($i=$st;$i<=$ed;$i++){ $h.=$i==$page?"<span class=\"pg current\">{$i}</span>":$mk($i,(string)$i); }
    if($ed<$pages){ if($ed<$pages-1)$h.='<span class="gap">…</span>'; $h.=$mk($pages,(string)$pages); }
    $h.=$mk(min($pages,$page+1),'下一页', $page >= $pages).'</div>';
    return $h;
}
function map_status_from_int($n){
    $n=(int)$n;
    if($n===1) return ['ok','已通过'];
    if($n===2) return ['no','未通过'];
    return ['wait','待审核'];
}
function map_status_from_str($s){
    $x=strtolower(trim((string)$s));
    if($x==='approved') return ['ok','已通过'];
    if($x==='rejected') return ['no','未通过'];
    return ['wait','待审核'];
}
function flash_redirect($ok, $msg){
    $qs = $_GET;
    $qs['ok']  = $ok ? 1 : 0;
    $qs['msg'] = $msg;
    header('Location: ?'.http_build_query($qs));
    exit;
}

/** ========= 顶部信息：用户名 & 注册时间 ========= */
$regTime = $u['created_at'] ?? '';
try{
    $st=$pdoUser->prepare("SELECT username, created_at FROM users WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    if($row=$st->fetch(PDO::FETCH_ASSOC)){
        $u['username']=$row['username'] ?? ($u['username']??'');
        $regTime=$row['created_at'] ?? $regTime;
    }
}catch(Throwable $e){}

/** ========= 处理 POST 动作（编辑/删除） ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // 编辑机位：仅 pending 或 rejected
    if ($action === 'spot_update' && $pdoSpot) {
        $spot_id = (int)($_POST['spot_id'] ?? 0);
        if ($spot_id <= 0) flash_redirect(0, '无效的机位ID');

        try{
            $st = $pdoSpot->prepare("SELECT * FROM spots WHERE id=? LIMIT 1");
            $st->execute([$spot_id]);
            $spot = $st->fetch(PDO::FETCH_ASSOC);
            if (!$spot) flash_redirect(0,'机位不存在');
            if ((int)$spot['uploader'] !== $uid) flash_redirect(0,'没有权限编辑该机位');
            $status = strtolower((string)($spot['review_status'] ?? ''));
            if ($status === 'approved') flash_redirect(0,'已通过的机位不可修改');

            $fields = [
                'title'          => trim((string)($_POST['title'] ?? '')),
                'lat'            => number_format((float)($_POST['lat'] ?? $spot['lat']), 6, '.', ''),
                'lon'            => number_format((float)($_POST['lon'] ?? $spot['lon']), 6, '.', ''),
                'elevation_m'    => ($_POST['elevation_m'] !== '' ? (int)$_POST['elevation_m'] : null),
                'type'           => (string)($_POST['type'] ?? $spot['type']),
                'accessibility'  => (string)($_POST['accessibility'] ?? $spot['accessibility']),
                'light_morning'  => isset($_POST['light_morning']) ? 1 : 0,
                'light_noon'     => isset($_POST['light_noon']) ? 1 : 0,
                'light_evening'  => isset($_POST['light_evening']) ? 1 : 0,
                'focal_min'      => (int)($_POST['focal_min'] ?? $spot['focal_min']),
                'focal_max'      => (int)($_POST['focal_max'] ?? $spot['focal_max']),
                'subject'        => (string)($_POST['subject'] ?? $spot['subject']),
                'parking_note'   => (trim((string)($_POST['parking_note'] ?? '')) ?: null),
                'transport_note' => (trim((string)($_POST['transport_note'] ?? '')) ?: null),
                'safety_note'    => (trim((string)($_POST['safety_note'] ?? '')) ?: null),
                'tips'           => (trim((string)($_POST['tips'] ?? '')) ?: null),
            ];
            $bmArr = isset($_POST['best_months']) ? (array)$_POST['best_months'] : [];
            $bmArr = array_values(array_filter(array_map('intval', $bmArr), fn($m)=>$m>=1 && $m<=12));
            $fields['best_months'] = $bmArr ? implode(',', $bmArr) : null;

            if ($fields['title'] === '') flash_redirect(0,'请填写机位标题');
            if (!is_numeric($fields['lat']) || !is_numeric($fields['lon'])) flash_redirect(0,'坐标格式不正确');

            $resetToPending = true;
            $review_status  = $resetToPending ? 'pending' : $status;

            $sql = "UPDATE spots SET
                      title=:title, lat=:lat, lon=:lon, elevation_m=:elevation_m,
                      type=:type, accessibility=:accessibility,
                      light_morning=:light_morning, light_noon=:light_noon, light_evening=:light_evening,
                      best_months=:best_months,
                      focal_min=:focal_min, focal_max=:focal_max, subject=:subject,
                      parking_note=:parking_note, transport_note=:transport_note, safety_note=:safety_note, tips=:tips,
                      review_status=:review_status, reviewed_by=NULL, reviewed_at=NULL, review_note=NULL,
                      updated_by=:updated_by, updated_at=NOW()
                    WHERE id=:id AND uploader=:uploader";
            $stmt = $pdoSpot->prepare($sql);
            $ok = $stmt->execute([
                ':title'          => $fields['title'],
                ':lat'            => $fields['lat'],
                ':lon'            => $fields['lon'],
                ':elevation_m'    => $fields['elevation_m'],
                ':type'           => $fields['type'],
                ':accessibility'  => $fields['accessibility'],
                ':light_morning'  => $fields['light_morning'],
                ':light_noon'     => $fields['light_noon'],
                ':light_evening'  => $fields['light_evening'],
                ':best_months'    => $fields['best_months'],
                ':focal_min'      => $fields['focal_min'],
                ':focal_max'      => $fields['focal_max'],
                ':subject'        => $fields['subject'],
                ':parking_note'   => $fields['parking_note'],
                ':transport_note' => $fields['transport_note'],
                ':safety_note'    => $fields['safety_note'],
                ':tips'           => $fields['tips'],
                ':review_status'  => $review_status,
                ':updated_by'     => $uid,
                ':id'             => $spot_id,
                ':uploader'       => $uid,
            ]);
            if (!$ok) flash_redirect(0,'保存失败');

            $qs = $_GET; $qs['ok']=1; $qs['msg']='机位已保存并进入待审核';
            unset($qs['edit']);
            header('Location: ?'.http_build_query($qs)); exit;
        }catch(Throwable $e){
            flash_redirect(0,'保存异常：'.$e->getMessage());
        }
    }

    // 删除机场照片（仅本人）
    if ($action === 'delete_airport_photo') {
        $pid = (int)($_POST['photo_id'] ?? 0);
        if ($pid<=0) flash_redirect(0,'无效的照片ID');
        try{
            $st = $pdoUser->prepare("SELECT id, filepath FROM airport_photos WHERE id=? AND user_id=? LIMIT 1");
            $st->execute([$pid, $uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) flash_redirect(0,'照片不存在或无权限');

            $del = $pdoUser->prepare("DELETE FROM airport_photos WHERE id=? AND user_id=?");
            $del->execute([$pid, $uid]);

            $rel = (string)$row['filepath'];
            $abs = __DIR__ . '/' . ltrim($rel, '/');
            if (is_file($abs)) @unlink($abs);

            flash_redirect(1,'机场照片已删除');
        }catch(Throwable $e){
            flash_redirect(0,'删除失败：'.$e->getMessage());
        }
    }

    // 删除机位照片（仅限属于我的机位）
    if ($action === 'delete_spot_photo' && $pdoSpot) {
        $pid = (int)($_POST['photo_id'] ?? 0);
        if ($pid<=0) flash_redirect(0,'无效的照片ID');
        try{
            $sql = "SELECT p.id, p.photo_url
                    FROM spot_photos p
                    JOIN spots s ON s.id = p.spot_id
                    WHERE p.id=? AND s.uploader=?";
            $st  = $pdoSpot->prepare($sql);
            $st->execute([$pid, $uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) flash_redirect(0,'照片不存在或无权限');

            $del = $pdoSpot->prepare("DELETE FROM spot_photos WHERE id=?");
            $del->execute([$pid]);

            $rel = (string)$row['photo_url'];
            $abs = __DIR__ . '/' . ltrim($rel, '/');
            if (is_file($abs)) @unlink($abs);

            flash_redirect(1,'机位照片已删除');
        }catch(Throwable $e){
            flash_redirect(0,'删除失败：'.$e->getMessage());
        }
    }

    flash_redirect(0,'未知操作');
}

/** ========= 列存在性检测 ========= */
$ap_has_review_note   = column_exists($pdoUser, 'airport_photos', 'review_note');
$sp_has_review_note   = $pdoSpot ? column_exists($pdoSpot, 'spot_photos', 'review_note') : false;
$spot_has_review_note = $pdoSpot ? column_exists($pdoSpot, 'spots', 'review_note') : false;

/** ========= 机位统计（我的） ========= */
$stats=['ap_total'=>0,'ap_approved'=>0,'spots_total'=>0,'spots_approved'=>0];
try{
    $st=$pdoUser->prepare("SELECT COUNT(*) FROM airport_photos WHERE user_id=?");
    $st->execute([$uid]); $stats['ap_total']=(int)$st->fetchColumn();

    $st=$pdoUser->prepare("SELECT COUNT(*) FROM airport_photos WHERE user_id=? AND approved=1");
    $st->execute([$uid]); $stats['ap_approved']=(int)$st->fetchColumn();
}catch(Throwable $e){}
if($pdoSpot){
    try{
        $st=$pdoSpot->prepare("SELECT COUNT(*) FROM spots WHERE uploader=?");
        $st->execute([$uid]); $stats['spots_total']=(int)$st->fetchColumn();

        $st=$pdoSpot->prepare("SELECT COUNT(*) FROM spots WHERE uploader=? AND review_status='approved'");
        $st->execute([$uid]); $stats['spots_approved']=(int)$st->fetchColumn();
    }catch(Throwable $e){}
}

/** ========= 最近机位贡献（含 IATA/ICAO；不跨库 JOIN；分页） ========= */
$spots_ps   =(int)qv($_GET,'s_ps',10); if(!in_array($spots_ps,[10,20,50,100],true)) $spots_ps=10;
$spots_page = pg_int(qv($_GET,'s_page',1));
$spots_total=0; $spots_rows=[];
if($pdoSpot){
    try{
        $st=$pdoSpot->prepare("SELECT COUNT(*) FROM spots WHERE uploader=?");
        $st->execute([$uid]); $spots_total=(int)$st->fetchColumn();

        if($spots_total>0){
            $off=max(0,($spots_page-1)*$spots_ps);
            $select_note = $spot_has_review_note ? ", review_note" : "";
            $sql="
                SELECT id, title, airport_id, review_status, created_at,
                       lat, lon, elevation_m, type, accessibility,
                       light_morning, light_noon, light_evening,
                       focal_min, focal_max, subject,
                       parking_note, transport_note, safety_note, tips
                       {$select_note}
                FROM spots
                WHERE uploader=?
                ORDER BY created_at DESC, id DESC
                LIMIT {$spots_ps} OFFSET {$off}
            ";
            $st=$pdoSpot->prepare($sql);
            $st->execute([$uid]);
            $spots_rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 批量机场码
            $aidSet = array_values(array_unique(array_filter(array_map(fn($r)=>(int)($r['airport_id']??0), $spots_rows))));
            $map = [];
            if($aidSet){
                $in = implode(',', array_map('intval', $aidSet));
                $sqlA = "SELECT id, iata_code, icao_code FROM airport_data WHERE id IN ($in)";
                $rsA = $pdoUser->query($sqlA)->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rsA as $a) {
                    $map[(int)$a['id']] = [
                        'iata' => (string)($a['iata_code'] ?? ''),
                        'icao' => (string)($a['icao_code'] ?? ''),
                    ];
                }
            }
            foreach($spots_rows as &$r){
                $aid=(int)($r['airport_id']??0);
                $r['airport_iata'] = $map[$aid]['iata'] ?? '';
                $r['airport_icao'] = $map[$aid]['icao'] ?? '';
            }
            unset($r);
        }
    }catch(Throwable $e){}
}

/** ========= 我的机场照片（全部状态；分页） ========= */
$ap_ps   =(int)qv($_GET,'ap_ps',12); if(!in_array($ap_ps,[12,24,48,96],true)) $ap_ps=12;
$ap_page = pg_int(qv($_GET,'ap_page',1));
$ap_total=0; $ap_rows=[];
try{
    $st=$pdoUser->prepare("SELECT COUNT(*) FROM airport_photos WHERE user_id=?");
    $st->execute([$uid]); $ap_total=(int)$st->fetchColumn();

    if($ap_total>0){
        $off=max(0,($ap_page-1)*$ap_ps);
        $select_note = $ap_has_review_note ? ", review_note" : "";
        $sql="
            SELECT id, filepath, approved, created_at
            {$select_note}
            FROM airport_photos
            WHERE user_id=?
            ORDER BY created_at DESC, id DESC
            LIMIT {$ap_ps} OFFSET {$off}
        ";
        $st=$pdoUser->prepare($sql);
        $st->execute([$uid]);
        $ap_rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}catch(Throwable $e){}

/** ========= 我的机位照片（按我的机位筛选；分页） ========= */
$has_spot_photos = $pdoSpot && table_exists($pdoSpot,'spot_photos');
$sp_ps    =(int)qv($_GET,'p_ps',12); if(!in_array($sp_ps,[12,24,48,96],true)) $sp_ps=12;
$sp_page  = pg_int(qv($_GET,'p_page',1));
$sp_total = 0; $sp_rows=[];
if($pdoSpot && $has_spot_photos){
    try{
        $st=$pdoSpot->prepare("
            SELECT COUNT(*)
            FROM spot_photos p
            JOIN spots s ON s.id = p.spot_id
            WHERE s.uploader = ?
        ");
        $st->execute([$uid]); $sp_total=(int)$st->fetchColumn();

        if($sp_total>0){
            $off=max(0,($sp_page-1)*$sp_ps);
            $select_p_note = $sp_has_review_note   ? "p.review_note AS p_review_note," : "";
            $select_s_note = $spot_has_review_note ? "s.review_note AS s_review_note," : "";
            $sql="
                SELECT p.id, p.spot_id, p.photo_url, p.created_at,
                       p.approved,
                       s.review_status,
                       {$select_p_note}
                       {$select_s_note}
                       1 as _dummy
                FROM spot_photos p
                JOIN spots s ON s.id = p.spot_id
                WHERE s.uploader = ?
                ORDER BY p.created_at DESC, p.id DESC
                LIMIT {$sp_ps} OFFSET {$off}
            ";
            $st=$pdoSpot->prepare($sql);
            $st->execute([$uid]);
            $sp_rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }catch(Throwable $e){}
}

/** ========= 若进入编辑模式（GET edit=spot_id）预取机位 ========= */
$edit_spot = null;
$edit_id = (int)($_GET['edit'] ?? 0);
if ($edit_id>0 && $pdoSpot) {
    try{
        $st = $pdoSpot->prepare("SELECT * FROM spots WHERE id=? AND uploader=? LIMIT 1");
        $st->execute([$edit_id, $uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (strtolower((string)$row['review_status']) !== 'approved') {
                $edit_spot = $row;
            }
        }
    }catch(Throwable $e){}
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>个人主页</title>
<style>
:root {
  --bg: #f6f8fb;
  --card: #fff;
  --text: #111827;
  --muted: #6b7280;
  --primary: #1677ff;
  --primary-light: #e6f0ff;
  --ok: #10b981;
  --ok-light: #ecfdf5;
  --warn: #f59e0b;
  --warn-light: #fffbeb;
  --no: #ef4444;
  --no-light: #fef2f2;
  --border: #e5e7eb;
  --radius: 12px;
  --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
  --transition: all 0.2s ease;
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif;
  background: var(--bg);
  color: var(--text);
  line-height: 1.6;
  padding: 0;
  margin: 0;
}

/* 布局容器 */
.container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 20px;
}

/* 卡片样式 */
.card {
  background: var(--card);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  padding: 20px;
  margin-bottom: 20px;
  transition: var(--transition);
}

.card:hover {
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
}

/* 头部区域 */
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--card);
  padding: 20px;
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  margin: 20px 0;
}

.header-content {
  flex: 1;
}

.h-title {
  font-weight: 600;
  font-size: 1.5rem;
  margin-bottom: 4px;
}

.h-sub {
  color: var(--muted);
  font-size: 0.9rem;
}

/* 按钮样式 */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 8px 16px;
  border-radius: var(--radius);
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
  border: 1px solid transparent;
  text-decoration: none;
  white-space: nowrap;
}

.btn-primary {
  background: var(--primary);
  color: white;
  border-color: var(--primary);
}

.btn-primary:hover {
  background: #1266d4;
  border-color: #1266d4;
}

.btn-outline {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text);
}

.btn-outline:hover {
  background: #f8fafc;
}

.btn-danger {
  background: #fee2e2;
  color: #b91c1c;
  border-color: #fecaca;
}

.btn-danger:hover {
  background: #fecaca;
}

.btn-sm {
  padding: 6px 12px;
  font-size: 0.85rem;
}

/* 徽章样式 */
.badge {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 500;
}

.badge-ok {
  background: rgba(16, 185, 129, 0.12);
  color: #059669;
}

.badge-wait {
  background: rgba(245, 158, 11, 0.12);
  color: #b45309;
}

.badge-no {
  background: rgba(239, 68, 68, 0.12);
  color: #b91c1c;
}

/* 表格样式 */
.table-responsive {
  overflow-x: auto;
}

.table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.9rem;
}

.table th {
  text-align: left;
  padding: 12px 16px;
  font-weight: 600;
  color: var(--text);
  background: #f8fafc;
  border-bottom: 1px solid var(--border);
}

.table td {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}

.table tr:last-child td {
  border-bottom: none;
}

/* 图片网格 */
.photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
}

.photo-item {
  position: relative;
  border-radius: var(--radius);
  overflow: hidden;
  background: #f1f5f9;
  aspect-ratio: 4/3;
}

.photo-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.3s ease;
}

.photo-item:hover img {
  transform: scale(1.05);
}

.photo-status {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 4px 8px;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  background: rgba(255, 255, 255, 0.9);
}

.photo-actions {
  position: absolute;
  top: 8px;
  right: 8px;
  display: flex;
  gap: 8px;
}

/* 分页器 */
.pagination {
  display: flex;
  gap: 8px;
  margin-top: 20px;
  flex-wrap: wrap;
}

.page-item {
  display: inline-flex;
}

.page-link {
  padding: 8px 12px;
  border-radius: var(--radius);
  border: 1px solid var(--border);
  background: white;
  color: var(--text);
  text-decoration: none;
  transition: var(--transition);
}

.page-link:hover {
  background: #f8fafc;
}

.page-link.active {
  background: var(--primary);
  color: white;
  border-color: var(--primary);
}

.page-link.disabled {
  opacity: 0.6;
  pointer-events: none;
}

/* 表单元素 */
.form-group {
  margin-bottom: 16px;
}

.form-label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
}

.form-control {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  font-size: 0.9rem;
  transition: var(--transition);
}

.form-control:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(22, 119, 255, 0.1);
}

.select-control {
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 10px center;
  background-size: 16px;
  padding-right: 32px;
}

.checkbox-group {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
}

.checkbox-input {
  width: 16px;
  height: 16px;
  border: 1px solid var(--border);
  border-radius: 4px;
  appearance: none;
  margin: 0;
  cursor: pointer;
  position: relative;
}

.checkbox-input:checked {
  background-color: var(--primary);
  border-color: var(--primary);
}

.checkbox-input:checked::after {
  content: '';
  position: absolute;
  left: 5px;
  top: 2px;
  width: 4px;
  height: 8px;
  border: solid white;
  border-width: 0 2px 2px 0;
  transform: rotate(45deg);
}

/* 弹窗 */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  opacity: 0;
  visibility: hidden;
  transition: all 0.3s ease;
}

.modal-overlay.active {
  opacity: 1;
  visibility: visible;
}

.modal-content {
  background: white;
  border-radius: var(--radius);
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
  width: 100%;
  max-width: 500px;
  max-height: 90vh;
  overflow-y: auto;
  transform: translateY(20px);
  transition: all 0.3s ease;
}

.modal-overlay.active .modal-content {
  transform: translateY(0);
}

.modal-header {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
}

.modal-title {
  font-size: 1.1rem;
  font-weight: 600;
}

.modal-body {
  padding: 20px;
}

.modal-footer {
  padding: 16px 20px;
  border-top: 1px solid var(--border);
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.reason-content {
  white-space: pre-wrap;
  background: #f8fafc;
  border: 1px dashed var(--border);
  border-radius: var(--radius);
  padding: 16px;
  line-height: 1.6;
}

/* 响应式调整 */
@media (max-width: 768px) {
  .header {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  
  .photo-grid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  }
}

@media (max-width: 480px) {
  .container {
    padding: 0 12px;
  }
  
  .photo-grid {
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  }
  
  .table td, .table th {
    padding: 8px 12px;
  }
}
</style>
</head>
<body>
<div class="container">
  
  <!-- 顶部消息提示 -->
  <?php if(isset($_GET['msg'])): ?>
    <div class="card alert <?= ((int)($_GET['ok'] ?? 0)) ? 'alert-ok':'alert-no' ?>">
      <?= h((string)$_GET['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- 顶部头部区域 -->
  <header class="header">
    <div class="header-content">
      <h1 class="h-title">你好，<?= h($u['username'] ?? '未命名用户') ?></h1>
      <p class="h-sub">注册时间：<?= h($regTime ?: '—') ?></p>
    </div>
    <a href="/" class="btn btn-primary">返回首页</a>
  </header>

  <!-- 统计卡片 -->
  <div class="card">
    <div class="kpis">
      <div class="kpi">
        <div class="kpi-number"><?= (int)$stats['ap_total'] ?></div>
        <div class="kpi-title">机场照片（总）</div>
      </div>
      <div class="kpi">
        <div class="kpi-number"><?= (int)$stats['ap_approved'] ?></div>
        <div class="kpi-title">机场照片（通过）</div>
      </div>
      <div class="kpi">
        <div class="kpi-number"><?= (int)$stats['spots_total'] ?></div>
        <div class="kpi-title">机位提交（总）</div>
      </div>
      <div class="kpi">
        <div class="kpi-number"><?= (int)$stats['spots_approved'] ?></div>
        <div class="kpi-title">机位提交（通过）</div>
      </div>
    </div>
  </div>

  <div class="grid">
    <!-- 最近机位贡献 -->
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header">
          <h2 class="card-title">最近机位贡献</h2>
          <div class="card-actions">
            <form method="get" class="form-inline">
              <label class="form-label">每页
                <select name="s_ps" class="form-control select-control" onchange="this.form.submit()">
                  <?php foreach([10,20,50,100] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $opt==$spots_ps?'selected':'' ?>><?= $opt ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <input type="hidden" name="s_page" value="<?= (int)$spots_page ?>">
            </form>
          </div>
        </div>
        
        <div class="card-body">
          <?php if(!$pdoSpot): ?>
            <div class="alert alert-info">未连接到机位库（db_spot）。</div>
          <?php elseif(!$spots_total): ?>
            <div class="alert alert-info">暂无机位贡献。</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>标题</th>
                    <th>机场</th>
                    <th>状态</th>
                    <th>提交时间</th>
                    <th>操作</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach($spots_rows as $r):
                    [$cls,$txt]=map_status_from_str($r['review_status'] ?? '');
                    $title = $r['title'] ?: ('机位 #'.$r['id']);
                    $apt = trim(($r['airport_iata']??'').' / '.($r['airport_icao']??''));
                    $canEdit = strtolower((string)$r['review_status']) !== 'approved';
                    $reject_reason = ($spot_has_review_note ? (string)($r['review_note'] ?? '') : '');
                ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h($title) ?></td>
                    <td><?= h($apt ?: '—') ?></td>
                    <td><span class="badge badge-<?= $cls ?>"><?= $txt ?></span></td>
                    <td><?= h($r['created_at'] ?? '') ?></td>
                    <td>
                      <div class="btn-group">
                        <?php if($canEdit): ?>
                          <a href="?<?= http_build_query(array_merge($_GET, ['edit'=>(int)$r['id']])) ?>" class="btn btn-sm btn-outline">编辑</a>
                        <?php else: ?>
                          <span class="text-muted">已通过</span>
                        <?php endif; ?>

                        <?php if($cls==='no'): ?>
                          <button type="button"
                                  class="btn btn-sm btn-outline"
                                  onclick="showReasonModal('机位未通过原因', <?= htmlspecialchars(json_encode($reject_reason ?: ''), ENT_QUOTES, 'UTF-8') ?>)">
                            查看原因
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            
            <div class="pagination">
              <?= render_pager($spots_page,$spots_total,$spots_ps,'s_') ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- 编辑机位表单 -->
      <?php if($edit_spot): ?>
        <?php [$cls,$txt] = map_status_from_str($edit_spot['review_status'] ?? ''); ?>
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">编辑机位 #<?= (int)$edit_spot['id'] ?>（当前：<span class="badge badge-<?= $cls ?>"><?= $txt ?></span>）</h2>
            <a href="?<?= h(http_build_query(array_diff_key($_GET, ['edit'=>1]))) ?>" class="btn btn-sm btn-outline">取消</a>
          </div>
          
          <div class="card-body">
            <form method="post">
              <input type="hidden" name="action" value="spot_update">
              <input type="hidden" name="spot_id" value="<?= (int)$edit_spot['id'] ?>">
              
              <div class="row">
                <div class="col-md-6">
                  <div class="form-group">
                    <label class="form-label">标题</label>
                    <input type="text" name="title" class="form-control" required value="<?= h($edit_spot['title'] ?? '') ?>">
                  </div>
                </div>
                
                <div class="col-md-6">
                  <div class="form-group">
                    <label class="form-label">类型</label>
                    <select name="type" class="form-control select-control">
                      <?php foreach(['fence'=>'围栏边','hill'=>'小山坡','garage'=>'停车场','rooftop'=>'楼顶','park'=>'公园','roadside'=>'路边','terminal'=>'航站楼','other'=>'其他'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= ($edit_spot['type']??'other')===$k?'selected':'' ?>><?= $v ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
              
              <!-- 其他表单字段... -->
              
              <div class="form-footer">
                <button type="submit" class="btn btn-primary">保存（提交后将进入待审核）</button>
                <a href="?<?= h(http_build_query(array_diff_key($_GET, ['edit'=>1]))) ?>" class="btn btn-outline">取消</a>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- 我的机场照片 -->
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">
          <h2 class="card-title">我的机场照片</h2>
          <form method="get" class="form-inline">
            <label class="form-label">每页
              <select name="ap_ps" class="form-control select-control" onchange="this.form.submit()">
                <?php foreach([12,24,48,96] as $opt): ?>
                  <option value="<?= $opt ?>" <?= $opt==$ap_ps?'selected':'' ?>><?= $opt ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <input type="hidden" name="ap_page" value="<?= (int)$ap_page ?>">
          </form>
        </div>
        
        <div class="card-body">
          <?php if(!$ap_total): ?>
            <div class="alert alert-info">暂无机场照片。</div>
          <?php else: ?>
            <div class="photo-grid">
              <?php foreach($ap_rows as $p):
                  $url='/'.ltrim((string)$p['filepath'],'/');
                  [$cls,$txt]=map_status_from_int($p['approved'] ?? 0);
                  $formId = 'del_ap_'.$p['id'];
                  $reason = $ap_has_review_note ? (string)($p['review_note'] ?? '') : '';
              ?>
                <div class="photo-item">
                  <img src="<?= h($url) ?>" alt="机场照片 #<?= (int)$p['id'] ?>">
                  <span class="photo-status badge-<?= $cls ?>"><?= $txt ?></span>
                  
                  <div class="photo-actions">
                    <form id="<?= $formId ?>" method="post">
                      <input type="hidden" name="action" value="delete_airport_photo">
                      <input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
                      <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete('<?= $formId ?>','确定删除这张机场照片吗？')">删除</button>
                    </form>
                  </div>
                  
                  <?php if($cls==='no'): ?>
                    <button type="button" 
                            class="btn btn-sm btn-outline" 
                            style="position:absolute;left:8px;bottom:8px"
                            onclick="showReasonModal('机场照片未通过原因', <?= htmlspecialchars(json_encode($reason ?: ''), ENT_QUOTES, 'UTF-8') ?>)">
                      查看原因
                    </button>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            
            <div class="pagination">
              <?= render_pager($ap_page,$ap_total,$ap_ps,'ap_') ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- 我的机位照片 -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">我的机位照片</h2>
      <form method="get" class="form-inline">
        <label class="form-label">每页
          <select name="p_ps" class="form-control select-control" onchange="this.form.submit()">
            <?php foreach([12,24,48,96] as $opt): ?>
              <option value="<?= $opt ?>" <?= $opt==$sp_ps?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <input type="hidden" name="p_page" value="<?= (int)$sp_page ?>">
      </form>
    </div>
    
    <div class="card-body">
      <?php if(!$pdoSpot): ?>
        <div class="alert alert-info">未连接到机位库（db_spot）。</div>
      <?php elseif(!$has_spot_photos): ?>
        <div class="alert alert-info">未检测到 spot_photos 表</div>
      <?php elseif(!$sp_total): ?>
        <div class="alert alert-info">暂无机位照片。</div>
      <?php else: ?>
        <div class="photo-grid">
          <?php foreach($sp_rows as $p):
              $url='/'.ltrim((string)$p['photo_url'],'/');
              if (array_key_exists('approved', $p) && $p['approved'] !== null && $p['approved'] !== '') {
                  [$cls,$txt] = map_status_from_int((int)$p['approved']);
              } else {
                  [$cls,$txt] = map_status_from_str($p['review_status'] ?? '');
              }
              $formId = 'del_sp_'.$p['id'];
              $reason = '';
              if ($sp_has_review_note && isset($p['p_review_note'])) $reason = (string)$p['p_review_note'];
              elseif ($spot_has_review_note && isset($p['s_review_note'])) $reason = (string)$p['s_review_note'];
          ?>
            <div class="photo-item">
              <img src="<?= h($url) ?>" alt="机位照片 #<?= (int)$p['id'] ?>">
              <span class="photo-status badge-<?= $cls ?>"><?= $txt ?></span>
              
              <div class="photo-actions">
                <form id="<?= $formId ?>" method="post">
                  <input type="hidden" name="action" value="delete_spot_photo">
                  <input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
                  <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete('<?= $formId ?>','确定删除这张机位照片吗？')">删除</button>
                </form>
              </div>
              
              <?php if($cls==='no'): ?>
                <button type="button"
                        class="btn btn-sm btn-outline"
                        style="position:absolute;left:8px;bottom:8px"
                        onclick="showReasonModal('机位照片未通过原因', <?= htmlspecialchars(json_encode($reason ?: ''), ENT_QUOTES, 'UTF-8') ?>)">
                  查看原因
                </button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        
        <div class="pagination">
          <?= render_pager($sp_page,$sp_total,$sp_ps,'p_') ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- 原因弹窗 -->
<div id="reasonModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="reasonModalTitle" class="modal-title">未通过原因</h3>
      <button type="button" class="btn btn-sm btn-outline" onclick="hideReasonModal()">×</button>
    </div>
    <div class="modal-body">
      <div id="reasonModalContent" class="reason-content">未提供具体原因。</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-primary" onclick="hideReasonModal()">我知道了</button>
    </div>
  </div>
</div>

<script>
// 显示原因弹窗
function showReasonModal(title, content) {
  const modal = document.getElementById('reasonModal');
  document.getElementById('reasonModalTitle').textContent = title || '未通过原因';
  document.getElementById('reasonModalContent').textContent = content || '未提供具体原因。';
  modal.classList.add('active');
  document.body.style.overflow = 'hidden';
}

// 隐藏原因弹窗
function hideReasonModal() {
  const modal = document.getElementById('reasonModal');
  modal.classList.remove('active');
  document.body.style.overflow = '';
}

// 确认删除
function confirmDelete(formId, message) {
  if (confirm(message)) {
    document.getElementById(formId).submit();
  }
}

// ESC键关闭弹窗
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    hideReasonModal();
  }
});
</script>
</body>
</html>
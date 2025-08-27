<?php
// /includes/guards.php
require_once __DIR__ . '/db.php';

/**
 * 机位地图门槛：
 * - is_admin=1 直接豁免
 * - 所在权限组(permission_group_id) 对应 permission_groups.bypass_map_gate=1 也豁免
 * - 否则统计主站 photos 表 approved=1 的数量，>= MIN_APPROVED(默认50) 才放行
 *
 * 依赖表：
 *  - users(id, is_admin, permission_group_id)
 *  - permission_groups(id, bypass_map_gate)
 *  - photos(user_id, approved)
 */
function can_access_spot_map(): bool
{
    if (empty($_SESSION['user']['id'])) return false;

    $MIN_APPROVED = 50; // 固定阈值（无需 site_settings）

    $uid = (int)$_SESSION['user']['id'];


    // 会话缓存：仅对“允许访问”的结果命中（ok=true），避免把拒绝状态卡住
    $now = time();
    if (isset($_SESSION['guard_cache']['map_gate']) &&
        ($_SESSION['guard_cache']['map_gate']['uid'] ?? 0) === $uid) {
        $c = $_SESSION['guard_cache']['map_gate'];
        $fresh = $now - (int)($c['ts'] ?? 0) < 600;
        if ($fresh && !empty($c['ok'])) { // 只缓存放行
            return true;
        }
        // 若缓存是拒绝或过期，继续走数据库校验
    }


    try {
        $pdo = get_pdo('db_aeroview');

        // 1) 取用户 is_admin 与 permission_group_id
        $u = $pdo->prepare("SELECT is_admin, permission_group_id FROM users WHERE id=? LIMIT 1");
        $u->execute([$uid]);
        $row = $u->fetch(PDO::FETCH_ASSOC);
        $isAdmin = (int)($row['is_admin'] ?? 0) === 1;
        $pgid    = (int)($row['permission_group_id'] ?? 0);

        // 管理员豁免
        if ($isAdmin) {
            $_SESSION['guard_cache']['map_gate'] = [
                'uid'=>$uid,'ok'=>true,'ts'=>$now,'cnt'=>null,'min'=>$MIN_APPROVED,'bypass'=>true,'by'=>'is_admin'
            ];
            return true;
        }

        // 2) 组豁免（permission_groups.bypass_map_gate=1）
        $bypass = false;
        if ($pgid > 0) {
            $g = $pdo->prepare("SELECT bypass_map_gate FROM permission_groups WHERE id=? LIMIT 1");
            try {
                $g->execute([$pgid]);
                $bypass = (int)($g->fetchColumn() ?? 0) === 1;
            } catch (Throwable $e) {
                // 旧库没有该列时，当作不豁免
                $bypass = false;
            }
        }
        if ($bypass) {
            $_SESSION['guard_cache']['map_gate'] = [
                'uid'=>$uid,'ok'=>true,'ts'=>$now,'cnt'=>null,'min'=>$MIN_APPROVED,'bypass'=>true,'by'=>'group'
            ];
            return true;
        }

        // 3) 统计已通过照片数
        $q = $pdo->prepare("SELECT COUNT(*) FROM photos WHERE user_id=? AND approved=1");
        $q->execute([$uid]);
        $cnt = (int)($q->fetchColumn() ?: 0);

        $ok = ($cnt >= $MIN_APPROVED);
        $_SESSION['guard_cache']['map_gate'] = [
            'uid'=>$uid,'ok'=>$ok,'ts'=>$now,'cnt'=>$cnt,'min'=>$MIN_APPROVED,'bypass'=>false
        ];
        return $ok;

    } catch (Throwable $e) {
        // 出错保守拦截
        return false;
    }
}

/** 辅助：拿到上次校验信息用于页面提示 */
function get_spot_map_gate_info(): array
{
    $c = $_SESSION['guard_cache']['map_gate'] ?? [];
    return [
        'count'  => isset($c['cnt']) ? (int)$c['cnt'] : null,
        'min'    => isset($c['min']) ? (int)$c['min'] : 50,
        'bypass' => (bool)($c['bypass'] ?? false),
        'by'     => $c['by'] ?? null, // 'is_admin' | 'group' | null
    ];
}

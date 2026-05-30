<?php
// 调酒互动统一接口：收藏 / 点赞 / 评分 / 评论 / 状态查询
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cocktail_helper.php';
$conn->set_charset('utf8mb4');
ensure_cocktail_tables($conn);
header('Content-Type: application/json; charset=UTF-8');

function jout($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$cid = (int)($_POST['cid'] ?? $_GET['cid'] ?? 0);
$uid = (int)($_SESSION['user_id'] ?? 0);

if ($cid <= 0) jout(['ok'=>false, 'msg'=>'参数错误']);

// 状态查询：统计 + 评论 + (登录则带我的状态)，无需登录
if ($action === 'state') {
    jout([
        'ok'       => true,
        'logged'   => $uid > 0,
        'stats'    => cocktail_interactions($conn, $cid, $uid),
        'comments' => array_map(function($c){
            return ['id'=>(int)$c['id'], 'content'=>$c['content'], 'username'=>$c['username'],
                    'avatar'=>$c['avatar'] ?: 'default.png', 'uid'=>(int)$c['uid'], 'created_at'=>$c['created_at'],
                    'like_count'=>(int)$c['like_count'], 'dislike_count'=>(int)$c['dislike_count'], 'my_react'=>$c['my_react']];
        }, cocktail_comments_list($conn, $cid, $uid)),
    ]);
}

// 以下操作需登录
if (!$uid) jout(['ok'=>false, 'need_login'=>true, 'msg'=>'请先登录']);

if ($action === 'fav') {
    if ($conn->query("SELECT 1 FROM cocktail_favorites WHERE user_id=$uid AND cocktail_id=$cid")->num_rows) {
        $conn->query("DELETE FROM cocktail_favorites WHERE user_id=$uid AND cocktail_id=$cid"); $faved = false;
    } else {
        $conn->query("INSERT INTO cocktail_favorites (user_id,cocktail_id) VALUES ($uid,$cid)"); $faved = true;
    }
    $st = cocktail_interactions($conn, $cid, $uid);
    jout(['ok'=>true, 'faved'=>$faved, 'fav_count'=>$st['fav_count']]);
}

if ($action === 'like') {
    if ($conn->query("SELECT 1 FROM cocktail_likes WHERE user_id=$uid AND cocktail_id=$cid")->num_rows) {
        $conn->query("DELETE FROM cocktail_likes WHERE user_id=$uid AND cocktail_id=$cid"); $liked = false;
    } else {
        $conn->query("INSERT INTO cocktail_likes (user_id,cocktail_id) VALUES ($uid,$cid)"); $liked = true;
    }
    $st = cocktail_interactions($conn, $cid, $uid);
    jout(['ok'=>true, 'liked'=>$liked, 'like_count'=>$st['like_count']]);
}

if ($action === 'rate') {
    $score = (int)($_POST['score'] ?? 0);
    if ($score < 1 || $score > 5) jout(['ok'=>false, 'msg'=>'评分需 1-5']);
    $conn->query("INSERT INTO cocktail_ratings (user_id,cocktail_id,score) VALUES ($uid,$cid,$score) ON DUPLICATE KEY UPDATE score=$score");
    $st = cocktail_interactions($conn, $cid, $uid);
    jout(['ok'=>true, 'rate_avg'=>$st['rate_avg'], 'rate_count'=>$st['rate_count'], 'my_score'=>$score]);
}

if ($action === 'comment') {
    $content = trim($_POST['content'] ?? '');
    if ($content === '') jout(['ok'=>false, 'msg'=>'评论内容为空']);
    if (mb_strlen($content) > 1000) $content = mb_substr($content, 0, 1000);
    $c = $conn->real_escape_string($content);
    $conn->query("INSERT INTO cocktail_comments (cocktail_id,user_id,content) VALUES ($cid,$uid,'$c')");
    jout(['ok'=>true]);
}

if ($action === 'comment_delete') {
    $coid = (int)($_POST['coid'] ?? 0);
    // 仅作者本人或管理员可删
    $role = $_SESSION['role'] ?? 'user';
    $own = $conn->query("SELECT user_id FROM cocktail_comments WHERE id=$coid")->fetch_assoc();
    if ($own && ((int)$own['user_id'] === $uid || in_array($role, ['admin','owner'], true))) {
        $conn->query("DELETE FROM cocktail_comments WHERE id=$coid");
        jout(['ok'=>true]);
    }
    jout(['ok'=>false, 'msg'=>'无权删除']);
}

if ($action === 'comment_react') {
    $coid = (int)($_POST['coid'] ?? 0);
    $type = (($_POST['rtype'] ?? '') === 'dislike') ? 'dislike' : 'like';
    if ($coid <= 0) jout(['ok'=>false, 'msg'=>'参数错误']);
    $cur = $conn->query("SELECT type FROM cocktail_comment_reactions WHERE user_id=$uid AND comment_id=$coid LIMIT 1")->fetch_assoc();
    if ($cur && $cur['type'] === $type) {
        $conn->query("DELETE FROM cocktail_comment_reactions WHERE user_id=$uid AND comment_id=$coid"); // 再点同一个=取消
    } else {
        $conn->query("INSERT INTO cocktail_comment_reactions (user_id,comment_id,type) VALUES ($uid,$coid,'$type') ON DUPLICATE KEY UPDATE type='$type'");
    }
    $lc = (int)($conn->query("SELECT COUNT(*) c FROM cocktail_comment_reactions WHERE comment_id=$coid AND type='like'")->fetch_assoc()['c'] ?? 0);
    $dc = (int)($conn->query("SELECT COUNT(*) c FROM cocktail_comment_reactions WHERE comment_id=$coid AND type='dislike'")->fetch_assoc()['c'] ?? 0);
    $mr = $conn->query("SELECT type FROM cocktail_comment_reactions WHERE user_id=$uid AND comment_id=$coid LIMIT 1")->fetch_assoc();
    jout(['ok'=>true, 'like_count'=>$lc, 'dislike_count'=>$dc, 'my_react'=>$mr['type'] ?? null]);
}

jout(['ok'=>false, 'msg'=>'未知操作']);

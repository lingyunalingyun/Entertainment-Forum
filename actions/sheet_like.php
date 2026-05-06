<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/exp_helper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'msg' => '请先登录']);
    exit;
}

ensure_song_sheets_table($conn);

$my_id = (int)$_SESSION['user_id'];
$sid   = (int)($_POST['id'] ?? 0);
if ($sid <= 0) {
    echo json_encode(['status' => 'error', 'msg' => '参数错误']);
    exit;
}

$r = $conn->query("SELECT id FROM song_sheets WHERE id=$sid");
if (!$r || $r->num_rows === 0) {
    echo json_encode(['status' => 'error', 'msg' => '曲谱不存在']);
    exit;
}

$chk = $conn->query("SELECT 1 FROM sheet_likes WHERE sheet_id=$sid AND user_id=$my_id");
if ($chk && $chk->num_rows > 0) {
    $conn->query("DELETE FROM sheet_likes WHERE sheet_id=$sid AND user_id=$my_id");
    $conn->query("UPDATE song_sheets SET likes = GREATEST(likes - 1, 0) WHERE id=$sid");
    $active = false;
} else {
    $conn->query("INSERT INTO sheet_likes (sheet_id, user_id) VALUES ($sid, $my_id)");
    $conn->query("UPDATE song_sheets SET likes = likes + 1 WHERE id=$sid");
    $active = true;
}

$cnt_r = $conn->query("SELECT likes FROM song_sheets WHERE id=$sid");
$new_count = $cnt_r ? (int)$cnt_r->fetch_assoc()['likes'] : 0;

echo json_encode(['status' => 'ok', 'active' => $active, 'count' => $new_count]);

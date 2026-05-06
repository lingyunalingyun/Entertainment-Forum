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

$my_id   = (int)$_SESSION['user_id'];
$my_role = $_SESSION['role'] ?? 'user';
$is_admin = in_array($my_role, ['admin', 'owner'], true);

$sid = (int)($_POST['id'] ?? 0);
if ($sid <= 0) {
    echo json_encode(['status' => 'error', 'msg' => '参数错误']);
    exit;
}

$r = $conn->query("SELECT uploader_id, file_path FROM song_sheets WHERE id=$sid");
if (!$r || $r->num_rows === 0) {
    echo json_encode(['status' => 'error', 'msg' => '曲谱不存在']);
    exit;
}
$row = $r->fetch_assoc();

if ((int)$row['uploader_id'] !== $my_id && !$is_admin) {
    echo json_encode(['status' => 'error', 'msg' => '没有权限']);
    exit;
}

// 硬删：先 unlink 文件，再删 DB（含点赞）
$abs = __DIR__ . '/../' . $row['file_path'];
if (is_file($abs)) @unlink($abs);

$conn->query("DELETE FROM sheet_likes WHERE sheet_id=$sid");
$conn->query("DELETE FROM song_sheets WHERE id=$sid");

echo json_encode(['status' => 'ok', 'msg' => '已删除']);

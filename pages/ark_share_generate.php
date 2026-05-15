<?php
// 生成静态分享 HTML：share/{mid}_arknights.html
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ark_pipeline.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok'=>false, 'msg'=>'请先登录']); exit;
}
$uid = (int)$_SESSION['user_id'];

// 拿 mid（先 session 后 users 表）
$mid = $_SESSION['mid'] ?? '';
if (!$mid) {
    $r = $conn->query("SELECT mid FROM users WHERE id={$uid} LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) $mid = $row['mid'] ?? '';
}
if (!$mid) {
    echo json_encode(['ok'=>false, 'msg'=>'用户没有 mid 字段，无法生成分享文件']); exit;
}
// 防路径穿越
if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $mid)) {
    echo json_encode(['ok'=>false, 'msg'=>'mid 格式不合法']); exit;
}

// 拿最近一次分析（不限 TTL，永久找最近一条）
$conn->query("SET NAMES utf8mb4");
$r = $conn->query("SELECT snapshot_data, deepseek_advice, created_at FROM ark_queries WHERE user_id={$uid} ORDER BY created_at DESC LIMIT 1");
if (!$r || !($row = $r->fetch_assoc())) {
    echo json_encode(['ok'=>false, 'msg'=>'你还没生成过分析，请先绑定鹰角通行证']); exit;
}

$snapshot = json_decode((string)$row['snapshot_data'], true);
$advice   = (string)$row['deepseek_advice'];
$share_time = (string)$row['created_at'];
if (!is_array($snapshot)) {
    echo json_encode(['ok'=>false, 'msg'=>'snapshot 数据损坏']); exit;
}

$html = ark_render_share_html($snapshot, $advice, $share_time);

// 写文件
$share_dir = __DIR__ . '/../share';
if (!is_dir($share_dir)) {
    @mkdir($share_dir, 0755, true);
}
$file_name = $mid . '_arknights.html';
$file_path = $share_dir . '/' . $file_name;
$bytes = @file_put_contents($file_path, $html);
if ($bytes === false) {
    echo json_encode(['ok'=>false, 'msg'=>'写入分享文件失败（检查 share 目录权限）']); exit;
}

// 拼绝对 URL
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/');
$url = "{$scheme}://{$host}{$base}/share/{$file_name}";

echo json_encode(['ok'=>true, 'url'=>$url, 'bytes'=>$bytes], JSON_UNESCAPED_UNICODE);

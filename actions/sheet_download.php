<?php
// 公开匿名下载，downloads++ 后流式返回文件
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/exp_helper.php';

ensure_song_sheets_table($conn);

$sid = (int)($_GET['id'] ?? 0);
if ($sid <= 0) {
    http_response_code(400);
    exit('bad id');
}

$r = $conn->query("SELECT file_path, original_filename, status FROM song_sheets WHERE id=$sid");
if (!$r || $r->num_rows === 0) {
    http_response_code(404);
    exit('not found');
}
$row = $r->fetch_assoc();
if ($row['status'] !== 'published') {
    http_response_code(403);
    exit('unavailable');
}

$abs = __DIR__ . '/../' . $row['file_path'];
if (!is_file($abs)) {
    http_response_code(404);
    exit('file missing');
}

$conn->query("UPDATE song_sheets SET downloads = downloads + 1 WHERE id=$sid");

$dl_name = $row['original_filename'] ?: ('sheet_' . $sid . '.txt');
$ext = strtolower(pathinfo($dl_name, PATHINFO_EXTENSION));
$mime = $ext === 'json' ? 'application/json' : 'text/plain';

header('Content-Type: ' . $mime . '; charset=utf-8');
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: attachment; filename="' . rawurlencode($dl_name) . '"; filename*=UTF-8\'\'' . rawurlencode($dl_name));
header('Cache-Control: no-cache');
readfile($abs);

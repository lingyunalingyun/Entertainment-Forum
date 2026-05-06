<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/exp_helper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'msg' => '请先登录']);
    exit;
}
if (!empty($_SESSION['is_banned'])) {
    echo json_encode(['status' => 'error', 'msg' => '账号被限制，无法上传']);
    exit;
}

ensure_song_sheets_table($conn);

$uid = (int)$_SESSION['user_id'];
$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'msg' => '文件上传失败：' . ($file['error'] ?? 'no file')]);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['txt', 'json'], true)) {
    echo json_encode(['status' => 'error', 'msg' => '只接受 .txt / .json 曲谱文件']);
    exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['status' => 'error', 'msg' => '文件不能超过 5MB']);
    exit;
}

$content = file_get_contents($file['tmp_name']);
if ($content === false || $content === '') {
    echo json_encode(['status' => 'error', 'msg' => '文件读取失败']);
    exit;
}
// 统一编码到 UTF-8（兼容 SkyStudio 老曲谱常见的 UTF-16 LE BOM）
$content = normalize_sheet_encoding($content);

$parsed = parse_sky_sheet_json($content);
if (!$parsed['ok']) {
    echo json_encode(['status' => 'error', 'msg' => $parsed['msg']]);
    exit;
}
$meta = $parsed['meta'];

// 表单字段，覆盖 JSON 默认
$title          = trim($_POST['title']          ?? $meta['name']);
$artist         = trim($_POST['artist']         ?? $meta['author']);
$transcribed_by = trim($_POST['transcribed_by'] ?? $meta['transcribedBy']);
$bpm            = (int)($_POST['bpm']           ?? $meta['bpm']);
$subdiv         = (int)($_POST['subdiv']        ?? $meta['subdiv']);
$difficulty     = max(1, min(5, (int)($_POST['difficulty'] ?? 3)));
$description    = trim($_POST['description']    ?? '');
$tags           = trim($_POST['tags']           ?? '');

if ($title === '') {
    echo json_encode(['status' => 'error', 'msg' => '曲名不能为空']);
    exit;
}
if (mb_strlen($title) > 150)        $title       = mb_substr($title, 0, 150);
if (mb_strlen($artist) > 100)       $artist      = mb_substr($artist, 0, 100);
if (mb_strlen($transcribed_by)>100) $transcribed_by = mb_substr($transcribed_by, 0, 100);
if (mb_strlen($description) > 500)  $description = mb_substr($description, 0, 500);
if (mb_strlen($tags) > 255)         $tags        = mb_substr($tags, 0, 255);

// 落盘
$dir = __DIR__ . '/../uploads/sheets/';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$safe_orig = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fa5}._-]/u', '_', $file['name']);
$filename  = date('Ymd') . '_' . uniqid() . '_' . $safe_orig;
if (file_put_contents($dir . $filename, $content) === false) {
    echo json_encode(['status' => 'error', 'msg' => '文件保存失败，请检查目录权限']);
    exit;
}
$rel_path = 'uploads/sheets/' . $filename;

$stmt = $conn->prepare("INSERT INTO song_sheets
    (uploader_id, title, artist, transcribed_by, bpm, subdiv, difficulty, description, tags,
     file_path, original_filename, file_size, note_count, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published')");
$file_size  = (int)$file['size'];
$note_count = (int)$meta['noteCount'];
$orig_name  = $file['name'];
$stmt->bind_param(
    'isssiiissssii',
    $uid, $title, $artist, $transcribed_by, $bpm, $subdiv, $difficulty,
    $description, $tags, $rel_path, $orig_name, $file_size, $note_count
);
if (!$stmt->execute()) {
    @unlink($dir . $filename);
    echo json_encode(['status' => 'error', 'msg' => '入库失败：' . $conn->error]);
    exit;
}
$new_id = $stmt->insert_id;
$stmt->close();

echo json_encode(['status' => 'ok', 'id' => $new_id, 'msg' => '上传成功']);

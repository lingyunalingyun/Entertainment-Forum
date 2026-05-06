<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/exp_helper.php';
ensure_song_sheets_table($conn);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$sid = (int)($_GET['id'] ?? 0);
if ($sid <= 0) {
    echo json_encode(['status' => 'error', 'msg' => 'bad id']);
    exit;
}

$r = $conn->query("SELECT s.*, u.username AS uploader, u.id AS uploader_uid
                   FROM song_sheets s JOIN users u ON u.id = s.uploader_id
                   WHERE s.id = $sid AND s.status='published'");
if (!$r || $r->num_rows === 0) {
    echo json_encode(['status' => 'error', 'msg' => 'not found']);
    exit;
}
$row = $r->fetch_assoc();

$item = [
    'id'             => (int)$row['id'],
    'title'          => $row['title'],
    'artist'         => $row['artist'],
    'transcribed_by' => $row['transcribed_by'],
    'bpm'            => (int)$row['bpm'],
    'subdiv'         => (int)$row['subdiv'],
    'difficulty'     => (int)$row['difficulty'],
    'description'    => $row['description'],
    'tags'           => $row['tags'],
    'note_count'     => (int)$row['note_count'],
    'file_size'      => (int)$row['file_size'],
    'downloads'      => (int)$row['downloads'],
    'likes'          => (int)$row['likes'],
    'views'          => (int)$row['views'],
    'is_recommended' => (bool)$row['is_recommended'],
    'created_at'     => $row['created_at'],
    'uploader'       => $row['uploader'],
    'uploader_uid'   => (int)$row['uploader_uid'],
    'download_url'   => SITE_URL . '/actions/sheet_download.php?id=' . (int)$row['id'],
    'detail_url'     => SITE_URL . '/pages/sheet_detail.php?id=' . (int)$row['id'],
];

echo json_encode(['status' => 'ok', 'item' => $item], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

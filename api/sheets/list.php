<?php
// 公开 API：曲谱列表
// 参数：q sort(newest|hot|downloads) difficulty bpm_min bpm_max page per_page
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/exp_helper.php';
ensure_song_sheets_table($conn);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$q          = trim($_GET['q'] ?? '');
$sort       = $_GET['sort'] ?? 'newest';
$difficulty = (int)($_GET['difficulty'] ?? 0);
$bpm_min    = (int)($_GET['bpm_min'] ?? 0);
$bpm_max    = (int)($_GET['bpm_max'] ?? 0);
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
$offset     = ($page - 1) * $per_page;

$where = "s.status = 'published'";
$params = [];
$types  = '';
if ($q !== '') {
    $where .= " AND (s.title LIKE ? OR s.artist LIKE ? OR s.transcribed_by LIKE ? OR s.tags LIKE ?)";
    $kw = '%' . $q . '%';
    array_push($params, $kw, $kw, $kw, $kw);
    $types .= 'ssss';
}
if ($difficulty >= 1 && $difficulty <= 5) {
    $where .= " AND s.difficulty = ?";
    $params[] = $difficulty; $types .= 'i';
}
if ($bpm_min > 0) { $where .= " AND s.bpm >= ?"; $params[] = $bpm_min; $types .= 'i'; }
if ($bpm_max > 0) { $where .= " AND s.bpm <= ?"; $params[] = $bpm_max; $types .= 'i'; }

$order_map = [
    'newest'    => 's.created_at DESC',
    'hot'       => 's.likes DESC, s.downloads DESC',
    'downloads' => 's.downloads DESC',
];
$order = $order_map[$sort] ?? $order_map['newest'];

// 总数
$stmt = $conn->prepare("SELECT COUNT(*) c FROM song_sheets s WHERE $where");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// 列表
$stmt = $conn->prepare("SELECT s.id, s.title, s.artist, s.transcribed_by, s.bpm, s.subdiv,
                              s.difficulty, s.tags, s.note_count, s.file_size,
                              s.downloads, s.likes, s.views, s.is_recommended,
                              s.created_at, u.username AS uploader
                       FROM song_sheets s JOIN users u ON u.id = s.uploader_id
                       WHERE $where
                       ORDER BY s.is_recommended DESC, $order
                       LIMIT ? OFFSET ?");
$stmt->bind_param($types . 'ii', ...array_merge($params, [$per_page, $offset]));
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) {
    $row['id']             = (int)$row['id'];
    $row['bpm']            = (int)$row['bpm'];
    $row['subdiv']         = (int)$row['subdiv'];
    $row['difficulty']     = (int)$row['difficulty'];
    $row['note_count']     = (int)$row['note_count'];
    $row['file_size']      = (int)$row['file_size'];
    $row['downloads']      = (int)$row['downloads'];
    $row['likes']          = (int)$row['likes'];
    $row['views']          = (int)$row['views'];
    $row['is_recommended'] = (bool)$row['is_recommended'];
    $row['download_url']   = SITE_URL . '/actions/sheet_download.php?id=' . $row['id'];
    $row['detail_url']     = SITE_URL . '/pages/sheet_detail.php?id=' . $row['id'];
    $items[] = $row;
}
$stmt->close();

echo json_encode([
    'status'    => 'ok',
    'total'     => $total,
    'page'      => $page,
    'per_page'  => $per_page,
    'pages'     => max(1, (int)ceil($total / $per_page)),
    'items'     => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

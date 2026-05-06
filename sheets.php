<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/exp_helper.php';
ensure_song_sheets_table($conn);

$is_logged_in = isset($_SESSION['user_id']);
$my_id   = (int)($_SESSION['user_id'] ?? 0);
$my_role = $_SESSION['role'] ?? 'user';

// 参数
$q          = trim($_GET['q'] ?? '');
$sort       = $_GET['sort'] ?? 'newest';
$difficulty = (int)($_GET['difficulty'] ?? 0);
$bpm_min    = (int)($_GET['bpm_min'] ?? 0);
$bpm_max    = (int)($_GET['bpm_max'] ?? 0);
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 24;
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
    $params[] = $difficulty;
    $types   .= 'i';
}
if ($bpm_min > 0) {
    $where .= " AND s.bpm >= ?";
    $params[] = $bpm_min;
    $types   .= 'i';
}
if ($bpm_max > 0) {
    $where .= " AND s.bpm <= ?";
    $params[] = $bpm_max;
    $types   .= 'i';
}

$order_map = [
    'newest'    => 's.created_at DESC',
    'hot'       => 's.likes DESC, s.downloads DESC',
    'downloads' => 's.downloads DESC',
];
$order = $order_map[$sort] ?? $order_map['newest'];

// 总数
$count_sql = "SELECT COUNT(*) c FROM song_sheets s WHERE $where";
$stmt = $conn->prepare($count_sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
$total_pages = max(1, (int)ceil($total / $per_page));

// 列表
$list_sql = "SELECT s.*, u.username, u.avatar
             FROM song_sheets s
             JOIN users u ON u.id = s.uploader_id
             WHERE $where
             ORDER BY s.is_recommended DESC, $order
             LIMIT ? OFFSET ?";
$stmt = $conn->prepare($list_sql);
$bind_types  = $types . 'ii';
$bind_params = array_merge($params, [$per_page, $offset]);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$result = $stmt->get_result();
$sheets = [];
while ($row = $result->fetch_assoc()) $sheets[] = $row;
$stmt->close();

function fmt_size_kb(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes / 1024 / 1024, 2) . ' MB';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>在线曲库 - 缪斯 MUSE</title>
    <style>
        body { background:#0d1117; color:#e6edf3; margin:0; font-family:"Microsoft YaHei", sans-serif; }
        .sh-hero {
            background:#0d1117; border-bottom:1px solid #30363d;
            padding:32px 0 24px; position:relative; overflow:hidden;
        }
        .sh-hero::before {
            content:''; position:absolute; inset:0;
            background-image:
                linear-gradient(rgba(63,185,80,.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(63,185,80,.04) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .sh-hero-inner { max-width:1200px; margin:0 auto; padding:0 24px; position:relative; z-index:1; }
        .sh-hero h1 { font-size:24px; font-weight:700; color:#e6edf3; font-family:"Courier New",monospace; margin:0 0 4px; }
        .sh-hero h1 span { color:#3fb950; }
        .sh-hero p { font-size:13px; color:#6e7681; margin:0; font-family:"Courier New",monospace; }

        .sh-toolbar {
            max-width:1200px; margin:24px auto 14px; padding:0 24px;
            display:flex; gap:10px; flex-wrap:wrap; align-items:center;
        }
        .sh-toolbar form { display:flex; gap:8px; flex-wrap:wrap; align-items:center; flex:1; }
        .sh-input, .sh-select {
            background:#161b22; border:1px solid #30363d; color:#e6edf3;
            padding:7px 11px; border-radius:4px; font-size:13px;
            font-family:"Courier New",monospace; outline:none;
        }
        .sh-input:focus, .sh-select:focus { border-color:#3fb950; }
        .sh-input { min-width:200px; flex:1; }
        .sh-btn {
            background:#3fb950; color:#0d1117; padding:7px 16px;
            border:none; border-radius:4px; font-weight:700; cursor:pointer;
            font-family:"Courier New",monospace; font-size:13px;
        }
        .sh-btn:hover { background:#5fdd70; }
        .sh-btn-line {
            background:transparent; color:#3fb950; padding:7px 16px;
            border:1px solid #3fb950; border-radius:4px; font-weight:700; cursor:pointer;
            font-family:"Courier New",monospace; font-size:13px;
            text-decoration:none; display:inline-flex; align-items:center; gap:5px;
        }
        .sh-btn-line:hover { background:rgba(63,185,80,.12); }

        .sh-grid {
            max-width:1200px; margin:0 auto; padding:0 24px 40px;
            display:grid; grid-template-columns: repeat(3, 1fr); gap:16px;
        }
        @media(max-width:900px) { .sh-grid { grid-template-columns: repeat(2, 1fr); } }
        @media(max-width:560px) { .sh-grid { grid-template-columns: 1fr; padding:0 12px 30px; } }

        .sh-card {
            background:#161b22; border:1px solid #30363d; border-radius:8px;
            padding:16px; transition:.18s; text-decoration:none; color:inherit;
            display:flex; flex-direction:column; gap:10px; position:relative;
        }
        .sh-card:hover { border-color:#3fb950; transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.4); }
        .sh-card-rec {
            position:absolute; top:10px; right:10px;
            background:rgba(227,179,65,.15); color:#e3b341;
            border:1px solid rgba(227,179,65,.4);
            font-size:10px; font-weight:700; padding:2px 7px; border-radius:3px;
            font-family:"Courier New",monospace;
        }
        .sh-card-title {
            color:#e6edf3; font-size:15px; font-weight:700; margin:0;
            line-height:1.3; word-break:break-all;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
        }
        .sh-card-meta {
            display:flex; flex-wrap:wrap; gap:6px 12px; font-size:11px; color:#8b949e;
            font-family:"Courier New",monospace;
        }
        .sh-card-meta span b { color:#c9d1d9; font-weight:400; }
        .sh-card-stars { color:#e3b341; letter-spacing:1px; font-size:11px; }
        .sh-card-foot {
            display:flex; justify-content:space-between; align-items:center;
            font-size:11px; color:#6e7681; padding-top:8px;
            border-top:1px solid #21262d; font-family:"Courier New",monospace;
        }
        .sh-card-foot .uploader { display:flex; align-items:center; gap:6px; }
        .sh-card-foot .uploader img { width:18px; height:18px; border-radius:50%; object-fit:cover; }
        .sh-card-stats { display:flex; gap:10px; }
        .sh-card-stats span { display:inline-flex; align-items:center; gap:3px; }

        .sh-empty {
            grid-column:1/-1; text-align:center; padding:60px 20px;
            color:#6e7681; font-family:"Courier New",monospace; font-size:14px;
        }

        .sh-pager {
            max-width:1200px; margin:0 auto; padding:10px 24px 40px;
            display:flex; gap:8px; justify-content:center; flex-wrap:wrap;
            font-family:"Courier New",monospace; font-size:13px;
        }
        .sh-pager a, .sh-pager span {
            padding:6px 12px; background:#161b22; border:1px solid #30363d;
            color:#8b949e; border-radius:4px; text-decoration:none;
        }
        .sh-pager a:hover { border-color:#3fb950; color:#3fb950; }
        .sh-pager .active { background:#3fb950; color:#0d1117; border-color:#3fb950; font-weight:700; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="sh-hero">
    <div class="sh-hero-inner">
        <h1>&gt; <span>SHEET</span> LIBRARY <span style="color:#6e7681;">// 在线曲库</span></h1>
        <p># 共 <?= $total ?> 首曲谱 // SkyMusic 桌面端可直接拉取</p>
    </div>
</div>

<div class="sh-toolbar">
    <form method="GET" action="sheets.php">
        <input class="sh-input" type="text" name="q" placeholder="搜索曲名 / 原唱 / 创谱人 / 标签..." value="<?= htmlspecialchars($q) ?>">
        <select class="sh-select" name="sort">
            <option value="newest"    <?= $sort==='newest'?'selected':'' ?>>最新</option>
            <option value="hot"       <?= $sort==='hot'?'selected':'' ?>>最热</option>
            <option value="downloads" <?= $sort==='downloads'?'selected':'' ?>>下载量</option>
        </select>
        <select class="sh-select" name="difficulty">
            <option value="0">全部难度</option>
            <?php for ($i=1;$i<=5;$i++): ?>
            <option value="<?= $i ?>" <?= $difficulty===$i?'selected':'' ?>>★ <?= $i ?></option>
            <?php endfor; ?>
        </select>
        <input class="sh-input" type="number" name="bpm_min" placeholder="BPM 下限" value="<?= $bpm_min?:'' ?>" style="min-width:110px;flex:0;">
        <input class="sh-input" type="number" name="bpm_max" placeholder="BPM 上限" value="<?= $bpm_max?:'' ?>" style="min-width:110px;flex:0;">
        <button class="sh-btn" type="submit">筛选</button>
    </form>
    <?php if ($is_logged_in): ?>
    <a class="sh-btn-line" href="pages/sheet_upload.php">+ 上传曲谱</a>
    <?php else: ?>
    <a class="sh-btn-line" href="pages/login.php">登录后上传</a>
    <?php endif; ?>
</div>

<div class="sh-grid">
    <?php if (empty($sheets)): ?>
        <div class="sh-empty">// 没有找到匹配的曲谱</div>
    <?php else: foreach ($sheets as $s):
        $stars = str_repeat('★', (int)$s['difficulty']) . str_repeat('☆', 5 - (int)$s['difficulty']);
    ?>
        <a class="sh-card" href="pages/sheet_detail.php?id=<?= (int)$s['id'] ?>">
            <?php if ($s['is_recommended']): ?><span class="sh-card-rec">★ 精选</span><?php endif; ?>
            <h3 class="sh-card-title"><?= htmlspecialchars($s['title']) ?></h3>
            <div class="sh-card-meta">
                <?php if ($s['artist']): ?><span>原唱: <b><?= htmlspecialchars($s['artist']) ?></b></span><?php endif; ?>
                <?php if ($s['transcribed_by']): ?><span>谱: <b><?= htmlspecialchars($s['transcribed_by']) ?></b></span><?php endif; ?>
                <?php if ($s['bpm']): ?><span>BPM <b><?= (int)$s['bpm'] ?></b></span><?php endif; ?>
                <span><b><?= (int)$s['note_count'] ?></b> 音符</span>
            </div>
            <div class="sh-card-stars"><?= $stars ?></div>
            <div class="sh-card-foot">
                <div class="uploader">
                    <img src="uploads/avatars/<?= htmlspecialchars($s['avatar'] ?: 'default.png') ?>"
                         onerror="this.onerror=null;this.src='uploads/avatars/default.png'">
                    <span><?= htmlspecialchars($s['username']) ?></span>
                </div>
                <div class="sh-card-stats">
                    <span title="下载">↓ <?= (int)$s['downloads'] ?></span>
                    <span title="点赞">♥ <?= (int)$s['likes'] ?></span>
                </div>
            </div>
        </a>
    <?php endforeach; endif; ?>
</div>

<?php if ($total_pages > 1):
    $qs = $_GET; unset($qs['page']);
    $qs_str = http_build_query($qs);
?>
<div class="sh-pager">
    <?php if ($page > 1): ?><a href="?<?= $qs_str ?>&page=<?= $page - 1 ?>">‹ 上一页</a><?php endif; ?>
    <?php
        $start = max(1, $page - 2);
        $end   = min($total_pages, $page + 2);
        for ($i=$start; $i<=$end; $i++):
            if ($i === $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= $qs_str ?>&page=<?= $i ?>"><?= $i ?></a>
        <?php endif;
        endfor; ?>
    <?php if ($page < $total_pages): ?><a href="?<?= $qs_str ?>&page=<?= $page + 1 ?>">下一页 ›</a><?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>

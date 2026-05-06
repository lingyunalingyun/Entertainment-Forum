<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/exp_helper.php';
ensure_song_sheets_table($conn);

$is_logged_in = isset($_SESSION['user_id']);
$my_id   = (int)($_SESSION['user_id'] ?? 0);
$my_role = $_SESSION['role'] ?? 'user';
$is_admin = in_array($my_role, ['admin', 'owner'], true);

$sid = (int)($_GET['id'] ?? 0);
if ($sid <= 0) {
    header('Location: ../sheets.php');
    exit;
}

$r = $conn->query("SELECT s.*, u.username, u.avatar, u.role, u.is_banned
                   FROM song_sheets s JOIN users u ON u.id = s.uploader_id
                   WHERE s.id = $sid");
if (!$r || $r->num_rows === 0) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}
$sheet = $r->fetch_assoc();

if ($sheet['status'] !== 'published' && !$is_admin && (int)$sheet['uploader_id'] !== $my_id) {
    http_response_code(403);
    include __DIR__ . '/../403.php';
    exit;
}

// views++（简化：每访问 +1）
$conn->query("UPDATE song_sheets SET views = views + 1 WHERE id=$sid");

$liked = false;
if ($my_id > 0) {
    $lr = $conn->query("SELECT 1 FROM sheet_likes WHERE sheet_id=$sid AND user_id=$my_id");
    $liked = $lr && $lr->num_rows > 0;
}
$can_delete = $is_admin || (int)$sheet['uploader_id'] === $my_id;

function fmt_size_kb2(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes / 1024 / 1024, 2) . ' MB';
}
$stars = str_repeat('★', (int)$sheet['difficulty']) . str_repeat('☆', 5 - (int)$sheet['difficulty']);
$tags_arr = array_filter(array_map('trim', explode(',', $sheet['tags'] ?? '')));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($sheet['title']) ?> - 曲库 - 缪斯 MUSE</title>
<style>
    body { background:#0d1117; color:#e6edf3; margin:0; font-family:"Microsoft YaHei", sans-serif; }
    .sd-wrap { max-width:900px; margin:30px auto; padding:0 24px; }
    .sd-back { color:#6e7681; text-decoration:none; font-family:"Courier New",monospace; font-size:12px; }
    .sd-back:hover { color:#3fb950; }
    .sd-card {
        background:#161b22; border:1px solid #30363d; border-radius:8px;
        padding:28px; margin-top:14px;
    }
    .sd-title-row { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:18px; flex-wrap:wrap; }
    .sd-title { color:#e6edf3; font-size:24px; font-weight:700; margin:0; line-height:1.3; word-break:break-all; flex:1; }
    .sd-rec { background:rgba(227,179,65,.15); color:#e3b341; border:1px solid rgba(227,179,65,.4);
              font-size:11px; font-weight:700; padding:3px 9px; border-radius:3px;
              font-family:"Courier New",monospace; }

    .sd-meta-grid {
        display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));
        gap:10px 18px; padding:14px 0; border-top:1px solid #21262d; border-bottom:1px solid #21262d;
        font-family:"Courier New",monospace; font-size:13px;
    }
    .sd-meta-grid .lbl { color:#6e7681; font-size:11px; margin-bottom:2px; }
    .sd-meta-grid .val { color:#e6edf3; font-weight:700; }

    .sd-stars { color:#e3b341; letter-spacing:2px; font-size:15px; }
    .sd-tags { display:flex; flex-wrap:wrap; gap:6px; margin-top:14px; }
    .sd-tag {
        background:rgba(63,185,80,.1); color:#3fb950; border:1px solid rgba(63,185,80,.3);
        padding:3px 10px; border-radius:3px; font-size:11px; font-family:"Courier New",monospace;
    }

    .sd-desc {
        margin-top:18px; padding:14px; background:#0d1117;
        border:1px solid #21262d; border-radius:4px; color:#c9d1d9;
        font-size:13px; line-height:1.7; white-space:pre-wrap; word-break:break-all;
    }

    .sd-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:22px; }
    .sd-btn-dl {
        background:#3fb950; color:#0d1117; padding:11px 26px;
        border:none; border-radius:4px; font-weight:700; cursor:pointer;
        font-family:"Courier New",monospace; font-size:14px;
        text-decoration:none; display:inline-flex; align-items:center; gap:6px;
    }
    .sd-btn-dl:hover { background:#5fdd70; }
    .sd-btn-like {
        background:transparent; color:#f85149; padding:11px 18px;
        border:1px solid #f85149; border-radius:4px; cursor:pointer;
        font-family:"Courier New",monospace; font-size:14px; font-weight:700;
    }
    .sd-btn-like.active { background:#f85149; color:#fff; }
    .sd-btn-like:hover { background:rgba(248,81,73,.12); }
    .sd-btn-like.active:hover { background:#d8403a; }
    .sd-btn-del {
        background:transparent; color:#8b949e; padding:11px 14px;
        border:1px solid #30363d; border-radius:4px; cursor:pointer;
        font-family:"Courier New",monospace; font-size:13px;
        margin-left:auto;
    }
    .sd-btn-del:hover { color:#f85149; border-color:#f85149; }

    .sd-uploader-row {
        margin-top:24px; padding-top:18px; border-top:1px solid #21262d;
        display:flex; align-items:center; gap:12px;
    }
    .sd-uploader-row img { width:40px; height:40px; border-radius:50%; object-fit:cover; border:1px solid #30363d; }
    .sd-uploader-row a { color:#e6edf3; text-decoration:none; font-weight:700; }
    .sd-uploader-row a:hover { color:#3fb950; }
    .sd-uploader-row .when { color:#6e7681; font-size:11px; font-family:"Courier New",monospace; margin-left:auto; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="sd-wrap">
    <a class="sd-back" href="../sheets.php">‹ 返回曲库</a>
    <div class="sd-card">
        <div class="sd-title-row">
            <h1 class="sd-title"><?= htmlspecialchars($sheet['title']) ?></h1>
            <?php if ($sheet['is_recommended']): ?><span class="sd-rec">★ 精选</span><?php endif; ?>
        </div>

        <div class="sd-meta-grid">
            <?php if ($sheet['artist']): ?>
            <div><div class="lbl">原唱 / 作曲</div><div class="val"><?= htmlspecialchars($sheet['artist']) ?></div></div>
            <?php endif; ?>
            <?php if ($sheet['transcribed_by']): ?>
            <div><div class="lbl">创谱人</div><div class="val"><?= htmlspecialchars($sheet['transcribed_by']) ?></div></div>
            <?php endif; ?>
            <?php if ($sheet['bpm']): ?>
            <div><div class="lbl">BPM</div><div class="val"><?= (int)$sheet['bpm'] ?></div></div>
            <?php endif; ?>
            <?php if ($sheet['subdiv']): ?>
            <div><div class="lbl">细分</div><div class="val">1/<?= (int)$sheet['subdiv'] ?></div></div>
            <?php endif; ?>
            <div><div class="lbl">音符数</div><div class="val"><?= (int)$sheet['note_count'] ?></div></div>
            <div><div class="lbl">难度</div><div class="val sd-stars"><?= $stars ?></div></div>
            <div><div class="lbl">下载</div><div class="val">↓ <?= (int)$sheet['downloads'] ?></div></div>
            <div><div class="lbl">点赞</div><div class="val">♥ <?= (int)$sheet['likes'] ?></div></div>
            <div><div class="lbl">浏览</div><div class="val"><?= (int)$sheet['views'] ?></div></div>
            <div><div class="lbl">大小</div><div class="val"><?= fmt_size_kb2((int)$sheet['file_size']) ?></div></div>
        </div>

        <?php if (!empty($tags_arr)): ?>
        <div class="sd-tags">
            <?php foreach ($tags_arr as $t): ?>
                <span class="sd-tag"># <?= htmlspecialchars($t) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($sheet['description']): ?>
        <div class="sd-desc"><?= htmlspecialchars($sheet['description']) ?></div>
        <?php endif; ?>

        <div class="sd-actions">
            <a class="sd-btn-dl" href="../actions/sheet_download.php?id=<?= $sid ?>">↓ 下载曲谱</a>
            <?php if ($is_logged_in): ?>
            <button class="sd-btn-like <?= $liked ? 'active' : '' ?>" id="likeBtn" data-id="<?= $sid ?>">
                <span id="likeIcon"><?= $liked ? '♥' : '♡' ?></span> <span id="likeCnt"><?= (int)$sheet['likes'] ?></span>
            </button>
            <?php else: ?>
            <a class="sd-btn-like" href="login.php" style="text-decoration:none;">♡ 登录后点赞</a>
            <?php endif; ?>
            <?php if ($can_delete): ?>
            <button class="sd-btn-del" id="delBtn" data-id="<?= $sid ?>">删除</button>
            <?php endif; ?>
        </div>

        <div class="sd-uploader-row">
            <img src="../uploads/avatars/<?= htmlspecialchars($sheet['avatar'] ?: 'default.png') ?>"
                 onerror="this.onerror=null;this.src='../uploads/avatars/default.png'">
            <div>
                <a href="profile.php?id=<?= (int)$sheet['uploader_id'] ?>"><?= htmlspecialchars($sheet['username']) ?></a>
                <?= get_role_badge($sheet['role'], !empty($sheet['is_banned']), 'margin-left:6px;') ?>
            </div>
            <span class="when">上传于 <?= htmlspecialchars(date('Y-m-d H:i', strtotime($sheet['created_at']))) ?></span>
        </div>
    </div>
</div>

<script>
const likeBtn = document.getElementById('likeBtn');
if (likeBtn) {
    likeBtn.addEventListener('click', function() {
        const id = this.dataset.id;
        fetch('../actions/sheet_like.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + encodeURIComponent(id)
        }).then(r => r.json()).then(data => {
            if (data.status === 'ok') {
                document.getElementById('likeIcon').textContent = data.active ? '♥' : '♡';
                document.getElementById('likeCnt').textContent = data.count;
                likeBtn.classList.toggle('active', data.active);
            } else {
                alert(data.msg || '操作失败');
            }
        });
    });
}
const delBtn = document.getElementById('delBtn');
if (delBtn) {
    delBtn.addEventListener('click', function() {
        if (!confirm('确认删除该曲谱？此操作不可恢复。')) return;
        fetch('../actions/sheet_delete.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + encodeURIComponent(this.dataset.id)
        }).then(r => r.json()).then(data => {
            if (data.status === 'ok') {
                alert('已删除');
                location.href = '../sheets.php';
            } else {
                alert(data.msg || '删除失败');
            }
        });
    });
}
</script>
</body>
</html>

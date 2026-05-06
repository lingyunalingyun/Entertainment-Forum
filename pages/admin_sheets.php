<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/exp_helper.php';
ensure_song_sheets_table($conn);

$my_role = $_SESSION['role'] ?? 'user';
if (!in_array($my_role, ['admin', 'owner'], true)) {
    http_response_code(403);
    include __DIR__ . '/../403.php';
    exit;
}

// 处理后台动作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $sid    = (int)($_POST['id'] ?? 0);
    if ($sid > 0) {
        if ($action === 'recommend') {
            $conn->query("UPDATE song_sheets SET is_recommended = 1 - is_recommended WHERE id=$sid");
        } elseif ($action === 'reject') {
            $conn->query("UPDATE song_sheets SET status='rejected' WHERE id=$sid");
        } elseif ($action === 'restore') {
            $conn->query("UPDATE song_sheets SET status='published' WHERE id=$sid");
        } elseif ($action === 'delete') {
            $r = $conn->query("SELECT file_path FROM song_sheets WHERE id=$sid");
            if ($r && $row = $r->fetch_assoc()) {
                $abs = __DIR__ . '/../' . $row['file_path'];
                if (is_file($abs)) @unlink($abs);
            }
            $conn->query("DELETE FROM sheet_likes WHERE sheet_id=$sid");
            $conn->query("DELETE FROM song_sheets WHERE id=$sid");
        }
    }
    header('Location: admin_sheets.php' . (isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$where  = "1=1";
if ($filter === 'published')   $where = "s.status='published'";
elseif ($filter === 'rejected') $where = "s.status='rejected'";
elseif ($filter === 'recommended') $where = "s.is_recommended=1";

$res = $conn->query("SELECT s.*, u.username FROM song_sheets s
                     JOIN users u ON u.id = s.uploader_id
                     WHERE $where
                     ORDER BY s.id DESC LIMIT 200");
$sheets = [];
if ($res) while ($row = $res->fetch_assoc()) $sheets[] = $row;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>曲库管理 - 缪斯 MUSE</title>
<style>
    body { background:#0d1117; color:#e6edf3; margin:0; font-family:"Microsoft YaHei", sans-serif; }
    .as-wrap { max-width:1100px; margin:30px auto; padding:0 24px; }
    .as-title { font-family:"Courier New",monospace; color:#3fb950; font-size:18px; margin:0 0 14px; }
    .as-tabs { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
    .as-tab {
        background:#161b22; border:1px solid #30363d; color:#8b949e;
        padding:6px 14px; border-radius:4px; text-decoration:none;
        font-family:"Courier New",monospace; font-size:12px;
    }
    .as-tab.active { background:#3fb950; color:#0d1117; border-color:#3fb950; font-weight:700; }
    .as-tab:hover { border-color:#3fb950; color:#3fb950; }
    .as-tab.active:hover { color:#0d1117; }

    table.as-tbl { width:100%; border-collapse:collapse; background:#161b22;
                   border:1px solid #30363d; border-radius:6px; overflow:hidden; }
    .as-tbl th, .as-tbl td {
        padding:10px 12px; border-bottom:1px solid #21262d;
        font-size:13px; text-align:left; vertical-align:middle;
    }
    .as-tbl th { background:#0d1117; color:#8b949e; font-weight:700;
                 font-family:"Courier New",monospace; font-size:11px; text-transform:uppercase; }
    .as-tbl tr:hover { background:#1c2128; }
    .as-tbl a { color:#58a6ff; text-decoration:none; }
    .as-tbl a:hover { text-decoration:underline; }
    .as-status-pub { color:#3fb950; }
    .as-status-rej { color:#f85149; }
    .as-rec { background:rgba(227,179,65,.15); color:#e3b341; padding:1px 6px;
              border-radius:3px; font-size:10px; font-family:"Courier New",monospace; font-weight:700; }

    .as-act { display:flex; gap:4px; flex-wrap:wrap; }
    .as-act button {
        background:transparent; border:1px solid #30363d; color:#8b949e;
        padding:3px 9px; border-radius:3px; cursor:pointer;
        font-family:"Courier New",monospace; font-size:11px;
    }
    .as-act button:hover { color:#3fb950; border-color:#3fb950; }
    .as-act .danger:hover { color:#f85149; border-color:#f85149; }
    .as-act .gold:hover { color:#e3b341; border-color:#e3b341; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="as-wrap">
    <h2 class="as-title">&gt; 曲库管理</h2>
    <div class="as-tabs">
        <a class="as-tab <?= $filter==='all'?'active':'' ?>"         href="?filter=all">全部 (<?= count($sheets) ?>)</a>
        <a class="as-tab <?= $filter==='published'?'active':'' ?>"   href="?filter=published">已发布</a>
        <a class="as-tab <?= $filter==='rejected'?'active':'' ?>"    href="?filter=rejected">已下架</a>
        <a class="as-tab <?= $filter==='recommended'?'active':'' ?>" href="?filter=recommended">★ 精选</a>
    </div>

    <table class="as-tbl">
        <thead>
            <tr>
                <th>ID</th><th>曲名</th><th>原唱</th><th>上传者</th>
                <th>状态</th><th>下载</th><th>♥</th><th>时间</th><th>操作</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($sheets)): ?>
            <tr><td colspan="9" style="text-align:center;color:#6e7681;padding:30px;">// 没有数据</td></tr>
        <?php else: foreach ($sheets as $s): ?>
            <tr>
                <td>#<?= (int)$s['id'] ?></td>
                <td>
                    <a href="sheet_detail.php?id=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['title']) ?></a>
                    <?php if ($s['is_recommended']): ?> <span class="as-rec">★</span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($s['artist'] ?: '—') ?></td>
                <td><a href="profile.php?id=<?= (int)$s['uploader_id'] ?>"><?= htmlspecialchars($s['username']) ?></a></td>
                <td>
                    <?php if ($s['status']==='published'): ?>
                        <span class="as-status-pub">● 已发布</span>
                    <?php else: ?>
                        <span class="as-status-rej">● 已下架</span>
                    <?php endif; ?>
                </td>
                <td><?= (int)$s['downloads'] ?></td>
                <td><?= (int)$s['likes'] ?></td>
                <td style="color:#6e7681;font-family:'Courier New',monospace;font-size:11px;">
                    <?= htmlspecialchars(date('m-d H:i', strtotime($s['created_at']))) ?>
                </td>
                <td>
                    <div class="as-act">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="action" value="recommend">
                            <button class="gold" type="submit"><?= $s['is_recommended'] ? '取消精选' : '★ 精选' ?></button>
                        </form>
                        <?php if ($s['status']==='published'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button class="danger" type="submit">下架</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="action" value="restore">
                            <button type="submit">恢复</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('硬删除该曲谱？文件将一并删除！');">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button class="danger" type="submit">删除</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>

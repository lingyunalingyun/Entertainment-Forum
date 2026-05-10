<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_role = $_SESSION['role'] ?? 'user';
$is_logged_in = isset($_SESSION['user_id']);

// 自动识别路径深度
$in_subdir = (strpos($_SERVER['PHP_SELF'], '/pages/') !== false);
$base = $in_subdir ? '../' : '';

// 加载当前用户装备的装饰（若已登录且 helper 可用）
$header_user_deco = null;
if ($is_logged_in && isset($conn) && function_exists('load_user_decoration')) {
    @ensure_decoration_tables($conn);
    $header_user_deco = load_user_decoration($conn, intval($_SESSION['user_id']));
}

// 未读消息数（只在有数据库连接时查询）
$unread_count = 0;
$pending_posts = 0;
if ($is_logged_in && isset($conn)) {
    $uid_h = intval($_SESSION['user_id']);

    if (empty($_SESSION['mid'])) {
        $mid_res = $conn->query("SELECT mid, is_cs FROM users WHERE id=$uid_h");
        if ($mid_res && $mid_row = $mid_res->fetch_assoc()) {
            $_SESSION['mid']   = $mid_row['mid'] ?? '';
            $_SESSION['is_cs'] = !empty($mid_row['is_cs']) ? 1 : 0;
        }
    }

    // 中途封禁检测（支持到期自动解封）
    $_SESSION['is_banned'] = 0;
    $_SESSION['ban_reason'] = '';
    $_SESSION['ban_until']  = null;
    $ban_res = $conn->query("SELECT is_banned, ban_reason, ban_until FROM users WHERE id=$uid_h");
    if ($ban_res && $ban_row = $ban_res->fetch_assoc()) {
        if (!empty($ban_row['is_banned'])) {
            $until = $ban_row['ban_until'];
            if ($until !== null && strtotime($until) <= time()) {
                // 封禁已到期，自动解封
                $conn->query("UPDATE users SET is_banned=0, ban_reason=NULL, ban_until=NULL, banned_by=NULL WHERE id=$uid_h");
            } else {
                $_SESSION['is_banned']  = 1;
                $_SESSION['ban_reason'] = $ban_row['ban_reason'] ?: '违反社区规范';
                $_SESSION['ban_until']  = $until;
            }
        }
    }

    $n_res = $conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = $uid_h AND is_read = 0");
    if ($n_res) $unread_count = (int)$n_res->fetch_assoc()['cnt'];
    $pending_reports = 0;
    if ($current_role === 'admin' || $current_role === 'owner') {
        $p_res = $conn->query("SELECT COUNT(*) as cnt FROM posts WHERE status='待审核'");
        if ($p_res) $pending_posts = (int)$p_res->fetch_assoc()['cnt'];
        $rp_res = $conn->query("SELECT COUNT(*) as cnt FROM reports WHERE status='pending'");
        if ($rp_res) $pending_reports = (int)$rp_res->fetch_assoc()['cnt'];
        $pr_tbl = $conn->query("SHOW TABLES LIKE 'profile_edit_requests'");
        $pending_profile_reviews = 0;
        if ($pr_tbl && $pr_tbl->num_rows > 0) {
            $pr_res = $conn->query("SELECT COUNT(*) as cnt FROM profile_edit_requests WHERE status='pending'");
            if ($pr_res) $pending_profile_reviews = (int)$pr_res->fetch_assoc()['cnt'];
        }
    }
}
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?= $base ?>style.css">
<script>
(function(){
    var base='<?= $base ?>';
    if(!document.querySelector('link[rel="icon"],link[rel="shortcut icon"]')){
        var l=document.createElement('link');
        l.rel='icon';l.type='image/svg+xml';l.href=base+'assets/logo.svg';
        document.head.appendChild(l);
    }
})();
</script>

<!-- ══ 全站留白覆盖：让两侧留白减少约 70% ══ -->
<style>
/* 主要容器统一宽到 ~1700px，左右内边距收紧到 10px */
.page-layout,
.container,
.wrap,
.page-wrap,
.ap-shell,
.ap-layout,
.pub-layout,
.panel-wrap,
.msg-layout,
.notif-wrap,
.profile-container,
.sd-wrap,
.notice-page,
.search-wrap,
.act-wrap,
.sh-hero-inner,
.lib-wrap,
.lib-grid,
.cat-hero-inner,
.sh-grid,
.notif-list,
.pub-form,
.sh-filter-bar {
    max-width: 1700px !important;
    padding-left: 10px !important;
    padding-right: 10px !important;
}
/* sheets.php 等使用内联 max-width 的容器 */
[style*="max-width:1200px"],
[style*="max-width: 1200px"],
[style*="max-width:1100px"],
[style*="max-width: 1100px"],
[style*="max-width:1000px"],
[style*="max-width: 1000px"],
[style*="max-width:960px"],
[style*="max-width: 960px"],
[style*="max-width:900px"],
[style*="max-width: 900px"] {
    max-width: 1700px !important;
}
/* admin.php 自身的 layout 不改（已经是 1700） */
.ap-layout { padding-left: 0 !important; padding-right: 0 !important; }
</style>

<?php if (empty($skip_loader)): ?>
<!-- ── 加载动画 ── -->
<style>
#muse-loader {
    position: fixed; inset: 0; z-index: 99999;
    background: #0d1117;
    display: flex; align-items: center; justify-content: center;
    transition: opacity .6s ease;
}
#muse-loader.fade-out { opacity: 0; pointer-events: none; }
.loader-inner { position: relative; width: 200px; height: 200px; display: flex; align-items: center; justify-content: center; }
.loader-fire { position: relative; z-index: 2; animation: fire-pulse 2s ease-in-out infinite; }
.loader-fire svg { filter: drop-shadow(0 0 8px rgba(0,150,39,.7)); }
@keyframes fire-pulse {
    0%,100% { transform: scale(1);   filter: drop-shadow(0 0 8px rgba(63,185,80,.5)); }
    50%      { transform: scale(1.08); filter: drop-shadow(0 0 18px rgba(63,185,80,.9)); }
}
.loader-ring { position: absolute; top: 50%; left: 50%; width: 0; height: 0; }
.muse-wrap {
    position: absolute; top: -9px; left: -9px; width: 18px; height: 18px;
    transform: rotate(calc(var(--i) * 40deg)) translateY(-84px);
}
.muse-icon {
    width: 18px; height: 18px; display: block;
    opacity: 0.4;
    animation: muse-glow 1.8s ease-in-out infinite;
    animation-delay: calc(var(--i) * 0.2s);
}
@keyframes muse-glow {
    0%,100% { opacity: 0.4; transform: scale(1);   filter: drop-shadow(0 0 2px rgba(63,185,80,.4)); }
    50%      { opacity: 1;  transform: scale(1.4); filter: drop-shadow(0 0 8px rgba(63,185,80,1)); }
}
</style>
<div id="muse-loader" aria-hidden="true">
    <div class="loader-inner">
        <div class="loader-fire">
            <svg viewBox="0 0 434 385" xmlns="http://www.w3.org/2000/svg" width="64" height="57">
                <path d="M0.493622 380.102L108.747 380.102L217.075 189.92L325.702 380.102L433.506 380.102L325.253 190.051L271.127 95.0255L217 0L162.873 95.0255L108.747 190.051L0.493622 380.102Z" fill="rgb(0,150,39)"/>
                <path d="M425.962 299L322.038 299L374 209L425.962 299Z" fill="rgb(0,150,39)"/>
                <path d="M86.2439 191.25L24.7561 191.25L55.5 138L86.2439 191.25Z" fill="rgb(0,150,39)"/>
                <path d="M333.919 51.5L294.081 51.5L314 17L333.919 51.5Z" fill="rgb(0,150,39)"/>
            </svg>
        </div>
        <div class="loader-ring">
            <!-- 0 Calliope 铁笔与蜡板 -->
            <div class="muse-wrap" style="--i:0">
                <svg class="muse-icon" viewBox="0 0 18 18" fill="none" stroke="#3fb950" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="1.5" y="2.5" width="9" height="12" rx="1" stroke-width="1.6"/>
                    <line x1="3.5" y1="6" x2="8" y2="6" stroke-width="1.2"/>
                    <line x1="3.5" y1="8.5" x2="8" y2="8.5" stroke-width="1.2"/>
                    <line x1="3.5" y1="11" x2="6.5" y2="11" stroke-width="1.2"/>
                    <path d="M11 1.5 L16 6.5 L14 8.5 L9 3.5 Z" stroke-width="1.4"/>
                </svg>
            </div>
            <!-- 1 Clio 书卷与桂冠 -->
            <div class="muse-wrap" style="--i:1">
                <svg class="muse-icon" viewBox="0 0 18 18" fill="none" stroke="#3fb950" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3.5" y="7" width="11" height="7" rx="1.5" stroke-width="1.6"/>
                    <circle cx="3.5" cy="10.5" r="2" stroke-width="1.4"/>
                    <circle cx="14.5" cy="10.5" r="2" stroke-width="1.4"/>
                    <line x1="6" y1="9" x2="12" y2="9" stroke-width="1.2"/>
                    <line x1="6" y1="11.5" x2="11" y2="11.5" stroke-width="1.2"/>
                    <path d="M5 5 C5 3.5 7 3 9 4 C11 3 13 3.5 13 5" stroke-width="1.4"/>
                </svg>
            </div>
            <!-- 2 Euterpe 长笛 -->
            <div class="muse-wrap" style="--i:2">
                <svg class="muse-icon" viewBox="0 0 18 18" fill="none" stroke="#3fb950" stroke-linecap="round">
                    <line x1="2.5" y1="6.5" x2="15.5" y2="6.5" stroke-width="2"/>
                    <line x1="2.5" y1="9" x2="15.5" y2="9" stroke-width="2"/>
                    <line x1="2.5" y1="11.5" x2="15.5" y2="11.5" stroke-width="2"/>
                    <circle cx="5.5" cy="6.5" r="1" fill="#3fb950" stroke="none"/>
                    <circle cx="9" cy="9" r="1" fill="#3fb950" stroke="none"/>
                    <circle cx="12.5" cy="11.5" r="1" fill="#3fb950" stroke="none"/>
                </svg>
            </div>
            <!-- 3 Erato 七弦琴与常春藤 -->
            <div class="muse-wrap" style="--i:3">
                <svg class="muse-icon" viewBox="0 0 18 18" fill="none" stroke="#3fb950" stroke-linecap="round">
                    <path d="M5 15 Q2.5 13.5 2.5 9 Q2.5 4.5 5 3" stroke-width="1.6"/>
                    <path d="M13 15 Q15.5 13.5 15.5 9 Q15.5 4.5 13 3" stroke-width="1.6"/>
                    <line x1="5" y1="3" x2="13" y2="3" stroke-width="1.6"/>
                    <line x1="5" y1="15" x2="13" y2="15" stroke-width="1.6"/>
                    <line x1="7" y1="3" x2="7" y2="15" stroke-width="1.2"/>
                    <line x1="9" y1="3" x2="9" y2="15" stroke-width="1.2"/>
                    <line x1="11" y1="3" x2="11" y2="15" stroke-width="1.2"/>
                    <path d="M14 16.5 Q17 14 16 12 Q13.5 13 14 16.5Z" fill="#3fb950" stroke="none"/>
                </svg>
            </div>
            <!-- 4 Terpsichore 竖琴 -->
            <div class="muse-wrap" style="--i:4">
                <svg class="muse-icon" viewBox="0 0 18 18" fill="none" stroke="#3fb950" stroke-linecap="round">
                    <line x1="4" y1="2" x2="4" y2="16" stroke-width="1.8"/>
                    <line x1="4" y1="16" x2="14" y2="16" stroke-width="1.8"/>
                    <path d="M4 2 Q14.5 1 14.5 9" stroke-width="1.8"/>
                    <line x1="4" y1="5" x2="14" y2="11" stroke-width="1.2"/>
                    <line x1="4" y1="8.5" x2="14" y2="13.5" stroke-width="1.2"/>
                    <line x1="4" y1="12" x2="14" y2="15.5" stroke-width="1.2"/>
                </svg>
            </div>
            <!-- 5 Melpomene 悲剧面具 -->
            <div class="muse-wrap" style="--i:5">
                <svg class="muse-icon" viewBox="0 0 18 18" fill="none" stroke="#3fb950" stroke-linecap="round">
                    <ellipse cx="9" cy="9" rx="6.5" ry="7.5" stroke-width="1.6"/>
                    <ellipse cx="6.5" cy="7.5" rx="1.4" ry="1.8" stroke-width="1.4"/>
                    <ellipse cx="11.5" cy="7.5" rx="1.4" ry="1.8" stroke-width="1.4"/>
                    <path d="M5.5 13 Q9 11 12.5 13" stroke-width="1.6"/>
                </svg>
            </div>
            <!-- 6 Thalia 喜剧面具 -->
            <div class="muse-wrap" style="--i:6">
                <svg class="muse-icon" viewBox="0 0 18 18" fill="none" stroke="#3fb950" stroke-linecap="round">
                    <ellipse cx="9" cy="9" rx="6.5" ry="7.5" stroke-width="1.6"/>
                    <ellipse cx="6.5" cy="7.5" rx="1.4" ry="1.8" stroke-width="1.4"/>
                    <ellipse cx="11.5" cy="7.5" rx="1.4" ry="1.8" stroke-width="1.4"/>
                    <path d="M5.5 12 Q9 15 12.5 12" stroke-width="1.6"/>
                </svg>
            </div>
            <!-- 7 Polyhymnia 面纱 -->
            <div class="muse-wrap" style="--i:7">
                <svg class="muse-icon" viewBox="0 0 18 18" fill="none" stroke="#3fb950" stroke-linecap="round">
                    <path d="M3 5 Q9 3 15 5" stroke-width="1.8"/>
                    <path d="M3 5 Q5 10 4 16" stroke-width="1.4"/>
                    <path d="M7.5 5 Q9.5 11 8.5 16" stroke-width="1.4"/>
                    <path d="M12 5 Q14 11 13 16" stroke-width="1.4"/>
                    <path d="M15 5 Q13 10 14 16" stroke-width="1.4"/>
                </svg>
            </div>
            <!-- 8 Urania 天球仪与圆规 -->
            <div class="muse-wrap" style="--i:8">
                <svg class="muse-icon" viewBox="0 0 18 18" fill="none" stroke="#3fb950" stroke-linecap="round">
                    <circle cx="9" cy="8" r="5.5" stroke-width="1.6"/>
                    <path d="M3.5 8 Q9 6 14.5 8" stroke-width="1.2"/>
                    <path d="M3.5 8 Q9 10 14.5 8" stroke-width="1.2"/>
                    <line x1="9" y1="2.5" x2="9" y2="13.5" stroke-width="1.2"/>
                    <path d="M7 15 L9 17 L11 15" stroke-width="1.6"/>
                </svg>
            </div>
        </div>
    </div>
</div>
<script>
window.addEventListener('load', function() {
    var loader = document.getElementById('muse-loader');
    if (!loader) return;
    setTimeout(function() {
        loader.classList.add('fade-out');
        setTimeout(function() { loader.style.display = 'none'; }, 650);
    }, 1800);
});
</script>
<?php endif; // skip_loader ?>
<style>
    .main-navbar {
        background: #0d1117;
        border-bottom: 1px solid #30363d;
        padding: 0 20px;
        height: 56px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 1000;
        font-family: "Microsoft YaHei", sans-serif;
    }
    .nav-logo {
        font-size: 18px;
        font-weight: 700;
        color: #3fb950;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 8px;
        letter-spacing: .5px;
        font-family: "Courier New", monospace;
    }
    .nav-logo:hover { color: #5fdd70; }
    .nav-logo-icon {
        width: 28px; height: 28px;
        display: flex; align-items: center; justify-content: center;
    }
    .nav-logo-icon img { width: 28px; height: 28px; object-fit: contain; }
    .nav-links {
        display: flex;
        gap: 16px;
        align-items: center;
    }
    .nav-item {
        text-decoration: none;
        color: #8b949e;
        font-size: 13px;
        transition: color .15s;
        display: flex;
        align-items: center;
        gap: 4px;
        position: relative;
    }
    .nav-item:hover { color: #e6edf3; }
    /* ── 后台抽屉 ── */
    .admin-drawer { position: relative; }
    .admin-drawer-btn {
        display: flex; align-items: center; gap: 5px;
        padding: 4px 12px; border-radius: 4px; cursor: pointer; font-family: inherit;
        background: rgba(227,179,65,.1); color: #e3b341;
        border: 1px solid rgba(227,179,65,.35); font-size: 12px; font-weight: 700;
        transition: .15s; white-space: nowrap;
    }
    .admin-drawer-btn:hover { background: rgba(227,179,65,.18); }
    .admin-drawer-btn .arrow { font-size: 9px; transition: transform .2s; }
    .admin-drawer-btn.open .arrow { transform: rotate(180deg); }
    .admin-drawer-menu {
        display: none; position: absolute; top: calc(100% + 8px); right: 0;
        min-width: 180px; background: #161b22; border: 1px solid #30363d;
        border-radius: 6px; overflow: hidden; z-index: 2000;
        box-shadow: 0 8px 24px rgba(0,0,0,.5);
    }
    .admin-drawer-menu.open { display: block; }
    .adm-item {
        display: flex; align-items: center; gap: 9px; padding: 10px 14px;
        text-decoration: none; font-size: 13px; color: #c9d1d9;
        transition: background .12s; position: relative; font-family: "Microsoft YaHei", sans-serif;
    }
    .adm-item:hover { background: #21262d; color: #e6edf3; }
    .adm-item .adm-icon { font-size: 14px; width: 18px; text-align: center; flex-shrink: 0; }
    .adm-item .adm-badge {
        margin-left: auto; background: #f85149; color: #fff; border-radius: 9px;
        min-width: 16px; height: 16px; font-size: 10px; display: flex; align-items: center;
        justify-content: center; padding: 0 3px; font-weight: 700;
    }
    .adm-sep { height: 1px; background: #21262d; margin: 3px 0; }
    .nav-search {
        display: flex;
        align-items: center;
        background: #161b22;
        border: 1px solid #30363d;
        padding: 4px 12px;
        border-radius: 4px;
        gap: 6px;
        transition: border-color .2s;
    }
    .nav-search:focus-within { border-color: #3fb950; }
    .nav-search input {
        border: none;
        background: transparent;
        outline: none;
        font-size: 13px;
        width: 140px;
        color: #e6edf3;
        font-family: inherit;
        padding: 0;
    }
    .nav-search button {
        border: none; background: none; cursor: pointer;
        color: #6e7681; font-size: 13px; padding: 0; line-height: 1;
        transition: color .15s;
    }
    .nav-search button:hover { color: #3fb950; }
    .user-avatar-small {
        width: 26px; height: 26px;
        border-radius: 50%; object-fit: cover;
        border: 1px solid #30363d;
    }
    .notif-badge {
        position: absolute;
        top: -6px; right: -8px;
        background: #f85149;
        color: #fff;
        border-radius: 10px;
        min-width: 16px; height: 16px;
        font-size: 10px;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700;
        padding: 0 3px;
        font-family: "Courier New", monospace;
    }
    .nav-divider { width: 1px; height: 20px; background: #30363d; }

    /* ── 汉堡按钮（仅移动端） ── */
    .nav-hamburger {
        display: none;
        flex-direction: column;
        gap: 5px;
        cursor: pointer;
        padding: 6px;
        border-radius: 4px;
        border: 1px solid #30363d;
        background: transparent;
        transition: border-color .15s;
    }
    .nav-hamburger:hover { border-color: #3fb950; }
    .nav-hamburger span {
        display: block; width: 20px; height: 2px;
        background: #8b949e; border-radius: 2px; transition: .2s;
    }
    .nav-hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); background: #3fb950; }
    .nav-hamburger.open span:nth-child(2) { opacity: 0; }
    .nav-hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); background: #3fb950; }

    /* 搜索图标按钮（移动端直接可见） */
    .nav-search-icon {
        display: none;
        align-items: center;
        justify-content: center;
        width: 34px; height: 34px;
        color: #8b949e; font-size: 17px;
        border: 1px solid #30363d; border-radius: 4px;
        text-decoration: none; transition: .15s;
    }
    .nav-search-icon:hover { border-color: #3fb950; color: #3fb950; }

    @media (max-width: 768px) {
        .nav-hamburger { display: flex; }
        .nav-search-icon { display: flex; }
        .nav-search { display: none !important; }
        .nav-divider { display: none; }
        .nav-links {
            display: none;
            position: absolute;
            top: 56px; left: 0; right: 0;
            background: #0d1117;
            border-bottom: 1px solid #30363d;
            padding: 12px 20px 16px;
            flex-direction: column;
            gap: 4px;
            z-index: 999;
        }
        .nav-links.open { display: flex; }
        .nav-item { padding: 10px 0; font-size: 14px; border-bottom: 1px solid #21262d; }
        .nav-item:last-child { border-bottom: none; }
        .btn-publish-nav { align-self: flex-start; margin: 6px 0; }
        .admin-drawer-btn { align-self: flex-start; }
        .admin-drawer-menu { position: static; box-shadow: none; border-radius: 4px; margin-top: 4px; width: 100%; }
    }
</style>

<nav class="main-navbar" style="position:relative;">
    <a href="<?= $base ?>index.php" class="nav-logo">
        <span class="nav-logo-icon"><img src="<?= $base ?>assets/logo.svg" alt="MUSE"></span> MUSE
    </a>

    <!-- 移动端右侧按钮组 -->
    <div style="display:flex;align-items:center;gap:6px;">
        <a href="<?= $base ?>search.php" class="nav-search-icon" title="搜索">⌕</a>
        <button class="nav-hamburger" id="nav-hamburger" aria-label="菜单" onclick="toggleNavMenu()">
            <span></span><span></span><span></span>
        </button>
    </div>

    <div class="nav-links" id="nav-links">
        <form action="<?= $base ?>search.php" method="GET" class="nav-search">
            <input type="text" name="keyword" placeholder="搜索...">
            <button type="submit">⌕</button>
        </form>

        <a href="<?= $base ?>categories.php" class="nav-item">分区</a>
        <a href="<?= $base ?>square.php" class="nav-item">广场</a>
        <a href="<?= $base ?>sheets.php" class="nav-item">☁ 光遇曲库</a>
        <?php if ($is_logged_in): ?>
        <a href="<?= $base ?>pages/moments.php" class="nav-item">动态</a>
        <?php endif; ?>
        <a href="<?= $base ?>dating.php" class="nav-item">交友</a>
        <a href="<?= $base ?>pages/ow_analyzer.php" class="nav-item">⌖ 守望战绩</a>
        <div class="nav-divider"></div>

        <?php
        $has_admin = in_array($current_role, ['admin', 'owner', 'reviewer']) || !empty($_SESSION['is_cs']);
        if ($has_admin): ?>
        <a href="<?= $base ?>pages/admin.php" class="admin-drawer-btn" style="text-decoration:none;">
            ⚙ 后台
            <?php
            $pending_total = ($pending_posts ?? 0) + ($pending_reports ?? 0) + ($pending_profile_reviews ?? 0);
            if ($pending_total > 0): ?>
            <span style="background:#f85149;color:#fff;border-radius:9px;min-width:16px;height:16px;font-size:10px;display:inline-flex;align-items:center;justify-content:center;padding:0 3px;font-weight:700;"><?= $pending_total > 99 ? '99+' : $pending_total ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <div class="nav-divider"></div>

        <?php if ($is_logged_in): ?>
            <a href="<?= $base ?>pages/publish.php" class="btn-publish-nav">+ 发帖</a>
            <a href="<?= $base ?>pages/notifications.php" class="nav-item" title="消息中心" style="position:relative;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($unread_count > 0): ?>
                    <span class="notif-badge"><?= $unread_count > 99 ? '99+' : $unread_count ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= $base ?>pages/profile.php" class="nav-item" style="position:relative;">
                <span style="position:relative;display:inline-block;width:26px;height:26px;flex-shrink:0;vertical-align:middle;">
                    <img src="<?= $base ?>uploads/avatars/<?= htmlspecialchars($_SESSION['avatar'] ?? 'default.png') ?>"
                         class="user-avatar-small"
                         style="width:100%;height:100%;display:block;"
                         onerror="this.onerror=null;this.src='<?= $base ?>uploads/avatars/default.png'">
                    <?php if (!empty($header_user_deco['frame']['image_path'])): ?>
                    <img src="<?= $base ?><?= htmlspecialchars($header_user_deco['frame']['image_path']) ?>" alt=""
                         style="position:absolute;top:-3px;left:-3px;width:32px;height:32px;pointer-events:none;">
                    <?php endif; ?>
                </span>
                <span style="color:#e6edf3; font-size:13px;"><?= htmlspecialchars($_SESSION['username']) ?></span>
            </a>
            <a href="<?= $base ?>pages/decorations.php" class="nav-item" title="我的装饰" style="font-size:12px;">装饰</a>
            <a href="<?= $base ?>pages/settings.php" class="nav-item" title="设置" style="font-size:12px;">设置</a>
            <a href="<?= $base ?>pages/logout.php" class="nav-item" style="font-size:12px;">退出</a>
        <?php else: ?>
            <a href="<?= $base ?>pages/login.php" class="nav-item">登录</a>
            <a href="<?= $base ?>pages/register.php" class="btn btn-green" style="padding:5px 14px; font-size:13px;">注册</a>
        <?php endif; ?>
    </div>
</nav>
<script>
function toggleNavMenu() {
    const btn   = document.getElementById('nav-hamburger');
    const links = document.getElementById('nav-links');
    btn.classList.toggle('open');
    links.classList.toggle('open');
}

function toggleAdminDrawer(e) {
    e.stopPropagation();
    const btn  = document.getElementById('adminDrawerBtn');
    const menu = document.getElementById('adminDrawerMenu');
    if (!btn || !menu) return;
    const open = menu.classList.toggle('open');
    btn.classList.toggle('open', open);
}

document.addEventListener('click', function(e) {
    // 关闭移动端导航
    const hbBtn   = document.getElementById('nav-hamburger');
    const navLinks = document.getElementById('nav-links');
    if (hbBtn && navLinks && !hbBtn.contains(e.target) && !navLinks.contains(e.target)) {
        hbBtn.classList.remove('open');
        navLinks.classList.remove('open');
    }
    // 关闭后台抽屉
    const drawer = document.getElementById('adminDrawer');
    const menu   = document.getElementById('adminDrawerMenu');
    const btn    = document.getElementById('adminDrawerBtn');
    if (drawer && !drawer.contains(e.target)) {
        menu && menu.classList.remove('open');
        btn  && btn.classList.remove('open');
    }
});
</script>

<?php if (!empty($_SESSION['is_banned'])): ?>
<?php
$_ban_until_str = $_SESSION['ban_until']
    ? '封禁至 ' . date('Y年m月d日', strtotime($_SESSION['ban_until']))
    : '永久封禁';
$_ban_cs_url = $base . 'pages/cs_chat.php';
?>
<div id="ban-banner" style="background:rgba(248,81,73,.1);border-bottom:1px solid rgba(248,81,73,.35);padding:8px 20px;display:flex;align-items:center;gap:12px;font-family:'Courier New',monospace;font-size:12px;flex-wrap:wrap;">
    <span style="color:#f85149;font-weight:700;">⛔ 账号已被限制</span>
    <span style="color:#8b949e;">原因：<span style="color:#e6edf3;"><?= htmlspecialchars($_SESSION['ban_reason']) ?></span></span>
    <span style="color:#6e7681;">|</span>
    <span style="color:#6e7681;"><?= htmlspecialchars($_ban_until_str) ?></span>
    <a href="<?= $_ban_cs_url ?>" style="color:#58a6ff;text-decoration:none;margin-left:auto;">💬 联系客服申诉 →</a>
</div>
<?php endif; ?>

<?php if ($is_logged_in): ?>
<style>
.cs-fab {
    position: fixed;
    bottom: 24px;
    left: 20px;
    z-index: 900;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #161b22;
    border: 1px solid #30363d;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: #6e7681;
    font-size: 18px;
    transition: .2s;
    box-shadow: 0 2px 12px rgba(0,0,0,.4);
}
.cs-fab:hover {
    border-color: #3fb950;
    color: #3fb950;
    box-shadow: 0 0 14px rgba(63,185,80,.2);
}
.cs-fab-tip {
    position: absolute;
    left: 50px;
    bottom: 50%;
    transform: translateY(50%);
    background: #161b22;
    border: 1px solid #30363d;
    color: #8b949e;
    font-size: 11px;
    font-family: "Courier New", monospace;
    padding: 4px 10px;
    border-radius: 4px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity .15s;
}
.cs-fab:hover .cs-fab-tip { opacity: 1; }
</style>
<a href="<?= $base ?>pages/cs_chat.php" class="cs-fab" title="联系客服">
    💬
    <span class="cs-fab-tip">联系客服</span>
</a>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var origin = location.origin;
    document.querySelectorAll('a[href]').forEach(function(a) {
        var h = a.getAttribute('href');
        if (!h || h === '#' || h.startsWith('javascript:') || a.target) return;
        // 只对外部链接加 _blank
        if ((h.startsWith('http://') || h.startsWith('https://')) && !h.startsWith(origin)) {
            a.target = '_blank';
            a.rel = (a.rel ? a.rel + ' ' : '') + 'noopener';
        }
    });
});
</script>

<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ark_pipeline.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$uid = (int)$_SESSION['user_id'];

@set_time_limit(180);

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$error  = '';
$notice = '';

// ─── 路径 C：粘贴 SDK token ───
if ($action === 'bind_token') {
    $hg_token = trim($_POST['hg_token'] ?? '');
    if ($hg_token === '' || mb_strlen($hg_token) < 16 || mb_strlen($hg_token) > 64) {
        $error = 'token 长度异常（应该是 24 字符左右），请重新从 web-api.hypergryph.com/account/info/hg 复制 content 字段';
    } else {
        $r = ark_bind_with_token($conn, $uid, $hg_token);
        if (!$r['ok']) $error = $r['msg'];
        else { $notice = '绑定成功，已生成分析报告'; }
    }
}

// ─── 路径 B：手机号 + 密码（fallback）───
if ($action === 'bind_password') {
    $phone = trim($_POST['phone'] ?? '');
    $pwd   = $_POST['password'] ?? '';
    if ($phone === '' || $pwd === '') {
        $error = '手机号或密码不能为空';
    } else {
        $login = hg_token_by_phone_password($phone, $pwd);
        if (!$login['ok']) $error = $login['msg'];
        else {
            $r = ark_bind_with_token($conn, $uid, $login['token']);
            if (!$r['ok']) $error = $r['msg'];
            else { $notice = '绑定成功，已生成分析报告'; }
        }
        $pwd = null;
        unset($_POST['password']);
    }
}

// ─── 解绑 ───
if ($action === 'unbind') {
    ark_delete_credentials($conn, $uid);
    header('Location: ark_bind.php?unbinded=1');
    exit;
}
if (isset($_GET['unbinded'])) $notice = '已解绑当前鹰角通行证';

// ─── 强制刷新分析 ───
$force_refresh = (($action === 'refresh') || isset($_GET['refresh']));

// ─── 读绑定状态 + 选当前查看哪一次分析 ───
$creds        = ark_get_credentials($conn, $uid);
$cur_query    = null;
$from_cache   = false;
$auth_expired = false;
$view_hid     = isset($_GET['hid']) && ctype_digit($_GET['hid']) ? (int)$_GET['hid'] : 0;

if ($creds) {
    if ($view_hid > 0) {
        // 查指定历史记录
        $cur_query = ark_get_query_by_id($conn, $view_hid, $uid);
        if ($cur_query) $from_cache = true;
        else $error = '指定的历史记录不存在或无权访问';
    } else {
        if (!$force_refresh) {
            $cur_query = ark_get_recent_query($conn, $uid);
            if ($cur_query) $from_cache = true;
        }
        if (!$cur_query) {
            // 没缓存或强制刷新 → 重新跑分析
            $r = ark_run_analysis_for_user($conn, $creds);
            if (!$r['ok']) {
                $error = $r['msg'];
                // 拉数据失败 = 授权过期 → 强制重新绑定流程，但保留历史
                $auth_expired = true;
            } else {
                $cur_query = $r['query'];
            }
        }
    }
}

// 历史记录无论绑没绑、过没过期都拿（让用户能看以前的报告）
$history_items = ark_list_queries($conn, $uid, 5);

// 解析 3 段卡片
$sections = [];
$snapshot = null;
if ($cur_query) {
    $sections = ark_parse_advice_sections((string)$cur_query['deepseek_advice']);
    $snapshot = json_decode((string)$cur_query['snapshot_data'], true);
}

// 段标题 → 颜色 kind 映射
function ark_section_kind(string $title): string {
    if (mb_strpos($title, '队伍') !== false) return 'team';
    if (mb_strpos($title, '资源') !== false) return 'resource';
    if (mb_strpos($title, '高难') !== false) return 'high';
    return 'misc';
}

function time_ago_cn_local(string $ts): string {
    $t = strtotime($ts); if (!$t) return $ts;
    $d = time() - $t;
    if ($d < 60)     return $d . '秒前';
    if ($d < 3600)   return floor($d / 60) . '分钟前';
    if ($d < 86400)  return floor($d / 3600) . '小时前';
    if ($d < 604800) return floor($d / 86400) . '天前';
    return date('Y-m-d', $t);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>明日方舟分析 · 缪斯 MUSE</title>
<link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
<link rel="stylesheet" href="../style.css">
<style>
.ak-wrap{max-width:1100px;margin:24px auto;padding:0 16px 80px;}
.ak-title{font-size:13px;font-weight:700;color:#3fb950;letter-spacing:1.5px;text-transform:uppercase;font-family:"Courier New",monospace;margin:0 0 6px;}
.ak-title::before{content:'// ';}
.ak-sub{color:#6e7681;font-size:12px;font-family:"Courier New",monospace;margin:0 0 18px;}

.ak-error{background:rgba(248,81,73,.08);border:1px solid rgba(248,81,73,.4);color:#f85149;border-radius:6px;padding:12px 14px;font-size:13px;margin-bottom:14px;}
.ak-notice{background:rgba(63,185,80,.08);border:1px solid rgba(63,185,80,.4);color:#3fb950;border-radius:6px;padding:12px 14px;font-size:13px;margin-bottom:14px;}
.ak-cache-tip{background:rgba(88,166,255,.06);border:1px solid rgba(88,166,255,.3);color:#58a6ff;border-radius:6px;padding:9px 14px;font-size:12px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.ak-cache-tip a{color:#3fb950;text-decoration:underline;}

/* 绑定表单 */
.ak-bind-card{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:20px;margin-bottom:14px;}
.ak-bind-card.recommend{border-color:#3fb950;}
.ak-bind-card h3{margin:0 0 12px;font-size:14px;font-weight:700;color:#e6edf3;letter-spacing:.5px;}
.ak-bind-card.recommend h3{color:#3fb950;}
.ak-bind-card h3 .recom-tag{display:inline-block;background:#3fb950;color:#0d1117;font-size:10px;padding:2px 8px;border-radius:3px;margin-left:8px;font-family:"Courier New",monospace;letter-spacing:1px;}
.ak-bind-card .steps{color:#8b949e;font-size:13px;line-height:1.9;padding-left:22px;margin:8px 0 14px;}
.ak-bind-card .steps li{margin:3px 0;}
.ak-bind-card code{background:#0d1117;border:1px solid #30363d;border-radius:3px;padding:2px 8px;color:#e3b341;font-size:12px;font-family:"Courier New",monospace;}
.ak-bind-card input{width:100%;box-sizing:border-box;background:#0d1117;border:1px solid #30363d;color:#e6edf3;border-radius:4px;padding:10px 12px;font-family:"Courier New",monospace;font-size:13px;outline:none;}
.ak-bind-card input:focus{border-color:#3fb950;}
.ak-bind-card label{display:block;font-size:12px;color:#8b949e;margin:10px 0 4px;font-family:"Courier New",monospace;}
.ak-bind-card button{background:#238636;border:1px solid #2ea043;color:#fff;border-radius:4px;padding:10px 22px;cursor:pointer;font-weight:700;font-family:inherit;font-size:13px;margin-top:14px;}
.ak-bind-card button:hover{background:#2ea043;}
.ak-bind-card.fallback button{background:#373e47;border-color:#444c56;}
.ak-bind-card.fallback button:hover{background:#444c56;}
.ak-bind-card .safety{color:#6e7681;font-size:11px;margin-top:8px;font-family:"Courier New",monospace;}

/* 已绑：profile */
.ak-profile{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:18px;display:flex;gap:18px;align-items:center;margin-bottom:14px;flex-wrap:wrap;}
.ak-profile .ak-name{font-size:18px;font-weight:700;color:#e6edf3;font-family:"Microsoft YaHei",sans-serif;}
.ak-profile .ak-meta{font-size:12px;color:#8b949e;font-family:"Courier New",monospace;margin-top:4px;}
.ak-profile .ak-actions{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;}
.ak-profile .ak-btn{background:#0d1117;border:1px solid #30363d;color:#c9d1d9;padding:7px 14px;border-radius:4px;font-size:12px;text-decoration:none;font-family:"Courier New",monospace;cursor:pointer;}
.ak-profile .ak-btn:hover{border-color:#58a6ff;color:#58a6ff;}
.ak-profile .ak-btn.danger:hover{border-color:#f85149;color:#f85149;}

/* KPI 概要 */
.ak-kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px;}
.ak-kpi{background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:11px 13px;}
.ak-kpi .lab{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:.8px;text-transform:uppercase;}
.ak-kpi .num{font-size:22px;color:#3fb950;font-weight:700;font-family:"Courier New",monospace;margin-top:4px;line-height:1;}
.ak-kpi .sub{font-size:11px;color:#8b949e;margin-top:5px;line-height:1.5;}

/* 历史侧栏 */
.ak-history{margin-bottom:14px;}
.ak-history-label{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:8px;}
.ak-history-label::before{content:'// ';opacity:.6;}
.ak-history-list{display:flex;gap:8px;flex-wrap:wrap;}
.ak-history-item{display:inline-flex;align-items:center;gap:8px;background:#161b22;border:1px solid #30363d;border-radius:4px;padding:6px 10px;font-size:12px;color:#c9d1d9;text-decoration:none;font-family:"Courier New",monospace;}
.ak-history-item:hover{border-color:#58a6ff;color:#58a6ff;}
.ak-history-item.active{border-color:#3fb950;background:rgba(63,185,80,.06);color:#3fb950;}

/* 3 段卡片 */
.ak-sections{display:flex;flex-direction:column;gap:12px;}
.ak-section{background:#0d1117;border:1px solid #30363d;border-left:3px solid #6e7681;border-radius:6px;padding:14px 20px;line-height:1.85;color:#c9d1d9;font-size:14px;}
.ak-section .head{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.ak-section .tag{font-family:"Courier New",monospace;font-size:10px;font-weight:700;letter-spacing:1.5px;padding:2px 8px;border-radius:3px;background:rgba(110,118,129,.15);color:#8b949e;border:1px solid #30363d;}
.ak-section .title{font-size:15px;font-weight:700;color:#e6edf3;}
.ak-section .body p{margin:5px 0;}
.ak-section .body ul{margin:5px 0;padding-left:22px;}
.ak-section .body li{margin:4px 0;}
.ak-section .body strong{color:#e3b341;}
.ak-section .body code{background:#161b22;border:1px solid #30363d;border-radius:3px;padding:1px 5px;color:#e3b341;font-size:12px;}

.ak-section.kind-team{border-left-color:#3fb950;}
.ak-section.kind-team .tag{background:rgba(63,185,80,.12);color:#3fb950;border-color:rgba(63,185,80,.3);}
.ak-section.kind-team .title{color:#3fb950;}

.ak-section.kind-resource{border-left-color:#58a6ff;}
.ak-section.kind-resource .tag{background:rgba(88,166,255,.12);color:#58a6ff;border-color:rgba(88,166,255,.3);}
.ak-section.kind-resource .title{color:#58a6ff;}

.ak-section.kind-high{border-left-color:#a78bfa;}
.ak-section.kind-high .tag{background:rgba(167,139,250,.12);color:#a78bfa;border-color:rgba(167,139,250,.3);}
.ak-section.kind-high .title{color:#a78bfa;}

.ak-foot{margin-top:18px;color:#484f58;font-size:11px;font-family:"Courier New",monospace;text-align:center;}
.ak-foot a{color:#6e7681;}

/* 通用 section block */
.ak-block{background:#161b22;border:1px solid #30363d;border-radius:8px;margin-bottom:14px;overflow:hidden;}
.ak-block-head{padding:10px 16px;border-bottom:1px solid #21262d;display:flex;justify-content:space-between;align-items:center;}
.ak-block-head h3{margin:0;font-size:11px;font-weight:700;color:#6e7681;letter-spacing:1.5px;text-transform:uppercase;font-family:"Courier New",monospace;}
.ak-block-head h3::before{content:'// ';opacity:.6;}
.ak-block-body{padding:16px;}

/* 六维图 + KPI 网格 */
.ak-radar-row{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;}
@media (max-width:820px){.ak-radar-row{grid-template-columns:1fr;}}
.ak-radar-wrap{background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:10px 6px 8px;display:flex;flex-direction:column;align-items:center;}
.ak-radar-title{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1.2px;text-transform:uppercase;margin:2px 0 4px;}
.ak-radar-title::before{content:'// ';opacity:.6;}
.ak-radar-foot{font-size:10px;color:#484f58;font-family:"Courier New",monospace;text-align:center;line-height:1.5;padding:0 8px 4px;}

/* 干员卡片网格 */
.ak-op-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:8px;}
.ak-op{background:#0d1117;border:1px solid #30363d;border-radius:5px;padding:8px 10px;display:flex;flex-direction:column;gap:3px;transition:.12s;}
.ak-op:hover{border-color:#58a6ff;}
.ak-op.r6{border-left:3px solid #e3b341;}
.ak-op.r5{border-left:3px solid #a78bfa;}
.ak-op.unfinished{border-left-color:#6e7681;}
.ak-op .nm{font-size:13px;font-weight:700;color:#e6edf3;font-family:"Microsoft YaHei",sans-serif;line-height:1.2;}
.ak-op .meta{font-size:11px;color:#8b949e;font-family:"Courier New",monospace;display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
.ak-op .badge{padding:1px 5px;border-radius:2px;background:rgba(110,118,129,.15);color:#8b949e;font-size:10px;}
.ak-op .e2{background:rgba(63,185,80,.15);color:#3fb950;}
.ak-op .e1{background:rgba(240,136,62,.15);color:#f0883e;}
.ak-op .lv{color:#c9d1d9;}
.ak-op .spec{color:#e3b341;}
.ak-op .equip{color:#58a6ff;}
.ak-op-toggle{margin-top:10px;text-align:center;}
.ak-op-toggle button{background:transparent;border:1px solid #30363d;color:#8b949e;border-radius:3px;padding:5px 14px;cursor:pointer;font-size:12px;font-family:"Courier New",monospace;}
.ak-op-toggle button:hover{border-color:#58a6ff;color:#58a6ff;}
.ak-op-hidden{display:none;}

/* 肉鸽卡片 */
.ak-rogue-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;}
.ak-rogue{background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:11px 13px;}
.ak-rogue .nm{font-size:12px;font-weight:700;color:#a78bfa;font-family:"Microsoft YaHei",sans-serif;margin-bottom:6px;line-height:1.3;}
.ak-rogue .row{display:flex;justify-content:space-between;font-size:11px;color:#8b949e;font-family:"Courier New",monospace;margin:2px 0;}
.ak-rogue .row b{color:#e6edf3;}

/* 进度（主线/活动/常规）*/
.ak-prog-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;}
.ak-prog{background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:10px 13px;}
.ak-prog .lab{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:.8px;text-transform:uppercase;}
.ak-prog .val{font-size:18px;font-weight:700;color:#58a6ff;font-family:"Courier New",monospace;margin-top:4px;}
.ak-prog .bar{height:5px;background:#161b22;border-radius:2px;margin-top:6px;overflow:hidden;}
.ak-prog .bar .fill{height:100%;background:linear-gradient(90deg,#3fb950,#58a6ff);}

/* 分享按钮 + 模态 */
.ak-share-row{display:flex;gap:10px;justify-content:flex-end;margin-bottom:14px;}
.ak-share-btn{background:linear-gradient(135deg,#a78bfa,#58a6ff);border:0;color:#fff;font-weight:700;padding:9px 22px;border-radius:5px;cursor:pointer;font-size:13px;}
.ak-share-btn:hover{filter:brightness(1.1);}
.ak-share-modal{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:9999;}
.ak-share-modal.show{display:flex;}
.ak-share-box{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:24px 32px;color:#e6edf3;max-width:520px;width:90%;}
.ak-share-box h3{margin:0 0 12px;font-size:15px;color:#a78bfa;}
.ak-share-box .url{background:#0d1117;border:1px solid #30363d;border-radius:4px;padding:10px 12px;font-family:"Courier New",monospace;font-size:12px;color:#3fb950;word-break:break-all;margin:8px 0;}
.ak-share-box .acts{display:flex;gap:10px;margin-top:14px;justify-content:flex-end;}
.ak-share-box button{background:#238636;color:#fff;border:0;padding:8px 18px;border-radius:4px;cursor:pointer;font-weight:600;}
.ak-share-box button.cancel{background:#373e47;}

/* loading 模态 */
.ak-loading{position:fixed;inset:0;background:rgba(0,0,0,.75);display:none;align-items:center;justify-content:center;z-index:9999;}
.ak-loading.show{display:flex;}
.ak-loading .box{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:24px 36px;color:#e6edf3;font-family:"Courier New",monospace;text-align:center;}
.ak-loading .spinner{display:inline-block;width:32px;height:32px;border:3px solid #30363d;border-top-color:#3fb950;border-radius:50%;animation:akspin 1s linear infinite;margin-bottom:12px;}
@keyframes akspin{to{transform:rotate(360deg);}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="ak-wrap">
    <h1 class="ak-title">Arknights Analyzer</h1>
    <p class="ak-sub">绑定鹰角通行证 → 拉取干员/养成/进度 → DeepSeek 给出针对性养成建议</p>

    <?php if ($error): ?>
        <div class="ak-error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($notice): ?>
        <div class="ak-notice">✓ <?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>

    <?php if ($creds && !$auth_expired): ?>
        <!-- 已绑：profile -->
        <div class="ak-profile">
            <div style="flex:1;min-width:200px;">
                <div class="ak-name"><?= htmlspecialchars($creds['nickname'] ?: 'Doctor') ?></div>
                <div class="ak-meta">
                    UID <b><?= htmlspecialchars($creds['game_uid']) ?></b>
                    · 渠道 <b><?= (int)$creds['channel_master_id'] === 1 ? '官服' : 'B服' ?></b>
                    · 上次验证 <?= time_ago_cn_local($creds['last_verified_at']) ?>
                </div>
            </div>
            <div class="ak-actions">
                <a class="ak-btn" href="ark_bind.php?refresh=1" onclick="document.getElementById('ak-loader').classList.add('show')">⟳ 重新分析</a>
                <form method="POST" style="display:inline;" onsubmit="return confirm('解绑会删除你的鹰角通行证凭据（不会删历史分析），确认？');">
                    <input type="hidden" name="action" value="unbind">
                    <button type="submit" class="ak-btn danger" style="background:transparent;border:1px solid #30363d;color:#c9d1d9;font-family:'Courier New',monospace;cursor:pointer;font-size:12px;padding:7px 14px;">解绑</button>
                </form>
            </div>
        </div>

        <?php if ($from_cache && $cur_query): ?>
        <div class="ak-cache-tip">
            ⏱ 分析数据生成于 <?= time_ago_cn_local($cur_query['created_at']) ?>
            <span style="margin-left:auto;">
                <a href="ark_bind.php?refresh=1" onclick="document.getElementById('ak-loader').classList.add('show')">立刻重新分析</a>
            </span>
        </div>
        <?php endif; ?>

        <?php if ($snapshot):
            $p = $snapshot['player'] ?? [];
            $roster = $snapshot['roster']   ?? [];
            $building = $snapshot['building'] ?? [];
            $progress = $snapshot['progress'] ?? [];
            $by_r = $roster['byRarity'] ?? [];
            $six_e2_list = $roster['sixStarE2'] ?? [];
            $six_un_list = $roster['sixStarUnfinished'] ?? [];
            $six_e2 = count($six_e2_list);
            $six_un = count($six_un_list);
            $radar = ark_radar_axes($snapshot);
        ?>

        <!-- KPI + 六维图 -->
        <div class="ak-block">
            <div class="ak-block-head"><h3>玩家档案 · UID <?= htmlspecialchars($p['uid'] ?? '') ?></h3></div>
            <div class="ak-block-body">
                <div class="ak-radar-row">
                    <div>
                        <div class="ak-kpi-row" style="margin-bottom:10px;">
                            <div class="ak-kpi">
                                <div class="lab">博士等级</div>
                                <div class="num"><?= (int)($p['level'] ?? 0) ?></div>
                                <div class="sub"><?= (int)($p['registerDays'] ?? 0) ?> 天老博士</div>
                            </div>
                            <div class="ak-kpi">
                                <div class="lab">干员总数</div>
                                <div class="num"><?= (int)($p['charCnt'] ?? 0) ?></div>
                                <div class="sub">皮肤 <?= (int)($p['skinCnt'] ?? 0) ?></div>
                            </div>
                            <div class="ak-kpi">
                                <div class="lab">6 星精二</div>
                                <div class="num"><?= $six_e2 ?></div>
                                <div class="sub">还有 <?= $six_un ?> 个没精二</div>
                            </div>
                            <div class="ak-kpi">
                                <div class="lab">理智</div>
                                <div class="num" style="color:#58a6ff;font-size:18px;"><?= htmlspecialchars($p['ap'] ?? '—') ?></div>
                                <div class="sub">秘书：<?= htmlspecialchars($p['secretary'] ?? '—') ?></div>
                            </div>
                        </div>
                        <!-- 星级分布条 -->
                        <div style="display:flex;gap:6px;align-items:flex-end;height:100px;padding:6px 8px;background:#0d1117;border:1px solid #30363d;border-radius:6px;">
                            <?php $colors = [0=>'#6e7681', 1=>'#9ca3af', 2=>'#22c55e', 3=>'#3b82f6', 4=>'#a78bfa', 5=>'#e3b341'];
                            $max_cnt = max(array_values($by_r) ?: [1]);
                            ?>
                            <?php for ($r=5; $r>=0; $r--):
                                $cnt = $by_r[$r] ?? 0;
                                $h = $cnt > 0 ? max(6, round($cnt / $max_cnt * 56)) : 2;
                            ?>
                            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:3px;height:100%;">
                                <span style="font-size:10px;color:#8b949e;font-family:Courier New,monospace;line-height:1;"><?= $cnt ?></span>
                                <div style="width:100%;height:<?= $h ?>px;background:<?= $colors[$r] ?>;border-radius:2px 2px 0 0;"></div>
                                <span style="font-size:9px;color:#6e7681;font-family:Courier New,monospace;line-height:1;"><?= $r+1 ?>★</span>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="ak-radar-wrap">
                        <div class="ak-radar-title">六维画像</div>
                        <?= ark_radar_svg($radar) ?>
                        <div class="ak-radar-foot">每轴 0–100，越靠外越好<br>顶尖玩家不一定满分</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 进度概要：常规 + 主线/支线 + 危机合约 -->
        <div class="ak-block">
            <div class="ak-block-head"><h3>进度概览</h3></div>
            <div class="ak-block-body">
                <div class="ak-prog-grid">
                    <?php $rt = $progress['routine'] ?? [];
                          [$d_cur, $d_tot] = array_pad(explode('/', $rt['daily'] ?? '0/0'), 2, 0);
                          [$w_cur, $w_tot] = array_pad(explode('/', $rt['weekly'] ?? '0/0'), 2, 0);
                          $d_pct = $d_tot > 0 ? round($d_cur / $d_tot * 100) : 0;
                          $w_pct = $w_tot > 0 ? round($w_cur / $w_tot * 100) : 0;
                    ?>
                    <div class="ak-prog">
                        <div class="lab">每日任务</div>
                        <div class="val"><?= htmlspecialchars($rt['daily'] ?? '0/0') ?></div>
                        <div class="bar"><div class="fill" style="width:<?= $d_pct ?>%;"></div></div>
                    </div>
                    <div class="ak-prog">
                        <div class="lab">每周任务</div>
                        <div class="val"><?= htmlspecialchars($rt['weekly'] ?? '0/0') ?></div>
                        <div class="bar"><div class="fill" style="width:<?= $w_pct ?>%;"></div></div>
                    </div>
                    <?php $cp = $progress['campaign'] ?? [];
                          [$c_cur, $c_tot] = array_pad(explode('/', $cp['reward'] ?? '0/0'), 2, 0);
                          $c_pct = $c_tot > 0 ? round($c_cur / $c_tot * 100) : 0;
                    ?>
                    <div class="ak-prog">
                        <div class="lab">剿灭周报</div>
                        <div class="val"><?= htmlspecialchars($cp['reward'] ?? '0/0') ?></div>
                        <div class="bar"><div class="fill" style="width:<?= $c_pct ?>%;"></div></div>
                    </div>
                    <div class="ak-prog">
                        <div class="lab">剿灭满杀</div>
                        <div class="val"><?= (int)($cp['fullKillStages'] ?? 0) ?> / <?= (int)($cp['totalStages'] ?? 0) ?></div>
                    </div>
                    <?php $tw = $progress['tower'] ?? []; ?>
                    <div class="ak-prog">
                        <div class="lab">危机合约</div>
                        <div class="val"><?= htmlspecialchars((string)($tw['avgBest'] ?? '0')) ?> <span style="font-size:11px;color:#8b949e;">/ 6 平均</span></div>
                        <div style="font-size:11px;color:#8b949e;margin-top:4px;">≥5 分 <?= (int)($tw['highScoreCnt'] ?? 0) ?> / <?= (int)($tw['recordCount'] ?? 0) ?> 关</div>
                    </div>
                    <div class="ak-prog">
                        <div class="lab">活动通关</div>
                        <div class="val"><?= (int)($progress['activitiesCleared'] ?? 0) ?> 个</div>
                        <div style="font-size:11px;color:#8b949e;margin-top:4px;">进行中 <?= (int)($progress['activitiesInProgress'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 集成战略 5 个赛季 -->
        <?php $rogue_list = $progress['rogue'] ?? []; ?>
        <?php if ($rogue_list): ?>
        <div class="ak-block">
            <div class="ak-block-head"><h3>集成战略战绩</h3></div>
            <div class="ak-block-body">
                <div class="ak-rogue-grid">
                    <?php foreach ($rogue_list as $rg): ?>
                    <div class="ak-rogue">
                        <div class="nm"><?= htmlspecialchars(ark_rogue_name($rg['id'] ?? '')) ?></div>
                        <div class="row"><span>BP 等级</span><b><?= (int)($rg['bpLevel'] ?? 0) ?></b></div>
                        <div class="row"><span>勋章</span><b><?= htmlspecialchars($rg['medal'] ?? '0/0') ?></b></div>
                        <div class="row"><span>藏品</span><b><?= (int)($rg['relicCnt'] ?? 0) ?></b></div>
                        <div class="row"><span>通关次数</span><b><?= (int)($rg['clearTime'] ?? 0) ?></b></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 6 星精二干员列表 -->
        <?php if ($six_e2_list): ?>
        <div class="ak-block">
            <div class="ak-block-head"><h3>6 星精二干员 · 共 <?= $six_e2 ?> 位</h3></div>
            <div class="ak-block-body">
                <div class="ak-op-grid" id="ak-op-e2">
                    <?php foreach ($six_e2_list as $idx => $op):
                        $hidden_cls = $idx >= 18 ? ' ak-op-hidden' : '';
                        $spec_sum = array_sum($op['spec'] ?? []);
                    ?>
                    <div class="ak-op r6<?= $hidden_cls ?>">
                        <div class="nm"><?= htmlspecialchars($op['name'] ?? '') ?></div>
                        <div class="meta">
                            <span class="badge e2">精二</span>
                            <span class="lv">Lv <?= (int)($op['level'] ?? 0) ?></span>
                        </div>
                        <div class="meta">
                            <span>技 <?= (int)($op['skill'] ?? 0) ?></span>
                            <?php if ($spec_sum > 0): ?>
                                <span class="spec">★<?= $spec_sum ?></span>
                            <?php endif; ?>
                            <?php if (!empty($op['equip'])): ?>
                                <span class="equip"><?= count($op['equip']) ?>模</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($six_e2 > 18): ?>
                <div class="ak-op-toggle">
                    <button type="button" onclick="document.querySelectorAll('#ak-op-e2 .ak-op-hidden').forEach(function(e){e.classList.remove('ak-op-hidden')});this.style.display='none'">展开全部 <?= $six_e2 ?> 位</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 6 星未精二（重点提醒）-->
        <?php if ($six_un_list): ?>
        <div class="ak-block">
            <div class="ak-block-head"><h3>6 星未精二 · 共 <?= $six_un ?> 位（吃灰提醒）</h3></div>
            <div class="ak-block-body">
                <div class="ak-op-grid">
                    <?php foreach ($six_un_list as $op): ?>
                    <div class="ak-op r6 unfinished">
                        <div class="nm"><?= htmlspecialchars($op['name'] ?? '') ?></div>
                        <div class="meta">
                            <?php $ev = (int)($op['evolve'] ?? 0); ?>
                            <span class="badge <?= $ev === 1 ? 'e1' : '' ?>"><?= $ev === 1 ? '精一' : '未精英' ?></span>
                            <span class="lv">Lv <?= (int)($op['level'] ?? 0) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 分析历史 -->
        <?php if (!empty($history_items)): ?>
        <div class="ak-history">
            <div class="ak-history-label">分析历史（点击查看）</div>
            <div class="ak-history-list">
                <?php foreach ($history_items as $h):
                    $is_active = $cur_query && (int)$h['id'] === (int)$cur_query['id'];
                ?>
                <a class="ak-history-item<?= $is_active ? ' active' : '' ?>" href="?hid=<?= (int)$h['id'] ?>">
                    <?= time_ago_cn_local($h['created_at']) ?>
                </a>
                <?php endforeach; ?>
                <?php if ($view_hid > 0): ?>
                <a class="ak-history-item" href="ark_bind.php" style="border-color:#58a6ff;color:#58a6ff;">← 回到最新</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- DeepSeek 3 段分析 -->
        <?php if ($sections): ?>
        <div class="ak-block">
            <div class="ak-block-head"><h3>DeepSeek 养成分析</h3></div>
            <div class="ak-block-body">
                <div class="ak-sections">
                    <?php foreach ($sections as $sec):
                        $kind = ark_section_kind($sec['title']);
                    ?>
                    <div class="ak-section kind-<?= htmlspecialchars($kind) ?>">
                        <div class="head">
                            <span class="tag"><?= htmlspecialchars($sec['title']) ?></span>
                            <span class="title"><?= htmlspecialchars($sec['title']) ?></span>
                        </div>
                        <div class="body">
                            <?= ark_md_to_html($sec['body']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php elseif ($cur_query): ?>
            <div class="ak-section">
                <div class="body"><pre style="white-space:pre-wrap;color:#c9d1d9;font-family:inherit;"><?= htmlspecialchars((string)$cur_query['deepseek_advice']) ?></pre></div>
            </div>
        <?php endif; ?>

        <!-- 分享按钮 -->
        <div class="ak-share-row">
            <button class="ak-share-btn" type="button" onclick="akShare()">↗ 生成分享页</button>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <?php if ($auth_expired): ?>
            <div class="ak-bind-card" style="border-color:#f0883e;background:linear-gradient(180deg,#161b22 0%,rgba(240,136,62,.06) 100%);">
                <h3 style="color:#f0883e;">⚠ 凭据已过期，请重新获取 SDK token</h3>
                <div style="font-size:13px;color:#c9d1d9;line-height:1.7;margin:8px 0 12px;">
                    上次绑定的鹰角通行证授权已失效（通常 7-30 天后过期），无法再拉取最新的 skland 数据。<br>
                    请按下面"路径 C"步骤重新获取 token——<b>历史记录会保留</b>，重新绑定后继续累积分析。
                </div>
                <form method="POST" style="display:inline;" onsubmit="return confirm('确定要解绑当前凭据吗？历史记录会保留。');">
                    <input type="hidden" name="action" value="unbind">
                    <button type="submit" style="background:transparent;border:1px solid #30363d;color:#8b949e;padding:6px 14px;border-radius:4px;cursor:pointer;font-size:12px;font-family:'Courier New',monospace;">先解绑当前凭据</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- 未绑/过期：路径 C 表单（推荐） -->
        <div class="ak-bind-card recommend">
            <h3><?= $auth_expired ? '重新绑定：粘贴新 SDK token' : '路径 C：粘贴 SDK token' ?><span class="recom-tag">推荐</span></h3>
            <ol class="steps">
                <li>在浏览器**已登录** <code>ak.hypergryph.com</code>（鹰角通行证）的状态下，新标签页打开：<br>
                    <code>https://web-api.hypergryph.com/account/info/hg</code></li>
                <li>页面会显示一段 JSON，复制 <code>data.content</code> 字段（约 24 字符）</li>
                <li>粘贴到下方，点"绑定并分析"</li>
            </ol>
            <form method="POST" autocomplete="off" onsubmit="document.getElementById('ak-loader').classList.add('show')">
                <input type="hidden" name="action" value="bind_token">
                <label>SDK token（24 字符）</label>
                <input type="text" name="hg_token" placeholder="24 字符，例：XxXxXxXxXxXxXxXxXxXxXxXx" autocomplete="off" required>
                <button type="submit">绑定并分析</button>
                <div class="safety">⚠ 凭据只用于拉取你的游戏数据，明文存放在数据库，请仅在自己的账号上使用。</div>
            </form>
        </div>

        <!-- 路径 B 表单（fallback） -->
        <div class="ak-bind-card fallback">
            <h3>路径 B：手机号 + 密码登录</h3>
            <div class="safety" style="margin:0 0 12px;">
                ⚠ 如果路径 C 拿不到 token，可以用账号密码登录。密码不入库，用完即扔。<br>
                ⚠ 当前站点 HTTP 传输，建议优先用路径 C。
            </div>
            <form method="POST" autocomplete="off" onsubmit="document.getElementById('ak-loader').classList.add('show')">
                <input type="hidden" name="action" value="bind_password">
                <label>鹰角通行证手机号</label>
                <input type="text" name="phone" placeholder="11 位手机号" autocomplete="off">
                <label>密码</label>
                <input type="password" name="password" autocomplete="new-password">
                <button type="submit">登录并分析</button>
            </form>
        </div>

        <?php if (!empty($history_items)): ?>
        <div class="ak-history" style="margin-top:20px;">
            <div class="ak-history-label">你的历史分析记录（保留最近 <?= count($history_items) ?> 条，可点击查看）</div>
            <div class="ak-history-list">
                <?php foreach ($history_items as $h): ?>
                <a class="ak-history-item" href="?hid=<?= (int)$h['id'] ?>">
                    <?= time_ago_cn_local($h['created_at']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="ak-foot">
        Data via skland.com + DeepSeek &middot; 同一玩家 24 小时内自动用缓存
    </div>
</div>

<!-- loading 模态 -->
<div class="ak-loading" id="ak-loader">
    <div class="box">
        <div class="spinner"></div>
        <div>正在拉取数据 + DeepSeek 分析…<br><span style="color:#8b949e;font-size:12px;">通常需 20–60 秒</span></div>
    </div>
</div>

<!-- 分享模态 -->
<div class="ak-share-modal" id="ak-share-modal">
    <div class="ak-share-box">
        <h3>↗ 分享你的明日方舟档案</h3>
        <div style="font-size:12px;color:#8b949e;margin-bottom:8px;">复制下面这个链接发给好友，他们能看到你的档案 + 分析报告：</div>
        <div class="url" id="ak-share-url">生成中…</div>
        <div class="acts">
            <button type="button" class="cancel" onclick="document.getElementById('ak-share-modal').classList.remove('show')">关闭</button>
            <button type="button" id="ak-share-copy" onclick="akShareCopy()">📋 复制链接</button>
            <button type="button" onclick="window.open(document.getElementById('ak-share-url').textContent,'_blank')">↗ 打开</button>
        </div>
    </div>
</div>

<script>
function akShare() {
    var modal = document.getElementById('ak-share-modal');
    var urlEl = document.getElementById('ak-share-url');
    urlEl.textContent = '生成中…';
    modal.classList.add('show');
    fetch('ark_share_generate.php', { method: 'POST', credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => {
            if (j.ok) urlEl.textContent = j.url;
            else urlEl.textContent = '❌ 失败：' + (j.msg || '未知');
        })
        .catch(e => { urlEl.textContent = '❌ 网络错误：' + e; });
}
function akShareCopy() {
    var url = document.getElementById('ak-share-url').textContent;
    var btn = document.getElementById('ak-share-copy');
    navigator.clipboard.writeText(url).then(function(){
        btn.textContent = '✓ 已复制';
        setTimeout(function(){ btn.textContent = '📋 复制链接'; }, 2000);
    }).catch(function(){
        // 老浏览器 fallback
        var ta = document.createElement('textarea');
        ta.value = url; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); btn.textContent='✓ 已复制'; } catch(e){}
        document.body.removeChild(ta);
    });
}
</script>

</body>
</html>

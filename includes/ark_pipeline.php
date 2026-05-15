<?php
// 把"用 SDK token 拉完整数据 + 跑 DeepSeek + 落库"这一长串流程包装成两个 high-level 函数
// 给 ark_bind.php 用，避免页面里嵌一大堆步骤代码

require_once __DIR__ . '/hypergryph_api.php';
require_once __DIR__ . '/skland_api.php';
require_once __DIR__ . '/ark_snapshot.php';
require_once __DIR__ . '/ark_analyzer.php';
require_once __DIR__ . '/ark_db.php';
require_once __DIR__ . '/site_settings.php';

/**
 * 用 SDK token 跑完整流水线（①→⑥）+ DeepSeek + 落库 credentials + 落库 query
 *
 * @return array ['ok'=>bool, 'msg'=>string?, 'query'=>array?, 'creds'=>array?]
 */
function ark_bind_with_token($conn, int $user_id, string $hg_token): array {
    // ① 基本信息
    $info = hg_basic_info($hg_token);
    if (!$info['ok']) return ['ok'=>false, 'msg'=>'① 拉玩家基本信息失败：' . $info['msg']];
    $ark_uid  = $info['uid']      ?? '';
    $nickname = $info['nickname'] ?? '';
    $cmid     = (int)($info['channelId'] ?? 1);
    if (!$ark_uid) return ['ok'=>false, 'msg'=>'① 拿到响应但没解析出方舟玩家 uid'];

    // ③ OAuth grant
    $grant = hg_oauth_grant($hg_token);
    if (!$grant['ok']) return ['ok'=>false, 'msg'=>'③ OAuth grant 失败：' . $grant['msg']];

    // ④ skland cred + sign token
    $cred = hg_skland_cred_by_code($grant['code']);
    if (!$cred['ok']) return ['ok'=>false, 'msg'=>'④ 换 skland cred 失败：' . $cred['msg']];
    if (empty($cred['cred']) || empty($cred['token'])) {
        return ['ok'=>false, 'msg'=>'④ 响应里缺 cred 或 sign token'];
    }

    // 新生成 dId（每个用户一个固定 dId，存表里复用）
    $did = bin2hex(random_bytes(16));

    // ⑤ binding（不强制必须有，但跑一下校验 sign）
    $bind = skland_bindings($cred['cred'], $cred['token'], $did);
    if (!$bind['ok']) return ['ok'=>false, 'msg'=>'⑤ skland binding 失败：' . $bind['msg']];

    // ⑥ player_info — 拉完整数据
    $player = skland_player_info($cred['cred'], $cred['token'], $did, $ark_uid);
    if (!$player['ok']) return ['ok'=>false, 'msg'=>'⑥ skland player_info 失败：' . $player['msg']];

    // 落库 credentials（含 did，下次复用同一个 dId 保持签名稳定）
    ark_save_credentials($conn, $user_id, [
        'hg_token'          => $hg_token,
        'skland_cred'       => $cred['cred'],
        'skland_sign_token' => $cred['token'],
        'skland_did'        => $did,
        'game_uid'          => $ark_uid,
        'nickname'          => $nickname,
        'channel_master_id' => $cmid,
    ]);

    // snapshot + DeepSeek
    $snap = ark_build_snapshot($player['data']);
    $ds_key   = get_setting($conn, 'deepseek_api_key',  '');
    $ds_url   = get_setting($conn, 'deepseek_base_url', 'https://api.deepseek.com');
    $ds_model = get_setting($conn, 'deepseek_model',    'deepseek-chat');
    $advice = call_deepseek_ark($snap, $ds_key, $ds_url, $ds_model);
    $advice_text = $advice['ok'] ? $advice['text'] : ('[DeepSeek 分析失败] ' . ($advice['msg'] ?? ''));

    // 落库 query
    $qid = ark_save_query($conn, $user_id, $ark_uid, $snap, $advice_text);

    return [
        'ok'    => true,
        'creds' => ark_get_credentials($conn, $user_id),
        'query' => ark_get_query_by_id($conn, $qid, $user_id),
    ];
}

/**
 * 用已存的 credentials 重新跑一次分析（不重新走 ①→⑤，只用存的 cred/sign_token/did 调 ⑥ + snapshot + DeepSeek）
 *
 * 注意：skland sign token 长期有效，不需要重新 grant，可以直接复用
 *
 * @return array ['ok'=>bool, 'msg'=>string?, 'query'=>array?]
 */
function ark_run_analysis_for_user($conn, array $creds): array {
    if (empty($creds['skland_cred']) || empty($creds['skland_sign_token']) ||
        empty($creds['skland_did'])  || empty($creds['game_uid'])) {
        return ['ok'=>false, 'msg'=>'绑定数据不全，请重新绑定'];
    }

    $player = skland_player_info(
        $creds['skland_cred'],
        $creds['skland_sign_token'],
        $creds['skland_did'],
        $creds['game_uid']
    );
    if (!$player['ok']) {
        // sign token 可能过期，提示用户重新绑定
        return ['ok'=>false, 'msg'=>'skland 数据拉取失败（可能授权已过期，请重新绑定）：' . $player['msg']];
    }

    $snap = ark_build_snapshot($player['data']);
    $ds_key   = get_setting($conn, 'deepseek_api_key',  '');
    $ds_url   = get_setting($conn, 'deepseek_base_url', 'https://api.deepseek.com');
    $ds_model = get_setting($conn, 'deepseek_model',    'deepseek-chat');
    $advice = call_deepseek_ark($snap, $ds_key, $ds_url, $ds_model);
    $advice_text = $advice['ok'] ? $advice['text'] : ('[DeepSeek 分析失败] ' . ($advice['msg'] ?? ''));

    $qid = ark_save_query($conn, (int)$creds['user_id'], (string)$creds['game_uid'], $snap, $advice_text);

    return ['ok'=>true, 'query' => ark_get_query_by_id($conn, $qid, (int)$creds['user_id'])];
}

/**
 * 把 DeepSeek 返回的 3 段 markdown 拆成 [{title, body}, ...]
 * 标题格式：【XXX】，每个标题到下一个标题之间是 body
 */
function ark_parse_advice_sections(string $text): array {
    // 用正则切分【XXX】，保留分隔符
    $parts = preg_split('/(【[^】]+】)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $sections = [];
    $cur_title = null; $cur_body = '';
    foreach ($parts as $p) {
        if (preg_match('/^【([^】]+)】$/u', $p, $m)) {
            if ($cur_title !== null) {
                $sections[] = ['title' => $cur_title, 'body' => trim($cur_body)];
            }
            $cur_title = $m[1];
            $cur_body = '';
        } else {
            if ($cur_title === null) continue;  // 标题前的杂文本忽略
            $cur_body .= $p;
        }
    }
    if ($cur_title !== null) {
        $sections[] = ['title' => $cur_title, 'body' => trim($cur_body)];
    }
    return $sections;
}

/**
 * 简单 markdown → HTML（用于卡片 body）
 * 支持：**bold**、`code`、行首列表（- / 1.）、段落换行
 */
function ark_md_to_html(string $md): string {
    $lines = explode("\n", $md);
    $html = ''; $in_list = false;
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            if ($in_list) { $html .= "</ul>"; $in_list = false; }
            $html .= "\n";
            continue;
        }
        // 列表项 - / 1.
        if (preg_match('/^(?:[-*]|\d+\.)\s+(.+)$/u', $trim, $m)) {
            if (!$in_list) { $html .= "<ul>"; $in_list = true; }
            $html .= "<li>" . ark_md_inline($m[1]) . "</li>";
            continue;
        }
        if ($in_list) { $html .= "</ul>"; $in_list = false; }
        $html .= "<p>" . ark_md_inline($trim) . "</p>";
    }
    if ($in_list) $html .= "</ul>";
    return $html;
}

function ark_md_inline(string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5);
    // **bold**
    $s = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $s);
    // `code`
    $s = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $s);
    return $s;
}

/**
 * 计算六维图轴数据（每个维度 0-100 数值）
 * 评分曲线是经验值，调整时改这里
 */
function ark_radar_axes(array $snapshot): array {
    $roster   = $snapshot['roster']   ?? [];
    $building = $snapshot['building'] ?? [];
    $progress = $snapshot['progress'] ?? [];

    $six_e2 = count($roster['sixStarE2'] ?? []);
    $six_un = count($roster['sixStarUnfinished'] ?? []);
    $six_total = $six_e2 + $six_un;

    // 1. 阵容深度（6 星精二数 / 50 满分）
    $depth = min(100, $six_e2 * 2);

    // 2. 养成完成度（6 星精二 / 6 星总数）
    $finish = $six_total > 0 ? round($six_e2 / $six_total * 100) : 0;

    // 3. 资源效率（4 制造站 speed 总和 / 7 满分）
    $speed_sum = 0;
    foreach (($building['manufactures'] ?? []) as $m) $speed_sum += (float)($m['speed'] ?? 0);
    $resource = min(100, round($speed_sum / 7 * 100));

    // 4. 集成战略（5 赛季 bpLevel 平均 / 150 满分）
    $rogue = $progress['rogue'] ?? [];
    $bp_sum = 0; $bp_n = 0;
    foreach ($rogue as $r) { $bp_sum += (int)($r['bpLevel'] ?? 0); $bp_n++; }
    $is_score = $bp_n > 0 ? min(100, round($bp_sum / $bp_n / 150 * 100)) : 0;

    // 5. 危机合约（avgBest / 6 满分）
    $cc_avg = (float)($progress['tower']['avgBest'] ?? 0);
    $cc_score = min(100, round($cc_avg / 6 * 100));

    // 6. 活动通关（activitiesCleared / 60 满分）
    $act_n = (int)($progress['activitiesCleared'] ?? 0);
    $act_score = min(100, round($act_n / 60 * 100));

    return [
        '阵容深度'   => $depth,
        '养成完成度' => $finish,
        '资源效率'   => $resource,
        '集成战略'   => $is_score,
        '危机合约'   => $cc_score,
        '活动通关'   => $act_score,
    ];
}

/**
 * SVG 六维雷达图（仿 ow_analyzer）
 */
function ark_radar_svg(array $axes): string {
    $cx = 160; $cy = 170; $R = 105;
    $labels = array_keys($axes); $values = array_values($axes);
    $n = count($labels);
    $svg  = '<svg viewBox="0 0 320 340" width="100%" style="max-width:300px;">';
    for ($g = 1; $g <= 5; $g++) {
        $rg = $R * $g / 5; $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $ang = -M_PI/2 + 2*M_PI*$i/$n;
            $pts[] = sprintf('%.1f,%.1f', $cx + $rg*cos($ang), $cy + $rg*sin($ang));
        }
        $stroke = ($g === 5) ? '#484f58' : '#262d36';
        $svg .= '<polygon points="'.implode(' ', $pts).'" fill="none" stroke="'.$stroke.'" stroke-dasharray="2,2"/>';
    }
    for ($i = 0; $i < $n; $i++) {
        $ang = -M_PI/2 + 2*M_PI*$i/$n;
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%.1f" y2="%.1f" stroke="#262d36"/>',
            $cx, $cy, $cx + $R*cos($ang), $cy + $R*sin($ang));
    }
    $poly = [];
    for ($i = 0; $i < $n; $i++) {
        $ang = -M_PI/2 + 2*M_PI*$i/$n;
        $r = max(0, min(100, (int)$values[$i])) / 100 * $R;
        $poly[] = sprintf('%.1f,%.1f', $cx + $r*cos($ang), $cy + $r*sin($ang));
    }
    $svg .= '<polygon points="'.implode(' ', $poly).'" fill="rgba(167,139,250,0.22)" stroke="#a78bfa" stroke-width="2"/>';
    foreach ($poly as $pt) {
        list($x, $y) = explode(',', $pt);
        $svg .= sprintf('<circle cx="%s" cy="%s" r="3.5" fill="#a78bfa"/>', $x, $y);
    }
    for ($i = 0; $i < $n; $i++) {
        $ang = -M_PI/2 + 2*M_PI*$i/$n;
        $lx = $cx + ($R + 22) * cos($ang);
        $ly = $cy + ($R + 22) * sin($ang);
        $anchor = ($lx < $cx - 5) ? 'end' : (($lx > $cx + 5) ? 'start' : 'middle');
        $svg .= sprintf(
            '<text x="%.1f" y="%.1f" fill="#c9d1d9" font-size="11" font-family="Microsoft YaHei,sans-serif" text-anchor="%s" dominant-baseline="middle">%s <tspan fill="#a78bfa" font-weight="700">%d</tspan></text>',
            $lx, $ly, $anchor, htmlspecialchars($labels[$i]), (int)round($values[$i])
        );
    }
    $svg .= '</svg>';
    return $svg;
}

/**
 * 渲染独立分享 HTML（含全部内联 CSS + 玩家档案 + 分析报告）
 * 返回完整 HTML 字符串，调用方写文件
 */
function ark_render_share_html(array $snapshot, string $advice, string $shareTime): string {
    $p = $snapshot['player'] ?? [];
    $roster = $snapshot['roster']   ?? [];
    $building = $snapshot['building'] ?? [];
    $progress = $snapshot['progress'] ?? [];
    $by_r = $roster['byRarity'] ?? [];
    $six_e2_list = $roster['sixStarE2'] ?? [];
    $six_un_list = $roster['sixStarUnfinished'] ?? [];
    $radar = ark_radar_axes($snapshot);
    $sections = ark_parse_advice_sections($advice);

    $nickname = htmlspecialchars($p['name'] ?? 'Doctor');
    $uid = htmlspecialchars($p['uid'] ?? '');
    $level = (int)($p['level'] ?? 0);
    $regdays = (int)($p['registerDays'] ?? 0);
    $charCnt = (int)($p['charCnt'] ?? 0);
    $skinCnt = (int)($p['skinCnt'] ?? 0);
    $six_e2 = count($six_e2_list);
    $six_un = count($six_un_list);
    $radar_svg = ark_radar_svg($radar);

    ob_start(); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $nickname ?> 的明日方舟档案 · MUSE</title>
<style>
body{background:#0d1117;color:#e6edf3;font-family:"Microsoft YaHei",sans-serif;margin:0;padding:24px 12px;line-height:1.6;}
.wrap{max-width:1000px;margin:0 auto;}
.brand{text-align:center;color:#6e7681;font-size:12px;font-family:"Courier New",monospace;margin-bottom:14px;}
.brand a{color:#a78bfa;text-decoration:none;}
.hero{background:linear-gradient(135deg,#161b22,#1c2128);border:1px solid #30363d;border-radius:8px;padding:22px;margin-bottom:14px;display:flex;flex-wrap:wrap;align-items:center;gap:18px;}
.hero h1{font-size:24px;margin:0 0 6px;color:#e6edf3;}
.hero .uid{font-size:13px;color:#8b949e;font-family:"Courier New",monospace;}
.hero .stats{margin-left:auto;display:flex;gap:18px;flex-wrap:wrap;}
.hero .stats .s{text-align:center;}
.hero .stats .v{font-size:24px;font-weight:800;color:#3fb950;font-family:"Courier New",monospace;line-height:1;}
.hero .stats .l{font-size:11px;color:#8b949e;text-transform:uppercase;font-family:"Courier New",monospace;margin-top:4px;}
.block{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px;margin-bottom:14px;}
.block h2{margin:0 0 14px;font-size:13px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1.5px;text-transform:uppercase;}
.block h2::before{content:'// ';opacity:.6;}
.radar-row{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;}
@media(max-width:820px){.radar-row{grid-template-columns:1fr;}}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:10px;}
.kpi{background:#0d1117;border:1px solid #30363d;border-radius:5px;padding:9px 11px;}
.kpi .l{font-size:11px;color:#6e7681;text-transform:uppercase;font-family:"Courier New",monospace;}
.kpi .v{font-size:20px;color:#3fb950;font-weight:700;font-family:"Courier New",monospace;margin-top:3px;}
.kpi .sub{font-size:11px;color:#8b949e;margin-top:3px;}
.radar-wrap{background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:8px;text-align:center;}
.radar-wrap .t{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1px;margin-bottom:4px;}
.bars{display:flex;gap:4px;align-items:flex-end;height:64px;padding:4px 6px;background:#0d1117;border:1px solid #30363d;border-radius:6px;}
.bars .col{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;}
.bars .col .n{font-size:10px;color:#8b949e;font-family:"Courier New",monospace;}
.bars .col .bar{width:100%;border-radius:2px 2px 0 0;}
.bars .col .s{font-size:9px;color:#6e7681;font-family:"Courier New",monospace;}
.prog-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:9px;}
.prog{background:#0d1117;border:1px solid #30363d;border-radius:5px;padding:9px 11px;}
.prog .l{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;}
.prog .v{font-size:17px;color:#58a6ff;font-weight:700;font-family:"Courier New",monospace;margin-top:3px;}
.prog .sub{font-size:11px;color:#8b949e;margin-top:3px;}
.rogue-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:9px;}
.rogue{background:#0d1117;border:1px solid #30363d;border-radius:5px;padding:10px 12px;}
.rogue .n{font-size:12px;font-weight:700;color:#a78bfa;margin-bottom:5px;}
.rogue .r{display:flex;justify-content:space-between;font-size:11px;color:#8b949e;font-family:"Courier New",monospace;margin:2px 0;}
.rogue .r b{color:#e6edf3;}
.op-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:7px;}
.op{background:#0d1117;border:1px solid #30363d;border-left:3px solid #e3b341;border-radius:4px;padding:7px 10px;}
.op.un{border-left-color:#6e7681;}
.op .nm{font-size:13px;font-weight:700;color:#e6edf3;}
.op .m{font-size:11px;color:#8b949e;font-family:"Courier New",monospace;display:flex;gap:5px;flex-wrap:wrap;align-items:center;margin-top:3px;}
.op .bdg{padding:1px 5px;border-radius:2px;background:rgba(63,185,80,.15);color:#3fb950;font-size:10px;}
.op .bdg.e1{background:rgba(240,136,62,.15);color:#f0883e;}
.op .bdg.u{background:rgba(110,118,129,.15);color:#8b949e;}
.section{background:#0d1117;border:1px solid #30363d;border-left:3px solid #6e7681;border-radius:5px;padding:12px 18px;margin-bottom:10px;}
.section .h{display:flex;gap:8px;align-items:center;margin-bottom:6px;}
.section .h .tg{font-family:"Courier New",monospace;font-size:10px;font-weight:700;letter-spacing:1.5px;padding:2px 7px;border-radius:3px;background:rgba(110,118,129,.15);color:#8b949e;border:1px solid #30363d;}
.section .h .ti{font-size:15px;font-weight:700;color:#e6edf3;}
.section.team{border-left-color:#3fb950;}.section.team .tg{background:rgba(63,185,80,.12);color:#3fb950;border-color:rgba(63,185,80,.3);}.section.team .ti{color:#3fb950;}
.section.resource{border-left-color:#58a6ff;}.section.resource .tg{background:rgba(88,166,255,.12);color:#58a6ff;border-color:rgba(88,166,255,.3);}.section.resource .ti{color:#58a6ff;}
.section.high{border-left-color:#a78bfa;}.section.high .tg{background:rgba(167,139,250,.12);color:#a78bfa;border-color:rgba(167,139,250,.3);}.section.high .ti{color:#a78bfa;}
.section .b p{margin:5px 0;}.section .b ul{margin:5px 0;padding-left:22px;}.section .b strong{color:#e3b341;}
.footer{text-align:center;color:#484f58;font-size:11px;font-family:"Courier New",monospace;margin-top:20px;padding:14px;}
.footer a{color:#6e7681;}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">↗ <a href="../pages/ark_bind.php">缪斯 MUSE · Arknights Analyzer</a></div>

    <div class="hero">
        <div>
            <h1><?= $nickname ?></h1>
            <div class="uid">UID <?= $uid ?> · 已入职 <?= $regdays ?> 天</div>
        </div>
        <div class="stats">
            <div class="s"><div class="v"><?= $level ?></div><div class="l">博士等级</div></div>
            <div class="s"><div class="v"><?= $charCnt ?></div><div class="l">干员</div></div>
            <div class="s"><div class="v"><?= $six_e2 ?></div><div class="l">6★精二</div></div>
            <div class="s"><div class="v"><?= $skinCnt ?></div><div class="l">皮肤</div></div>
        </div>
    </div>

    <div class="block">
        <h2>玩家概况</h2>
        <div class="radar-row">
            <div>
                <div class="kpi-grid">
                    <div class="kpi"><div class="l">理智</div><div class="v" style="font-size:16px;"><?= htmlspecialchars($p['ap'] ?? '—') ?></div><div class="sub">秘书 <?= htmlspecialchars($p['secretary'] ?? '—') ?></div></div>
                    <div class="kpi"><div class="l">6 星未精二</div><div class="v" style="color:#f0883e;"><?= $six_un ?></div><div class="sub">吃灰中</div></div>
                    <div class="kpi"><div class="l">5 星精二</div><div class="v"><?= count($roster['fiveStarE2'] ?? []) ?></div></div>
                    <div class="kpi"><div class="l">基建心情</div><div class="v"><?= htmlspecialchars($building['labor'] ?? '—') ?></div></div>
                </div>
                <div class="bars">
                    <?php $colors=[0=>'#6e7681',1=>'#9ca3af',2=>'#22c55e',3=>'#3b82f6',4=>'#a78bfa',5=>'#e3b341'];
                    for($r=5;$r>=0;$r--):
                        $cnt=$by_r[$r]??0;$h=$cnt>0?max(8,min(50,$cnt*0.4)):4;
                    ?>
                    <div class="col">
                        <span class="n"><?= $cnt ?></span>
                        <div class="bar" style="height:<?= $h ?>px;background:<?= $colors[$r] ?>;"></div>
                        <span class="s"><?= $r+1 ?>★</span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="radar-wrap">
                <div class="t">// 六维画像</div>
                <?= $radar_svg ?>
            </div>
        </div>
    </div>

    <div class="block">
        <h2>进度概览</h2>
        <div class="prog-grid">
            <?php $rt=$progress['routine']??[];
                [$dc,$dt]=array_pad(explode('/',$rt['daily']??'0/0'),2,0);
                [$wc,$wt]=array_pad(explode('/',$rt['weekly']??'0/0'),2,0);
                $cp=$progress['campaign']??[];
                $tw=$progress['tower']??[]; ?>
            <div class="prog"><div class="l">每日任务</div><div class="v"><?= htmlspecialchars($rt['daily']??'0/0') ?></div></div>
            <div class="prog"><div class="l">每周任务</div><div class="v"><?= htmlspecialchars($rt['weekly']??'0/0') ?></div></div>
            <div class="prog"><div class="l">剿灭周报</div><div class="v"><?= htmlspecialchars($cp['reward']??'0/0') ?></div></div>
            <div class="prog"><div class="l">剿灭满杀</div><div class="v"><?= (int)($cp['fullKillStages']??0) ?> / <?= (int)($cp['totalStages']??0) ?></div></div>
            <div class="prog"><div class="l">危机合约</div><div class="v"><?= htmlspecialchars((string)($tw['avgBest']??'0')) ?> <span style="font-size:11px;color:#8b949e;">/ 6</span></div><div class="sub">≥5 分 <?= (int)($tw['highScoreCnt']??0) ?> / <?= (int)($tw['recordCount']??0) ?></div></div>
            <div class="prog"><div class="l">活动通关</div><div class="v"><?= (int)($progress['activitiesCleared']??0) ?> 个</div><div class="sub">进行中 <?= (int)($progress['activitiesInProgress']??0) ?></div></div>
        </div>
    </div>

    <?php $rg=$progress['rogue']??[]; if($rg): ?>
    <div class="block">
        <h2>集成战略战绩</h2>
        <div class="rogue-grid">
            <?php foreach($rg as $r): ?>
            <div class="rogue">
                <div class="n"><?= htmlspecialchars(ark_rogue_name($r['id']??'')) ?></div>
                <div class="r"><span>BP 等级</span><b><?= (int)($r['bpLevel']??0) ?></b></div>
                <div class="r"><span>勋章</span><b><?= htmlspecialchars($r['medal']??'0/0') ?></b></div>
                <div class="r"><span>藏品</span><b><?= (int)($r['relicCnt']??0) ?></b></div>
                <div class="r"><span>通关</span><b><?= (int)($r['clearTime']??0) ?> 次</b></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if($six_e2_list): ?>
    <div class="block">
        <h2>6 星精二干员 · 共 <?= $six_e2 ?> 位</h2>
        <div class="op-grid">
            <?php foreach($six_e2_list as $op):
                $ss=array_sum($op['spec']??[]); ?>
            <div class="op">
                <div class="nm"><?= htmlspecialchars($op['name']??'') ?></div>
                <div class="m">
                    <span class="bdg">精二</span><span>Lv<?= (int)($op['level']??0) ?></span>
                    <span>技<?= (int)($op['skill']??0) ?></span>
                    <?php if($ss>0): ?><span style="color:#e3b341;">★<?= $ss ?></span><?php endif; ?>
                    <?php if(!empty($op['equip'])): ?><span style="color:#58a6ff;"><?= count($op['equip']) ?>模</span><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if($six_un_list): ?>
    <div class="block">
        <h2>6 星未精二 · 共 <?= $six_un ?> 位（吃灰）</h2>
        <div class="op-grid">
            <?php foreach($six_un_list as $op):
                $ev=(int)($op['evolve']??0); ?>
            <div class="op un">
                <div class="nm"><?= htmlspecialchars($op['name']??'') ?></div>
                <div class="m">
                    <span class="bdg <?= $ev===1?'e1':'u' ?>"><?= $ev===1?'精一':'未精英' ?></span>
                    <span>Lv<?= (int)($op['level']??0) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if($sections): ?>
    <div class="block">
        <h2>DeepSeek 养成分析</h2>
        <?php foreach($sections as $sec):
            $k=ark_section_kind($sec['title']); ?>
        <div class="section <?= htmlspecialchars($k) ?>">
            <div class="h"><span class="tg"><?= htmlspecialchars($sec['title']) ?></span><span class="ti"><?= htmlspecialchars($sec['title']) ?></span></div>
            <div class="b"><?= ark_md_to_html($sec['body']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="footer">
        ↗ <a href="../pages/ark_bind.php">由缪斯 MUSE 的 Arknights Analyzer 生成</a> &middot; 数据快照 <?= htmlspecialchars($shareTime) ?>
    </div>
</div>
</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * rogueId → 中文名映射
 */
function ark_rogue_name(string $id): string {
    static $map = [
        'rogue_1' => '傀影与猩红孤钻',
        'rogue_2' => '水月与深蓝之树',
        'rogue_3' => '探索者的银凇止境',
        'rogue_4' => '萨卡兹的无终奇语',
        'rogue_5' => '岁的界园志异',
    ];
    return $map[$id] ?? $id;
}


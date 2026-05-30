<?php
// 守望国服单场对局详情（AJAX）：接 matchId + customerToken → queryMatchInfo → 渲染 HTML 片段
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/site_settings.php';
require_once __DIR__ . '/../includes/ds163_ow_api.php';
header('Content-Type: text/html; charset=UTF-8');

if (!isset($_SESSION['user_id'])) { http_response_code(403); echo '<div class="md-err">请先登录</div>'; exit; }

$matchId = trim($_POST['matchId'] ?? $_GET['matchId'] ?? '');
$ct      = trim($_POST['customerToken'] ?? $_GET['customerToken'] ?? '');
if ($matchId === '' || $ct === '') { echo '<div class="md-err">缺少参数</div>'; exit; }

$cred = ds163_load_credentials($conn);
$r = ds163_customer_match_info($cred, $ct, $matchId);
if (!$r['ok']) { echo '<div class="md-err">⚠ ' . htmlspecialchars($r['msg'] ?? '查询失败') . '（可能 NTES_YD_SESS 会话已过期，需后台更新凭证）</div>'; exit; }

$d      = $r['data'] ?? [];
$heroes = ds163_hero_map();
$maps   = ds163_map_map();
$role_cn = ['tank'=>'坦克', 'dps'=>'输出', 'healer'=>'辅助', 'open'=>'自由'];

$win   = ($d['matchRet'] ?? 0) > 0;
$mp    = $maps[$d['mapGuid'] ?? ''] ?? [];
$secs  = (int)($d['gameTimeSec'] ?? 0);
$dur   = sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);

// 段位英文→中文
function md_rank_cn($rankInfo) {
    static $cn = ['Bronze'=>'青铜','Silver'=>'白银','Gold'=>'黄金','Platinum'=>'铂金','Diamond'=>'钻石','Master'=>'大师','Grandmaster'=>'宗师','Champion'=>'冠军','Ultimate'=>'究极'];
    $rk = $rankInfo['rankName'] ?? 'None';
    if ($rk === 'None' || $rk === '') return '未定级';
    return ($cn[$rk] ?? $rk) . ' ' . ($rankInfo['rankSubTier'] ?? '');
}

// 渲染一名玩家行（主行 + 可展开的详情行）
function md_player_row($p, $heroes) {
    $h = $heroes[$p['heroGuid'] ?? ''] ?? [];
    $rank = md_rank_cn($p['rankInfo'] ?? []);
    $hi = $p['heroIcon'] ?? ($h['icon'] ?? '');
    ob_start(); ?>
    <tr class="md-prow" title="点击展开更多数据">
        <td class="md-hero"><?php if ($hi): ?><img src="<?= htmlspecialchars($hi) ?>" referrerpolicy="no-referrer" alt=""><?php endif; ?><span><?= htmlspecialchars($h['name'] ?? '') ?></span></td>
        <td class="md-name"><?= htmlspecialchars($p['name'] ?? '—') ?></td>
        <td><?= (int)($p['kill'] ?? 0) ?>/<?= (int)($p['death'] ?? 0) ?>/<?= (int)($p['assist'] ?? 0) ?></td>
        <td><?= (int)($p['heroDamage'] ?? 0) ?></td>
        <td><?= (int)($p['cure'] ?? 0) ?></td>
        <td><?= (int)($p['resistDamage'] ?? 0) ?></td>
        <td class="md-rank"><?= htmlspecialchars($rank) ?></td>
    </tr>
    <tr class="md-pdetail" style="display:none;"><td colspan="7">
        承受伤害 <b><?= (int)($p['damageTaken'] ?? 0) ?></b> · 承受治疗 <b><?= (int)($p['healingTaken'] ?? 0) ?></b> · 最终一击 <b><?= (int)($p['finalHit'] ?? 0) ?></b> · 占点时间 <b><?= (int)($p['targetCompetingTime'] ?? 0) ?></b>s
    </td></tr>
    <?php return ob_get_clean();
}

// 禁用英雄图标行（映射不到图标时退显示英雄名/guid，便于诊断）
function md_bans($guids, $heroes) {
    if (empty($guids)) return '<span style="color:#6e7681;">本局无禁用</span>';
    $out = '';
    foreach ((array)$guids as $g) {
        $h = $heroes[$g] ?? [];
        $icon = $h['icon'] ?? ''; $name = $h['name'] ?? '';
        if ($icon)      $out .= '<img src="' . htmlspecialchars($icon) . '" referrerpolicy="no-referrer" title="' . htmlspecialchars($name) . '" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline-block\'"><span style="display:none;color:#8b949e;margin-right:8px;font-size:12px;">' . htmlspecialchars($name) . '</span>';
        elseif ($name)  $out .= '<span style="color:#8b949e;margin-right:8px;">' . htmlspecialchars($name) . '</span>';
        else            $out .= '<span style="color:#6e7681;margin-right:8px;">#' . htmlspecialchars(substr((string)$g, -6)) . '</span>';
    }
    return $out;
}
?>
<div class="md-box">
    <div class="md-head">
        <span class="md-map"><?= htmlspecialchars($mp['name'] ?? ($d['mapGuid'] ?? '未知地图')) ?></span>
        <span class="md-mode"><?= htmlspecialchars($mp['mode'] ?? '') ?></span>
        <span class="md-result <?= $win ? 'win' : 'lose' ?>"><?= $win ? '胜' : '负' ?></span>
        <span class="md-score"><?= (int)($d['teamScore'] ?? 0) ?> : <?= (int)($d['opponentScore'] ?? 0) ?></span>
        <span class="md-dur"><?= $dur ?></span>
    </div>

    <?php foreach (['teammateList'=>'我方', 'enemyList'=>'敌方'] as $key => $label):
        $list = $d[$key] ?? [];
        if (!$list) continue;
    ?>
    <div class="md-team">
        <div class="md-team-title md-<?= $key==='teammateList'?'ally':'enemy' ?>"><?= $label ?></div>
        <table class="md-table">
            <thead><tr><th>英雄</th><th>玩家</th><th>K/D/A</th><th>伤害</th><th>治疗</th><th>承伤</th><th>段位</th></tr></thead>
            <tbody>
                <?php foreach ($list as $p) echo md_player_row($p, $heroes); ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <div class="md-bans">
        <div><span class="md-ban-label">我方禁用</span><?= md_bans($d['teamBanHeroGuids'] ?? [], $heroes) ?></div>
        <div><span class="md-ban-label">敌方禁用</span><?= md_bans($d['enemyBanHeroGuids'] ?? [], $heroes) ?></div>
    </div>
</div>

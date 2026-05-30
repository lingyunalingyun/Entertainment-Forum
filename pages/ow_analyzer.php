<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/site_settings.php';
require_once __DIR__ . '/../includes/ds163_ow_api.php';

$skip_loader = true; // 战绩页禁用全站火焰加载动画（每次查询整页跳转太晃眼）

const OW_CACHE_TTL_HOURS = 6;  // 战绩缓存有效期（小时）

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

@set_time_limit(180);

// ── 输入处理 ──
$battletag_in = trim($_POST['battletag'] ?? $_GET['battletag'] ?? '');
$region       = $_POST['region'] ?? $_GET['region'] ?? 'cn';
if (!in_array($region, ['cn', 'intl'], true)) $region = 'cn';

$error      = '';
$data       = null;   // 国服=ds163 {profile,sport,leisure,card}; 国际=overfast {summary,stats}
$advice     = '';
$ds_error   = '';

function ow_http_get($url, $timeout = 20) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Cache-Control: no-store'],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'err' => $err];
}

// 国际服走 OverFast；BattleTag 形如 Player#1234，OverFast 用 - 替代 #
function overfast_query($battletag, $platform = 'pc', $gamemode = 'competitive') {
    $player_id = str_replace('#', '-', $battletag);
    $base = "https://overfast-api.tekrop.fr/players/" . urlencode($player_id);

    $rs = ow_http_get($base . '/summary', 25);
    if ($rs['code'] === 0)   return ['err' => '无法连接 OverFast API（服务器到 overfast-api.tekrop.fr 不通）：' . $rs['err']];
    if ($rs['code'] === 404) return ['err' => '未找到该玩家（国际服）。请确认 BattleTag 拼写，且生涯需设为公开。'];
    if ($rs['code'] !== 200) return ['err' => "OverFast 返回 HTTP {$rs['code']}：" . substr((string)$rs['body'], 0, 200)];
    $summary = json_decode($rs['body'], true);
    if (!is_array($summary)) return ['err' => 'OverFast summary 解析失败'];

    // stats/summary 给整体 + roles + heroes 的紧凑视图
    $rss_url = $base . "/stats/summary?gamemode={$gamemode}&platform={$platform}";
    $rss = ow_http_get($rss_url, 30);
    $stats_sum = ($rss['code'] === 200) ? json_decode($rss['body'], true) : null;

    return ['data' => ['summary' => $summary, 'stats' => $stats_sum, 'platform' => $platform, 'gamemode' => $gamemode]];
}

// 国际服 OverFast 数据用单独的 DeepSeek 调用（数据形态完全不同）
function call_deepseek_intl($battletag, $intl_data, $history, $key, $base_url, $model) {
    if (!$key) return ['ok'=>false, 'msg'=>'未配置 DeepSeek API Key，请到 后台 → 系统设置 → AI 配置 中填写。'];

    $base_url = rtrim($base_url ?: 'https://api.deepseek.com', '/');
    $model    = $model ?: 'deepseek-chat';

    $shrink = function($d, $hero_n = 8) {
        $sum   = $d['summary'] ?? [];
        $stats = $d['stats']   ?? [];
        $platform = $d['platform'] ?? 'pc';
        $heroes_compact = [];
        if (isset($stats['heroes']) && is_array($stats['heroes'])) {
            $list = [];
            foreach ($stats['heroes'] as $name => $h) {
                $list[] = [
                    'hero'         => $name,
                    'time_played'  => $h['time_played']    ?? 0,
                    'games_played' => $h['games_played']   ?? 0,
                    'win_pct'      => $h['win_percentage'] ?? null,
                    'kda'          => $h['kda']            ?? null,
                ];
            }
            usort($list, function($a, $b){ return ($b['time_played'] ?? 0) <=> ($a['time_played'] ?? 0); });
            $heroes_compact = array_slice($list, 0, $hero_n);
        }
        return [
            'username'        => $sum['username']             ?? null,
            'title'           => $sum['title']                ?? null,
            'endorsement_lv'  => $sum['endorsement']['level'] ?? null,
            'competitive_ranks' => $sum['competitive'][$platform] ?? null,
            'general'         => $stats['general'] ?? null,
            'roles'           => $stats['roles']   ?? null,
            'top_heroes_by_time' => $heroes_compact,
        ];
    };

    $current = ['snapshot_at' => date('Y-m-d H:i')] + $shrink($intl_data, 8);

    $history_compact = [];
    foreach ((array)$history as $h) {
        if (empty($h['data'])) continue;
        $history_compact[] = ['snapshot_at' => date('Y-m-d H:i', strtotime($h['created_at']))]
            + $shrink($h['data'], 5);
    }

    $payload = ['battle_tag' => $battletag, 'region' => 'international', 'current' => $current];
    if (!empty($history_compact)) $payload['history'] = $history_compact;

    $sys = "你是一位经验丰富、风格直接的守望先锋（Overwatch 2）教练，信奉「严师出高徒」——敢戳痛点，但每一刀都为了让玩家变强。\n" .
           "你拿到的是国际服 OverFast 数据：含段位（按 tank/damage/support 角色）、对局总览（场次/胜率/KDA/总时长）、按时长排序的常用英雄。\n" .
           "你的任务：基于这些数据，输出一份直率、犀利、但建设性的中文教练分析。\n\n" .
           "硬性要求：\n" .
           "1. 全部用简体中文。\n" .
           "2. **严格按这五个段落输出，标题必须原样使用【】包裹**：\n" .
           "   【核心画像】 — 3–5 行综合判断；如果有历史快照，开头追加 2–3 行「最近变化」（带数字，例：\"胜率 48%→55%\"）。\n" .
           "   【位置诊断】 — 针对玩家三个角色（坦克/输出/辅助）里实际上场最多的位置点评，至少 3 条短点评，每条 ≤30 字。\n" .
           "   【英雄玩法画像】 — 针对常用英雄/玩法倾向（突进/枪男/输出辅助/前排压制 等），2–4 条短点评。\n" .
           "   【维度提醒】 — 针对段位、胜率、KDA、英雄池广度 这些维度里**最差的 2–3 项**，逐条点出问题，每条 ≤30 字。\n" .
           "   【改进建议】 — 3–5 条 actionable 建议，每条形如\"做 X 来解决 Y\"，必须可立即执行。\n" .
           "3. 允许犀利、直接甚至带刺的语气，但有两条铁律——\n" .
           "   (a) 攻击点必须落在\"具体行为或选择\"，不攻击玩家本人/人格。\n" .
           "   (b) 【改进建议】里给的每一条必须可执行。\n" .
           "4. 收束基调：刀子嘴豆腐心。\n" .
           "5. 数据不足时只在已有数据范围内分析。";

    if (!empty($history_compact)) {
        $sys .= "\n6. **历史对比强化**：本次 user 输入包含 history 快照（按时间倒序）。【核心画像】开头的「最近变化」必须明确点出综合胜率、KDA、英雄选择的涨跌（带数字），并在【位置诊断】/【维度提醒】里呼应。变化不显著就直说。";
    }

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' =>
                "请基于以下国际服战绩 JSON 进行分析与建议：\n\n```json\n" .
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```"],
        ],
        'temperature' => 0.5,
        'max_tokens'  => 2000,
    ];

    $ch = curl_init($base_url . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $resp_body = curl_exec($ch);
    $resp_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($resp_code !== 200) return ['ok'=>false, 'msg'=>"DeepSeek 调用失败 HTTP {$resp_code}：" . ($err ?: substr((string)$resp_body, 0, 300))];
    $j = json_decode($resp_body, true);
    $content = $j['choices'][0]['message']['content'] ?? '';
    if ($content === '') return ['ok'=>false, 'msg'=>'DeepSeek 返回为空'];
    return ['ok'=>true, 'text'=>$content];
}

// 国服 ds163 官方数据用单独的 DeepSeek 调用（客观数据：段位/各位置胜率KDA/场均）
function call_deepseek_ds163($battletag, $ds_data, $history, $key, $base_url, $model) {
    if (!$key) return ['ok'=>false, 'msg'=>'未配置 DeepSeek API Key，请到 后台 → 系统设置 → AI 配置 中填写。'];
    $base_url = rtrim($base_url ?: 'https://api.deepseek.com', '/');
    $model    = $model ?: 'deepseek-chat';

    $role_cn = ['tank'=>'坦克', 'dps'=>'输出', 'healer'=>'辅助', 'open'=>'自由组队'];
    $shrink = function($d) use ($role_cn) {
        $p = $d['profile'] ?? [];
        $out = [
            'level'         => $p['level']         ?? null,
            'game_time_h'   => $p['gameTime']      ?? null,
            'total_matches' => $p['totalMatchNum'] ?? null,
        ];
        foreach (['sport'=>'竞技', 'leisure'=>'快速'] as $mode => $lbl) {
            $m = $d[$mode] ?? null;
            if (!is_array($m) || empty($m['guideCountData'])) continue;
            $roles = [];
            foreach ((array)$m['guideCountData'] as $g) {
                $rk = $g['lastRankInfo']['rankName'] ?? 'None';
                $roles[] = [
                    'role'         => $role_cn[$g['roleType'] ?? ''] ?? ($g['roleType'] ?? '?'),
                    'matches'      => $g['matchSum']     ?? null,
                    'winRate'      => $g['winRate']      ?? null,
                    'kda'          => $g['kda']          ?? null,
                    'rank'         => ($rk === 'None') ? '未定级' : trim($rk . ' ' . ($g['lastRankInfo']['rankSubTier'] ?? '')),
                    'maxWinStreak' => $g['maxWinStreak'] ?? null,
                ];
            }
            $s = $m['presetsSummaryData'] ?? [];
            $out[$lbl] = ['roles' => $roles, 'avg' => [
                'kill'=>$s['aveKill']??null, 'death'=>$s['aveDeath']??null, 'assist'=>$s['aveAssist']??null,
                'heroDamage'=>$s['aveHeroDamage']??null, 'cure'=>$s['aveCure']??null, 'resistDamage'=>$s['aveResistDamage']??null,
                'winRate'=>$s['winRate']??null,
            ]];
        }
        return $out;
    };

    $current = ['snapshot_at'=>date('Y-m-d H:i')] + $shrink($ds_data);
    $history_compact = [];
    foreach ((array)$history as $h) {
        if (empty($h['data'])) continue;
        $history_compact[] = ['snapshot_at'=>date('Y-m-d H:i', strtotime($h['created_at']))] + $shrink($h['data']);
    }
    $payload = ['battle_tag'=>$battletag, 'region'=>'国服(官方)', 'current'=>$current];
    if ($history_compact) $payload['history'] = $history_compact;

    $sys = "你是一位经验丰富、风格直接的守望先锋（Overwatch 2）教练，信奉「严师出高徒」——敢戳痛点，但每一刀都为了让玩家变强。\n" .
           "你拿到的是国服官方战绩数据：含等级/总时长/总场次，竞技与快速两种模式下各位置（坦克/输出/辅助）的段位、场次、胜率、KDA、最高连胜，以及场均击杀/死亡/助攻/伤害/治疗/承伤。\n" .
           "你的任务：基于这些客观数据，输出一份直率、犀利、但建设性的中文教练分析。\n\n" .
           "硬性要求：\n" .
           "1. 全部用简体中文。\n" .
           "2. **严格按这五个段落输出，标题必须原样使用【】包裹**：\n" .
           "   【核心画像】 — 3–5 行综合判断；有历史快照则开头追加 2–3 行「最近变化」（带数字，例：\"胜率 48%→55%\"）。\n" .
           "   【位置诊断】 — 针对实际上场最多的位置点评，至少 3 条，每条 ≤30 字。\n" .
           "   【英雄玩法画像】 — 从各位置胜率/KDA/场均数据反推玩法倾向（输出型奶/激进坦/苟命C 等），2–4 条。\n" .
           "   【维度提醒】 — 段位、胜率、KDA、场均死亡、伤害/治疗效率 里**最差的 2–3 项**，逐条点出，每条 ≤30 字。\n" .
           "   【改进建议】 — 3–5 条 actionable 建议，每条形如\"做 X 来解决 Y\"，可立即执行。\n" .
           "3. 允许犀利、直接甚至带刺，但两条铁律：(a) 攻击点落在\"具体行为或选择\"，不攻击玩家本人；(b)【改进建议】每条必须可执行。\n" .
           "4. 收束基调：刀子嘴豆腐心。\n" .
           "5. 数据不足（如竞技无数据/段位未定级）时只在已有数据范围内分析，不编造。";
    if ($history_compact) {
        $sys .= "\n6. **历史对比强化**：user 输入含 history 快照（时间倒序）。【核心画像】开头点出胜率/KDA/段位的涨跌（带数字），并在【位置诊断】/【维度提醒】呼应。变化不显著就直说。";
    }

    $body = [
        'model' => $model,
        'messages' => [
            ['role'=>'system', 'content'=>$sys],
            ['role'=>'user',   'content'=>"请基于以下国服官方战绩 JSON 进行分析与建议：\n\n```json\n" .
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```"],
        ],
        'temperature' => 0.5,
        'max_tokens'  => 2000,
    ];

    $ch = curl_init($base_url . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $resp_body = curl_exec($ch);
    $resp_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);
    if ($resp_code !== 200) return ['ok'=>false, 'msg'=>"DeepSeek 调用失败 HTTP {$resp_code}：" . ($err ?: substr((string)$resp_body, 0, 300))];
    $j = json_decode($resp_body, true);
    $content = $j['choices'][0]['message']['content'] ?? '';
    if ($content === '') return ['ok'=>false, 'msg'=>'DeepSeek 返回为空'];
    return ['ok'=>true, 'text'=>$content];
}

// 用 ds163 客观数据算六维雷达（竞技优先，无则快速；各轴 0–100，凭经验阈值估算）
function build_radar_ds163($data) {
    $m = null;
    foreach (['sport', 'leisure'] as $mode) {
        if (!empty($data[$mode]['presetsSummaryData'])) { $m = $data[$mode]; break; }
    }
    if (!$m) return null;
    $s = $m['presetsSummaryData'];
    // 主位置（场次最多）的胜率/KDA
    $wr = $s['winRate'] ?? 50; $kda = 0; $best = -1;
    foreach ((array)($m['guideCountData'] ?? []) as $g) {
        if (($g['matchSum'] ?? 0) > $best) { $best = $g['matchSum'] ?? 0; $wr = $g['winRate'] ?? $wr; $kda = $g['kda'] ?? 0; }
    }
    $clamp = function($v){ return (int)round(max(0, min(100, $v))); };
    $contrib = max(((float)($s['aveHeroDamage'] ?? 0))/8000, ((float)($s['aveCure'] ?? 0))/10000, ((float)($s['aveResistDamage'] ?? 0))/8000) * 100;
    return [
        '胜率' => $clamp((float)$wr),
        'KDA'  => $clamp((float)$kda / 5 * 100),
        '击杀' => $clamp((float)($s['aveKill'] ?? 0) / 15 * 100),
        '生存' => $clamp(100 - (float)($s['aveDeath'] ?? 6) * 8),
        '参团' => $clamp((float)($s['aveAssist'] ?? 0) / 15 * 100),
        '贡献' => $clamp($contrib),
    ];
}

// ── OWL 战队 / 职业选手匹配（基于 build_radar_ds163 的六维画像）──
$OWL_TEAMS = [
    '上海龙之队 Shanghai Dragons'  => ['tagline'=>'突袭爆发 · 团战切入凶', 'weights'=>['击杀'=>3,'参团'=>1,'生存'=>-1], 'desc'=>'敢冲敢秀，开团定生死。继续练先手切入，但记得回头看队友跟没跟上。'],
    '首尔王朝 Seoul Dynasty'       => ['tagline'=>'韩系老牌 · 教科书压制', 'weights'=>['胜率'=>2,'KDA'=>2,'生存'=>1], 'desc'=>'靠基本功碾人，少花活多稳赢。继续打磨核心英雄的细节。'],
    '旧金山震动 SF Shock'          => ['tagline'=>'全能 meta · 六边形战士', 'weights'=>['胜率'=>1,'KDA'=>1,'击杀'=>1,'生存'=>1,'参团'=>1,'贡献'=>1], 'desc'=>'没有明显短板，meta 一变就能跟上。保持英雄池广度，别陷进单一英雄。'],
    '达拉斯燃料 Dallas Fuel'       => ['tagline'=>'团队运营 · 节奏控制', 'weights'=>['贡献'=>2,'参团'=>2,'KDA'=>1], 'desc'=>'不浪不送，靠节奏累积优势。后期发力型，警惕"过稳"导致节奏拖沓。'],
    '亚特兰大君临 Atlanta Reign'   => ['tagline'=>'稳守反击 · 后发制人', 'weights'=>['生存'=>2,'贡献'=>2,'胜率'=>1], 'desc'=>'站得住、耗得起。守家英雄最大化优势，但要练点开团别太被动。'],
    '华盛顿正义 Washington Justice'=> ['tagline'=>'灵活多变 · 临场应变', 'weights'=>['参团'=>2,'击杀'=>1,'KDA'=>1], 'desc'=>'阵容多变、应变快。多读对位、磨好 BO 选英雄的能力。'],
];
$OWL_PLAYERS = [
    'dps' => [
        ['name'=>'Fleta（上海龙之队）','tag'=>'Carry 型输出','axes'=>['胜率'=>70,'KDA'=>80,'击杀'=>90,'生存'=>50,'参团'=>60,'贡献'=>70],'desc'=>'一打多的大核，全队输出靠你扛。'],
        ['name'=>'Profit（伦敦喷火）','tag'=>'全能稳定输出','axes'=>['胜率'=>80,'KDA'=>85,'击杀'=>75,'生存'=>70,'参团'=>70,'贡献'=>60],'desc'=>'英雄池深，关键团从不掉链子。'],
        ['name'=>'Carpe（费城融合）','tag'=>'激进神枪','axes'=>['胜率'=>60,'KDA'=>70,'击杀'=>95,'生存'=>40,'参团'=>50,'贡献'=>60],'desc'=>'极致输出，站桩火力压制。'],
    ],
    'tank' => [
        ['name'=>'Mano（纽约九霄天擎）','tag'=>'稳健前排','axes'=>['胜率'=>75,'KDA'=>70,'击杀'=>50,'生存'=>85,'参团'=>70,'贡献'=>80],'desc'=>'前排定海神针，站位滴水不漏。'],
        ['name'=>'Fate（上海龙之队）','tag'=>'激进开团坦','axes'=>['胜率'=>65,'KDA'=>60,'击杀'=>80,'生存'=>50,'参团'=>85,'贡献'=>70],'desc'=>'先手开团狂魔，节奏带动者。'],
        ['name'=>'Super（旧金山震动）','tag'=>'全能主坦','axes'=>['胜率'=>80,'KDA'=>70,'击杀'=>60,'生存'=>75,'参团'=>80,'贡献'=>75],'desc'=>'攻守兼备，团队大脑。'],
    ],
    'healer' => [
        ['name'=>'JJoNak（纽约九霄天擎）','tag'=>'输出型奶','axes'=>['胜率'=>70,'KDA'=>70,'击杀'=>80,'生存'=>55,'参团'=>85,'贡献'=>75],'desc'=>'会输出的禅雅塔，奶量与伤害两开花。'],
        ['name'=>'Viol2t（旧金山震动）','tag'=>'稳健治疗','axes'=>['胜率'=>80,'KDA'=>75,'击杀'=>45,'生存'=>80,'参团'=>70,'贡献'=>90],'desc'=>'治疗量拉满，后排稳如老狗。'],
        ['name'=>'Anamo（纽约九霄天擎）','tag'=>'团队辅助','axes'=>['胜率'=>75,'KDA'=>70,'击杀'=>50,'生存'=>70,'参团'=>85,'贡献'=>80],'desc'=>'团队节奏的粘合剂，视野与配合一流。'],
    ],
];

// 主位置（竞技+快速里累计场次最多的 roleType）
function ds163_main_role($data) {
    $cnt = [];
    foreach (['sport', 'leisure'] as $m) {
        foreach ((array)($data[$m]['guideCountData'] ?? []) as $g) {
            $r = $g['roleType'] ?? ''; if ($r === '') continue;
            $cnt[$r] = ($cnt[$r] ?? 0) + ($g['matchSum'] ?? 0);
        }
    }
    if (!$cnt) return 'open';
    arsort($cnt);
    reset($cnt);
    return (string)key($cnt);
}

// 用六维画像匹配最契合的 OWL 战队 + 最神似的职业选手
function match_owl($radar, $main_role) {
    global $OWL_TEAMS, $OWL_PLAYERS;
    // 战队：按权重对「偏离中位 50」加权
    $bestT = null; $bestTs = -INF;
    foreach ($OWL_TEAMS as $name => $info) {
        $sc = 0; $aw = 0;
        foreach ($info['weights'] as $ax => $w) { $sc += (($radar[$ax] ?? 50) - 50) * $w; $aw += abs($w); }
        $norm = $aw ? $sc / (50 * $aw) : 0;
        if ($norm > $bestTs) { $bestTs = $norm; $bestT = ['name'=>$name] + $info; }
    }
    if ($bestT) $bestT['match_pct'] = max(0, min(100, (int)round(($bestTs + 1) * 50)));
    // 选手：在主位置池里取六维欧氏距离最近的（open/未知用全池）
    $pool = $OWL_PLAYERS[$main_role] ?? [];
    if (!$pool) foreach ($OWL_PLAYERS as $g) $pool = array_merge($pool, $g);
    $bestP = null; $bestPd = INF;
    foreach ($pool as $pl) {
        $d = 0;
        foreach (['胜率','KDA','击杀','生存','参团','贡献'] as $ax) $d += pow(($radar[$ax] ?? 50) - ($pl['axes'][$ax] ?? 50), 2);
        if ($d < $bestPd) { $bestPd = $d; $bestP = $pl; }
    }
    if ($bestP) $bestP['match_pct'] = max(40, min(99, (int)round(100 - sqrt($bestPd / 6))));
    return ['team'=>$bestT, 'player'=>$bestP];
}

// 缓存/历史表（被删 owjob 死代码时误删，现恢复）
function ensure_ow_queries_table($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS ow_queries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        battletag VARCHAR(100) NOT NULL,
        region VARCHAR(8) NOT NULL DEFAULT 'cn',
        owjob_data MEDIUMTEXT,
        deepseek_advice MEDIUMTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id, created_at),
        INDEX idx_tag_region_time (battletag, region, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $col = $conn->query("SHOW COLUMNS FROM ow_queries LIKE 'region'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE ow_queries ADD COLUMN region VARCHAR(8) NOT NULL DEFAULT 'cn' AFTER battletag");
        $conn->query("CREATE INDEX idx_tag_region_time ON ow_queries (battletag, region, created_at)");
    }
    $done = true;
}

function time_ago_cn($ts) {
    $diff = time() - strtotime($ts);
    if ($diff < 60)        return '刚刚';
    if ($diff < 3600)      return floor($diff/60)   . '分钟前';
    if ($diff < 86400)     return floor($diff/3600) . '小时前';
    if ($diff < 86400*30)  return floor($diff/86400). '天前';
    return date('m-d H:i', strtotime($ts));
}

// ── 主流程 ──
ensure_ow_queries_table($conn);
$uid = (int)$_SESSION['user_id'];
$from_cache    = false;
$cached_at     = null;
$force_refresh = !empty($_GET['refresh']) || !empty($_POST['refresh']);
$ai_history_count = 0;  // 本次 DeepSeek 实际对比了几次历史

// 优先：?hid=X 直接看自己的某条历史
if (isset($_GET['hid']) && ctype_digit($_GET['hid'])) {
    $hid = (int)$_GET['hid'];
    $hr  = $conn->query("SELECT battletag, region, owjob_data, deepseek_advice, created_at FROM ow_queries WHERE id={$hid} AND user_id={$uid} LIMIT 1");
    if ($hr && $row = $hr->fetch_assoc()) {
        $battletag_in = $row['battletag'];
        $region       = $row['region'] ?: 'cn';
        $data         = json_decode($row['owjob_data'], true);
        $advice       = (string)$row['deepseek_advice'];
        $from_cache   = true;
        $cached_at    = $row['created_at'];
    } else {
        $error = '未找到该历史记录（可能不存在或不是你查的）。';
    }
}
// 否则：表单提交查询
elseif ($battletag_in !== '') {
    if (!preg_match('/^[A-Za-z0-9\x{4e00}-\x{9fa5}]{2,}#\d{3,}$/u', $battletag_in)) {
        $error = '格式不对。BattleTag 形如 Player#1234（数字 3 位以上）。';
    } else {
        $tag_esc = $conn->real_escape_string($battletag_in);
        $reg_esc = $conn->real_escape_string($region);

        // 缓存命中？（按 BattleTag + region 跨用户共享，6 小时内）
        $cache_row = null;
        if (!$force_refresh) {
            $cr = $conn->query("SELECT id, owjob_data, deepseek_advice, created_at FROM ow_queries WHERE battletag='{$tag_esc}' AND region='{$reg_esc}' AND created_at > DATE_SUB(NOW(), INTERVAL " . OW_CACHE_TTL_HOURS . " HOUR) ORDER BY created_at DESC LIMIT 1");
            if ($cr && $cr->num_rows > 0) $cache_row = $cr->fetch_assoc();
        }

        if ($cache_row) {
            $data       = json_decode($cache_row['owjob_data'], true);
            $advice     = (string)$cache_row['deepseek_advice'];
            $from_cache = true;
            $cached_at  = $cache_row['created_at'];
            $rc = $conn->query("SELECT id FROM ow_queries WHERE user_id={$uid} AND battletag='{$tag_esc}' AND region='{$reg_esc}' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 1");
            if (!$rc || $rc->num_rows === 0) {
                $dj = $conn->real_escape_string(json_encode($data, JSON_UNESCAPED_UNICODE));
                $av = $conn->real_escape_string($advice);
                $conn->query("INSERT INTO ow_queries (user_id, battletag, region, owjob_data, deepseek_advice) VALUES ({$uid}, '{$tag_esc}', '{$reg_esc}', '{$dj}', '{$av}')");
            }
        } else {
            // 真查 —— 按 region 分流（国服=网易大神官方直连，国际=OverFast）
            if ($region === 'intl') {
                $r = overfast_query($battletag_in);
            } else {
                $prof = ds163_full_profile(ds163_load_credentials($conn), $battletag_in);
                $r = $prof['ok'] ? ['data' => $prof] : ['err' => $prof['msg'] ?? '国服查询失败'];
            }
            if (!empty($r['err'])) {
                $error = $r['err'];
            } else {
                $data = $r['data'];
                if (!$data) {
                    $error = ($region === 'intl' ? 'OverFast' : '国服官方接口') . ' 返回空数据';
                } else {
                    // 抓本 BattleTag + region 的过往快照（最近 3 次）— 在落库之前查
                    $history_snapshots = [];
                    $hsq = $conn->query("SELECT owjob_data, created_at FROM ow_queries WHERE battletag='{$tag_esc}' AND region='{$reg_esc}' ORDER BY created_at DESC LIMIT 3");
                    if ($hsq) {
                        while ($hsr = $hsq->fetch_assoc()) {
                            $hsj = json_decode($hsr['owjob_data'], true);
                            if ($hsj) $history_snapshots[] = ['data'=>$hsj, 'created_at'=>$hsr['created_at']];
                        }
                    }
                    $ai_history_count = count($history_snapshots);

                    $ds_key      = get_setting($conn, 'deepseek_api_key',  '');
                    $ds_base_url = get_setting($conn, 'deepseek_base_url', 'https://api.deepseek.com');
                    $ds_model    = get_setting($conn, 'deepseek_model',    'deepseek-chat');
                    $ds = ($region === 'intl')
                        ? call_deepseek_intl ($battletag_in, $data, $history_snapshots, $ds_key, $ds_base_url, $ds_model)
                        : call_deepseek_ds163($battletag_in, $data, $history_snapshots, $ds_key, $ds_base_url, $ds_model);
                    if ($ds['ok']) $advice = $ds['text'];
                    else           $ds_error = $ds['msg'];

                    // 落库
                    $dj = $conn->real_escape_string(json_encode($data, JSON_UNESCAPED_UNICODE));
                    $av = $conn->real_escape_string($advice);
                    $conn->query("INSERT INTO ow_queries (user_id, battletag, region, owjob_data, deepseek_advice) VALUES ({$uid}, '{$tag_esc}', '{$reg_esc}', '{$dj}', '{$av}')");
                }
            }
        }
    }
}

// 加载当前用户最近 8 条历史
$history_items = [];
$hl = $conn->query("SELECT id, battletag, region, created_at, owjob_data FROM ow_queries WHERE user_id={$uid} ORDER BY created_at DESC LIMIT 8");
if ($hl) while ($row = $hl->fetch_assoc()) $history_items[] = $row;

// 把 DeepSeek 输出按【...】标题切成段落卡片
// 返回 [['title'=>'核心画像','body'=>'...'], ...]；若没有任何标题则返回单条 fallback
function parse_owpa_sections($s) {
    $s = trim((string)$s);
    if ($s === '') return [];
    // 用行首【xxx】当锚点切段（避免正文里的【】被误切）
    if (!preg_match('/(^|\n)\s*【[^】]+】/u', $s)) {
        return [['title' => '', 'body' => $s]];
    }
    $parts = preg_split('/\n(?=\s*【[^】]+】)/u', "\n" . $s);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^【([^】]+)】\s*(.*)$/us', $p, $m)) {
            $out[] = ['title' => trim($m[1]), 'body' => trim($m[2])];
        } else {
            // 第一段如果没标题，归到「前言」
            $out[] = ['title' => '', 'body' => $p];
        }
    }
    return $out;
}

// 段落标题 -> CSS 类后缀（决定卡片左边条颜色）
function owpa_section_kind($title) {
    $map = [
        '核心画像' => 'core',
        '位置诊断' => 'role',
        '英雄玩法画像' => 'hero',
        '维度提醒' => 'dim',
        '改进建议' => 'fix',
    ];
    return $map[$title] ?? 'misc';
}

// 简易 markdown
function mini_md($s) {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/^###\s+(.+)$/m', '<h4>$1</h4>', $s);
    $s = preg_replace('/^##\s+(.+)$/m',  '<h3>$1</h3>', $s);
    $s = preg_replace('/^#\s+(.+)$/m',   '<h2>$1</h2>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    $s = preg_replace('/\*(.+?)\*/s',     '<em>$1</em>', $s);
    $s = preg_replace_callback('/(?:^- .+(?:\r?\n|$))+/m', function($m){
        $items = preg_split('/\r?\n/', trim($m[0]));
        $li = '';
        foreach ($items as $it) { $li .= '<li>' . preg_replace('/^- /', '', $it) . '</li>'; }
        return "<ul>$li</ul>";
    }, $s);
    $s = preg_replace('/\n{2,}/', '</p><p>', $s);
    $s = '<p>' . $s . '</p>';
    $s = preg_replace('/<p>(\s*<(h2|h3|h4|ul)>)/', '$1', $s);
    $s = preg_replace('/(<\/(h2|h3|h4|ul)>\s*)<\/p>/', '$1', $s);
    return $s;
}

// SVG 六边形雷达图
function radar_svg($axes) {
    $cx = 160; $cy = 170; $R = 105;
    $labels = array_keys($axes); $values = array_values($axes);
    $n = count($labels);
    $svg  = '<svg viewBox="0 0 320 340" width="100%" style="max-width:300px;">';
    // 5 圈网格
    for ($g = 1; $g <= 5; $g++) {
        $rg = $R * $g / 5; $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $ang = -M_PI/2 + 2*M_PI*$i/$n;
            $pts[] = sprintf('%.1f,%.1f', $cx + $rg*cos($ang), $cy + $rg*sin($ang));
        }
        $stroke = ($g === 5) ? '#484f58' : '#262d36';
        $svg .= '<polygon points="'.implode(' ', $pts).'" fill="none" stroke="'.$stroke.'" stroke-dasharray="2,2"/>';
    }
    // 6 条放射轴
    for ($i = 0; $i < $n; $i++) {
        $ang = -M_PI/2 + 2*M_PI*$i/$n;
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%.1f" y2="%.1f" stroke="#262d36"/>',
            $cx, $cy, $cx + $R*cos($ang), $cy + $R*sin($ang));
    }
    // 玩家多边形
    $poly = [];
    for ($i = 0; $i < $n; $i++) {
        $ang = -M_PI/2 + 2*M_PI*$i/$n;
        $r = max(0, min(100, $values[$i])) / 100 * $R;
        $poly[] = sprintf('%.1f,%.1f', $cx + $r*cos($ang), $cy + $r*sin($ang));
    }
    $svg .= '<polygon points="'.implode(' ', $poly).'" fill="rgba(63,185,80,0.22)" stroke="#3fb950" stroke-width="2"/>';
    foreach ($poly as $pt) {
        list($x, $y) = explode(',', $pt);
        $svg .= sprintf('<circle cx="%s" cy="%s" r="3.5" fill="#3fb950"/>', $x, $y);
    }
    // 6 轴标签
    for ($i = 0; $i < $n; $i++) {
        $ang = -M_PI/2 + 2*M_PI*$i/$n;
        $lx = $cx + ($R + 18) * cos($ang);
        $ly = $cy + ($R + 18) * sin($ang);
        $anchor = ($lx < $cx - 5) ? 'end' : (($lx > $cx + 5) ? 'start' : 'middle');
        $svg .= sprintf(
            '<text x="%.1f" y="%.1f" fill="#c9d1d9" font-size="11" font-family="Microsoft YaHei,sans-serif" text-anchor="%s" dominant-baseline="middle">%s <tspan fill="#3fb950" font-weight="700">%d</tspan></text>',
            $lx, $ly, $anchor, htmlspecialchars($labels[$i]), (int)round($values[$i])
        );
    }
    $svg .= '</svg>';
    return $svg;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>战绩分析 · 缪斯 MUSE</title>
<link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
<link rel="stylesheet" href="../style.css">
<style>
.ow-wrap{max-width:1100px;margin:24px auto;padding:0 16px 80px;}
.ow-title{font-size:13px;font-weight:700;color:#3fb950;letter-spacing:1.5px;text-transform:uppercase;font-family:"Courier New",monospace;margin:0 0 6px;}
.ow-title::before{content:'// ';}
.ow-sub{color:#6e7681;font-size:12px;font-family:"Courier New",monospace;margin:0 0 18px;}

.ow-form{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:18px;margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.ow-form input[type=text]{flex:1;min-width:260px;background:#0d1117;border:1px solid #30363d;color:#e6edf3;border-radius:4px;padding:8px 10px;font-family:"Courier New",monospace;font-size:13px;outline:none;}
.ow-form input[type=text]:focus{border-color:#3fb950;}
.ow-form button{background:#238636;border:1px solid #2ea043;color:#fff;border-radius:4px;padding:8px 18px;cursor:pointer;font-weight:700;font-family:inherit;font-size:13px;transition:.15s;}
.ow-form button:hover{background:#2ea043;}
.ow-form button[disabled]{background:#21262d;border-color:#30363d;color:#6e7681;cursor:wait;}

.region-toggle{display:inline-flex;border:1px solid #30363d;border-radius:4px;overflow:hidden;background:#0d1117;}
.region-toggle label{padding:8px 14px;font-size:12px;color:#8b949e;font-family:"Courier New",monospace;cursor:pointer;letter-spacing:.5px;transition:.15s;border-right:1px solid #30363d;}
.region-toggle label:last-child{border-right:none;}
.region-toggle input{display:none;}
.region-toggle input:checked + label{background:#3fb950;color:#0d1117;font-weight:700;}
.region-toggle label:hover{color:#e6edf3;}
.region-toggle input:checked + label:hover{color:#0d1117;}

.region-tag{display:inline-block;background:rgba(63,185,80,.12);color:#3fb950;font-size:10px;padding:1px 6px;border-radius:3px;font-family:"Courier New",monospace;letter-spacing:.5px;margin-left:4px;}
.region-tag.intl{background:rgba(88,166,255,.12);color:#58a6ff;}

.ow-tip{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;margin:0 0 14px;line-height:1.7;}
.ow-tip code{background:#161b22;border:1px solid #30363d;border-radius:3px;padding:1px 5px;color:#e3b341;}

.ow-history{margin-bottom:14px;}
.ow-history-label{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:8px;}
.ow-history-label::before{content:'// ';opacity:.6;}
.ow-history-list{display:flex;gap:8px;flex-wrap:wrap;}
.ow-history-item{display:inline-flex;align-items:center;gap:8px;background:#161b22;border:1px solid #30363d;border-radius:4px;padding:6px 10px;font-size:12px;color:#c9d1d9;text-decoration:none;font-family:"Courier New",monospace;transition:.15s;}
.ow-history-item:hover{border-color:#58a6ff;color:#58a6ff;}
.ow-history-item.active{border-color:#3fb950;background:rgba(63,185,80,.06);color:#3fb950;}
.ow-history-item .bt{font-weight:600;font-family:"Microsoft YaHei",sans-serif;}
.ow-history-item .sc{background:#0d1117;border:1px solid #30363d;border-radius:3px;padding:1px 6px;font-size:11px;color:#3fb950;}
.ow-history-item .ts{color:#6e7681;font-size:11px;}

.ow-cache-tip{background:rgba(88,166,255,.06);border:1px solid rgba(88,166,255,.3);color:#58a6ff;border-radius:6px;padding:9px 14px;font-size:12px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.ow-cache-tip a{color:#3fb950;text-decoration:underline;}

.ow-error{background:rgba(248,81,73,.08);border:1px solid rgba(248,81,73,.4);color:#f85149;border-radius:6px;padding:12px 14px;font-size:13px;margin-bottom:14px;}

.profile-card{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:18px;display:flex;gap:18px;align-items:center;margin-bottom:14px;flex-wrap:wrap;}
.profile-name{font-size:18px;font-weight:700;color:#e6edf3;font-family:"Courier New",monospace;}
.profile-meta{font-size:12px;color:#8b949e;font-family:"Courier New",monospace;margin-top:4px;}
.profile-right{margin-left:auto;display:flex;gap:12px;align-items:stretch;flex-wrap:wrap;justify-content:flex-end;}
.score-badge{display:flex;flex-direction:column;align-items:center;justify-content:center;background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:10px 16px;min-width:90px;}
.score-badge .lab{font-size:10px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1px;}
.score-badge .num{font-size:28px;font-weight:800;color:#3fb950;font-family:"Courier New",monospace;line-height:1;margin-top:3px;}
.score-badge.low .num{color:#f0883e;}
.score-badge.bad .num{color:#f85149;}

.hero-badge{display:flex;flex-direction:column;align-items:flex-start;justify-content:center;background:#0d1117;border:1px solid #30363d;border-left:3px solid #e3b341;border-radius:6px;padding:9px 14px;min-width:140px;}
.hero-badge .lab{font-size:10px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1px;}
.hero-badge .lab::before{content:'★ ';color:#e3b341;}
.hero-badge .hero{font-size:18px;color:#e3b341;font-weight:700;line-height:1.1;margin-top:4px;font-family:"Microsoft YaHei",sans-serif;}
.hero-badge .meta{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;margin-top:4px;}

.section{background:#161b22;border:1px solid #30363d;border-radius:8px;margin-bottom:14px;overflow:hidden;}
.section-head{padding:10px 16px;border-bottom:1px solid #21262d;display:flex;justify-content:space-between;align-items:center;}
.section-head h3{margin:0;font-size:11px;font-weight:700;color:#6e7681;letter-spacing:1.5px;text-transform:uppercase;font-family:"Courier New",monospace;}
.section-head h3::before{content:'// ';opacity:.6;}
.section-head .meta{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;}
.section-body{padding:14px 16px;}

.analysis-grid{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;}
@media (max-width:820px){.analysis-grid{grid-template-columns:1fr;}}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;}
.kpi{background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:10px 12px;}
.kpi-label{font-size:12px;color:#e6edf3;font-weight:600;line-height:1.3;}
.kpi-value{font-size:22px;color:#3fb950;font-weight:700;font-family:"Courier New",monospace;margin-top:4px;line-height:1;}
.kpi-hint{font-size:11px;color:#6e7681;line-height:1.5;margin-top:6px;font-family:"Microsoft YaHei",sans-serif;}

.radar-wrap{background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:10px 6px 6px;display:flex;flex-direction:column;align-items:center;}
.radar-title{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1.2px;text-transform:uppercase;margin:2px 0 4px;}
.radar-title::before{content:'// ';opacity:.6;}
.radar-foot{font-size:10px;color:#484f58;font-family:"Courier New",monospace;text-align:center;line-height:1.5;padding:0 8px 6px;}

.hero-table{width:100%;border-collapse:collapse;font-size:13px;}
.hero-table th,.hero-table td{padding:8px 10px;border-bottom:1px solid #21262d;text-align:left;}
.hero-table th{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1px;text-transform:uppercase;font-weight:600;}
.hero-table td{color:#c9d1d9;font-family:"Courier New",monospace;}
.hero-table tbody tr:hover{background:#1c2128;}
.hero-name{color:#e6edf3 !important;font-family:"Microsoft YaHei",sans-serif !important;font-weight:600;}
.hero-state{font-size:11px;padding:2px 6px;border-radius:3px;background:rgba(63,185,80,.12);color:#3fb950;}
.hero-state.warn{background:rgba(240,136,62,.12);color:#f0883e;}
.hero-state.bad{background:rgba(248,81,73,.12);color:#f85149;}

.ai-block{background:#0d1117;border:1px solid #30363d;border-left:3px solid #3fb950;border-radius:6px;padding:16px 20px;line-height:1.75;color:#c9d1d9;font-size:14px;}
.ai-block h2{font-size:16px;color:#e6edf3;margin:14px 0 8px;}
.ai-block h3{font-size:14px;color:#3fb950;margin:14px 0 8px;font-family:"Courier New",monospace;letter-spacing:1px;}
.ai-block h4{font-size:13px;color:#58a6ff;margin:12px 0 6px;}
.ai-block p{margin:6px 0;}
.ai-block ul{margin:6px 0;padding-left:22px;}
.ai-block li{margin:3px 0;}
.ai-block strong{color:#e3b341;}

/* OWPA 分类卡片 */
.owpa-cards{display:flex;flex-direction:column;gap:12px;}
.owpa-card{background:#0d1117;border:1px solid #30363d;border-left:3px solid #6e7681;border-radius:6px;padding:14px 18px;line-height:1.75;color:#c9d1d9;font-size:14px;}
.owpa-card .ohead{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.owpa-card .ohead .otag{font-family:"Courier New",monospace;font-size:10px;font-weight:700;letter-spacing:1.5px;padding:2px 7px;border-radius:3px;background:rgba(110,118,129,.15);color:#8b949e;border:1px solid #30363d;}
.owpa-card .ohead .otitle{font-size:14px;font-weight:700;color:#e6edf3;}
.owpa-card .obody{color:#c9d1d9;}
.owpa-card .obody p{margin:5px 0;}
.owpa-card .obody ul{margin:5px 0;padding-left:20px;}
.owpa-card .obody li{margin:3px 0;}
.owpa-card .obody strong{color:#e3b341;}
/* 五类配色 */
.owpa-card.kind-core{border-left-color:#3fb950;}
.owpa-card.kind-core .otag{background:rgba(63,185,80,.12);color:#3fb950;border-color:rgba(63,185,80,.3);}
.owpa-card.kind-role{border-left-color:#58a6ff;}
.owpa-card.kind-role .otag{background:rgba(88,166,255,.12);color:#58a6ff;border-color:rgba(88,166,255,.3);}
.owpa-card.kind-hero{border-left-color:#a78bfa;}
.owpa-card.kind-hero .otag{background:rgba(167,139,250,.12);color:#a78bfa;border-color:rgba(167,139,250,.3);}
.owpa-card.kind-dim{border-left-color:#d29922;}
.owpa-card.kind-dim .otag{background:rgba(210,153,34,.12);color:#d29922;border-color:rgba(210,153,34,.3);}
.owpa-card.kind-fix{border-left-color:#3fb950;background:linear-gradient(180deg,#0d1117 0%,rgba(63,185,80,.04) 100%);}
.owpa-card.kind-fix .otag{background:rgba(63,185,80,.18);color:#3fb950;border-color:rgba(63,185,80,.4);}
.owpa-card.kind-fix .otitle{color:#3fb950;}

.team-card{background:#0d1117;border:1px solid #30363d;border-left:3px solid #58a6ff;border-radius:6px;padding:18px 22px;display:flex;gap:18px;align-items:center;flex-wrap:wrap;}
.team-info{flex:1;min-width:240px;}
.team-name{font-size:18px;font-weight:700;color:#58a6ff;margin-bottom:4px;font-family:"Microsoft YaHei",sans-serif;}
.team-tagline{font-size:12px;color:#8b949e;font-family:"Courier New",monospace;margin-bottom:10px;letter-spacing:.5px;}
.team-desc{font-size:13px;color:#c9d1d9;line-height:1.7;margin:0;}
.team-axes{margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;}
.team-axes .ax{font-size:11px;background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid rgba(88,166,255,.3);border-radius:3px;padding:2px 8px;font-family:"Courier New",monospace;}
.team-match{display:flex;flex-direction:column;align-items:center;background:#161b22;border:1px solid #30363d;border-radius:6px;padding:10px 16px;min-width:90px;}
.team-match .lab{font-size:10px;color:#6e7681;font-family:"Courier New",monospace;letter-spacing:1px;}
.team-match .num{font-size:24px;font-weight:800;color:#58a6ff;font-family:"Courier New",monospace;line-height:1;margin-top:3px;}
.team-match .sub{font-size:10px;color:#6e7681;font-family:"Courier New",monospace;margin-top:2px;}

.ow-foot{margin-top:18px;color:#484f58;font-size:11px;font-family:"Courier New",monospace;text-align:center;}
.ow-foot a{color:#6e7681;}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="ow-wrap">
    <h1 class="ow-title">Overwatch Analyzer</h1>
    <p class="ow-sub">输入战网 BattleTag → 拉取生涯数据 → DeepSeek 给出针对性提升建议</p>

    <form class="ow-form" method="POST" action="ow_analyzer.php" id="ow-form">
        <input type="text" name="battletag" placeholder="BattleTag，例：Player#1234" value="<?= htmlspecialchars($battletag_in) ?>" required>
        <div class="region-toggle">
            <input type="radio" name="region" value="cn"   id="reg-cn"   <?= $region==='cn'  ?'checked':''?>><label for="reg-cn">国服</label>
            <input type="radio" name="region" value="intl" id="reg-intl" <?= $region==='intl'?'checked':''?>><label for="reg-intl">国际服</label>
        </div>
        <button type="submit" id="ow-btn">分析</button>
    </form>

    <p class="ow-tip">
        <?php if ($region === 'intl'): ?>
        ⓘ <b>国际服</b>数据来自 <code>overfast-api.tekrop.fr</code>。仅支持已公开生涯的国际服账号；从国内服务器访问偶尔慢/超时。<br>
        <?php else: ?>
        ⓘ <b>国服</b>：输入完整 BattleTag（昵称#数字）查询。<br>
        <?php endif; ?>
        ⓘ 同一个 BattleTag + 服务器组合 <b>6 小时内</b>会自动用缓存，秒开。
    </p>

    <?php if (!empty($history_items)):
        $cur_hid = isset($_GET['hid']) && ctype_digit($_GET['hid']) ? (int)$_GET['hid'] : 0;
    ?>
    <div class="ow-history">
        <div class="ow-history-label">最近查询</div>
        <div class="ow-history-list">
            <?php foreach ($history_items as $h):
                $jd = json_decode($h['owjob_data'], true);
                $h_reg = $h['region'] ?: 'cn';
                // 国服显示等级；国际服没有单一总分
                $sc = null;
                if ($h_reg === 'cn' && isset($jd['profile']['level'])) $sc = 'Lv' . (int)$jd['profile']['level'];
                $is_active = ($cur_hid === (int)$h['id']);
            ?>
            <a href="?hid=<?= (int)$h['id'] ?>" class="ow-history-item<?= $is_active ? ' active' : '' ?>" title="查看这次结果">
                <span class="bt"><?= htmlspecialchars($h['battletag']) ?></span>
                <span class="region-tag<?= $h_reg==='intl'?' intl':'' ?>"><?= $h_reg==='intl'?'国际':'国服' ?></span>
                <?php if ($sc !== null): ?><span class="sc"><?= $sc ?></span><?php endif; ?>
                <span class="ts"><?= time_ago_cn($h['created_at']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($from_cache && $cached_at): ?>
        <div class="ow-cache-tip">
            ⏱ 数据来自 <?= time_ago_cn($cached_at) ?>的缓存
            <span style="margin-left:auto;">
                <a href="ow_analyzer.php?battletag=<?= urlencode($battletag_in) ?>&amp;region=<?= htmlspecialchars($region) ?>&amp;refresh=1">强制重新查询</a>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="ow-error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($data && $region === 'cn'):
        $p = $data['profile'] ?? [];
        $role_cn = ['tank'=>'坦克', 'dps'=>'输出', 'healer'=>'辅助', 'open'=>'自由'];
        $rank_cn = ['Bronze'=>'青铜','Silver'=>'白银','Gold'=>'黄金','Platinum'=>'铂金','Diamond'=>'钻石','Master'=>'大师','Grandmaster'=>'宗师','Champion'=>'冠军','Ultimate'=>'究极'];
    ?>
        <script>window.OW_CT = <?= json_encode($p['customerToken'] ?? '', JSON_UNESCAPED_SLASHES) ?>;</script>
        <div class="profile-card">
            <?php if (!empty($p['icon'])): ?>
                <img src="<?= htmlspecialchars($p['icon']) ?>" alt="" style="width:64px;height:64px;border-radius:8px;border:1px solid #30363d;background:#0d1117;">
            <?php endif; ?>
            <div style="flex:1;min-width:200px;">
                <div class="profile-name"><?= htmlspecialchars($p['name'] ?? $battletag_in) ?> <span class="region-tag">国服</span></div>
                <div class="profile-meta">
                    bnetId <b><?= htmlspecialchars((string)($p['bnetId'] ?? '—')) ?></b>
                    · 赞赏等级 <b><?= htmlspecialchars((string)($p['level'] ?? '—')) ?></b>
                    · 时长 <b><?= htmlspecialchars((string)($p['gameTime'] ?? '—')) ?></b>h
                    · 总场次 <b><?= htmlspecialchars((string)($p['totalMatchNum'] ?? '—')) ?></b>
                </div>
            </div>
        </div>

        <?php $radar = build_radar_ds163($data); if ($radar): ?>
        <div class="section">
            <div class="section-head"><h3>六维画像</h3><span class="meta">竞技优先 · 场均数据估算</span></div>
            <div class="section-body" style="display:flex;justify-content:center;">
                <div class="radar-wrap" style="border:none;background:transparent;">
                    <?= radar_svg($radar) ?>
                    <div class="radar-foot">胜率 / KDA / 击杀 / 生存 / 参团 / 贡献，每轴 0–100，越靠外越好。</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="section" style="padding-top:0;">
        <div class="ow-mode-tabs" style="padding:14px 16px 4px;margin-bottom:0;">
            <?php $fb=true; foreach (['sport'=>'竞技', 'leisure'=>'快速'] as $mode => $mlbl): if (empty($data[$mode]['guideCountData'])) continue; ?>
                <button type="button" class="ow-mtab<?= $fb ? ' active' : '' ?>" data-pane="owpane-<?= $mode ?>"><?= $mlbl ?></button>
            <?php $fb=false; endforeach; ?>
        </div>
        <?php $fb=true; foreach (['sport'=>'竞技', 'leisure'=>'快速'] as $mode => $mlbl):
            $md = $data[$mode] ?? null;
            if (!is_array($md) || empty($md['guideCountData'])) continue;
            $sum = $md['presetsSummaryData'] ?? [];
        ?>
        <div class="owpane" id="owpane-<?= $mode ?>"<?= $fb ? '' : ' style="display:none;"' ?>>
            <div class="section-body">
                <div class="kpi-grid">
                    <?php foreach ((array)$md['guideCountData'] as $g):
                        $rk = $g['lastRankInfo']['rankName'] ?? 'None';
                        $rank_txt = ($rk === 'None' || $rk === '') ? '未定级' : (($rank_cn[$rk] ?? $rk) . ' ' . ($g['lastRankInfo']['rankSubTier'] ?? ''));
                    ?>
                    <div class="kpi">
                        <div class="kpi-label"><?= htmlspecialchars($role_cn[$g['roleType'] ?? ''] ?? ($g['roleType'] ?? '?')) ?><?php if ($rank_txt !== '未定级'): ?> · <?= htmlspecialchars($rank_txt) ?><?php endif; ?></div>
                        <div class="kpi-value"><?= htmlspecialchars((string)($g['winRate'] ?? '—')) ?>%</div>
                        <div class="kpi-hint"><?= (int)($g['matchSum'] ?? 0) ?> 场 · KDA <?= htmlspecialchars((string)($g['kda'] ?? '—')) ?> · 最高 <?= (int)($g['maxWinStreak'] ?? 0) ?> 连胜</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($sum): ?>
                <div class="kpi-grid" style="margin-top:10px;">
                    <div class="kpi"><div class="kpi-label">场均击杀</div><div class="kpi-value"><?= htmlspecialchars((string)($sum['aveKill'] ?? '—')) ?></div></div>
                    <div class="kpi"><div class="kpi-label">场均死亡</div><div class="kpi-value"><?= htmlspecialchars((string)($sum['aveDeath'] ?? '—')) ?></div></div>
                    <div class="kpi"><div class="kpi-label">场均助攻</div><div class="kpi-value"><?= htmlspecialchars((string)($sum['aveAssist'] ?? '—')) ?></div></div>
                    <div class="kpi"><div class="kpi-label">场均伤害</div><div class="kpi-value" style="font-size:18px;"><?= htmlspecialchars((string)($sum['aveHeroDamage'] ?? '—')) ?></div></div>
                    <div class="kpi"><div class="kpi-label">场均治疗</div><div class="kpi-value" style="font-size:18px;"><?= htmlspecialchars((string)($sum['aveCure'] ?? '—')) ?></div></div>
                    <div class="kpi"><div class="kpi-label">场均承伤</div><div class="kpi-value" style="font-size:18px;"><?= htmlspecialchars((string)($sum['aveResistDamage'] ?? '—')) ?></div></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($md['matchList'])):
                    $mlist  = (array)$md['matchList'];
                    $mcount = count($mlist);
                    $tbl_id = 'ml-' . $mode;
                ?>
                <div class="section-head" style="border:none;padding:14px 0 6px;">
                    <h3>对局记录（<?= $mcount ?> 场）</h3>
                    <?php if ($mcount > 10): ?><span class="meta"><a href="javascript:;" class="ml-toggle" data-tbl="<?= $tbl_id ?>" style="color:#58a6ff;text-decoration:none;">展开全部 ▾</a></span><?php endif; ?>
                </div>
                <div style="overflow-x:auto;">
                    <table class="hero-table">
                        <thead><tr><th>英雄</th><th>位置</th><th>结果</th><th>比分</th><th>K/D/A</th><th>伤害</th><th>治疗</th><th>承伤</th><th>时间</th><th>详情</th></tr></thead>
                        <tbody>
                        <?php foreach ($mlist as $idx => $mt):
                            $win = ($mt['matchRet'] ?? 0) > 0;
                            $ts  = !empty($mt['beginTs']) ? date('m-d H:i', (int)round($mt['beginTs'] / 1000)) : '—';
                        ?>
                            <tr class="<?= $tbl_id ?>-row"<?= $idx >= 10 ? ' style="display:none;"' : '' ?>>
                                <td><?php if (!empty($mt['heroIcon'])): ?><img src="<?= htmlspecialchars($mt['heroIcon']) ?>" alt="" style="width:26px;height:26px;border-radius:4px;vertical-align:middle;background:#0d1117;"><?php endif; ?></td>
                                <td><?= htmlspecialchars($role_cn[$mt['roleType'] ?? ''] ?? '—') ?></td>
                                <td><span class="hero-state <?= $win ? '' : 'bad' ?>"><?= $win ? '胜' : '负' ?></span></td>
                                <td><?= (int)($mt['teamScore'] ?? 0) ?>:<?= (int)($mt['opponentScore'] ?? 0) ?></td>
                                <td><?= (int)($mt['kill'] ?? 0) ?>/<?= (int)($mt['death'] ?? 0) ?>/<?= (int)($mt['assist'] ?? 0) ?></td>
                                <td><?= (int)($mt['heroDamage'] ?? 0) ?></td>
                                <td><?= (int)($mt['cure'] ?? 0) ?></td>
                                <td><?= (int)($mt['resistDamage'] ?? 0) ?></td>
                                <td style="color:#6e7681;"><?= $ts ?></td>
                                <td><?php if (!empty($mt['matchId'])): ?><a href="javascript:;" class="ow-md-btn" data-mid="<?= htmlspecialchars($mt['matchId']) ?>" style="color:#58a6ff;text-decoration:none;">查看</a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php $fb=false; endforeach; ?>
        </div><!-- /mode section -->
    <?php endif; /* end region===cn render */ ?>

    <?php if ($data && $region === 'intl'):
        $sum   = $data['summary'] ?? [];
        $stats = $data['stats']   ?? [];
        $platform = $data['platform'] ?? 'pc';
        $ranks    = $sum['competitive'][$platform] ?? null;
        $g        = $stats['general'] ?? [];

        // Top heroes by time
        $intl_heroes = [];
        if (isset($stats['heroes']) && is_array($stats['heroes'])) {
            foreach ($stats['heroes'] as $name => $h) {
                $intl_heroes[] = [
                    'hero'  => $name,
                    'time'  => $h['time_played']    ?? 0,
                    'games' => $h['games_played']   ?? 0,
                    'wp'    => $h['win_percentage'] ?? null,
                    'kda'   => $h['kda']            ?? null,
                ];
            }
            usort($intl_heroes, function($a,$b){ return ($b['time'] ?? 0) <=> ($a['time'] ?? 0); });
            $intl_heroes = array_slice($intl_heroes, 0, 10);
        }
    ?>
        <div class="profile-card">
            <?php if (!empty($sum['avatar'])): ?>
                <img src="<?= htmlspecialchars($sum['avatar']) ?>" alt="" style="width:64px;height:64px;border-radius:50%;border:1px solid #30363d;background:#0d1117;">
            <?php endif; ?>
            <div style="flex:1;min-width:200px;">
                <div class="profile-name"><?= htmlspecialchars($sum['username'] ?? $battletag_in) ?> <span class="region-tag intl">国际服 · <?= htmlspecialchars(strtoupper($platform)) ?></span></div>
                <div class="profile-meta">
                    <?= htmlspecialchars($sum['title'] ?? '—') ?> · 推荐等级 <b><?= htmlspecialchars((string)($sum['endorsement']['level'] ?? '—')) ?></b>
                </div>
                <?php if ($ranks): ?>
                <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                    <?php foreach (['tank','damage','support','open'] as $role):
                        $rk = $ranks[$role] ?? null;
                        if (!$rk) continue;
                        $rdiv = $rk['division'] ?? '?';
                        $rtier = $rk['tier'] ?? '?';
                    ?>
                        <span class="kpi" style="padding:4px 10px;font-size:11px;color:#c9d1d9;font-family:Courier New,monospace;"><?= ucfirst($role) ?>: <b style="color:#e3b341;"><?= htmlspecialchars($rdiv) ?> <?= htmlspecialchars((string)$rtier) ?></b></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($g)): ?>
        <div class="section">
            <div class="section-head"><h3>对局总览 · 竞技</h3><span class="meta">来源 OverFast</span></div>
            <div class="section-body">
                <div class="kpi-grid">
                    <div class="kpi"><div class="kpi-label">场次</div><div class="kpi-value"><?= htmlspecialchars((string)($g['games_played'] ?? '—')) ?></div></div>
                    <div class="kpi"><div class="kpi-label">胜率</div><div class="kpi-value"><?= htmlspecialchars((string)($g['winrate'] ?? $g['win_percentage'] ?? '—')) ?></div></div>
                    <div class="kpi"><div class="kpi-label">KDA</div><div class="kpi-value"><?= htmlspecialchars((string)($g['kda'] ?? '—')) ?></div></div>
                    <div class="kpi"><div class="kpi-label">总时长</div><div class="kpi-value" style="font-size:16px;"><?php $tp=(int)($g['time_played']??0); echo $tp>0 ? (intdiv($tp,3600).'h'.intdiv($tp%3600,60).'m') : '—'; ?></div></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($intl_heroes)): ?>
        <div class="section">
            <div class="section-head"><h3>常用英雄（按时长）</h3></div>
            <div class="section-body" style="overflow-x:auto;">
                <table class="hero-table">
                    <thead><tr><th>英雄</th><th>时长</th><th>场次</th><th>胜率</th><th>KDA</th></tr></thead>
                    <tbody>
                    <?php foreach ($intl_heroes as $h):
                        $tp = (int)($h['time'] ?? 0);
                        $tstr = $tp>=3600 ? (intdiv($tp,3600).'h'.intdiv($tp%3600,60).'m') : (intdiv($tp,60).'m');
                    ?>
                        <tr>
                            <td class="hero-name"><?= htmlspecialchars(str_replace(['-','_'],' ',$h['hero'])) ?></td>
                            <td><?= $tp>0?$tstr:'—' ?></td>
                            <td><?= (int)($h['games'] ?? 0) ?></td>
                            <td><?= htmlspecialchars((string)($h['wp'] ?? '—')) ?></td>
                            <td><?= htmlspecialchars((string)($h['kda'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; /* end region===intl render */ ?>

    <?php if ($advice): ?>
        <?php $owpa_sections = parse_owpa_sections($advice); ?>
        <div class="section">
            <div class="section-head">
                <h3>DeepSeek 教练分析</h3>
                <?php if ($ai_history_count > 0): ?>
                    <span class="meta">已对比 <b style="color:#3fb950;"><?= $ai_history_count ?></b> 次历史</span>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <?php if (count($owpa_sections) <= 1 && empty($owpa_sections[0]['title'])): ?>
                    <div class="ai-block"><?= mini_md($advice) ?></div>
                <?php else: ?>
                    <div class="owpa-cards">
                        <?php foreach ($owpa_sections as $sec):
                            $kind  = owpa_section_kind($sec['title']);
                            $title = $sec['title'] ?: '其它';
                        ?>
                            <div class="owpa-card kind-<?= $kind ?>">
                                <div class="ohead">
                                    <span class="otag"><?= htmlspecialchars(strtoupper($kind)) ?></span>
                                    <span class="otitle"><?= htmlspecialchars($title) ?></span>
                                </div>
                                <div class="obody"><?= mini_md($sec['body']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($ds_error): ?>
        <div class="ow-error">⚠ <?= htmlspecialchars($ds_error) ?></div>
    <?php endif; ?>

    <?php if ($data && $region === 'cn' && ($radar_owl = build_radar_ds163($data))):
        $owl = match_owl($radar_owl, ds163_main_role($data));
        $tm = $owl['team']; $pl = $owl['player'];
    ?>
    <div class="section">
        <div class="section-head"><h3>你适合的 OWL 战队 &amp; 选手</h3><span class="meta">基于六维画像匹配</span></div>
        <div class="section-body">
            <?php if ($tm): ?>
            <div class="team-card">
                <div class="team-info">
                    <div class="team-name"><?= htmlspecialchars($tm['name']) ?></div>
                    <div class="team-tagline"><?= htmlspecialchars($tm['tagline']) ?></div>
                    <p class="team-desc"><?= htmlspecialchars($tm['desc']) ?></p>
                </div>
                <div class="team-match">
                    <span class="lab">战队契合</span>
                    <span class="num"><?= $tm['match_pct'] ?></span>
                    <span class="sub">/ 100</span>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($pl): ?>
            <div class="team-card" style="border-left-color:#e3b341;margin-top:12px;">
                <div class="team-info">
                    <div class="team-name" style="color:#e3b341;"><?= htmlspecialchars($pl['name']) ?></div>
                    <div class="team-tagline"><?= htmlspecialchars($pl['tag']) ?></div>
                    <p class="team-desc"><?= htmlspecialchars($pl['desc']) ?></p>
                </div>
                <div class="team-match">
                    <span class="lab">选手神似</span>
                    <span class="num" style="color:#e3b341;"><?= $pl['match_pct'] ?></span>
                    <span class="sub">/ 100</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($data || $error): ?>
    <div class="ow-foot">
        <?php if ($region === 'intl'): ?>
        战绩数据 · <a href="https://overfast-api.tekrop.fr" target="_blank" rel="noopener">overfast-api.tekrop.fr</a>　|　教练分析 · DeepSeek
        <?php else: ?>
        教练分析 · DeepSeek
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 查询进度条 -->
<div id="ow-progress" style="display:none;position:fixed;inset:0;background:rgba(13,17,23,.94);z-index:10000;flex-direction:column;align-items:center;justify-content:center;">
  <div style="color:#3fb950;font-family:'Courier New',monospace;font-size:14px;margin-bottom:14px;letter-spacing:1px;">// 正在查询战绩…</div>
  <div style="width:280px;height:6px;background:#21262d;border-radius:3px;overflow:hidden;">
    <div id="ow-progress-bar" style="width:0;height:100%;background:#3fb950;transition:width .25s ease;"></div>
  </div>
</div>

<!-- 单场详情弹窗 -->
<div id="ow-md-mask" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:flex-start;justify-content:center;overflow:auto;padding:40px 16px;">
  <div style="background:#0d1117;border:1px solid #30363d;border-radius:8px;max-width:780px;width:100%;padding:18px;position:relative;">
    <a href="javascript:;" id="ow-md-close" style="position:absolute;top:10px;right:16px;color:#8b949e;text-decoration:none;font-size:18px;">✕</a>
    <div id="ow-md-body" style="color:#c9d1d9;font-family:'Courier New',monospace;font-size:13px;">加载中…</div>
  </div>
</div>
<style>
#ow-md-body .md-err{color:#f85149;padding:10px;}
#ow-md-body .md-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px;}
#ow-md-body .md-map{font-weight:700;color:#e6edf3;font-size:16px;}
#ow-md-body .md-mode{color:#8b949e;font-size:12px;}
#ow-md-body .md-result{padding:1px 8px;border-radius:3px;font-weight:700;}
#ow-md-body .md-result.win{background:rgba(63,185,80,.15);color:#3fb950;}
#ow-md-body .md-result.lose{background:rgba(248,81,73,.15);color:#f85149;}
#ow-md-body .md-score{color:#e3b341;font-weight:700;}
#ow-md-body .md-dur{color:#6e7681;font-size:12px;}
#ow-md-body .md-team-title{font-size:12px;font-weight:700;letter-spacing:1px;margin:12px 0 6px;}
#ow-md-body .md-team-title.md-ally{color:#3fb950;}
#ow-md-body .md-team-title.md-enemy{color:#f85149;}
#ow-md-body .md-table{width:100%;border-collapse:collapse;font-size:12px;}
#ow-md-body .md-table th,#ow-md-body .md-table td{padding:5px 8px;border-bottom:1px solid #21262d;text-align:left;white-space:nowrap;}
#ow-md-body .md-table th{color:#6e7681;font-size:11px;}
#ow-md-body .md-hero{display:flex;align-items:center;gap:6px;}
#ow-md-body .md-hero img{width:24px;height:24px;border-radius:4px;}
#ow-md-body .md-name{color:#e6edf3;font-family:"Microsoft YaHei",sans-serif;}
#ow-md-body .md-rank{color:#e3b341;}
#ow-md-body .md-bans{display:flex;gap:24px;margin-top:14px;flex-wrap:wrap;}
#ow-md-body .md-bans img{width:24px;height:24px;border-radius:4px;margin-right:4px;filter:grayscale(1) brightness(.6);}
#ow-md-body .md-ban-label{color:#6e7681;font-size:11px;margin-right:8px;}
#ow-md-body .md-prow{cursor:pointer;}
#ow-md-body .md-prow:hover{background:#1c2128;}
#ow-md-body .md-pdetail td{background:#010409;color:#8b949e;font-size:11px;padding:6px 10px;}
#ow-md-body .md-pdetail b{color:#e3b341;}
.ow-mode-tabs{display:flex;gap:8px;margin-bottom:12px;}
.ow-mtab{background:#161b22;border:1px solid #30363d;color:#8b949e;padding:7px 22px;border-radius:6px;cursor:pointer;font-family:"Courier New",monospace;font-size:13px;font-weight:700;transition:.15s;}
.ow-mtab:hover{color:#e6edf3;border-color:#3fb950;}
.ow-mtab.active{background:#3fb950;color:#0d1117;border-color:#3fb950;}
</style>

<script>
// 提交时禁用按钮防重复
document.getElementById('ow-form').addEventListener('submit', function(){
    var b = document.getElementById('ow-btn');
    b.disabled = true; b.textContent = '查询中…';
    var p=document.getElementById('ow-progress'), bar=document.getElementById('ow-progress-bar');
    if(p && bar){ p.style.display='flex'; var w=8; bar.style.width='8%';
        var t=setInterval(function(){ w+=Math.random()*11; if(w>=92){w=92;clearInterval(t);} bar.style.width=w+'%'; },220);
    }
});
// 对局记录「展开全部 / 收起」
document.querySelectorAll('.ml-toggle').forEach(function(a){
    a.addEventListener('click', function(){
        var rows = document.querySelectorAll('.' + a.dataset.tbl + '-row');
        var expand = a.textContent.indexOf('展开') >= 0;
        rows.forEach(function(r, i){ if (i >= 10) r.style.display = expand ? '' : 'none'; });
        a.textContent = expand ? '收起 ▴' : '展开全部 ▾';
    });
});
// 单场详情弹窗
(function(){
    var mask=document.getElementById('ow-md-mask'), body=document.getElementById('ow-md-body');
    if(!mask) return;
    function close(){ mask.style.display='none'; }
    var cb=document.getElementById('ow-md-close'); if(cb) cb.addEventListener('click',close);
    mask.addEventListener('click',function(e){ if(e.target===mask) close(); });
    // 点击玩家行展开/收起更详细数据
    body.addEventListener('click',function(e){
        var row=e.target.closest('.md-prow');
        if(row){ var d=row.nextElementSibling; if(d&&d.classList.contains('md-pdetail')) d.style.display=(d.style.display==='none'?'':'none'); }
    });
    document.querySelectorAll('.ow-md-btn').forEach(function(b){
        b.addEventListener('click',function(){
            var mid=b.dataset.mid, ct=window.OW_CT||'';
            if(!ct){ alert('缺 customerToken，请重新查询'); return; }
            body.innerHTML='加载中…'; mask.style.display='flex';
            var fd=new FormData(); fd.append('matchId',mid); fd.append('customerToken',ct);
            fetch('../actions/ow_ds163_match.php',{method:'POST',body:fd,credentials:'same-origin'})
                .then(function(r){return r.text();})
                .then(function(html){ body.innerHTML=html; })
                .catch(function(){ body.innerHTML='<div class="md-err">请求失败</div>'; });
        });
    });
})();
// 竞技/快速 模式 TAB 切换
document.querySelectorAll('.ow-mtab').forEach(function(t){
    t.addEventListener('click',function(){
        document.querySelectorAll('.ow-mtab').forEach(function(x){x.classList.remove('active');});
        t.classList.add('active');
        document.querySelectorAll('.owpane').forEach(function(p){p.style.display='none';});
        var pane=document.getElementById(t.dataset.pane); if(pane) pane.style.display='';
    });
});
</script>
</body>
</html>

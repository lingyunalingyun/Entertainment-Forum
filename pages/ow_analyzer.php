<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/site_settings.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

@set_time_limit(180);

// ── 输入处理 ──
$battletag_in = trim($_POST['battletag'] ?? $_GET['battletag'] ?? '');
$region       = $_POST['region'] ?? $_GET['region'] ?? 'cn';
if (!in_array($region, ['cn', 'intl'], true)) $region = 'cn';

$error      = '';
$data       = null;   // 国服=owjob result; 国际=overfast {summary, stats, ...}
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

function owjob_query($battletag) {
    $tag_enc = urlencode($battletag);

    // 1. precheck
    $url = "https://owjob.online/api/precheck?id={$tag_enc}&t=" . round(microtime(true) * 1000);
    $r = ow_http_get($url, 25);
    if ($r['code'] === 0) return ['err' => '无法连接 owjob.online（服务器到 owjob.online 不通）：' . $r['err']];
    if ($r['code'] !== 200) return ['err' => "precheck 失败 HTTP {$r['code']}"];
    $j = json_decode($r['body'], true);
    if (!$j || empty($j['ok'])) return ['err' => 'precheck 返回异常：' . substr((string)$r['body'], 0, 200)];

    $job_id = $j['job_id'] ?? '';
    if (!$job_id) {
        $line = $j['precheck']['line'] ?? '没找到该玩家';
        return ['err' => "owjob 未能解析这个 BattleTag：{$line}"];
    }

    // 2. poll job —— 最多 90 秒，每 2 秒一次
    $deadline = time() + 90;
    $last_progress = '';
    while (time() < $deadline) {
        $u = "https://owjob.online/api/job?job_id=" . urlencode($job_id) . "&t=" . round(microtime(true) * 1000);
        $r2 = ow_http_get($u, 12);
        if ($r2['code'] === 200) {
            $jj = json_decode($r2['body'], true);
            $st = $jj['job']['status'] ?? '';
            $last_progress = $jj['job']['progress'] ?? $last_progress;
            if (!empty($jj['job']['error'])) return ['err' => 'owjob 任务出错：' . $jj['job']['error']];
            if ($st === 'done' || !empty($jj['job']['ready'])) break;
        }
        sleep(2);
    }
    if (time() >= $deadline) {
        return ['err' => 'owjob 处理超时（>90秒）。当前状态：' . ($last_progress ?: '未知')];
    }

    // 3. result
    $u3 = "https://owjob.online/api/result?job_id=" . urlencode($job_id) . "&t=" . round(microtime(true) * 1000);
    $r3 = ow_http_get($u3, 50);
    if ($r3['code'] !== 200) return ['err' => "result 拉取失败 HTTP {$r3['code']}"];
    $jr = json_decode($r3['body'], true);
    if (!$jr || empty($jr['ok'])) return ['err' => 'result 返回异常'];
    return ['data' => $jr['result'] ?? null, 'job_id' => $job_id];
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
           "你的任务：基于这些数据，输出一份直率、犀利、但建设性的中文教练建议。\n" .
           "硬性要求：\n" .
           "1. 全部用简体中文。\n" .
           "2. 结构化为五节：【核心画像】【强项】【弱项】【针对性提升建议】【推荐练习套路】。\n" .
           "3. 每节给具体英雄/玩法/技能/位置/团队定位层面的可执行建议，不要空话套话。\n" .
           "4. 允许犀利、直接甚至带刺的语气，但有两条铁律——\n" .
           "   (a) 每一句批评后面必须紧跟具体的「下一步怎么改」，只骂不教学绝对不行；\n" .
           "   (b) 攻击点必须落在\"具体行为或选择\"，不攻击玩家本人或人格。\n" .
           "5. 收束基调：刀子嘴豆腐心。\n" .
           "6. 数据不足时只在已有数据范围内分析。";

    if (!empty($history_compact)) {
        $sys .= "\n7. **历史对比**：本次输入除了 current，还附带了同一玩家的 history 快照（按时间倒序）。请在【核心画像】开头追加一段「最近变化」(2–4 行)，明确点出综合胜率、KDA、英雄选择的涨跌（带数字），并在【强项】【弱项】里呼应。变化不显著就直说。";
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

function call_deepseek($battletag, $owjob_result, $history, $key, $base_url, $model) {
    if (!$key) return ['ok'=>false, 'msg'=>'未配置 DeepSeek API Key，请到 后台 → 系统设置 → AI 配置 中填写。'];

    $base_url = rtrim($base_url ?: 'https://api.deepseek.com', '/');
    $model    = $model ?: 'deepseek-chat';

    // 把一份 owjob 数据压缩成对比友好的紧凑结构
    $shrink = function($r, $hero_n = 12) {
        return [
            'mode_label'             => $r['mode_label']             ?? null,
            'sample_size'            => $r['sample_size']            ?? null,
            'overall_score'          => $r['score']                  ?? null,
            'low_contribution_share' => $r['low_contribution_share'] ?? null,
            'metrics' => array_map(function($a){
                return ['label'=>$a['label']??null, 'value'=>$a['value']??null];
            }, (array)($r['analysis'] ?? [])),
            'heroes' => array_map(function($h){
                return [
                    'hero'    => $h['hero']    ?? null,
                    'state'   => $h['state']   ?? null,
                    'matches' => $h['matches'] ?? null,
                    'score'   => $h['score']   ?? null,
                ];
            }, array_slice((array)($r['hero_judgements'] ?? []), 0, $hero_n)),
        ];
    };

    $current = ['snapshot_at' => date('Y-m-d H:i')] + $shrink($owjob_result, 12);

    // 历史快照（按时间倒序，最近的在前）
    $history_compact = [];
    foreach ((array)$history as $h) {
        if (empty($h['data'])) continue;
        $history_compact[] = ['snapshot_at' => date('Y-m-d H:i', strtotime($h['created_at']))]
            + $shrink($h['data'], 6);
    }

    $payload = ['battle_tag' => $battletag, 'current' => $current];
    if (!empty($history_compact)) $payload['history'] = $history_compact;

    $sys = "你是一位经验丰富、风格直接的守望先锋（Overwatch 2）教练，信奉「严师出高徒」——敢戳痛点，但每一刀都为了让玩家变强。\n" .
           "你拿到的数据由第三方平台预分析后给出。\n" .
           "你的任务：基于其量化指标和英雄数据，输出一份直率、犀利、但建设性的中文教练建议。\n" .
           "硬性要求：\n" .
           "1. 全部用简体中文。\n" .
           "2. 结构化为五节：【核心画像】【强项】【弱项】【针对性提升建议】【推荐练习套路】。\n" .
           "3. 每节给具体英雄/玩法/技能/位置/团队定位层面的可执行建议，不要空话套话。\n" .
           "4. 允许犀利、直接甚至带刺的语气（\"挖坑\"\"摆烂\"\"抽风\"\"看不到团队\"\"养成型送头\"等表达可以用），但有两条铁律——\n" .
           "   (a) 每一句批评后面必须紧跟具体的「下一步怎么改」（位置/键位/技能时机/英雄选择/沟通），只骂不教学绝对不行；\n" .
           "   (b) 攻击点必须落在\"具体行为或选择\"（走位、视野、技能用法、英雄池、定位），永远不攻击玩家这个人、智商或人格。\n" .
           "5. 收束基调：刀子嘴豆腐心——前面戳得痛、中间讲清楚为什么烂、结尾给一条让玩家\"明天就想开 OW 练一把\"的可执行路径。\n" .
           "6. 数据不足时只在已有数据范围内分析，明确说明哪里数据不够，不要编造。";

    if (!empty($history_compact)) {
        $sys .= "\n7. **历史对比**：本次输入除了 current，还附带了同一玩家的 history 快照（按时间倒序）。请在【核心画像】开头追加一段「最近变化」(2–4 行)，明确点出：\n" .
                "   - 综合分、关键指标的涨/跌（要带数字，例：\"压制 37→52，长进了\"）；\n" .
                "   - 英雄选择/熟练度的变化趋势（练新英雄了？放弃了某英雄？）；\n" .
                "   - 在【强项】/【弱项】里要呼应这个对比，强化\"哪块进步要保持、哪块掉了要补\"的诊断。\n" .
                "   如果两次数据样本差距太大或时间太近变化不明显，**直接说\"对比变化不显著\"**，不要硬编造对比。";
    }

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' =>
                "请基于以下战绩 JSON 进行分析与建议：\n\n```json\n" .
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

    if ($resp_code !== 200) {
        return ['ok'=>false, 'msg'=>"DeepSeek 调用失败 HTTP {$resp_code}：" . ($err ?: substr((string)$resp_body, 0, 300))];
    }
    $j = json_decode($resp_body, true);
    $content = $j['choices'][0]['message']['content'] ?? '';
    if ($content === '') return ['ok'=>false, 'msg'=>'DeepSeek 返回为空'];
    return ['ok'=>true, 'text'=>$content];
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
            // 真查 —— 按 region 分流
            $r = ($region === 'intl') ? overfast_query($battletag_in) : owjob_query($battletag_in);
            if (!empty($r['err'])) {
                $error = $r['err'];
            } else {
                $data = $r['data'];
                if (!$data) {
                    $error = ($region === 'intl' ? 'OverFast' : 'owjob') . ' 返回空数据';
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
                        ? call_deepseek_intl($battletag_in, $data, $history_snapshots, $ds_key, $ds_base_url, $ds_model)
                        : call_deepseek     ($battletag_in, $data, $history_snapshots, $ds_key, $ds_base_url, $ds_model);
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

// 比例数字格式化
function fmt_pct($v) {
    if ($v === null || $v === '') return '—';
    if (is_numeric($v) && $v <= 1.5) return round($v * 100, 1) . '%';
    return htmlspecialchars((string)$v);
}

// ── 缓存与历史 ──
const OW_CACHE_TTL_HOURS = 6;

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
    // 旧表升级：若没有 region 列就补一个
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

// owjob 的 label 含义字典：关键词 => [一句话解释, 雷达轴名 (null=不上雷达), 是否需要反转方向(高=差则 true)]
$LABEL_HINTS = [
    '人机浓度'  => ['你最近的发挥跟人机 BOT 的相似度，越高越像人机',                '人味',  true],
    '挖坑'      => ['明显拖累团队的对局占比，越高越爱挖坑',                          '不挖坑', true],
    '本职'      => ['在自己角色上完成本职责任的得分，越高越尽责',                    '本职',   false],
    '抽风'      => ['发挥稳定性反向得分，越高代表越容易突然失常',                    '稳定',   true],
    '压得住'    => ['对线和团战中的压制力得分，越高越能压人',                        '压制',   false],
    '别先躺'    => ['团战生存能力得分，越高越能站住',                                '生存',   false],
    '常驻背锅'  => ['你最常承担「背锅」角色的位置（坦/输出/辅助）',                  null,    false],
    '低贡献'    => ['团战贡献低于队友均值的对局占比，越高越爱划水',                  null,    true],
];

// 解析 label：剥前缀（"最烂一项："/"暂时没烂穿："）后匹配关键词
function analyze_label($label) {
    global $LABEL_HINTS;
    $core = preg_replace('/^(最烂一项|暂时没烂穿)[：:]\s*/u', '', (string)$label);
    foreach ($LABEL_HINTS as $kw => $info) {
        if (mb_strpos($core, $kw) !== false) {
            return ['core'=>$core, 'hint'=>$info[0], 'theme'=>$info[1], 'invert'=>$info[2]];
        }
    }
    return ['core'=>$core, 'hint'=>null, 'theme'=>null, 'invert'=>false];
}

// 把 owjob 数值（数字或带 % 的字符串）转成 0–100 得分；invert=true 则反转方向
function to_score($v, $invert) {
    if ($v === null || $v === '' || $v === '-') return null;
    $s = trim((string)$v);
    if (preg_match('/^([\d.]+)\s*%$/', $s, $m)) $n = floatval($m[1]);
    elseif (is_numeric($s)) $n = floatval($s);
    else return null;
    $n = max(0, min(100, $n));
    return $invert ? (100 - $n) : $n;
}

// 从 owjob analysis 数组里抽出 6 个雷达轴的得分
function build_radar_axes($analysis) {
    $axes = ['本职'=>null, '压制'=>null, '稳定'=>null, '生存'=>null, '人味'=>null, '不挖坑'=>null];
    foreach ((array)$analysis as $a) {
        $info = analyze_label($a['label'] ?? '');
        if (!$info['theme'] || !array_key_exists($info['theme'], $axes)) continue;
        $sc = to_score($a['value'] ?? null, $info['invert']);
        if ($sc === null) continue;
        // 同一个轴出现多个值时取较低的（露怯优先）
        $axes[$info['theme']] = ($axes[$info['theme']] === null) ? $sc : min($axes[$info['theme']], $sc);
    }
    foreach ($axes as $k=>$v) if ($v === null) $axes[$k] = 50; // 缺失补 50（中位）
    return $axes;
}

// 战队风格 → 雷达 6 轴的核心权重（负数表示该轴低反而契合）
$TEAM_PROFILES = [
    '首尔王朝 Seoul Dynasty' => [
        'tagline' => '韩系老牌 · 教科书对线压制',
        'desc'    => '每团都把对位吃干净，靠基本功碾人。继续打磨核心英雄的细节，少花活、多稳赢。',
        'weights' => ['压制'=>2, '稳定'=>2, '本职'=>1],
    ],
    '旧金山震动 SF Shock' => [
        'tagline' => '全能 meta · 六边形战士',
        'desc'    => '没有明显短板，meta 一变你就能跟上。继续保持英雄池广度，别陷进单一英雄。',
        'weights' => ['本职'=>1, '压制'=>1, '稳定'=>1, '生存'=>1, '人味'=>1, '不挖坑'=>1],
    ],
    '上海龙之队 Shanghai Dragons' => [
        'tagline' => '突袭爆发 · 团战切入凶',
        'desc'    => '敢冲、敢秀，开团决定整局走向。继续练先手切入英雄（猎空/索杰恩/D.Va），但要记得回头看队友跟没跟上。',
        'weights' => ['压制'=>3, '人味'=>1, '生存'=>-1],
    ],
    '达拉斯燃料 Dallas Fuel' => [
        'tagline' => '经济型运营 · 节奏控制',
        'desc'    => '不浪不送，靠节奏累积优势。这种打法适合后期发力，但要警惕"过于稳"导致的节奏拖沓。',
        'weights' => ['稳定'=>2, '不挖坑'=>2, '本职'=>1],
    ],
    '休斯顿前锋 Houston Outlaws' => [
        'tagline' => '防守硬刚 · 守点专家',
        'desc'    => '对面打不死你。守家英雄（堡垒/法老之鹰/巴蒂斯特）能给你最大化优势，但要练点开团能力别一直被动。',
        'weights' => ['生存'=>2, '本职'=>2, '不挖坑'=>1],
    ],
    '杭州闪电 Hangzhou Spark' => [
        'tagline' => '灵活应变 · 变阵大师',
        'desc'    => '阵容多变、临场应变快。继续保持英雄池广度，多关注 BO 选英雄的对位读秒能力。',
        'weights' => ['人味'=>2, '本职'=>2, '压制'=>1],
    ],
    '温哥华泰坦 Vancouver Titans' => [
        'tagline' => '死亡突进 · 激进 dive',
        'desc'    => '高风险高回报，开团狂魔。强项是把比赛节奏拉到自己的舒适区，但 dive 失败的代价你得能扛。',
        'weights' => ['压制'=>2, '人味'=>2, '生存'=>-2],
    ],
    '伦敦喷火 London Spitfire' => [
        'tagline' => '宏观运营 · 战略脑',
        'desc'    => '会看大局，能做正确的决策。这种打法在排位里偏吃亏（队友不一定听你的），但在固定团队里你就是定海神针。',
        'weights' => ['不挖坑'=>3, '本职'=>2, '人味'=>1],
    ],
];

// 把雷达 6 轴跟战队权重模板做加权匹配，返回最佳战队 + 匹配度
function match_team($axes) {
    global $TEAM_PROFILES;
    $best = null; $best_score = -INF;
    foreach ($TEAM_PROFILES as $name => $info) {
        $score = 0; $abs_w = 0;
        foreach ($info['weights'] as $axis => $w) {
            $v = $axes[$axis] ?? 50;
            $score += ($v - 50) * $w;     // 偏离中位 50 越多、权重符号契合，得分越高
            $abs_w += abs($w);
        }
        // 归一化：理论极值范围 ±50 * abs_w
        $norm = $abs_w ? ($score / (50 * $abs_w)) : 0;  // -1 .. +1
        if ($norm > $best_score) {
            $best_score = $norm;
            $best = ['name' => $name] + $info;
        }
    }
    if (!$best) return null;
    // 找出在该战队权重模板下，玩家最契合的 3 个轴（按 (axes[axis]-50)*sign(weight) 排序取前 3）
    $contrib = [];
    foreach ($best['weights'] as $axis => $w) {
        $sign = $w >= 0 ? 1 : -1;
        $contrib[$axis] = ((($axes[$axis] ?? 50) - 50) * $sign);
    }
    arsort($contrib);
    $best['top_axes'] = array_slice(array_keys($contrib), 0, 3);
    $best['match_pct'] = max(0, min(100, (int)round(($best_score + 1) * 50)));  // -1..+1 → 0..100
    return $best;
}

// 从 hero_judgements 里挑最佳角色演绎者：评分最高且场次≥3
function pick_best_hero($heroes) {
    if (!is_array($heroes) || empty($heroes)) return null;
    $cand = array_filter($heroes, function($h){
        return ($h['matches'] ?? 0) >= 3 && is_numeric($h['score'] ?? null);
    });
    if (empty($cand)) {
        // 数据少时退而求其次：场次≥1
        $cand = array_filter($heroes, function($h){ return is_numeric($h['score'] ?? null); });
    }
    if (empty($cand)) return null;
    usort($cand, function($a, $b){ return ($b['score'] ?? 0) <=> ($a['score'] ?? 0); });
    return $cand[0];
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
        ⓘ <b>国服</b>数据来自 <code>owjob.online</code>。生涯需公开。首次查询新账号需后台爬取，可能等待 <b>10–60 秒</b>。<br>
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
                // 国服分数从 owjob 的 score；国际服没有单一总分，用 endorsement level 代显
                $sc = null;
                if ($h_reg === 'cn' && isset($jd['score'])) $sc = (int)$jd['score'];
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
        $score = isset($data['score']) ? (int)$data['score'] : null;
        $score_cls = $score === null ? '' : ($score >= 60 ? '' : ($score >= 45 ? 'low' : 'bad'));
        $dq = $data['data_quality'] ?? [];
        $best_hero = pick_best_hero($data['hero_judgements'] ?? []);
    ?>
        <div class="profile-card">
            <div style="flex:1;min-width:200px;">
                <div class="profile-name"><?= htmlspecialchars($battletag_in) ?></div>
                <div class="profile-meta">
                    模式 <b><?= htmlspecialchars($data['mode_label'] ?? '—') ?></b>
                    · 样本 <b><?= (int)($data['sample_size'] ?? 0) ?></b> 场
                    · 数据质量 <b><?= htmlspecialchars($dq['quality_label'] ?? '—') ?></b>
                </div>
            </div>
            <div class="profile-right">
                <?php if ($best_hero): ?>
                <div class="hero-badge">
                    <span class="lab">最佳角色演绎</span>
                    <span class="hero"><?= htmlspecialchars($best_hero['hero'] ?? '—') ?></span>
                    <span class="meta"><?= (int)($best_hero['matches'] ?? 0) ?> 场 · 评分 <?= htmlspecialchars((string)($best_hero['score'] ?? '—')) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($score !== null): ?>
                <div class="score-badge <?= $score_cls ?>">
                    <span class="lab">综合评分</span>
                    <span class="num"><?= $score ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($data['analysis'])):
            $radar_axes = build_radar_axes($data['analysis']);
        ?>
        <div class="section">
            <div class="section-head">
                <h3>量化指标</h3>
                <span class="meta">来源 owjob.online</span>
            </div>
            <div class="section-body">
                <div class="analysis-grid">
                    <div class="kpi-grid">
                        <?php foreach ($data['analysis'] as $a):
                            $info = analyze_label($a['label'] ?? '');
                        ?>
                        <div class="kpi">
                            <div class="kpi-label"><?= htmlspecialchars($info['core'] ?: ($a['label'] ?? '—')) ?></div>
                            <div class="kpi-value"><?= htmlspecialchars((string)($a['value'] ?? '—')) ?></div>
                            <?php if (!empty($info['hint'])): ?>
                            <div class="kpi-hint"><?= htmlspecialchars($info['hint']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if (isset($data['low_contribution_share'])): ?>
                        <div class="kpi">
                            <div class="kpi-label">低贡献场次占比</div>
                            <div class="kpi-value"><?= fmt_pct($data['low_contribution_share']) ?></div>
                            <div class="kpi-hint">团战贡献低于队友均值的对局占比，越高越爱划水</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="radar-wrap">
                        <div class="radar-title">六维画像</div>
                        <?= radar_svg($radar_axes) ?>
                        <div class="radar-foot">每轴 0–100，越靠外越好。<br>缺省维度按中位 50 显示。</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($data['hero_judgements'])): ?>
        <div class="section">
            <div class="section-head"><h3>常用英雄</h3></div>
            <div class="section-body" style="overflow-x:auto;">
                <table class="hero-table">
                    <thead><tr><th>英雄</th><th>模式</th><th>场次</th><th>占比</th><th>评分</th><th>状态</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['hero_judgements'] as $h):
                        $st = (string)($h['state'] ?? '');
                        $cls = (mb_strpos($st, '不背') !== false || mb_strpos($st, '稳') !== false) ? '' :
                               ((mb_strpos($st, '背锅') !== false || mb_strpos($st, '烂') !== false) ? 'bad' : 'warn');
                    ?>
                        <tr>
                            <td class="hero-name"><?= htmlspecialchars($h['hero'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($h['mode'] ?? '—') ?></td>
                            <td><?= (int)($h['matches'] ?? 0) ?></td>
                            <td><?= fmt_pct($h['share'] ?? null) ?></td>
                            <td><?= htmlspecialchars((string)($h['score'] ?? '—')) ?></td>
                            <td><span class="hero-state <?= $cls ?>"><?= htmlspecialchars($st ?: '—') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
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
        <div class="section">
            <div class="section-head">
                <h3>DeepSeek 教练分析</h3>
                <?php if ($ai_history_count > 0): ?>
                    <span class="meta">已对比 <b style="color:#3fb950;"><?= $ai_history_count ?></b> 次历史</span>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <div class="ai-block"><?= mini_md($advice) ?></div>
            </div>
        </div>
    <?php elseif ($ds_error): ?>
        <div class="ow-error">⚠ <?= htmlspecialchars($ds_error) ?></div>
    <?php endif; ?>

    <?php
        if ($data && $region === 'cn' && !empty($data['analysis'])):
            $team = match_team($radar_axes ?? build_radar_axes($data['analysis']));
            if ($team):
    ?>
        <div class="section">
            <div class="section-head"><h3>适合你的战队</h3></div>
            <div class="section-body">
                <div class="team-card">
                    <div class="team-info">
                        <div class="team-name"><?= htmlspecialchars($team['name']) ?></div>
                        <div class="team-tagline"><?= htmlspecialchars($team['tagline']) ?></div>
                        <p class="team-desc"><?= htmlspecialchars($team['desc']) ?></p>
                        <div class="team-axes">
                            <?php foreach ($team['top_axes'] as $ax): ?>
                                <span class="ax"><?= htmlspecialchars($ax) ?> <?= (int)round($radar_axes[$ax] ?? 50) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="team-match">
                        <span class="lab">契合度</span>
                        <span class="num"><?= $team['match_pct'] ?></span>
                        <span class="sub">/ 100</span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; endif; ?>

    <?php if ($data || $error): ?>
    <div class="ow-foot">
        <?php if ($region === 'intl'): ?>
        战绩数据 · <a href="https://overfast-api.tekrop.fr" target="_blank" rel="noopener">overfast-api.tekrop.fr</a>　|　教练分析 · DeepSeek
        <?php else: ?>
        战绩数据 · <a href="https://owjob.online" target="_blank" rel="noopener">owjob.online</a>　|　教练分析 · DeepSeek
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// 提交时禁用按钮防重复
document.getElementById('ow-form').addEventListener('submit', function(){
    var b = document.getElementById('ow-btn');
    b.disabled = true; b.textContent = '查询中（可能 10-60 秒）…';
});
</script>
</body>
</html>

<?php
// 把 skland /game/player/info 返回的 ~800KB JSON 裁剪成 ~5-8KB 的玩家档案 snapshot
// 用于喂 DeepSeek 做 4 维度分析（队伍诊断 / 资源规划 / 高难准备度 / 抽卡规划）
//
// 输入：$player_data = skland_player_info() 返回的 ['data']（顶层有 status/chars/charInfoMap/building/...）
// 输出：紧凑数组，调用方再 json_encode 给模型

/**
 * 把干员的 charId 翻译成中文名（用 player_info 自带的 charInfoMap）
 */
function ark_char_name(array $info_map, string $char_id): string {
    return $info_map[$char_id]['name'] ?? $char_id;
}

/**
 * 主裁剪入口
 *
 * @param array $d 来自 skland_player_info 的 data 字段（已是 array）
 * @return array 紧凑 snapshot
 */
function ark_build_snapshot(array $d): array {
    $info_map = $d['charInfoMap'] ?? [];

    // ─── 玩家基本信息 ───
    $status = $d['status'] ?? [];
    $register_ts = $status['registerTs'] ?? 0;
    $register_days = $register_ts > 0 ? (int)((time() - $register_ts) / 86400) : 0;

    $player = [
        'uid'          => $status['uid'] ?? '',
        'name'         => $status['name'] ?? '',
        'level'        => $status['level'] ?? 0,
        'registerDays' => $register_days,
        'charCnt'      => $status['charCnt'] ?? 0,
        'skinCnt'      => $status['skinCnt'] ?? 0,
        'ap'           => ($status['ap']['current'] ?? 0) . '/' . ($status['ap']['max'] ?? 0),
        'secretary'    => ark_char_name($info_map, $status['secretary']['charId'] ?? ''),
    ];

    // ─── 干员档案 ───
    $chars = $d['chars'] ?? [];
    $by_rarity = [0=>0, 1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
    $six_star_owned    = [];  // 6 星全列表
    $five_star_e2      = [];  // 5 星精二
    $unfinished_6star  = [];  // 6 星没精二的，重点提醒

    foreach ($chars as $c) {
        $info = $info_map[$c['charId']] ?? null;
        if (!$info) continue;
        $r = $info['rarity'] ?? 0;
        if (isset($by_rarity[$r])) $by_rarity[$r]++;

        if ($r === 5) { // 6 星
            $skills_spec = [];
            foreach (($c['skills'] ?? []) as $sk) {
                $skills_spec[] = $sk['specializeLevel'] ?? 0;
            }
            $equips = [];
            foreach (($c['equip'] ?? []) as $eq) {
                if (($eq['level'] ?? 0) > 0) {
                    // 取模组 id 末尾后两位（001/002/X）
                    $eid = $eq['id'] ?? '';
                    $tag = preg_match('/uniequip_(\d+)_/', $eid, $m) ? $m[1] : '?';
                    $equips[] = "{$tag}:L" . $eq['level'];
                }
            }

            $entry = [
                'name'    => $info['name'] ?? $c['charId'],
                'prof'    => $info['subProfessionName'] ?? '',
                'evolve'  => $c['evolvePhase'] ?? 0,    // 0/1/2 = 未精/精一/精二
                'level'   => $c['level'] ?? 0,
                'skill'   => $c['mainSkillLvl'] ?? 0,   // 1-7
                'spec'    => $skills_spec,              // [0/1/2/3, ...]
                'equip'   => $equips,                   // ["001:L3", ...]
                'favor'   => $c['favorPercent'] ?? 0,
            ];

            if (($c['evolvePhase'] ?? 0) === 2) {
                $six_star_owned[] = $entry;
            } else {
                $unfinished_6star[] = $entry + ['note' => $c['evolvePhase'] === 1 ? '精一未精二' : '未精英'];
            }
        }
        elseif ($r === 4 && ($c['evolvePhase'] ?? 0) === 2) {
            // 5 星精二列表
            $five_star_e2[] = $info['name'] ?? $c['charId'];
        }
    }

    // 6 星精二按"专精数量"排序（专精多 = 主力）
    usort($six_star_owned, function($a, $b) {
        $sa = array_sum($a['spec']); $sb = array_sum($b['spec']);
        return $sb <=> $sa;
    });

    // ─── 助战干员（核心配置展示位）───
    $assist_list = [];
    foreach (($d['assistChars'] ?? []) as $a) {
        if (!$a) continue;
        $assist_list[] = [
            'name'   => ark_char_name($info_map, $a['charId'] ?? ''),
            'evolve' => $a['evolvePhase'] ?? 0,
            'level'  => $a['level'] ?? 0,
            'skill'  => $a['mainSkillLvl'] ?? 0,
            'spec'   => $a['specializeLevel'] ?? 0,
        ];
    }

    // ─── 基建状态 ───
    // 制造站 formulaId 是玩家本地配方索引，要去 manufactureFormulaInfoMap[fid].itemId 反查实际产物
    static $item_names = [
        '2001' => '初级作战记录', '2002' => '中级作战记录',
        '2003' => '高级作战记录', '2004' => '特级作战记录',
        '3003' => '赤金',
        '4001' => '合成玉', '4002' => '合成玉', '4003' => '合成玉',
    ];
    $formula_map = $d['manufactureFormulaInfoMap'] ?? [];
    $b = $d['building'] ?? [];
    $manufactures = [];
    foreach (($b['manufactures'] ?? []) as $m) {
        $fid = (string)($m['formulaId'] ?? '');
        $iid = (string)($formula_map[$fid]['itemId'] ?? '');
        $product = $item_names[$iid] ?? ($iid ? "itemId={$iid}" : '未启动');
        $manufactures[] = [
            'level'   => $m['level'] ?? 0,
            'product' => $product,
            'speed'   => $m['speed'] ?? 0,
            'workers' => count($m['chars'] ?? []),
            'complete'=> $m['complete'] ?? 0,
            'remain'  => $m['remain'] ?? 0,
        ];
    }
    // 贸易站：森空岛 API 不返回 speed，但 strategy 表明产物（O_GOLD=龙门币、O_DIAMOND=合成玉碎片）
    static $strategy_names = [
        'O_GOLD'    => '龙门币',
        'O_DIAMOND' => '合成玉碎片',
    ];
    $tradings = [];
    foreach (($b['tradings'] ?? []) as $t) {
        $strat = $t['strategy'] ?? '';
        $tradings[] = [
            'level'    => $t['level'] ?? 0,
            'product'  => $strategy_names[$strat] ?? $strat,
            'workers'  => count($t['chars'] ?? []),
            'stockCnt' => count($t['stock'] ?? []),       // 当前堆积订单数（满了就停工）
            'stockMax' => $t['stockLimit'] ?? 0,
        ];
    }
    $powerCount = 0; $powerLevels = [];
    foreach (($b['powers'] ?? []) as $p) {
        $powerCount++;
        $powerLevels[] = $p['level'] ?? 0;
    }
    $building = [
        'control'      => $b['control']['level'] ?? 0,
        'labor'        => ($b['labor']['value'] ?? 0) . '/' . ($b['labor']['maxValue'] ?? 0),
        'manufactures' => $manufactures,
        'tradings'     => $tradings,                            // 贸易站换龙门币/合成玉碎片，不直接产玉
        'powers'       => ['count'=>$powerCount, 'levels'=>$powerLevels],
        'dormCount'    => count($b['dormitories'] ?? []),
        'meetingLvl'   => $b['meeting']['level'] ?? 0,
        'hireLvl'      => $b['hire']['level'] ?? 0,
        'trainingLvl'  => $b['training']['level'] ?? 0,
        'tiredChars'   => count($b['tiredChars'] ?? []),  // 心情低的干员数
    ];

    // ─── 进度战绩 ───
    $routine = [
        'daily'  => ($d['routine']['daily']['current']  ?? 0) . '/' . ($d['routine']['daily']['total']  ?? 0),
        'weekly' => ($d['routine']['weekly']['current'] ?? 0) . '/' . ($d['routine']['weekly']['total'] ?? 0),
    ];

    // 剿灭：满杀 400 的关卡数 + 周报奖励进度
    $campaign_records = $d['campaign']['records'] ?? [];
    $full_kill = 0;
    foreach ($campaign_records as $cr) {
        if (($cr['maxKills'] ?? 0) >= 400) $full_kill++;
    }
    $campaign = [
        'fullKillStages' => $full_kill,
        'totalStages'    => count($campaign_records),
        'reward'         => ($d['campaign']['reward']['current'] ?? 0) . '/' . ($d['campaign']['reward']['total'] ?? 0),
    ];

    // 危机合约：高分关卡数
    $tower_records = $d['tower']['records'] ?? [];
    $tower_best_sum = 0; $tower_high_score = 0;
    foreach ($tower_records as $t) {
        $best = $t['best'] ?? 0;
        $tower_best_sum += $best;
        if ($best >= 5) $tower_high_score++;
    }
    $tower = [
        'recordCount'   => count($tower_records),
        'avgBest'       => $tower_records ? round($tower_best_sum / count($tower_records), 1) : 0,
        'highScoreCnt'  => $tower_high_score,    // >=5 分（高分）数量
        'rewardCur'     => $d['tower']['reward']['higherItem']['current'] ?? 0,
        'rewardMax'     => $d['tower']['reward']['higherItem']['total'] ?? 0,
    ];

    // 集成战略
    $rogue = [];
    foreach (($d['rogue']['records'] ?? []) as $rg) {
        $rogue[] = [
            'id'        => $rg['rogueId'] ?? '',
            'bpLevel'   => $rg['bpLevel'] ?? 0,         // 通行证等级（越高反映越肝）
            'relicCnt'  => $rg['relicCnt'] ?? 0,        // 收集到的藏品数
            'medal'     => ($rg['medal']['current'] ?? 0) . '/' . ($rg['medal']['total'] ?? 0),
            'clearTime' => $rg['clearTime'] ?? 0,
        ];
    }

    // 活动通关数（只看 mini/side/d0/d5/d7 类的本体活动）
    $act_cleared_count = 0;
    $act_inprogress    = [];
    foreach (($d['activity'] ?? []) as $a) {
        $all_cleared = true;
        $has_progress = false;
        foreach (($a['zones'] ?? []) as $z) {
            $cleared = $z['clearedStage'] ?? 0;
            $total   = $z['totalStage']   ?? 0;
            if ($total > 0 && $cleared > 0) $has_progress = true;
            if ($total > 0 && $cleared < $total) $all_cleared = false;
        }
        if ($all_cleared && $has_progress) $act_cleared_count++;
        elseif ($has_progress) $act_inprogress[] = $a['actId'] ?? '';
    }

    return [
        'player'           => $player,
        'roster' => [
            'byRarity'         => $by_rarity,
            'sixStarE2'        => $six_star_owned,
            'sixStarUnfinished'=> $unfinished_6star,
            'fiveStarE2'       => $five_star_e2,
            'assistChars'      => $assist_list,
        ],
        'building'         => $building,
        'progress' => [
            'routine'          => $routine,
            'campaign'         => $campaign,
            'tower'            => $tower,
            'rogue'            => $rogue,
            'activitiesCleared'=> $act_cleared_count,
            'activitiesInProgress' => count($act_inprogress),
        ],
    ];
}

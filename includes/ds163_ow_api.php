<?php
// 网易大神（act.ds.163.com）守望先锋官方战绩 API 封装
//
// ── 鉴权（已实测，2026-05）────────────────────────────────────────────
// 用一个“服务账号”（站长自己的网易大神/守望国服号）的登录态，查询任意玩家。
// 不需要 cookie、不需要 checksum。靠两样：
//   1) 登录 token（32位hex）—— 放在 search 的 body.token，以及查别人时的 gl-bigdata-auth-token 头
//   2) gl-bigdata-* 请求头 —— 表明“查询者是谁”（=服务账号自己的 roleId/server/dts）
//
// 凭证存 site_settings，后台可改。token 会过期，过期后需重新抓一份更新（见 ds_ow_test 页提示）。
//
// 完整流程：
//   ① POST /searchBnetAccount  body{token,roleId,dts,server,name="昵称#数字"}
//      → {bnetId, name, icon, level, gameTime, totalMatchNum, customerToken}
//   ② GET /customer/queryCountInfo?gameMode={sport|leisure}&season=&token={customerToken}
//      + gl-bigdata-* 头  → 各位置场次/胜率/KDA/对局列表
//   ③ GET /customer/queryCard?season=&token={customerToken} + gl-bigdata-* 头 → 段位/等级/头像
//
// 关键事实：customerToken = base64("sign=固定值&bnetId=目标&timestamp=毫秒")，搜索接口直接返回，
//           sign 绑服务账号、不绑被查者，所以一份服务账号凭证可查任意人。

const DS163_OW_BASE   = 'https://datamsapi.ds.163.com';
const DS163_OW_PREFIX = '/v1/a19ld5tool';

/**
 * 从 site_settings 读取服务账号凭证
 * @return array ['token','roleId','dts','server','uid','deviceid','xsrf']
 */
function ds163_load_credentials($conn): array {
    return [
        'token'    => trim(get_setting($conn, 'ow_ds163_token',    '')),
        'roleId'   => trim(get_setting($conn, 'ow_ds163_roleid',   '')),
        'dts'      => trim(get_setting($conn, 'ow_ds163_dts',      '2026')) ?: '2026',
        'server'   => trim(get_setting($conn, 'ow_ds163_server',   '1'))    ?: '1',
        'uid'      => trim(get_setting($conn, 'ow_ds163_uid',      '')),
        'deviceid' => trim(get_setting($conn, 'ow_ds163_deviceid', '')),
        'xsrf'     => trim(get_setting($conn, 'ow_ds163_xsrf',     '')),
        'ntes_sess'=> trim(get_setting($conn, 'ow_ds163_ntes_sess','')),  // customer/查别人需要的当前会话cookie
    ];
}

/** 凭证是否齐全（至少要 token + roleId） */
function ds163_credentials_ok(array $cred): bool {
    return $cred['token'] !== '' && $cred['roleId'] !== '';
}

/** 公共请求头（gl-* 身份头）。$with_bigdata=true 时附带查别人需要的 gl-bigdata-* 头 */
function ds163_headers(array $cred, bool $with_bigdata, array $extra = []): array {
    $h = [
        'Accept: application/json, text/plain, */*',
        'Origin: https://act.ds.163.com',
        'Referer: https://act.ds.163.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36 app/df_client dfVersion/100115',
        'gl-clienttype: 60',
    ];
    if ($cred['uid'])      $h[] = 'gl-uid: ' . $cred['uid'];
    if ($cred['deviceid']) $h[] = 'gl-deviceid: ' . $cred['deviceid'];
    if ($cred['xsrf'])     $h[] = 'gl-x-xsrf-token: ' . $cred['xsrf'];
    if ($with_bigdata) {
        $h[] = 'gl-bigdata-auth-token: ' . $cred['token'];
        $h[] = 'gl-bigdata-role-id: '    . $cred['roleId'];
        $h[] = 'gl-bigdata-dts: '        . $cred['dts'];
        $h[] = 'gl-bigdata-server: '     . $cred['server'];
        // customer/ 校验当前登录会话：必须带 NTES_YD_SESS + GL-XSRF-TOKEN cookie
        if ($cred['ntes_sess']) {
            $cookie = 'GOD_UUID=' . $cred['uid'] . '; NTES_YD_SESS=' . $cred['ntes_sess'];
            if ($cred['xsrf']) $cookie .= '; GL-XSRF-TOKEN=' . $cred['xsrf'];
            $h[] = 'Cookie: ' . $cookie;
        }
    }
    return array_merge($h, $extra);
}

/** 统一解析响应：大神格式 {code:0, success:true, data:{...}} */
function ds163_parse_resp($resp, int $code, string $err): array {
    if ($code === 0) return ['ok'=>false, 'code'=>0, 'raw'=>'', 'msg'=>"网络错误：$err"];
    $j = json_decode($resp, true);
    if ($code !== 200) {
        return ['ok'=>false, 'code'=>$code, 'raw'=>$resp,
                'msg'=>"HTTP $code：" . ($j['errMsg'] ?? $j['error'] ?? substr((string)$resp, 0, 200))];
    }
    if (is_array($j) && isset($j['code']) && $j['code'] !== 0) {
        return ['ok'=>false, 'code'=>$j['code'], 'raw'=>$resp,
                'msg'=>'API 错误：' . ($j['errMsg'] ?? $j['reason'] ?? ('code ' . $j['code']))];
    }
    return ['ok'=>true, 'code'=>$code, 'data'=>$j['data'] ?? $j, 'raw'=>$resp];
}

/**
 * ① 搜战网账号（输 BattleTag 昵称#数字 → 拿 bnetId + customerToken）
 * 接口：POST /searchBnetAccount  body{token,roleId,dts,server,name}
 */
function ds163_search_bnet(array $cred, string $bnet_name): array {
    $body = [
        'token'  => $cred['token'],
        'roleId' => (int)$cred['roleId'],
        'dts'    => (string)$cred['dts'],
        'server' => (string)$cred['server'],
        'name'   => $bnet_name,
    ];
    $body_json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init(DS163_OW_BASE . DS163_OW_PREFIX . '/searchBnetAccount');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => '',   // 自动解 gzip
        CURLOPT_HTTPHEADER     => ds163_headers($cred, false, ['Content-Type: application/json;charset=UTF-8']),
        CURLOPT_POSTFIELDS     => $body_json,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ds163_parse_resp($resp, $code, $err);
}

/** 通用 customer GET（查别人，token=搜索返回的 customerToken） */
function ds163_customer_get(array $cred, string $path, string $customer_token, array $query = []): array {
    $query['token'] = $customer_token;
    $url = DS163_OW_BASE . DS163_OW_PREFIX . '/customer/' . ltrim($path, '/') . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ds163_headers($cred, true),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ds163_parse_resp($resp, $code, $err);
}

/** ② 统计概览（场次/胜率/KDA/对局列表）。$gameMode = sport(竞技) | leisure(快速) */
function ds163_query_count_info(array $cred, string $customer_token, string $gameMode = 'sport', string $season = ''): array {
    return ds163_customer_get($cred, 'queryCountInfo', $customer_token, ['gameMode'=>$gameMode, 'season'=>$season]);
}

/** ③ 段位卡（name/icon/level/段位） */
function ds163_query_card(array $cred, string $customer_token, string $season = ''): array {
    return ds163_customer_get($cred, 'queryCard', $customer_token, ['season'=>$season]);
}

/**
 * 一站式：输 BattleTag → 返回 {profile, sport, leisure, card}
 * @return array ['ok'=>bool, 'msg'=>?, 'profile'=>?, 'sport'=>?, 'leisure'=>?, 'card'=>?]
 */
function ds163_full_profile(array $cred, string $bnet_name): array {
    if (!ds163_credentials_ok($cred)) {
        return ['ok'=>false, 'msg'=>'未配置服务账号凭证（后台 → 守望国服 填 token/roleId）'];
    }
    $sr = ds163_search_bnet($cred, $bnet_name);
    if (!$sr['ok']) return ['ok'=>false, 'msg'=>$sr['msg'] ?? '搜索失败', 'raw'=>$sr['raw'] ?? ''];

    $profile = $sr['data'] ?? [];
    $ct = $profile['customerToken'] ?? '';
    if (!$ct) return ['ok'=>false, 'msg'=>'未找到该玩家（搜索无 customerToken）'];

    $sport   = ds163_query_count_info($cred, $ct, 'sport');
    $leisure = ds163_query_count_info($cred, $ct, 'leisure');
    $card    = ds163_query_card($cred, $ct);

    return [
        'ok'      => true,
        'profile' => $profile,
        'sport'   => $sport['ok']   ? ($sport['data']   ?? null) : null,
        'leisure' => $leisure['ok'] ? ($leisure['data'] ?? null) : null,
        'card'    => $card['ok']    ? ($card['data']    ?? null) : null,
    ];
}

/** 查别人单场对局详情（matchId 来自 matchList/queryCountInfo） */
function ds163_customer_match_info(array $cred, string $customer_token, string $matchId): array {
    return ds163_customer_get($cred, 'queryMatchInfo', $customer_token, ['matchId'=>$matchId]);
}

/** 查别人对局列表（分页，比 queryCountInfo 的 matchList 更全） */
function ds163_customer_match_list(array $cred, string $customer_token, string $gameMode = 'sport', int $page = 1, string $season = ''): array {
    return ds163_customer_get($cred, 'queryMatchList', $customer_token, ['gameMode'=>$gameMode, 'page'=>$page, 'season'=>$season]);
}

/** 英雄映射 heroGuid => ['name','icon','roleType']（读 includes/ow_config/ow_hero_config.json，每请求缓存） */
function ds163_hero_map(): array {
    static $m = null; if ($m !== null) return $m; $m = [];
    $j = json_decode(@file_get_contents(__DIR__ . '/ow_config/ow_hero_config.json'), true);
    foreach ((array)$j as $h) {
        if (!empty($h['heroGuid'])) $m[$h['heroGuid']] = ['name'=>$h['name']??'', 'icon'=>$h['smallIconUrl'] ?? ($h['icon'] ?? ''), 'roleType'=>$h['roleType']??''];
    }
    return $m;
}

/** 地图映射 guid => ['name','mode','icon'] */
function ds163_map_map(): array {
    static $m = null; if ($m !== null) return $m; $m = [];
    $j = json_decode(@file_get_contents(__DIR__ . '/ow_config/ow_map_config.json'), true);
    foreach ((array)$j as $mp) {
        if (!empty($mp['guid'])) $m[$mp['guid']] = ['name'=>$mp['name']??'', 'mode'=>$mp['mode']??'', 'icon'=>$mp['icon']??''];
    }
    return $m;
}

/** statMap 属性名映射 [heroGuid][valueGuid] => valueText（读 ow_hero_attr.json） */
function ds163_stat_map(): array {
    static $m = null; if ($m !== null) return $m; $m = [];
    $j = json_decode(@file_get_contents(__DIR__ . '/ow_config/ow_hero_attr.json'), true);
    foreach ((array)$j as $a) {
        if (!empty($a['heroGuid']) && !empty($a['valueGuid'])) $m[$a['heroGuid']][$a['valueGuid']] = $a['valueText'] ?? '';
    }
    return $m;
}

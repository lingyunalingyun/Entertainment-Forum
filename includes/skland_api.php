<?php
// 森空岛（鹰角网络）API 封装
// 用途：通过用户授权的 Cred 调森空岛接口拿明日方舟玩家数据
// 参考实现：ArkMowers/arknights-mower、aynakeya/skland-api、ProbiusOfficial/Skland_API

const SKLAND_BASE       = 'https://zonai.skland.com';
const SKLAND_UA         = 'Skland/1.0.1 (com.hypergryph.skland; build:100001014; Android 31; ) Okhttp/4.11.0';
const SKLAND_PLATFORM   = '1';        // 1=Android
const SKLAND_VNAME      = '1.0.1';

/**
 * 生成签名 + 待发送的 header 子集
 *
 * 算法（参考 mower 的 Python 实现）：
 *   t           = 当前 Unix 时间 - 2（秒）
 *   header_sub  = {"platform": "1", "timestamp": t, "dId": $did, "vName": "1.0.1"}
 *   header_json = JSON.stringify(header_sub, 无空格分隔)
 *   sig_input   = path + body_or_query + t + header_json
 *   hex_s       = HMAC-SHA256(key=token, msg=sig_input).hex()
 *   sign        = MD5(hex_s).hex()
 *
 * @param string $path           接口路径（含起始 /，不含 query）
 * @param string $body_or_query  GET 时为 query string（不含 ?），POST 时为 JSON body
 * @param string $token          来自 cred 接口换取的 sign token（不是 cred 本身）
 * @param string $did            设备 dId
 * @return array ['sign'=>md5_hex, 'headers'=>['platform'=>...,'timestamp'=>...,'dId'=>...,'vName'=>...,'sign'=>...]]
 */
function skland_sign(string $path, string $body_or_query, string $token, string $did): array {
    $t = (string)(time() - 2);
    $header_sub = [
        'platform'  => SKLAND_PLATFORM,
        'timestamp' => $t,
        'dId'       => $did,
        'vName'     => SKLAND_VNAME,
    ];
    // 紧凑 JSON：无空格分隔
    $header_json = json_encode($header_sub, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig_input   = $path . $body_or_query . $t . $header_json;
    $hex_s       = hash_hmac('sha256', $sig_input, $token);
    $sign        = md5($hex_s);
    return [
        'sign'    => $sign,
        'headers' => $header_sub + ['sign' => $sign],
    ];
}

/**
 * 从 Cred 换取签名 token（首次绑定时调一次，结果可缓存）
 *
 * 接口：GET /api/v1/user/auth/refresh_token （部分实现也用 cred 接口直接拿）
 * 不同社区实现差异较大，本函数返回错误时调用方应回退到"cred 直接当 token"试一次
 *
 * @return array ['ok'=>bool, 'token'=>string?, 'msg'=>string?]
 */
function skland_token_from_cred(string $cred): array {
    $url = SKLAND_BASE . '/api/v1/user/auth/refresh_token';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => [
            'cred: ' . $cred,
            'User-Agent: ' . SKLAND_UA,
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 0)   return ['ok'=>false, 'msg'=>"无法连接森空岛：$err"];
    if ($code !== 200) return ['ok'=>false, 'msg'=>"refresh_token HTTP $code：" . substr((string)$body, 0, 200)];

    $j = json_decode($body, true);
    $token = $j['data']['token'] ?? '';
    if (!$token) return ['ok'=>false, 'msg'=>'refresh_token 响应里没拿到 token：' . substr((string)$body, 0, 200)];
    return ['ok'=>true, 'token'=>$token];
}

/**
 * 通用 GET 调用（带签名）
 *
 * @param string $path        如 '/api/v1/user/check'
 * @param array  $query       GET 参数数组
 * @param string $cred        用户 Cred
 * @param string $token       签名 token（从 cred 换来的，或 cred 本身）
 * @param string $did         dId
 * @return array ['ok'=>bool, 'code'=>int, 'data'=>mixed, 'raw'=>string, 'msg'=>string?]
 */
function skland_get(string $path, array $query, string $cred, string $token, string $did): array {
    ksort($query);  // 按 key 排序（部分实现要求；mower 没排但稳妥起见排一下）
    $qs = http_build_query($query);
    $signed = skland_sign($path, $qs, $token, $did);
    $url = SKLAND_BASE . $path . ($qs ? ('?' . $qs) : '');

    $headers = [
        'cred: ' . $cred,
        'sign: ' . $signed['sign'],
        'platform: ' . $signed['headers']['platform'],
        'timestamp: ' . $signed['headers']['timestamp'],
        'dId: ' . $signed['headers']['dId'],
        'vName: ' . $signed['headers']['vName'],
        'User-Agent: ' . SKLAND_UA,
        'Accept: application/json',
        'Accept-Encoding: gzip',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => 'gzip',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 0) return ['ok'=>false, 'code'=>0, 'raw'=>'', 'msg'=>"网络错误：$err"];
    $j = json_decode($body, true);
    if ($code !== 200) {
        $apiMsg = $j['message'] ?? substr((string)$body, 0, 200);
        return ['ok'=>false, 'code'=>$code, 'raw'=>$body, 'msg'=>"HTTP $code：$apiMsg"];
    }
    // 森空岛标准响应 {code:0, message:"OK", data:{...}}
    if (isset($j['code']) && $j['code'] !== 0) {
        return ['ok'=>false, 'code'=>$code, 'raw'=>$body, 'msg'=>"API 错误：" . ($j['message'] ?? '未知')];
    }
    return ['ok'=>true, 'code'=>$code, 'data'=>$j['data'] ?? null, 'raw'=>$body];
}

/**
 * 验证 Cred 是否有效（同时拿到森空岛 userId）
 * 接口：GET /api/v1/user/check
 *
 * @return array ['ok'=>bool, 'sk_uid'=>string?, 'msg'=>string?, 'raw'=>string?]
 */
function skland_check_cred(string $cred, string $token, string $did): array {
    $r = skland_get('/api/v1/user/check', [], $cred, $token, $did);
    if (!$r['ok']) return ['ok'=>false, 'msg'=>$r['msg'], 'raw'=>$r['raw'] ?? ''];
    $uid = $r['data']['userId'] ?? $r['data']['uid'] ?? '';
    return ['ok'=>true, 'sk_uid'=>$uid, 'raw'=>$r['raw']];
}

/**
 * 拿用户绑定的游戏角色列表（一个鹰角账号可能绑了多个明日方舟角色）
 * 接口：GET /api/v1/game/player/binding
 *
 * @return array ['ok'=>bool, 'bindings'=>array?, 'msg'=>string?, 'raw'=>string?]
 */
function skland_bindings(string $cred, string $token, string $did): array {
    $r = skland_get('/api/v1/game/player/binding', [], $cred, $token, $did);
    if (!$r['ok']) return ['ok'=>false, 'msg'=>$r['msg'], 'raw'=>$r['raw'] ?? ''];
    return ['ok'=>true, 'bindings'=>$r['data']['list'] ?? [], 'raw'=>$r['raw']];
}

/**
 * 拿明日方舟玩家数据（干员/进度/资源/基建/活动 …）
 * 接口：GET /api/v1/game/player/info?uid={game_uid}
 *
 * @return array ['ok'=>bool, 'data'=>array?, 'msg'=>string?, 'raw'=>string?]
 */
function skland_player_info(string $cred, string $token, string $did, string $game_uid): array {
    $r = skland_get('/api/v1/game/player/info', ['uid' => $game_uid], $cred, $token, $did);
    if (!$r['ok']) return ['ok'=>false, 'msg'=>$r['msg'], 'raw'=>$r['raw'] ?? ''];
    return ['ok'=>true, 'data'=>$r['data'], 'raw'=>$r['raw']];
}

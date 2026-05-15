<?php
// 鹰角官方 API（ak.hypergryph.com / as.hypergryph.com / passport.hypergryph.com）封装
// 用途：用户提供鹰角通行证 token，后端代理调"基本信息""抽卡历史""OAuth 授权"等接口
// 参考实现：mole828/GachaNest、cibimo/ArkGachaAnalysisTool、ProbiusOfficial/Skland_API

const HG_AS    = 'https://as.hypergryph.com';
const HG_AK    = 'https://ak.hypergryph.com';
// 森空岛在鹰角 OAuth 体系里的 appCode
const SKLAND_APP_CODE = '4ca99fa6b56cc2ba';

/**
 * 通用 POST JSON 调用
 *
 * @return array ['ok'=>bool, 'code'=>int, 'data'=>mixed, 'raw'=>string, 'msg'=>string?]
 */
function hg_post(string $url, array $body, array $extra_headers = []): array {
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ], $extra_headers);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 0) return ['ok'=>false, 'code'=>0, 'raw'=>'', 'msg'=>"网络错误：$err"];
    $j = json_decode($resp, true);
    if ($code !== 200) {
        return ['ok'=>false, 'code'=>$code, 'raw'=>$resp,
                'msg'=>"HTTP $code：" . ($j['message'] ?? substr((string)$resp, 0, 200))];
    }
    // 鹰角接口约定：status=0（u8 接口）或 code=0（其它）才是成功
    if (isset($j['status']) && $j['status'] !== 0) {
        return ['ok'=>false, 'code'=>$code, 'raw'=>$resp, 'msg'=>"API 错误：" . ($j['msg'] ?? $j['message'] ?? '未知')];
    }
    if (isset($j['code']) && $j['code'] !== 0) {
        return ['ok'=>false, 'code'=>$code, 'raw'=>$resp, 'msg'=>"API 错误：" . ($j['msg'] ?? $j['message'] ?? '未知')];
    }
    return ['ok'=>true, 'code'=>$code, 'data'=>$j['data'] ?? $j, 'raw'=>$resp];
}

/**
 * 通用 GET 调用
 */
function hg_get(string $url, array $extra_headers = []): array {
    $headers = array_merge([
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ], $extra_headers);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 0) return ['ok'=>false, 'code'=>0, 'raw'=>'', 'msg'=>"网络错误：$err"];
    $j = json_decode($resp, true);
    if ($code !== 200) {
        return ['ok'=>false, 'code'=>$code, 'raw'=>$resp,
                'msg'=>"HTTP $code：" . ($j['message'] ?? $j['msg'] ?? substr((string)$resp, 0, 200))];
    }
    if (isset($j['status']) && $j['status'] !== 0) {
        return ['ok'=>false, 'code'=>$code, 'raw'=>$resp, 'msg'=>"API 错误：" . ($j['msg'] ?? '未知')];
    }
    if (isset($j['code']) && $j['code'] !== 0) {
        return ['ok'=>false, 'code'=>$code, 'raw'=>$resp, 'msg'=>"API 错误：" . ($j['msg'] ?? $j['message'] ?? '未知')];
    }
    return ['ok'=>true, 'code'=>$code, 'data'=>$j['data'] ?? $j, 'raw'=>$resp];
}

/**
 * 用手机号 + 密码换 SDK token（24 字符短 token，可喂给 OAuth grant）
 *
 * 接口：POST https://as.hypergryph.com/user/auth/v1/token_by_phone_password
 *   body: { phone, password }
 *   返回 data.token 是 SDK 体系的 24 字符 token
 *
 * Web localStorage 里 ONE_ACCOUNT_ROLE_META.token 是 184 字符 Web token，是另一套体系，
 * grant 接口不收，只有这条路能拿到 SDK token。
 *
 * @return array ['ok'=>bool, 'token'=>string?, 'msg'=>string?, 'raw'=>string]
 */
function hg_token_by_phone_password(string $phone, string $password): array {
    $body = ['phone' => $phone, 'password' => $password];
    $r = hg_post(HG_AS . '/user/auth/v1/token_by_phone_password', $body);
    if (!$r['ok']) return ['ok'=>false, 'msg'=>$r['msg'], 'raw'=>$r['raw'] ?? ''];
    $token = $r['data']['token'] ?? '';
    if (!$token) return ['ok'=>false, 'msg'=>'返回里没有 token 字段', 'raw'=>$r['raw']];
    return ['ok'=>true, 'token'=>$token, 'raw'=>$r['raw']];
}

/**
 * 拿玩家基本信息（uid、昵称、渠道）
 *
 * 主渠道接口：POST https://as.hypergryph.com/u8/user/info/v1/basic
 *   body: { appId:1, channelMasterId:1, channelToken:{ token } }
 * （B 站渠道格式不同，本函数先按主渠道试）
 *
 * @param string $hg_token 用户在 ak.hypergryph.com 登录后拿到的 token
 * @return array ['ok'=>bool, 'uid'=>string?, 'nickname'=>string?, 'channelId'=>int?, 'raw'=>string]
 */
function hg_basic_info(string $hg_token): array {
    $body = [
        'appId'           => 1,
        'channelMasterId' => 1,
        'channelToken'    => ['token' => $hg_token],
    ];
    $r = hg_post(HG_AS . '/u8/user/info/v1/basic', $body);
    if (!$r['ok']) return ['ok'=>false, 'msg'=>$r['msg'], 'raw'=>$r['raw'] ?? ''];
    $d = $r['data'] ?? [];
    return [
        'ok'        => true,
        'uid'       => $d['uid']      ?? '',
        'nickname'  => $d['nickName'] ?? $d['nickname'] ?? '',
        'channelId' => 1,
        'raw'       => $r['raw'],
    ];
}


/**
 * 用鹰角 token 换 OAuth grant code（指定 appCode，默认换森空岛的）
 *
 * 接口：POST https://as.hypergryph.com/user/oauth2/v2/grant
 *   body: { appCode, token, type:0 }
 *
 * @return array ['ok'=>bool, 'code'=>string?, 'msg'=>string?, 'raw'=>string]
 */
function hg_oauth_grant(string $hg_token, string $app_code = SKLAND_APP_CODE): array {
    $body = [
        'appCode' => $app_code,
        'token'   => $hg_token,
        'type'    => 0,
    ];
    $r = hg_post(HG_AS . '/user/oauth2/v2/grant', $body);
    if (!$r['ok']) return ['ok'=>false, 'msg'=>$r['msg'], 'raw'=>$r['raw'] ?? ''];
    $code = $r['data']['code'] ?? '';
    if (!$code) return ['ok'=>false, 'msg'=>'未拿到 grant code', 'raw'=>$r['raw']];
    return ['ok'=>true, 'code'=>$code, 'raw'=>$r['raw']];
}

/**
 * 用 OAuth grant code 换森空岛 Cred + skland sign token
 *
 * 接口：POST https://zonai.skland.com/api/v1/user/auth/generate_cred_by_code
 *   body: { kind:1, code }
 *
 * @return array ['ok'=>bool, 'cred'=>string?, 'token'=>string?, 'userId'=>string?, 'raw'=>string]
 */
function hg_skland_cred_by_code(string $oauth_code): array {
    $body = ['kind' => 1, 'code' => $oauth_code];
    $r = hg_post('https://zonai.skland.com/api/v1/user/auth/generate_cred_by_code', $body);
    if (!$r['ok']) return ['ok'=>false, 'msg'=>$r['msg'], 'raw'=>$r['raw'] ?? ''];
    $d = $r['data'] ?? [];
    return [
        'ok'     => true,
        'cred'   => $d['cred']   ?? '',
        'token'  => $d['token']  ?? '',   // 签名用的 token（接口直接给）
        'userId' => $d['userId'] ?? '',
        'raw'    => $r['raw'],
    ];
}

<?php
// 明日方舟分析功能的两张表 + 读写帮手
//   ark_credentials — 用户的 SDK token / skland cred 等长期凭据（一用户一行）
//   ark_queries     — 每次分析查询的 snapshot + DeepSeek 建议（多行历史）

const ARK_CACHE_TTL_HOURS = 24;  // 24 小时内复用上次的 snapshot + advice

function ensure_ark_credentials_table($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS ark_credentials (
        user_id INT NOT NULL PRIMARY KEY,
        hg_token VARCHAR(64) NOT NULL,
        skland_cred VARCHAR(255) DEFAULT '',
        skland_sign_token VARCHAR(255) DEFAULT '',
        skland_did VARCHAR(64) DEFAULT '',
        game_uid VARCHAR(32) DEFAULT '',
        nickname VARCHAR(64) DEFAULT '',
        channel_master_id INT DEFAULT 1,
        last_verified_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_uid (game_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function ensure_ark_queries_table($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS ark_queries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        game_uid VARCHAR(32) NOT NULL,
        snapshot_data MEDIUMTEXT,
        deepseek_advice MEDIUMTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id, created_at),
        INDEX idx_uid_time (game_uid, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * 拿用户的绑定记录（没绑过返回 null）
 */
function ark_get_credentials($conn, int $user_id): ?array {
    ensure_ark_credentials_table($conn);
    $r = $conn->query("SELECT * FROM ark_credentials WHERE user_id={$user_id} LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) return null;
    return $row;
}

/**
 * 写入/更新绑定记录（按 user_id upsert）
 */
function ark_save_credentials($conn, int $user_id, array $fields): bool {
    ensure_ark_credentials_table($conn);
    $cols = ['hg_token', 'skland_cred', 'skland_sign_token', 'skland_did',
             'game_uid', 'nickname', 'channel_master_id'];
    $set_pairs = [];
    foreach ($cols as $c) {
        if (!array_key_exists($c, $fields)) continue;
        $v = $conn->real_escape_string((string)$fields[$c]);
        $set_pairs[] = "{$c}='{$v}'";
    }
    $set_pairs[] = "last_verified_at=NOW()";
    $set_sql = implode(', ', $set_pairs);

    $hg_token = $conn->real_escape_string($fields['hg_token'] ?? '');
    $skc      = $conn->real_escape_string($fields['skland_cred'] ?? '');
    $sks      = $conn->real_escape_string($fields['skland_sign_token'] ?? '');
    $skd      = $conn->real_escape_string($fields['skland_did'] ?? '');
    $guid     = $conn->real_escape_string($fields['game_uid'] ?? '');
    $nick     = $conn->real_escape_string($fields['nickname'] ?? '');
    $cmid     = (int)($fields['channel_master_id'] ?? 1);

    return (bool)$conn->query(
        "INSERT INTO ark_credentials
            (user_id, hg_token, skland_cred, skland_sign_token, skland_did, game_uid, nickname, channel_master_id)
         VALUES
            ({$user_id}, '{$hg_token}', '{$skc}', '{$sks}', '{$skd}', '{$guid}', '{$nick}', {$cmid})
         ON DUPLICATE KEY UPDATE {$set_sql}"
    );
}

/**
 * 删除绑定（用户主动解绑）
 */
function ark_delete_credentials($conn, int $user_id): bool {
    ensure_ark_credentials_table($conn);
    return (bool)$conn->query("DELETE FROM ark_credentials WHERE user_id={$user_id}");
}

/**
 * 拿用户最近一次分析（TTL 内）
 */
function ark_get_recent_query($conn, int $user_id, int $ttl_hours = ARK_CACHE_TTL_HOURS): ?array {
    ensure_ark_queries_table($conn);
    $r = $conn->query(
        "SELECT id, game_uid, snapshot_data, deepseek_advice, created_at
         FROM ark_queries
         WHERE user_id={$user_id}
           AND created_at > DATE_SUB(NOW(), INTERVAL {$ttl_hours} HOUR)
         ORDER BY created_at DESC LIMIT 1"
    );
    if (!$r || !($row = $r->fetch_assoc())) return null;
    return $row;
}

/**
 * 拿指定历史记录（by id, 校验所有权）
 */
function ark_get_query_by_id($conn, int $id, int $user_id): ?array {
    ensure_ark_queries_table($conn);
    $r = $conn->query(
        "SELECT id, game_uid, snapshot_data, deepseek_advice, created_at
         FROM ark_queries
         WHERE id={$id} AND user_id={$user_id} LIMIT 1"
    );
    if (!$r || !($row = $r->fetch_assoc())) return null;
    return $row;
}

/**
 * 拿用户的历史记录列表（用于侧栏）
 */
function ark_list_queries($conn, int $user_id, int $limit = 10): array {
    ensure_ark_queries_table($conn);
    $r = $conn->query(
        "SELECT id, game_uid, created_at
         FROM ark_queries
         WHERE user_id={$user_id}
         ORDER BY created_at DESC LIMIT {$limit}"
    );
    if (!$r) return [];
    $rows = [];
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    return $rows;
}

/**
 * 落库一次分析结果，同时 prune 保持最多 ARK_HISTORY_KEEP 条
 */
const ARK_HISTORY_KEEP = 5;

function ark_save_query($conn, int $user_id, string $game_uid, array $snapshot, string $advice): int {
    ensure_ark_queries_table($conn);
    $guid = $conn->real_escape_string($game_uid);
    $snap = $conn->real_escape_string(json_encode($snapshot, JSON_UNESCAPED_UNICODE));
    $adv  = $conn->real_escape_string($advice);
    $conn->query(
        "INSERT INTO ark_queries (user_id, game_uid, snapshot_data, deepseek_advice)
         VALUES ({$user_id}, '{$guid}', '{$snap}', '{$adv}')"
    );
    $new_id = (int)$conn->insert_id;

    // prune：保留最新 5 条
    $keep = ARK_HISTORY_KEEP;
    $conn->query(
        "DELETE FROM ark_queries
         WHERE user_id={$user_id}
           AND id NOT IN (
               SELECT id FROM (
                   SELECT id FROM ark_queries
                   WHERE user_id={$user_id}
                   ORDER BY created_at DESC, id DESC LIMIT {$keep}
               ) t
           )"
    );
    return $new_id;
}

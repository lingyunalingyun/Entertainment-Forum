<?php
// 通用站点配置（key/value），用于存放 AI key、第三方接口地址等可在后台动态调整的配置
function ensure_site_settings_table($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS site_settings (
        skey VARCHAR(100) NOT NULL PRIMARY KEY,
        svalue MEDIUMTEXT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function get_setting($conn, $key, $default = '') {
    ensure_site_settings_table($conn);
    $k = $conn->real_escape_string($key);
    $r = $conn->query("SELECT svalue FROM site_settings WHERE skey='$k' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        return $row['svalue'] ?? $default;
    }
    return $default;
}

function set_setting($conn, $key, $value) {
    ensure_site_settings_table($conn);
    $k = $conn->real_escape_string($key);
    $v = $conn->real_escape_string((string)$value);
    return $conn->query("INSERT INTO site_settings (skey, svalue) VALUES ('$k', '$v') ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)");
}

// 用于密钥类字段在前端显示时打码
function mask_secret($s) {
    $s = (string)$s;
    $len = strlen($s);
    if ($len <= 8) return str_repeat('•', $len);
    return substr($s, 0, 4) . str_repeat('•', max(4, $len - 8)) . substr($s, -4);
}

<?php
// 经验 & 等级辅助函数

function get_level_by_exp(int $exp): int {
    if ($exp >= 50000) return 6;
    if ($exp >= 30000) return 5;
    if ($exp >= 15000) return 4;
    if ($exp >= 5000)  return 3;
    if ($exp >= 1000)  return 2;
    return 1;
}

function get_next_level_exp(int $level): ?int {
    $map = [1 => 1000, 2 => 5000, 3 => 15000, 4 => 30000, 5 => 50000, 6 => null];
    return $map[$level] ?? null;
}

function get_prev_level_exp(int $level): int {
    $map = [1 => 0, 2 => 1000, 3 => 5000, 4 => 15000, 5 => 30000, 6 => 50000];
    return $map[$level] ?? 0;
}

function get_level_color(int $level): string {
    $c = [1 => '#8b949e', 2 => '#58a6ff', 3 => '#3fb950', 4 => '#d29922', 5 => '#f0883e', 6 => '#f85149'];
    return $c[$level] ?? '#8b949e';
}

function get_level_name(int $level): string {
    $n = [1 => '新手', 2 => '学徒', 3 => '成员', 4 => '精英', 5 => '大师', 6 => '传奇'];
    return $n[$level] ?? '???';
}

function calc_login_exp(int $streak): int {
    return min($streak * 10, 100);
}

// 增加经验并自动更新等级
function add_exp($conn, int $user_id, int $amount): array {
    $conn->query("UPDATE users SET exp = exp + $amount WHERE id = $user_id");
    $r = $conn->query("SELECT exp FROM users WHERE id = $user_id");
    $exp = $r ? (int)$r->fetch_assoc()['exp'] : 0;
    $new_level = get_level_by_exp($exp);
    $conn->query("UPDATE users SET level = $new_level WHERE id = $user_id");
    return ['exp' => $exp, 'level' => $new_level];
}

// ─── 角色信息 ───────────────────────────────────────────
// role 取值：'owner' | 'admin' | 'reviewer' | 'sponsor' | 'user'
// is_banned 为 true 时无论 role 是什么都显示封禁样式
function get_role_info(string $role, bool $is_banned = false): array {
    if ($is_banned) return [
        'label'  => '已封禁',
        'color'  => '#6e7681',
        'bg'     => 'rgba(110,118,129,.12)',
        'border' => 'rgba(110,118,129,.35)',
    ];
    $map = [
        'owner'    => ['label'=>'★ 站长',   'color'=>'#f85149','bg'=>'rgba(248,81,73,.15)',  'border'=>'rgba(248,81,73,.4)'],
        'admin'    => ['label'=>'管理员',   'color'=>'#a78bfa','bg'=>'rgba(167,139,250,.15)','border'=>'rgba(167,139,250,.4)'],
        'reviewer' => ['label'=>'◈ 审核员', 'color'=>'#e3b341','bg'=>'rgba(227,179,65,.15)', 'border'=>'rgba(227,179,65,.4)'],
        'sponsor'  => ['label'=>'赞助者',   'color'=>'#c084fc','bg'=>'rgba(192,132,252,.15)','border'=>'rgba(192,132,252,.4)'],
        'user'     => ['label'=>'成员',     'color'=>'#58a6ff','bg'=>'rgba(88,166,255,.15)', 'border'=>'rgba(88,166,255,.4)'],
    ];
    return $map[$role] ?? $map['user'];
}

function get_role_badge(string $role, bool $is_banned = false, string $extra = ''): string {
    $i = get_role_info($role, $is_banned);
    $class = (!$is_banned && $role === 'sponsor') ? ' class="sponsor-badge"' : '';
    return "<span{$class} style=\"font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;"
         . "letter-spacing:.3px;background:{$i['bg']};color:{$i['color']};border:1px solid {$i['border']};{$extra}\">"
         . $i['label'] . "</span>";
}

// ─── 检查并补全 users 表所需字段 ─────────────────────────
function ensure_user_columns($conn): void {
    $cols = [
        'email_verified'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'verify_token'         => 'VARCHAR(64) DEFAULT NULL',
        'verify_token_expires' => 'DATETIME DEFAULT NULL',
        'verify_resend_at'     => 'DATETIME DEFAULT NULL',
        'reset_token'          => 'VARCHAR(64) DEFAULT NULL',
        'reset_token_expires'  => 'DATETIME DEFAULT NULL',
        'exp'                  => 'INT NOT NULL DEFAULT 0',
        'login_streak'         => 'INT NOT NULL DEFAULT 0',
        'last_login_date'      => 'DATE DEFAULT NULL',
        'is_banned'            => 'TINYINT(1) NOT NULL DEFAULT 0',
        'ban_reason'           => 'VARCHAR(255) DEFAULT NULL',
        'ban_until'            => 'DATETIME DEFAULT NULL',
        'banned_by'            => 'INT DEFAULT NULL',
        'show_followers'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'show_following'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'post_visibility'      => "VARCHAR(20) NOT NULL DEFAULT 'public'",
        'mid'                  => "CHAR(8) DEFAULT NULL",
        'is_cs'                => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($cols as $col => $def) {
        $r = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $col $def");
        }
    }

    $no_mid = $conn->query("SELECT id FROM users WHERE mid IS NULL");
    if ($no_mid && $no_mid->num_rows > 0) {
        while ($row = $no_mid->fetch_assoc()) {
            $uid = (int)$row['id'];
            do {
                $new_mid = (string)random_int(10000000, 99999999);
                $chk = $conn->query("SELECT id FROM users WHERE mid='$new_mid'");
            } while ($chk && $chk->num_rows > 0);
            $conn->query("UPDATE users SET mid='$new_mid' WHERE id=$uid");
        }
    }

    // 黑名单表
    $conn->query("CREATE TABLE IF NOT EXISTS user_blocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        blocker_id INT NOT NULL,
        blocked_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_block (blocker_id, blocked_id)
    )");

    // 帖子阅读量日志（10分钟去重）
    $conn->query("CREATE TABLE IF NOT EXISTS post_view_logs (
        post_id INT NOT NULL,
        viewer_key VARCHAR(64) NOT NULL,
        viewed_at DATETIME NOT NULL,
        PRIMARY KEY (post_id, viewer_key)
    )");

    // posts 表扩展列
    foreach ([
        'views'     => 'INT NOT NULL DEFAULT 0',
        'repost_id' => 'INT DEFAULT NULL',
    ] as $_col => $_def) {
        $r = $conn->query("SHOW COLUMNS FROM posts LIKE '$_col'");
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE posts ADD COLUMN $_col $_def");
    }

    // messages 表 ref_post_id 列
    $r = $conn->query("SHOW TABLES LIKE 'messages'");
    if ($r && $r->num_rows > 0) {
        $r2 = $conn->query("SHOW COLUMNS FROM messages LIKE 'ref_post_id'");
        if ($r2 && $r2->num_rows === 0)
            $conn->query("ALTER TABLE messages ADD COLUMN ref_post_id INT DEFAULT NULL");
    }

    // 举报表
    $conn->query("CREATE TABLE IF NOT EXISTS reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT NOT NULL,
        type VARCHAR(10) NOT NULL COMMENT 'post|user',
        target_id INT NOT NULL,
        reason VARCHAR(100) NOT NULL,
        detail VARCHAR(500) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|handled|dismissed',
        handler_id INT DEFAULT NULL,
        handled_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type_target (type, target_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 修复已存在的 reports 表 charset
    $conn->query("ALTER TABLE reports CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 个人资料审核申请表
    $conn->query("CREATE TABLE IF NOT EXISTS profile_edit_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        new_username VARCHAR(50) DEFAULT NULL,
        new_gender VARCHAR(10) DEFAULT NULL,
        new_phone VARCHAR(20) DEFAULT NULL,
        new_birthday DATE DEFAULT NULL,
        new_signature VARCHAR(200) DEFAULT NULL,
        new_avatar VARCHAR(100) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        admin_id INT DEFAULT NULL,
        admin_note VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME DEFAULT NULL,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// 可见性检查：当前访问者能否看到 $author_id 的帖子
// 返回 true = 可以看，false = 不能看
function can_see_posts($conn, string $visibility, int $author_id, int $viewer_id, string $viewer_role = 'user'): bool {
    if ($viewer_id === $author_id) return true;
    if (in_array($viewer_role, ['admin', 'owner'])) return true;
    if ($visibility === 'public') return true;
    if ($visibility === 'private') return false;
    if ($viewer_id === 0) return false; // 未登录

    if ($visibility === 'followers') {
        // 访问者需关注作者
        $r = $conn->query("SELECT id FROM follows WHERE follower_id=$viewer_id AND followed_id=$author_id");
        return $r && $r->num_rows > 0;
    }
    if ($visibility === 'following') {
        // 作者需关注访问者
        $r = $conn->query("SELECT id FROM follows WHERE follower_id=$author_id AND followed_id=$viewer_id");
        return $r && $r->num_rows > 0;
    }
    if ($visibility === 'mutual') {
        // 互相关注
        $r1 = $conn->query("SELECT id FROM follows WHERE follower_id=$viewer_id AND followed_id=$author_id");
        $r2 = $conn->query("SELECT id FROM follows WHERE follower_id=$author_id AND followed_id=$viewer_id");
        return ($r1 && $r1->num_rows > 0) && ($r2 && $r2->num_rows > 0);
    }
    return true;
}

// 检查是否被拉黑（blocker 拉黑了 blocked）
function is_blocked($conn, int $blocker_id, int $blocked_id): bool {
    if ($blocker_id === 0 || $blocked_id === 0) return false;
    $r = $conn->query("SELECT id FROM user_blocks WHERE blocker_id=$blocker_id AND blocked_id=$blocked_id");
    return $r && $r->num_rows > 0;
}

// ─── 在线曲库（song_sheets）──────────────────────────────
// 给 SkyMusic 桌面端拉取曲谱用，匿名可下载，登录可上传
function ensure_song_sheets_table($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS song_sheets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uploader_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        artist VARCHAR(100) DEFAULT NULL,
        transcribed_by VARCHAR(100) DEFAULT NULL,
        bpm INT DEFAULT NULL,
        subdiv INT DEFAULT NULL,
        difficulty TINYINT NOT NULL DEFAULT 3,
        description VARCHAR(500) DEFAULT NULL,
        tags VARCHAR(255) DEFAULT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) DEFAULT NULL,
        file_size INT NOT NULL DEFAULT 0,
        note_count INT NOT NULL DEFAULT 0,
        downloads INT NOT NULL DEFAULT 0,
        likes INT NOT NULL DEFAULT 0,
        views INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'published',
        is_recommended TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_uploader (uploader_id),
        INDEX idx_status (status),
        INDEX idx_created (created_at),
        INDEX idx_downloads (downloads),
        INDEX idx_likes (likes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS sheet_likes (
        sheet_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sheet_user (sheet_id, user_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 文件目录
    $dir = dirname(__DIR__) . '/uploads/sheets';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

// 把任意编码的曲谱字节流转成 UTF-8 字符串（去 BOM）
// 支持 UTF-8 / UTF-16 LE / UTF-16 BE，SkyStudio 老曲谱大量用 UTF-16 LE BOM
function normalize_sheet_encoding(string $content): string {
    if (strlen($content) >= 2) {
        $b0 = $content[0]; $b1 = $content[1];
        if ($b0 === "\xFF" && $b1 === "\xFE") {
            $conv = @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($content, 2));
            if ($conv !== false) return $conv;
        }
        if ($b0 === "\xFE" && $b1 === "\xFF") {
            $conv = @iconv('UTF-16BE', 'UTF-8//IGNORE', substr($content, 2));
            if ($conv !== false) return $conv;
        }
    }
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
    return $content;
}

// 解析 SkyStudio JSON 曲谱，提取元数据
// 返回 ['ok'=>bool, 'msg'=>string, 'meta'=>['name','bpm','subdiv','author','transcribedBy','noteCount']]
function parse_sky_sheet_json(string $content): array {
    $content = normalize_sheet_encoding($content);
    $data = json_decode($content, true);
    if ($data === null) {
        $err = function_exists('json_last_error_msg') ? json_last_error_msg() : 'json decode failed';
        return ['ok' => false, 'msg' => 'JSON 解析失败：' . $err, 'meta' => []];
    }
    // 兼容 [{}] 数组包裹和 {} 单对象两种写法
    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
        $song = $data[0];
    } elseif (is_array($data) && isset($data['songNotes'])) {
        $song = $data;
    } else {
        return ['ok' => false, 'msg' => '曲谱结构不合法（顶层应为数组或对象）', 'meta' => []];
    }
    if (empty($song['songNotes']) || !is_array($song['songNotes'])) {
        return ['ok' => false, 'msg' => '曲谱缺少 songNotes 字段', 'meta' => []];
    }
    return ['ok' => true, 'msg' => '', 'meta' => [
        'name'           => isset($song['name']) ? trim((string)$song['name']) : '',
        'bpm'            => isset($song['bpm']) ? (int)$song['bpm'] : 0,
        'subdiv'         => isset($song['subdiv']) ? (int)$song['subdiv'] : 0,
        'author'         => isset($song['author']) ? trim((string)$song['author']) : '',
        'transcribedBy'  => isset($song['transcribedBy']) ? trim((string)$song['transcribedBy']) : '',
        'noteCount'      => count($song['songNotes']),
    ]];
}

// ─── 内部：构建 HTML 邮件模板 ───────────────────────────
function _build_email_html(string $title, string $greeting, string $body_html, string $btn_text, string $btn_link): string {
    $site = SITE_NAME;
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:0;background:#0d1117;font-family:\'Courier New\',monospace;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#0d1117;padding:40px 20px;">'
        . '<tr><td align="center">'
        . '<table width="480" cellpadding="0" cellspacing="0" style="background:#161b22;border:1px solid #30363d;border-radius:6px;">'
        . '<tr><td style="padding:10px 28px;background:#0d1117;border-bottom:1px solid #21262d;">'
        . '<span style="color:#3fb950;font-size:13px;font-weight:700;">&gt; ' . htmlspecialchars($site) . ' // mail</span>'
        . '</td></tr>'
        . '<tr><td style="padding:32px;">'
        . '<p style="color:#3fb950;font-size:20px;font-weight:700;margin:0 0 20px;">&gt; ' . $title . '</p>'
        . '<p style="color:#8b949e;font-size:13px;margin:0 0 12px;">你好，' . htmlspecialchars($greeting) . '</p>'
        . '<div style="color:#8b949e;font-size:13px;line-height:1.8;margin:0 0 28px;">' . $body_html . '</div>'
        . '<table cellpadding="0" cellspacing="0" style="margin-bottom:28px;">'
        . '<tr><td style="background:#3fb950;border-radius:4px;">'
        . '<a href="' . htmlspecialchars($btn_link) . '" style="display:inline-block;padding:11px 32px;color:#fff;text-decoration:none;font-size:14px;font-weight:700;font-family:\'Courier New\',monospace;">' . htmlspecialchars($btn_text) . '</a>'
        . '</td></tr></table>'
        . '<p style="color:#6e7681;font-size:11px;margin:0 0 6px;">或复制链接到浏览器：</p>'
        . '<p style="color:#58a6ff;font-size:11px;word-break:break-all;margin:0 0 24px;padding:8px 12px;background:#0d1117;border-radius:4px;border:1px solid #21262d;">' . htmlspecialchars($btn_link) . '</p>'
        . '<p style="color:#6e7681;font-size:11px;margin:0;">如非本人操作，请忽略此邮件。</p>'
        . '</td></tr>'
        . '<tr><td style="padding:10px 28px;background:#0d1117;border-top:1px solid #21262d;">'
        . '<span style="color:#6e7681;font-size:11px;">— ' . htmlspecialchars($site) . ' 自动发送，请勿回复</span>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

// ─── 内部：发送 HTML 邮件 ───────────────────────────────
function _send_html_mail(string $to, string $subject, string $html): bool {
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = "From: " . SITE_NAME . " <" . MAIL_FROM . ">\r\n"
             . "Reply-To: " . MAIL_FROM . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n";
    return mail($to, $encoded_subject, $html, $headers);
}

// 发送邮箱验证邮件
function send_verify_email(string $to_addr, string $username, string $token): bool {
    $link    = SITE_URL . '/pages/verify.php?token=' . rawurlencode($token);
    $subject = '【' . SITE_NAME . '】请验证您的邮箱';
    $body    = '感谢注册 ' . SITE_NAME . '！请点击下方按钮完成邮箱验证。<br>'
             . '链接 <b style="color:#e6edf3;">24 小时</b>内有效。';
    $html    = _build_email_html('VERIFY_EMAIL_', $username, $body, '验证邮箱', $link);
    return _send_html_mail($to_addr, $subject, $html);
}

// 发送密码重置邮件
function send_reset_email(string $to_addr, string $username, string $token): bool {
    $link    = SITE_URL . '/pages/reset_password.php?token=' . rawurlencode($token);
    $subject = '【' . SITE_NAME . '】密码重置请求';
    $body    = '我们收到了你的密码重置请求。请点击下方按钮设置新密码。<br>'
             . '链接 <b style="color:#e6edf3;">1 小时</b>内有效。如非本人操作，请忽略此邮件并确认账号安全。';
    $html    = _build_email_html('RESET_PASSWORD_', $username, $body, '重置密码', $link);
    return _send_html_mail($to_addr, $subject, $html);
}

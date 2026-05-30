<?php
// 调酒页相关：食材表 / 鸡尾酒表 / 配方关联表

function ensure_cocktail_tables($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS ingredients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL UNIQUE,
        type VARCHAR(20) NOT NULL DEFAULT 'other',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS cocktails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        name_en VARCHAR(80) NOT NULL DEFAULT '',
        glass VARCHAR(40) NOT NULL DEFAULT '',
        method VARCHAR(20) NOT NULL DEFAULT 'shake',
        instructions TEXT NOT NULL,
        garnish VARCHAR(120) NOT NULL DEFAULT '',
        image VARCHAR(255) NOT NULL DEFAULT '',
        abv_hint VARCHAR(20) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS cocktail_ingredients (
        cocktail_id INT NOT NULL,
        ingredient_id INT NOT NULL,
        amount VARCHAR(40) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (cocktail_id, ingredient_id),
        INDEX idx_ingredient (ingredient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS cocktail_steps (
        cocktail_id INT NOT NULL,
        step_order INT NOT NULL,
        content VARCHAR(500) NOT NULL,
        PRIMARY KEY (cocktail_id, step_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 互动：收藏 / 点赞 / 评分 / 评论 ──
    $conn->query("CREATE TABLE IF NOT EXISTS cocktail_favorites (
        user_id INT NOT NULL, cocktail_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, cocktail_id), INDEX idx_c (cocktail_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS cocktail_likes (
        user_id INT NOT NULL, cocktail_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, cocktail_id), INDEX idx_c (cocktail_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS cocktail_ratings (
        user_id INT NOT NULL, cocktail_id INT NOT NULL,
        score TINYINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, cocktail_id), INDEX idx_c (cocktail_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS cocktail_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cocktail_id INT NOT NULL, user_id INT NOT NULL,
        content VARCHAR(1000) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_c (cocktail_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 评论点赞/讨厌
    $conn->query("CREATE TABLE IF NOT EXISTS cocktail_comment_reactions (
        user_id INT NOT NULL, comment_id INT NOT NULL,
        type ENUM('like','dislike') NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, comment_id), INDEX idx_c (comment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $done = true;
}

// 取某配方的互动统计 + 当前用户状态
function cocktail_interactions($conn, $cid, $uid = 0) {
    $cid = (int)$cid; $uid = (int)$uid;
    $like = (int)($conn->query("SELECT COUNT(*) c FROM cocktail_likes WHERE cocktail_id=$cid")->fetch_assoc()['c'] ?? 0);
    $fav  = (int)($conn->query("SELECT COUNT(*) c FROM cocktail_favorites WHERE cocktail_id=$cid")->fetch_assoc()['c'] ?? 0);
    $rr   = $conn->query("SELECT COUNT(*) c, AVG(score) a FROM cocktail_ratings WHERE cocktail_id=$cid")->fetch_assoc();
    $my = ['liked'=>false, 'faved'=>false, 'score'=>0];
    if ($uid) {
        $my['liked'] = (bool)$conn->query("SELECT 1 FROM cocktail_likes WHERE cocktail_id=$cid AND user_id=$uid")->num_rows;
        $my['faved'] = (bool)$conn->query("SELECT 1 FROM cocktail_favorites WHERE cocktail_id=$cid AND user_id=$uid")->num_rows;
        $sr = $conn->query("SELECT score FROM cocktail_ratings WHERE cocktail_id=$cid AND user_id=$uid")->fetch_assoc();
        $my['score'] = (int)($sr['score'] ?? 0);
    }
    return [
        'like_count' => $like,
        'fav_count'  => $fav,
        'rate_count' => (int)($rr['c'] ?? 0),
        'rate_avg'   => round((float)($rr['a'] ?? 0), 1),
        'my'         => $my,
    ];
}

// 取某配方的评论列表（带用户名）
function cocktail_comments_list($conn, $cid, $uid = 0, $limit = 200) {
    $cid = (int)$cid; $uid = (int)$uid; $limit = (int)$limit;
    $out = [];
    $r = $conn->query("SELECT cc.id, cc.content, cc.created_at, u.username, u.avatar, u.id AS uid,
        (SELECT COUNT(*) FROM cocktail_comment_reactions WHERE comment_id=cc.id AND type='like')    AS like_count,
        (SELECT COUNT(*) FROM cocktail_comment_reactions WHERE comment_id=cc.id AND type='dislike') AS dislike_count,
        (SELECT type FROM cocktail_comment_reactions WHERE comment_id=cc.id AND user_id=$uid LIMIT 1) AS my_react
        FROM cocktail_comments cc JOIN users u ON u.id=cc.user_id
        WHERE cc.cocktail_id=$cid ORDER BY cc.id ASC LIMIT $limit");
    if ($r) while ($row = $r->fetch_assoc()) $out[] = $row;
    return $out;
}

// 食材类型 → 中文名 + 颜色（前端按钮分组用）
function cocktail_ingredient_types() {
    return [
        'spirit'  => ['label' => '基酒',   'color' => '#f85149'],
        'liqueur' => ['label' => '利口酒', 'color' => '#a78bfa'],
        'mixer'   => ['label' => '混饮',   'color' => '#58a6ff'],
        'juice'   => ['label' => '果汁',   'color' => '#d29922'],
        'syrup'   => ['label' => '糖浆',   'color' => '#f0883e'],
        'spice'   => ['label' => '调料',   'color' => '#3fb950'],
        'garnish' => ['label' => '装饰',   'color' => '#c084fc'],
        'other'   => ['label' => '其他',   'color' => '#8b949e'],
    ];
}

// 调法枚举
function cocktail_methods() {
    return [
        'shake'  => '摇和（Shake）',
        'stir'   => '搅拌（Stir）',
        'build'  => '兑和（Build）',
        'muddle' => '捣压（Muddle）',
        'blend'  => '搅打（Blend）',
        'layer'  => '分层（Layer）',
    ];
}

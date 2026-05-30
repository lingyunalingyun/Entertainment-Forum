<?php
// 左侧收缩侧边栏：分类(groups) + 链接(links)，管理员后台可编辑
function ensure_sidebar_tables($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS sidebar_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS sidebar_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        label VARCHAR(50) NOT NULL,
        url VARCHAR(255) NOT NULL,
        icon VARCHAR(10) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        INDEX idx_g (group_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 首次为空时灌入默认数据
    $cnt = (int)($conn->query("SELECT COUNT(*) c FROM sidebar_groups")->fetch_assoc()['c'] ?? 0);
    if ($cnt === 0) {
        $seed = [
            ['社区',   [['分区','categories.php','📁'], ['广场','square.php','🌃'], ['动态','pages/moments.php','📰'], ['交友','dating.php','💌']]],
            ['内容',   [['光遇曲库','sheets.php','☁'], ['调酒','bartender.php','🍸']]],
            ['游戏战绩', [['守望战绩','pages/ow_analyzer.php','⌖'], ['方舟分析','pages/ark_bind.php','⌖']]],
        ];
        $gi = 0;
        foreach ($seed as $g) {
            $gn = $conn->real_escape_string($g[0]);
            $conn->query("INSERT INTO sidebar_groups (name, sort_order) VALUES ('$gn', $gi)");
            $gid = $conn->insert_id;
            $li = 0;
            foreach ($g[1] as $l) {
                $lb = $conn->real_escape_string($l[0]);
                $u  = $conn->real_escape_string($l[1]);
                $ic = $conn->real_escape_string($l[2]);
                $conn->query("INSERT INTO sidebar_links (group_id, label, url, icon, sort_order) VALUES ($gid, '$lb', '$u', '$ic', $li)");
                $li++;
            }
            $gi++;
        }
    }
    $done = true;
}

// 读取侧边栏：返回 [ ['id','name','links'=>[ ['id','label','url','icon'], ... ] ], ... ]
function get_sidebar($conn) {
    $out = [];
    $gr = $conn->query("SELECT id, name FROM sidebar_groups ORDER BY sort_order ASC, id ASC");
    if (!$gr) return $out;
    while ($g = $gr->fetch_assoc()) {
        $gid = (int)$g['id'];
        $g['links'] = [];
        $lr = $conn->query("SELECT id, label, url, icon FROM sidebar_links WHERE group_id=$gid ORDER BY sort_order ASC, id ASC");
        if ($lr) while ($l = $lr->fetch_assoc()) $g['links'][] = $l;
        $out[] = $g;
    }
    return $out;
}

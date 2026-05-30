<?php
session_start();
$skip_loader = true;

$my_role = $_SESSION['role'] ?? '';
$is_admin_or_owner = in_array($my_role, ['admin', 'owner']);
$is_owner = $my_role === 'owner';
$can_msg  = in_array($my_role, ['owner', 'reviewer']);
$is_cs    = !empty($_SESSION['is_cs']) || $is_admin_or_owner;

if (!$is_admin_or_owner && !$can_msg && !$is_cs) {
    header("Content-Type: text/html; charset=utf-8");
    die("<h3>🚫 权限不足</h3> <a href='../index.php'>返回主页</a>");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/exp_helper.php';
require_once __DIR__ . '/../includes/site_settings.php';
require_once __DIR__ . '/../includes/cocktail_helper.php';
require_once __DIR__ . '/../includes/sidebar_helper.php';

$conn->set_charset('utf8mb4');
ensure_user_columns($conn);
ensure_decoration_tables($conn);
ensure_cocktail_tables($conn);
ensure_sidebar_tables($conn);
if (function_exists('ensure_song_sheets_table')) ensure_song_sheets_table($conn);

$my_id   = intval($_SESSION['user_id']);
$my_name = htmlspecialchars($_SESSION['username'] ?? '管理员');
$now     = date('Y-m-d H:i:s');

// 默认 tab 基于角色
if ($is_admin_or_owner)      $default_tab = 'posts';
elseif ($can_msg)            $default_tab = 'messages';
else                         $default_tab = 'cs';
$tab = $_GET['tab'] ?? $default_tab;

// tab 访问控制
$admin_only_tabs   = ['posts','reports','bans','categories','activities','carousel','profile_reviews','sheets','comments','decorations','cocktails','sidebar'];
$messages_tabs     = ['messages'];
$cs_tabs           = ['cs'];
$owner_only_tabs   = ['ai'];

if (in_array($tab, $admin_only_tabs) && !$is_admin_or_owner) { header("Location: admin.php?tab=$default_tab"); exit; }
if (in_array($tab, $messages_tabs)   && !$can_msg)            { header("Location: admin.php?tab=$default_tab"); exit; }
if (in_array($tab, $cs_tabs)         && !$is_cs)              { header("Location: admin.php?tab=$default_tab"); exit; }
if (in_array($tab, $owner_only_tabs) && !$is_owner)           { header("Location: admin.php?tab=$default_tab"); exit; }

// ── AI 配置保存（仅 owner） ──
$ai_msg = $_GET['ai_msg'] ?? '';
if ($tab === 'ai' && $is_owner && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ai'])) {
    $ds_url   = trim($_POST['deepseek_base_url'] ?? '');
    $ds_model = trim($_POST['deepseek_model']    ?? '');
    $ds_key_input = $_POST['deepseek_api_key']   ?? '';
    if ($ds_url   !== '') set_setting($conn, 'deepseek_base_url', $ds_url);
    if ($ds_model !== '') set_setting($conn, 'deepseek_model',    $ds_model);
    // 留空表示不修改 key（避免被打码字符串覆盖）
    if ($ds_key_input !== '' && strpos($ds_key_input, '•') === false) {
        set_setting($conn, 'deepseek_api_key', $ds_key_input);
    }
    if (!empty($_POST['clear_key'])) set_setting($conn, 'deepseek_api_key', '');
    header("Location: admin.php?tab=ai&ai_msg=saved"); exit;
}

// ── 守望国服（网易大神 ds163）凭证保存（仅 owner） ──
if ($tab === 'ai' && $is_owner && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_owcn'])) {
    foreach (['ow_ds163_roleid','ow_ds163_uid','ow_ds163_deviceid','ow_ds163_dts','ow_ds163_server','ow_ds163_xsrf','ow_ds163_ntes_sess'] as $k) {
        if (isset($_POST[$k])) set_setting($conn, $k, trim($_POST[$k]));
    }
    // token 敏感：留空表示不修改（避免被打码字符串覆盖）
    $owtok = $_POST['ow_ds163_token'] ?? '';
    if ($owtok !== '' && strpos($owtok, '•') === false) set_setting($conn, 'ow_ds163_token', trim($owtok));
    if (!empty($_POST['ow_clear_token'])) set_setting($conn, 'ow_ds163_token', '');
    header("Location: admin.php?tab=ai&ai_msg=owcn_saved"); exit;
}

// ── 帖子操作 ──
if ($is_admin_or_owner && $tab === 'posts' && isset($_GET['action'], $_GET['id'])) {
    $pid = intval($_GET['id']); $act = $_GET['action'];
    if ($act === 'approve') {
        $conn->query("UPDATE posts SET status='已发布', approved_by=$my_id, approved_at='$now' WHERE id=$pid");
        $pr = $conn->query("SELECT user_id FROM posts WHERE id=$pid");
        if ($pr && $row = $pr->fetch_assoc()) {
            $aid = intval($row['user_id']);
            if ($aid !== $my_id)
                $conn->query("INSERT INTO notifications (user_id,from_user_id,type,post_id,created_at) VALUES ($aid,$my_id,'post_approved',$pid,'$now')");
        }
    } elseif ($act === 'delete') {
        $conn->query("DELETE FROM posts WHERE id=$pid");
    }
    header("Location: admin.php?tab=posts&sub=" . urlencode($_GET['sub'] ?? 'all')); exit;
}

// ── 分区操作 ──
$conn->query("CREATE TABLE IF NOT EXISTS categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL, description VARCHAR(200) DEFAULT '', color VARCHAR(7) DEFAULT '#3fb950', icon VARCHAR(10) DEFAULT '#', cover_image VARCHAR(255) DEFAULT '', sort_order INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$col2 = $conn->query("SHOW COLUMNS FROM posts LIKE 'category_id'");
if ($col2 && $col2->num_rows === 0) $conn->query("ALTER TABLE posts ADD COLUMN category_id INT NULL DEFAULT NULL");
$col3 = $conn->query("SHOW COLUMNS FROM categories LIKE 'cover_image'");
if ($col3 && $col3->num_rows === 0) $conn->query("ALTER TABLE categories ADD COLUMN cover_image VARCHAR(255) DEFAULT '' AFTER icon");
$upload_dir = __DIR__ . '/../uploads/categories/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

function save_cover($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif']) || $file['size'] > 5*1024*1024) return null;
    $dir = __DIR__ . '/../uploads/categories/';
    $fname = 'cat_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . $fname) ? 'uploads/categories/'.$fname : null;
}

$cat_msg = $_GET['cat_msg'] ?? '';
if ($is_admin_or_owner && $tab === 'categories') {
    if (isset($_GET['delete_cat'])) {
        $did = intval($_GET['delete_cat']);
        $row = $conn->query("SELECT cover_image FROM categories WHERE id=$did")->fetch_assoc();
        if (!empty($row['cover_image'])) { $p = __DIR__.'/../'.$row['cover_image']; if(file_exists($p)) unlink($p); }
        $conn->query("UPDATE posts SET category_id=NULL WHERE category_id=$did");
        $conn->query("DELETE FROM categories WHERE id=$did");
        header("Location: admin.php?tab=categories&cat_msg=delete_ok"); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $cname  = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $cdesc  = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $ccolor = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#3fb950';
        $cicon  = $conn->real_escape_string(mb_substr(trim($_POST['icon'] ?? '#'), 0, 4));
        $csort  = intval($_POST['sort_order'] ?? 0);
        $edit_id = intval($_POST['edit_id'] ?? 0);
        if ($cname) {
            if ($edit_id) {
                $cover_sql = '';
                if (!empty($_FILES['cover_image']['name'])) {
                    $nc = save_cover($_FILES['cover_image']);
                    if ($nc) {
                        $old = $conn->query("SELECT cover_image FROM categories WHERE id=$edit_id")->fetch_assoc();
                        if (!empty($old['cover_image'])) { $op=__DIR__.'/../'.$old['cover_image']; if(file_exists($op)) unlink($op); }
                        $cover_sql = ", cover_image='".$conn->real_escape_string($nc)."'";
                    }
                }
                $conn->query("UPDATE categories SET name='$cname',description='$cdesc',color='$ccolor',icon='$cicon',sort_order=$csort$cover_sql WHERE id=$edit_id");
                header("Location: admin.php?tab=categories&cat_msg=edit_ok"); exit;
            } else {
                $cover = save_cover($_FILES['cover_image'] ?? []);
                if (!$cover) { $cat_form_err = '图片上传失败'; }
                else {
                    $sc = $conn->real_escape_string($cover);
                    $conn->query("INSERT INTO categories (name,description,color,icon,sort_order,cover_image) VALUES ('$cname','$cdesc','$ccolor','$cicon',$csort,'$sc')");
                    header("Location: admin.php?tab=categories&cat_msg=create_ok"); exit;
                }
            }
        }
    }
}

// ── 左侧侧边栏管理 ──
if ($is_admin_or_owner && $tab === 'sidebar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sb_act = $_POST['sb_action'] ?? '';
    if ($sb_act === 'add_group') {
        $n = $conn->real_escape_string(mb_substr(trim($_POST['gname'] ?? ''), 0, 50));
        if ($n !== '') {
            $mo = (int)($conn->query("SELECT COALESCE(MAX(sort_order),-1)+1 m FROM sidebar_groups")->fetch_assoc()['m'] ?? 0);
            $conn->query("INSERT INTO sidebar_groups (name, sort_order) VALUES ('$n', $mo)");
        }
    } elseif ($sb_act === 'rename_group') {
        $gid = (int)($_POST['gid'] ?? 0);
        $n = $conn->real_escape_string(mb_substr(trim($_POST['gname'] ?? ''), 0, 50));
        if ($gid && $n !== '') $conn->query("UPDATE sidebar_groups SET name='$n' WHERE id=$gid");
    } elseif ($sb_act === 'del_group') {
        $gid = (int)($_POST['gid'] ?? 0);
        if ($gid) { $conn->query("DELETE FROM sidebar_groups WHERE id=$gid"); $conn->query("DELETE FROM sidebar_links WHERE group_id=$gid"); }
    } elseif ($sb_act === 'add_link') {
        $gid = (int)($_POST['gid'] ?? 0);
        $lb = $conn->real_escape_string(mb_substr(trim($_POST['label'] ?? ''), 0, 50));
        $u  = $conn->real_escape_string(mb_substr(trim($_POST['url'] ?? ''), 0, 255));
        $ic = $conn->real_escape_string(mb_substr(trim($_POST['icon'] ?? ''), 0, 10));
        if ($gid && $lb !== '' && $u !== '') {
            $mo = (int)($conn->query("SELECT COALESCE(MAX(sort_order),-1)+1 m FROM sidebar_links WHERE group_id=$gid")->fetch_assoc()['m'] ?? 0);
            $conn->query("INSERT INTO sidebar_links (group_id, label, url, icon, sort_order) VALUES ($gid, '$lb', '$u', '$ic', $mo)");
        }
    } elseif ($sb_act === 'del_link') {
        $lid = (int)($_POST['lid'] ?? 0);
        if ($lid) $conn->query("DELETE FROM sidebar_links WHERE id=$lid");
    }
    header("Location: admin.php?tab=sidebar&sb_msg=ok"); exit;
}

// ── 站内活动（首页轮播）──
$conn->query("CREATE TABLE IF NOT EXISTS site_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    subtitle VARCHAR(200) DEFAULT '',
    image_path VARCHAR(255) NOT NULL,
    link_url VARCHAR(500) NOT NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$ca_msg = $_GET['ca_msg'] ?? '';
$carousel_items = [];
$ca_edit = null;
$ca_form_err = '';
if ($is_admin_or_owner && $tab === 'carousel') {
    if (isset($_GET['delete_ca'])) {
        $did = intval($_GET['delete_ca']);
        $row = $conn->query("SELECT image_path FROM site_activities WHERE id=$did")->fetch_assoc();
        if ($row && !empty($row['image_path'])) {
            $p = __DIR__.'/../'.$row['image_path']; if (file_exists($p)) @unlink($p);
        }
        $conn->query("DELETE FROM site_activities WHERE id=$did");
        header("Location: admin.php?tab=carousel&ca_msg=delete_ok"); exit;
    }
    if (isset($_GET['toggle_ca'])) {
        $tid = intval($_GET['toggle_ca']);
        $conn->query("UPDATE site_activities SET is_active=1-is_active WHERE id=$tid");
        header("Location: admin.php?tab=carousel&ca_msg=toggle_ok"); exit;
    }
    if (isset($_GET['edit_ca']) && is_numeric($_GET['edit_ca'])) {
        $r = $conn->query("SELECT * FROM site_activities WHERE id=".(int)$_GET['edit_ca']);
        $ca_edit = ($r && $r->num_rows>0) ? $r->fetch_assoc() : null;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ca_title'])) {
        $title    = mb_substr(trim($_POST['ca_title'] ?? ''), 0, 120);
        $subtitle = mb_substr(trim($_POST['ca_subtitle'] ?? ''), 0, 200);
        $link_url = trim($_POST['ca_link'] ?? '');
        $sort     = intval($_POST['ca_sort'] ?? 0);
        $active   = isset($_POST['ca_active']) ? 1 : 0;
        $edit_id  = intval($_POST['ca_edit_id'] ?? 0);

        if ($title === '' || $link_url === '') {
            $ca_form_err = '标题和链接不能为空';
        } else {
            // 处理图片上传
            $img_path = '';
            if (!empty($_FILES['ca_image']['name'])) {
                $f = $_FILES['ca_image'];
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $f['tmp_name']);
                finfo_close($finfo);
                if ($f['error'] === UPLOAD_ERR_OK && in_array($mime, $allowed) && $f['size'] <= 10*1024*1024) {
                    $dir = __DIR__ . '/../uploads/site_activities/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) ?: 'jpg';
                    $fname = 'sa_' . date('Ymd') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($f['tmp_name'], $dir.$fname)) {
                        $img_path = 'uploads/site_activities/'.$fname;
                    }
                } else {
                    $ca_form_err = '图片格式或大小不符（仅支持 JPG/PNG/GIF/WEBP，最大 10MB）';
                }
            }
            if (!$ca_form_err) {
                $st_title = $conn->real_escape_string($title);
                $st_sub   = $conn->real_escape_string($subtitle);
                $st_link  = $conn->real_escape_string($link_url);
                if ($edit_id > 0) {
                    $img_sql = '';
                    if ($img_path !== '') {
                        // 删除旧图
                        $old = $conn->query("SELECT image_path FROM site_activities WHERE id=$edit_id")->fetch_assoc();
                        if ($old && !empty($old['image_path'])) {
                            $op = __DIR__.'/../'.$old['image_path']; if (file_exists($op)) @unlink($op);
                        }
                        $img_sql = ", image_path='".$conn->real_escape_string($img_path)."'";
                    }
                    $conn->query("UPDATE site_activities SET title='$st_title', subtitle='$st_sub', link_url='$st_link', sort_order=$sort, is_active=$active$img_sql WHERE id=$edit_id");
                    header("Location: admin.php?tab=carousel&ca_msg=edit_ok"); exit;
                } else {
                    if ($img_path === '') { $ca_form_err = '新建必须上传图片'; }
                    else {
                        $st_img = $conn->real_escape_string($img_path);
                        $conn->query("INSERT INTO site_activities (title, subtitle, image_path, link_url, sort_order, is_active, created_by) VALUES ('$st_title','$st_sub','$st_img','$st_link',$sort,$active,$my_id)");
                        header("Location: admin.php?tab=carousel&ca_msg=create_ok"); exit;
                    }
                }
            }
        }
    }
    $cr = $conn->query("SELECT * FROM site_activities ORDER BY sort_order ASC, id DESC");
    if ($cr) while ($r = $cr->fetch_assoc()) $carousel_items[] = $r;
}

// ── 调酒：食材 + 鸡尾酒 ──
$ck_msg = $_GET['ck_msg'] ?? '';
$ck_sub = $_GET['sub'] ?? 'cocktails';
if (!in_array($ck_sub, ['ingredients','cocktails'])) $ck_sub = 'cocktails';
$ingredient_types = cocktail_ingredient_types();
$cocktail_methods_map = cocktail_methods();
$ck_edit_cocktail = null;
$all_ingredients = [];
$all_cocktails   = [];
$ck_form_err = '';

if ($is_admin_or_owner && $tab === 'cocktails') {
    // ── 食材操作 ──
    if ($ck_sub === 'ingredients') {
        if (isset($_GET['delete_ing'])) {
            $iid = intval($_GET['delete_ing']);
            $conn->query("DELETE FROM cocktail_ingredients WHERE ingredient_id=$iid");
            $conn->query("DELETE FROM ingredients WHERE id=$iid");
            header("Location: admin.php?tab=cocktails&sub=ingredients&ck_msg=ing_del"); exit;
        }
        if (isset($_GET['toggle_ing'])) {
            $iid = intval($_GET['toggle_ing']);
            $conn->query("UPDATE ingredients SET is_active=1-is_active WHERE id=$iid");
            header("Location: admin.php?tab=cocktails&sub=ingredients&ck_msg=ing_toggle"); exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ing_form'])) {
            $iname  = mb_substr(trim($_POST['ing_name'] ?? ''), 0, 50);
            $itype  = $_POST['ing_type'] ?? 'other';
            if (!isset($ingredient_types[$itype])) $itype = 'other';
            $isort  = intval($_POST['ing_sort'] ?? 0);
            $iactive = isset($_POST['ing_active']) ? 1 : 0;
            $iedit  = intval($_POST['ing_edit_id'] ?? 0);
            if ($iname === '') { $ck_form_err = '食材名称不能为空'; }
            else {
                $sn = $conn->real_escape_string($iname);
                $st = $conn->real_escape_string($itype);
                if ($iedit > 0) {
                    $conn->query("UPDATE ingredients SET name='$sn', type='$st', sort_order=$isort, is_active=$iactive WHERE id=$iedit");
                    header("Location: admin.php?tab=cocktails&sub=ingredients&ck_msg=ing_edit"); exit;
                } else {
                    $ok = @$conn->query("INSERT INTO ingredients (name, type, sort_order, is_active) VALUES ('$sn', '$st', $isort, $iactive)");
                    if (!$ok) { $ck_form_err = '食材名称重复或写入失败'; }
                    else { header("Location: admin.php?tab=cocktails&sub=ingredients&ck_msg=ing_new"); exit; }
                }
            }
        }
    }

    // ── 鸡尾酒操作 ──
    if ($ck_sub === 'cocktails') {
        if (isset($_GET['delete_ck'])) {
            $cid = intval($_GET['delete_ck']);
            $row = $conn->query("SELECT image FROM cocktails WHERE id=$cid")->fetch_assoc();
            if ($row && !empty($row['image'])) {
                $p = __DIR__ . '/../' . $row['image']; if (file_exists($p)) @unlink($p);
            }
            $conn->query("DELETE FROM cocktail_ingredients WHERE cocktail_id=$cid");
            $conn->query("DELETE FROM cocktail_steps WHERE cocktail_id=$cid");
            $conn->query("DELETE FROM cocktails WHERE id=$cid");
            header("Location: admin.php?tab=cocktails&sub=cocktails&ck_msg=ck_del"); exit;
        }
        if (isset($_GET['toggle_ck'])) {
            $cid = intval($_GET['toggle_ck']);
            $conn->query("UPDATE cocktails SET is_active=1-is_active WHERE id=$cid");
            header("Location: admin.php?tab=cocktails&sub=cocktails&ck_msg=ck_toggle"); exit;
        }
        if (isset($_GET['edit_ck']) && is_numeric($_GET['edit_ck'])) {
            $cid = (int)$_GET['edit_ck'];
            $r = $conn->query("SELECT * FROM cocktails WHERE id=$cid");
            $ck_edit_cocktail = ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
            if ($ck_edit_cocktail) {
                $ir = $conn->query("SELECT ingredient_id, amount FROM cocktail_ingredients WHERE cocktail_id=$cid ORDER BY sort_order ASC, ingredient_id ASC");
                $ck_edit_cocktail['ings'] = [];
                if ($ir) while ($row = $ir->fetch_assoc()) $ck_edit_cocktail['ings'][] = $row;
                $sr = $conn->query("SELECT content FROM cocktail_steps WHERE cocktail_id=$cid ORDER BY step_order ASC");
                $ck_edit_cocktail['steps'] = [];
                if ($sr) while ($row = $sr->fetch_assoc()) $ck_edit_cocktail['steps'][] = $row['content'];
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ck_form'])) {
            $cname   = mb_substr(trim($_POST['ck_name'] ?? ''), 0, 80);
            $cen     = mb_substr(trim($_POST['ck_name_en'] ?? ''), 0, 80);
            $g_type  = trim($_POST['ck_glass_type'] ?? '');
            $g_ml    = preg_replace('/[^\d.]/', '', $_POST['ck_glass_ml'] ?? '');
            $cglass  = mb_substr(trim($g_type . ($g_ml !== '' ? '（' . $g_ml . 'ml）' : '')), 0, 40);
            $cmethod = $_POST['ck_method'] ?? 'shake';
            if (!isset($cocktail_methods_map[$cmethod])) $cmethod = 'shake';
            $cgarn   = mb_substr(trim($_POST['ck_garnish'] ?? ''), 0, 120);
            $a_preset = trim($_POST['ck_abv_preset'] ?? '');
            $a_num    = preg_replace('/[^\d.]/', '', $_POST['ck_abv_num'] ?? '');
            $cabv    = mb_substr(trim($a_preset . ($a_num !== '' ? ' ' . $a_num . '%' : '')), 0, 20);
            $csort   = intval($_POST['ck_sort'] ?? 0);
            $cactive = isset($_POST['ck_active']) ? 1 : 0;
            $cedit   = intval($_POST['ck_edit_id'] ?? 0);
            $ing_ids = $_POST['ck_ing_id']     ?? [];
            $ing_num  = $_POST['ck_ing_num']  ?? [];
            $ing_unit = $_POST['ck_ing_unit'] ?? [];
            $steps_in = $_POST['ck_step']      ?? [];

            // 步骤：过滤空行
            $steps_clean = [];
            if (is_array($steps_in)) {
                foreach ($steps_in as $s) {
                    $s = mb_substr(trim((string)$s), 0, 500);
                    if ($s !== '') $steps_clean[] = $s;
                }
            }

            // 重名检查（排除当前编辑项）
            if (!$ck_form_err && $cname !== '') {
                $nm_chk = $conn->real_escape_string($cname);
                $dup = $conn->query("SELECT id FROM cocktails WHERE name='$nm_chk' AND id!=$cedit LIMIT 1");
                if ($dup && $dup->num_rows > 0) $ck_form_err = '已有同名配方「' . $cname . '」，换个名字吧';
            }

            if ($cname === '')         $ck_form_err = '鸡尾酒名称不能为空';
            if (!$steps_clean)         $ck_form_err = '至少添加一个调制步骤';

            // 图片处理
            $img_path = '';
            if (!$ck_form_err && !empty($_FILES['ck_image']['name'])) {
                $f = $_FILES['ck_image'];
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $f['tmp_name']); finfo_close($finfo);
                if ($f['error'] === UPLOAD_ERR_OK && in_array($mime, $allowed) && $f['size'] <= 5*1024*1024) {
                    $dir = __DIR__ . '/../uploads/cocktails/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) ?: 'jpg';
                    $fname = 'ck_' . date('Ymd') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($f['tmp_name'], $dir.$fname)) {
                        $img_path = 'uploads/cocktails/'.$fname;
                    }
                } else {
                    $ck_form_err = '图片格式不支持（JPG/PNG/GIF/WEBP）或超过 5MB';
                }
            }

            if (!$ck_form_err) {
                $sn  = $conn->real_escape_string($cname);
                $sen = $conn->real_escape_string($cen);
                $sg  = $conn->real_escape_string($cglass);
                $sm  = $conn->real_escape_string($cmethod);
                $sga = $conn->real_escape_string($cgarn);
                $sa  = $conn->real_escape_string($cabv);
                $cid_use = 0;
                if ($cedit > 0) {
                    $img_sql = '';
                    if ($img_path !== '') {
                        $old = $conn->query("SELECT image FROM cocktails WHERE id=$cedit")->fetch_assoc();
                        if ($old && !empty($old['image'])) {
                            $op = __DIR__.'/../'.$old['image']; if (file_exists($op)) @unlink($op);
                        }
                        $img_sql = ", image='".$conn->real_escape_string($img_path)."'";
                    }
                    $conn->query("UPDATE cocktails SET name='$sn', name_en='$sen', glass='$sg', method='$sm', garnish='$sga', abv_hint='$sa', sort_order=$csort, is_active=$cactive$img_sql WHERE id=$cedit");
                    $cid_use = $cedit;
                } else {
                    $si_img = $conn->real_escape_string($img_path);
                    $conn->query("INSERT INTO cocktails (name, name_en, glass, method, instructions, garnish, image, abv_hint, sort_order, is_active) VALUES ('$sn','$sen','$sg','$sm','','$sga','$si_img','$sa',$csort,$cactive)");
                    $cid_use = (int)$conn->insert_id;
                }
                // 重建材料关联
                if ($cid_use > 0) {
                    $conn->query("DELETE FROM cocktail_ingredients WHERE cocktail_id=$cid_use");
                    if (is_array($ing_ids)) {
                        $seen = [];
                        foreach ($ing_ids as $idx => $iid) {
                            $iid = (int)$iid;
                            if ($iid <= 0 || isset($seen[$iid])) continue;
                            $seen[$iid] = 1;
                            $amt = mb_substr(trim((string)(($ing_num[$idx] ?? '') . ($ing_unit[$idx] ?? ''))), 0, 40);
                            $sa2 = $conn->real_escape_string($amt);
                            $conn->query("INSERT INTO cocktail_ingredients (cocktail_id, ingredient_id, amount, sort_order) VALUES ($cid_use, $iid, '$sa2', $idx)");
                        }
                    }
                    // 重建步骤
                    $conn->query("DELETE FROM cocktail_steps WHERE cocktail_id=$cid_use");
                    foreach ($steps_clean as $i => $s) {
                        $ss = $conn->real_escape_string($s);
                        $conn->query("INSERT INTO cocktail_steps (cocktail_id, step_order, content) VALUES ($cid_use, $i, '$ss')");
                    }
                }
                header("Location: admin.php?tab=cocktails&sub=cocktails&ck_msg=" . ($cedit ? 'ck_edit' : 'ck_new')); exit;
            }
        }
    }

    // ── 列表加载 ──
    $ir = $conn->query("SELECT * FROM ingredients ORDER BY type ASC, CONVERT(name USING gbk) ASC");
    if ($ir) while ($row = $ir->fetch_assoc()) $all_ingredients[] = $row;

    $cr2 = $conn->query("SELECT c.*, COUNT(ci.ingredient_id) AS ing_count FROM cocktails c LEFT JOIN cocktail_ingredients ci ON ci.cocktail_id=c.id GROUP BY c.id ORDER BY CONVERT(c.name USING gbk) ASC");
    if ($cr2) while ($row = $cr2->fetch_assoc()) $all_cocktails[] = $row;
}

// ── 装饰系统管理 ──
$dc_msg = $_GET['dc_msg'] ?? '';
$dc_filter = $_GET['dc_filter'] ?? 'all';
$decorations = [];
$dc_edit = null;
$dc_form_err = '';
$dc_grant_msg = '';
if ($is_admin_or_owner && $tab === 'decorations') {
    // 删除装饰
    if (isset($_GET['delete_dc'])) {
        $did = intval($_GET['delete_dc']);
        $row = $conn->query("SELECT image_path FROM decorations WHERE id=$did")->fetch_assoc();
        if ($row && !empty($row['image_path'])) {
            $p = __DIR__.'/../'.$row['image_path']; if (file_exists($p)) @unlink($p);
        }
        $conn->query("UPDATE users SET equipped_frame_id=NULL WHERE equipped_frame_id=$did");
        $conn->query("UPDATE users SET equipped_plate_skin_id=NULL WHERE equipped_plate_skin_id=$did");
        $conn->query("UPDATE users SET equipped_plate_suffix_id=NULL WHERE equipped_plate_suffix_id=$did");
        $conn->query("DELETE FROM user_decorations WHERE decoration_id=$did");
        $conn->query("DELETE FROM decorations WHERE id=$did");
        header("Location: admin.php?tab=decorations&dc_msg=delete_ok"); exit;
    }
    // 启用/停用
    if (isset($_GET['toggle_dc'])) {
        $tid = intval($_GET['toggle_dc']);
        $conn->query("UPDATE decorations SET is_active=1-is_active WHERE id=$tid");
        header("Location: admin.php?tab=decorations&dc_msg=toggle_ok"); exit;
    }
    // 编辑加载
    if (isset($_GET['edit_dc']) && is_numeric($_GET['edit_dc'])) {
        $r = $conn->query("SELECT * FROM decorations WHERE id=".(int)$_GET['edit_dc']);
        $dc_edit = ($r && $r->num_rows>0) ? $r->fetch_assoc() : null;
    }
    // 收回装饰（管理员从用户处收回）
    if (isset($_GET['revoke_dc'], $_GET['revoke_uid'])) {
        $rdc = intval($_GET['revoke_dc']); $ruid = intval($_GET['revoke_uid']);
        $conn->query("DELETE FROM user_decorations WHERE user_id=$ruid AND decoration_id=$rdc");
        $conn->query("UPDATE users SET equipped_frame_id=NULL        WHERE id=$ruid AND equipped_frame_id=$rdc");
        $conn->query("UPDATE users SET equipped_plate_skin_id=NULL   WHERE id=$ruid AND equipped_plate_skin_id=$rdc");
        $conn->query("UPDATE users SET equipped_plate_suffix_id=NULL WHERE id=$ruid AND equipped_plate_suffix_id=$rdc");
        header("Location: admin.php?tab=decorations&dc_msg=revoke_ok&dc_view=".$rdc); exit;
    }
    // 表单提交：新增/编辑装饰品
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dc_form'])) {
        $type = $_POST['dc_type'] ?? '';
        if (!in_array($type, ['frame','plate_skin','plate_suffix'])) $dc_form_err = '类型无效';
        $name = mb_substr(trim($_POST['dc_name'] ?? ''), 0, 80);
        $desc = mb_substr(trim($_POST['dc_desc'] ?? ''), 0, 200);
        $bg   = mb_substr(trim($_POST['dc_bg'] ?? ''), 0, 40);
        $tc   = mb_substr(trim($_POST['dc_color'] ?? ''), 0, 40);
        $bd   = mb_substr(trim($_POST['dc_border'] ?? ''), 0, 40);
        $sfx  = mb_substr(trim($_POST['dc_suffix'] ?? ''), 0, 40);
        $sort = intval($_POST['dc_sort'] ?? 0);
        $active = isset($_POST['dc_active']) ? 1 : 0;
        $edit_id = intval($_POST['dc_edit_id'] ?? 0);

        if ($name === '') $dc_form_err = '名称不能为空';
        if ($type === 'plate_suffix' && $sfx === '') $dc_form_err = '后缀名牌必须填后缀文本';
        if ($type === 'frame' && empty($_FILES['dc_image']['name']) && !$edit_id) $dc_form_err = '头像框必须上传图片';

        $img_path = '';
        if (!$dc_form_err && !empty($_FILES['dc_image']['name'])) {
            $f = $_FILES['dc_image'];
            $allowed = ['image/png','image/jpeg','image/gif','image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $f['tmp_name']); finfo_close($finfo);
            if ($f['error']===UPLOAD_ERR_OK && in_array($mime, $allowed) && $f['size'] <= 5*1024*1024) {
                $dir = __DIR__ . '/../uploads/decorations/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) ?: 'png';
                $fname = $type . '_' . date('Ymd') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], $dir.$fname)) {
                    $img_path = 'uploads/decorations/'.$fname;
                }
            } else {
                $dc_form_err = '图片格式不支持或超过 5MB';
            }
        }

        if (!$dc_form_err) {
            $sn = $conn->real_escape_string($name);
            $sd = $conn->real_escape_string($desc);
            $sbg = $conn->real_escape_string($bg);
            $stc = $conn->real_escape_string($tc);
            $sbd = $conn->real_escape_string($bd);
            $ssx = $conn->real_escape_string($sfx);
            if ($edit_id > 0) {
                $img_sql = '';
                if ($img_path !== '') {
                    $old = $conn->query("SELECT image_path FROM decorations WHERE id=$edit_id")->fetch_assoc();
                    if ($old && !empty($old['image_path'])) {
                        $op = __DIR__.'/../'.$old['image_path']; if (file_exists($op)) @unlink($op);
                    }
                    $img_sql = ", image_path='".$conn->real_escape_string($img_path)."'";
                }
                $conn->query("UPDATE decorations SET name='$sn', description='$sd', bg_color='$sbg', text_color='$stc', border_color='$sbd', suffix_text='$ssx', sort_order=$sort, is_active=$active$img_sql WHERE id=$edit_id");
                header("Location: admin.php?tab=decorations&dc_msg=edit_ok"); exit;
            } else {
                $st = $conn->real_escape_string($type);
                $simg = $conn->real_escape_string($img_path);
                $conn->query("INSERT INTO decorations (type, name, description, image_path, bg_color, text_color, border_color, suffix_text, sort_order, is_active) VALUES ('$st','$sn','$sd','$simg','$sbg','$stc','$sbd','$ssx',$sort,$active)");
                header("Location: admin.php?tab=decorations&dc_msg=create_ok"); exit;
            }
        }
    }
    // 发放装饰
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_form'])) {
        $g_dc = intval($_POST['grant_dc_id'] ?? 0);
        $g_mid = strtoupper(trim($_POST['grant_mid'] ?? ''));
        if ($g_dc && $g_mid !== '') {
            $sm = $conn->real_escape_string($g_mid);
            $ur = $conn->query("SELECT id, username FROM users WHERE mid='$sm'");
            $u = $ur ? $ur->fetch_assoc() : null;
            if (!$u) {
                $dc_grant_msg = '错误：MID 不存在';
            } else {
                $conn->query("INSERT IGNORE INTO user_decorations (user_id, decoration_id) VALUES ({$u['id']}, $g_dc)");
                $dc_grant_msg = '✓ 已发放给 ' . htmlspecialchars($u['username']);
            }
        }
    }

    // 加载列表
    $where = '1=1';
    if (in_array($dc_filter, ['frame','plate_skin','plate_suffix'])) $where = "type='".$conn->real_escape_string($dc_filter)."'";
    $dr = $conn->query("SELECT d.*, (SELECT COUNT(*) FROM user_decorations WHERE decoration_id=d.id) AS owners FROM decorations d WHERE $where ORDER BY type, sort_order ASC, id DESC");
    if ($dr) while ($row = $dr->fetch_assoc()) $decorations[] = $row;

    // 装饰拥有者列表（点击查看）
    $dc_view = intval($_GET['dc_view'] ?? 0);
    $dc_view_owners = [];
    $dc_view_item = null;
    if ($dc_view > 0) {
        $vr = $conn->query("SELECT * FROM decorations WHERE id=$dc_view");
        $dc_view_item = $vr ? $vr->fetch_assoc() : null;
        if ($dc_view_item) {
            $or = $conn->query("SELECT u.id, u.username, u.mid, ud.obtained_at FROM user_decorations ud JOIN users u ON u.id=ud.user_id WHERE ud.decoration_id=$dc_view ORDER BY ud.id DESC");
            if ($or) while ($r = $or->fetch_assoc()) $dc_view_owners[] = $r;
        }
    }
}

// ── 资料审核 ──
$pr_msg = $_GET['pr_msg'] ?? '';
$pr_filter = 'pending'; $pr_reqs = null; $pr_counts = ['pending'=>0,'approved'=>0,'rejected'=>0];
if ($is_admin_or_owner && $tab === 'profile_reviews') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['req_id'])) {
        $req_id = intval($_POST['req_id']);
        $action = $_POST['action'] ?? '';
        $note   = $conn->real_escape_string(trim($_POST['note'] ?? ''));
        $rq = $conn->query("SELECT * FROM profile_edit_requests WHERE id=$req_id AND status='pending'");
        if ($rq && $rq->num_rows > 0) {
            $req = $rq->fetch_assoc();
            $uid = (int)$req['user_id'];
            if ($action === 'approve') {
                $sets = [];
                if (!empty($req['new_username']))   $sets[] = "username='".$conn->real_escape_string($req['new_username'])."'";
                if (!empty($req['new_gender']))     $sets[] = "gender='".$conn->real_escape_string($req['new_gender'])."'";
                if ($req['new_phone'] !== null)     $sets[] = "phone='".$conn->real_escape_string($req['new_phone'])."'";
                if (!empty($req['new_birthday']))   $sets[] = "birthday='".$conn->real_escape_string($req['new_birthday'])."'";
                if ($req['new_signature'] !== null) $sets[] = "signature='".$conn->real_escape_string($req['new_signature'])."'";
                if (!empty($req['new_avatar'])) {
                    $ur = $conn->query("SELECT mid FROM users WHERE id=$uid");
                    $urow = $ur ? $ur->fetch_assoc() : null;
                    $ext = pathinfo($req['new_avatar'], PATHINFO_EXTENSION);
                    $final = ($urow['mid'] ?? ('u'.$uid)) . '.' . $ext;
                    $av_dir = __DIR__ . '/../uploads/avatars/';
                    $old = $av_dir . $req['new_avatar'];
                    $new = $av_dir . $final;
                    if (file_exists($old)) {
                        if (file_exists($new) && $new !== $old) @unlink($new);
                        rename($old, $new);
                    }
                    $sets[] = "avatar='".$conn->real_escape_string($final)."'";
                }
                if ($sets) $conn->query("UPDATE users SET ".implode(',', $sets)." WHERE id=$uid");
                $conn->query("UPDATE profile_edit_requests SET status='approved', admin_id=$my_id, reviewed_at=NOW() WHERE id=$req_id");
                header("Location: admin.php?tab=profile_reviews&pr_msg=approved"); exit;
            } elseif ($action === 'reject') {
                if (!empty($req['new_avatar'])) {
                    $f = __DIR__ . '/../uploads/avatars/' . $req['new_avatar'];
                    if (file_exists($f)) @unlink($f);
                }
                $conn->query("UPDATE profile_edit_requests SET status='rejected', admin_id=$my_id, admin_note='$note', reviewed_at=NOW() WHERE id=$req_id");
                header("Location: admin.php?tab=profile_reviews&pr_msg=rejected"); exit;
            }
        }
    }
    $pr_filter = $_GET['pr_filter'] ?? 'pending';
    if (!in_array($pr_filter, ['pending','approved','rejected','all'])) $pr_filter = 'pending';
    $pr_where = $pr_filter === 'all' ? '' : "WHERE r.status='$pr_filter'";
    $pr_reqs = $conn->query("SELECT r.*, u.username AS cur_username, u.gender AS cur_gender, u.phone AS cur_phone, u.birthday AS cur_birthday, u.signature AS cur_signature, u.avatar AS cur_avatar FROM profile_edit_requests r JOIN users u ON u.id=r.user_id $pr_where ORDER BY r.created_at DESC LIMIT 200");
    foreach (['pending','approved','rejected'] as $s) {
        $cr = $conn->query("SELECT COUNT(*) c FROM profile_edit_requests WHERE status='$s'");
        $pr_counts[$s] = $cr ? (int)$cr->fetch_assoc()['c'] : 0;
    }
}

// ── 曲库管理 ──
$sh_filter = 'all'; $sheets = []; $sh_msg = $_GET['sh_msg'] ?? '';
if ($is_admin_or_owner && $tab === 'sheets') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sheet_action'])) {
        $sa = $_POST['sheet_action']; $sid = intval($_POST['sheet_id'] ?? 0);
        if ($sid > 0) {
            if ($sa === 'recommend')   $conn->query("UPDATE song_sheets SET is_recommended=1-is_recommended WHERE id=$sid");
            elseif ($sa === 'reject')  $conn->query("UPDATE song_sheets SET status='rejected' WHERE id=$sid");
            elseif ($sa === 'restore') $conn->query("UPDATE song_sheets SET status='published' WHERE id=$sid");
            elseif ($sa === 'delete') {
                $r = $conn->query("SELECT file_path FROM song_sheets WHERE id=$sid");
                if ($r && $row = $r->fetch_assoc()) {
                    $abs = __DIR__ . '/../' . $row['file_path'];
                    if (is_file($abs)) @unlink($abs);
                }
                $conn->query("DELETE FROM sheet_likes WHERE sheet_id=$sid");
                $conn->query("DELETE FROM song_sheets WHERE id=$sid");
            }
        }
        header("Location: admin.php?tab=sheets&sh_filter=" . urlencode($_GET['sh_filter'] ?? 'all')); exit;
    }
    $sh_filter = $_GET['sh_filter'] ?? 'all';
    $sh_where = "1=1";
    if ($sh_filter === 'published')        $sh_where = "s.status='published'";
    elseif ($sh_filter === 'rejected')     $sh_where = "s.status='rejected'";
    elseif ($sh_filter === 'recommended')  $sh_where = "s.is_recommended=1";
    $shr = $conn->query("SELECT s.*, u.username FROM song_sheets s JOIN users u ON u.id=s.uploader_id WHERE $sh_where ORDER BY s.id DESC LIMIT 200");
    if ($shr) while ($row = $shr->fetch_assoc()) $sheets[] = $row;
}

// ── 客服后台 ──
$cs_active_tid = 0; $cs_active_ticket = null; $cs_active_messages = []; $cs_last_msg_id = 0;
$cs_pending = []; $cs_my_active = []; $cs_agents = [];
$cs_issue_types = ['account'=>'账号问题','content'=>'内容投诉','technical'=>'技术故障','punishment'=>'处罚申诉','suggestion'=>'功能建议','other'=>'其他问题'];
$cs_type_colors = ['account'=>'#58a6ff','content'=>'#f85149','technical'=>'#d29922','punishment'=>'#a78bfa','suggestion'=>'#3fb950','other'=>'#8b949e'];
if ($is_cs && $tab === 'cs') {
    $conn->query("CREATE TABLE IF NOT EXISTS cs_tickets (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(80) NOT NULL, description TEXT, status ENUM('pending','active','resolved') DEFAULT 'pending', cs_id INT DEFAULT NULL, last_cs_reply_at DATETIME DEFAULT NULL, next_comp_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, resolved_at DATETIME DEFAULT NULL)");
    $conn->query("CREATE TABLE IF NOT EXISTS cs_messages (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, sender_id INT NOT NULL, is_cs TINYINT(1) DEFAULT 0, content TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $cs_active_tid = isset($_GET['tid']) ? (int)$_GET['tid'] : 0;
    if ($cs_active_tid) {
        $tr = $conn->query("SELECT * FROM cs_tickets WHERE id=$cs_active_tid AND cs_id=$my_id AND status='active'");
        if ($tr) $cs_active_ticket = $tr->fetch_assoc();
        if ($cs_active_ticket) {
            $mr = $conn->query("SELECT * FROM cs_messages WHERE ticket_id=$cs_active_tid ORDER BY id ASC");
            while ($m = $mr->fetch_assoc()) { $cs_active_messages[] = $m; $cs_last_msg_id = max($cs_last_msg_id, (int)$m['id']); }
        }
    }
    $pr2 = $conn->query("SELECT id, type, description, created_at FROM cs_tickets WHERE status='pending' ORDER BY id ASC");
    while ($row = $pr2->fetch_assoc()) $cs_pending[] = $row;
    $ar2 = $conn->query("SELECT id, type, description, created_at FROM cs_tickets WHERE cs_id=$my_id AND status='active' ORDER BY id ASC");
    while ($row = $ar2->fetch_assoc()) $cs_my_active[] = $row;
    if ($is_owner) {
        $agr = $conn->query("SELECT id, username, mid, role FROM users WHERE is_cs=1 ORDER BY id ASC");
        while ($row = $agr->fetch_assoc()) $cs_agents[] = $row;
    }
}

// ── 评论删除 ──
if ($is_admin_or_owner && $tab === 'comments' && isset($_GET['delete_comment'])) {
    $cid = intval($_GET['delete_comment']);
    $conn->query("DELETE FROM comments WHERE id=$cid OR parent_id=$cid");
    $back_mid = urlencode($_GET['mid'] ?? '');
    header("Location: admin.php?tab=comments&mid=$back_mid&cmt_msg=deleted"); exit;
}

// ── 活动页：建表 + 数据加载 ──
$conn->query("CREATE TABLE IF NOT EXISTS activity_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    slug VARCHAR(80) UNIQUE NOT NULL,
    content_json LONGTEXT,
    bg_image VARCHAR(255) DEFAULT '',
    canvas_height INT DEFAULT 800,
    comments_enabled TINYINT(1) DEFAULT 1,
    status ENUM('draft','published') DEFAULT 'draft',
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS activity_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_page_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page (activity_page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$ap_msg = $_GET['ap_msg'] ?? '';
$activity_pages = [];
if ($is_admin_or_owner && $tab === 'activities') {
    if (isset($_GET['delete_page'])) {
        $did = intval($_GET['delete_page']);
        $row = $conn->query("SELECT bg_image,content_json FROM activity_pages WHERE id=$did")->fetch_assoc();
        if ($row) {
            if (!empty($row['bg_image'])) { $p = __DIR__.'/../'.$row['bg_image']; if(file_exists($p)) unlink($p); }
            $arr = json_decode($row['content_json'] ?? '{}', true);
            if (!empty($arr['components'])) {
                foreach ($arr['components'] as $c) {
                    if (($c['type'] ?? '') === 'image' && !empty($c['src']) && strpos($c['src'], 'uploads/activities/') === 0) {
                        $p = __DIR__.'/../'.$c['src']; if(file_exists($p)) unlink($p);
                    }
                }
            }
            $conn->query("DELETE FROM activity_comments WHERE activity_page_id=$did");
            $conn->query("DELETE FROM activity_pages WHERE id=$did");
        }
        header("Location: admin.php?tab=activities&ap_msg=delete_ok"); exit;
    }
    if (isset($_GET['toggle_status'])) {
        $tid = intval($_GET['toggle_status']);
        $conn->query("UPDATE activity_pages SET status=IF(status='published','draft','published') WHERE id=$tid");
        header("Location: admin.php?tab=activities&ap_msg=status_ok"); exit;
    }
    $apr = $conn->query("SELECT a.*, u.username AS creator_name, (SELECT COUNT(*) FROM activity_comments WHERE activity_page_id=a.id) AS cmt_count FROM activity_pages a LEFT JOIN users u ON u.id=a.created_by ORDER BY a.id DESC");
    if ($apr) while ($r = $apr->fetch_assoc()) $activity_pages[] = $r;
}

// ── 加载帖子审核数据 ──
if ($tab === 'posts') {
    $r = $conn->query("SHOW COLUMNS FROM posts LIKE 'approved_by'");
    if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE posts ADD COLUMN approved_by INT DEFAULT NULL");
    $r = $conn->query("SHOW COLUMNS FROM posts LIKE 'approved_at'");
    if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE posts ADD COLUMN approved_at DATETIME DEFAULT NULL");
    $total   = (int)$conn->query("SELECT COUNT(*) c FROM posts")->fetch_assoc()['c'];
    $pending = (int)$conn->query("SELECT COUNT(*) c FROM posts WHERE status='待审核'")->fetch_assoc()['c'];
    $pub     = (int)$conn->query("SELECT COUNT(*) c FROM posts WHERE status='已发布'")->fetch_assoc()['c'];
    $draft   = (int)$conn->query("SELECT COUNT(*) c FROM posts WHERE status='草稿'")->fetch_assoc()['c'];
    $sub = $_GET['sub'] ?? 'all';
    if ($sub === 'pending')       $where = "WHERE p.status='待审核'";
    elseif ($sub === 'published') $where = "WHERE p.status='已发布'";
    elseif ($sub === 'draft')     $where = "WHERE p.status='草稿'";
    else                          $where = '';
    $result = $conn->query("SELECT p.id,p.title,p.content,p.status,p.created_at,p.approved_at,u.username AS author_name,u.userid AS author_uid,a.username AS approver_name FROM posts p LEFT JOIN users u ON p.user_id=u.id LEFT JOIN users a ON p.approved_by=a.id $where ORDER BY FIELD(p.status,'待审核','草稿','已发布'),p.id DESC");
    $posts = [];
    if ($result) while ($r = $result->fetch_assoc()) $posts[] = $r;
}

// ── 加载举报数据 ──
if ($tab === 'reports') {
    $rfilter = $_GET['rf'] ?? 'pending'; $rtype = $_GET['rt'] ?? 'all';
    $rw = []; if ($rfilter!=='all') $rw[]="r.status='$rfilter'"; if($rtype!=='all') $rw[]="r.type='$rtype'";
    $rw_sql = $rw ? 'WHERE '.implode(' AND ',$rw) : '';
    $rres = $conn->query("SELECT r.*,u.username AS reporter_name,h.username AS handler_name FROM reports r LEFT JOIN users u ON u.id=r.reporter_id LEFT JOIN users h ON h.id=r.handler_id $rw_sql ORDER BY r.id DESC LIMIT 200");
    $reports = []; if ($rres) while ($row=$rres->fetch_assoc()) $reports[]=$row;
    $rcnt = [];
    foreach (['pending','handled','dismissed'] as $s) { $c=$conn->query("SELECT COUNT(*) c FROM reports WHERE status='$s'"); $rcnt[$s]=$c?(int)$c->fetch_assoc()['c']:0; }
    $rcnt['all'] = array_sum($rcnt);
}

// ── 加载封禁数据 ──
if ($tab === 'bans') {
    $conn->query("UPDATE users SET is_banned=0,ban_reason=NULL,ban_until=NULL,banned_by=NULL WHERE is_banned=1 AND ban_until IS NOT NULL AND ban_until<='$now'");
    $bfilter = $_GET['bf'] ?? 'all';
    $bwhere = "WHERE u.is_banned=1";
    if ($bfilter==='timed') $bwhere.=" AND u.ban_until IS NOT NULL";
    if ($bfilter==='perm')  $bwhere.=" AND u.ban_until IS NULL";
    $bres = $conn->query("SELECT u.id,u.username,u.mid,u.avatar,u.role,u.ban_reason,u.ban_until,b.username AS banned_by_name FROM users u LEFT JOIN users b ON b.id=u.banned_by $bwhere ORDER BY u.id DESC");
    $banned = []; if ($bres) while ($r=$bres->fetch_assoc()) $banned[]=$r;
}

// ── 加载分区数据 ──
if ($tab === 'categories') {
    $edit_cat = null;
    if (isset($_GET['edit_cat']) && is_numeric($_GET['edit_cat'])) {
        $r = $conn->query("SELECT * FROM categories WHERE id=".(int)$_GET['edit_cat']);
        $edit_cat = ($r && $r->num_rows>0) ? $r->fetch_assoc() : null;
    }
    $cats = [];
    $cr = $conn->query("SELECT c.*,COUNT(p.id) as post_count FROM categories c LEFT JOIN posts p ON p.category_id=c.id AND p.status='已发布' GROUP BY c.id ORDER BY c.sort_order ASC,c.id ASC");
    if ($cr) while ($c=$cr->fetch_assoc()) $cats[]=$c;
}

// ── 加载评论查询数据 ──
$cmt_user = null; $cmt_list = []; $cmt_mid = ''; $cmt_msg = $_GET['cmt_msg'] ?? '';
if ($tab === 'comments') {
    $cmt_mid = strtoupper(trim($_GET['mid'] ?? ''));
    if ($cmt_mid !== '') {
        $sm = $conn->real_escape_string($cmt_mid);
        $ur = $conn->query("SELECT id,username,avatar,role FROM users WHERE mid='$sm'");
        $cmt_user = $ur ? $ur->fetch_assoc() : null;
        if ($cmt_user) {
            $uid = intval($cmt_user['id']);
            $cr = $conn->query("SELECT c.id,c.content,c.created_at,c.likes,c.parent_id,p.id AS post_id,COALESCE(p.title,'[动态]') AS post_title FROM comments c LEFT JOIN posts p ON c.post_id=p.id WHERE c.user_id=$uid ORDER BY c.created_at DESC LIMIT 200");
            if ($cr) while ($r=$cr->fetch_assoc()) $cmt_list[]=$r;
        }
    }
}

// ── 加载私信查询数据 ──
$msg_a = $msg_b = null; $msg_list = []; $msg_errors = []; $msg_total = 0; $msg_pages = 1;
if ($tab === 'messages') {
    $mid_a = strtoupper(trim($_GET['mid_a'] ?? ''));
    $mid_b = strtoupper(trim($_GET['mid_b'] ?? ''));
    $mpage = max(1, intval($_GET['mpage'] ?? 1)); $per = 50;
    if ($mid_a !== '' || $mid_b !== '') {
        if ($mid_a==='') $msg_errors[]='请填写用户 A 的 MID';
        if ($mid_b==='') $msg_errors[]='请填写用户 B 的 MID';
        if (empty($msg_errors)) {
            $sa=$conn->real_escape_string($mid_a); $sb=$conn->real_escape_string($mid_b);
            $ra=$conn->query("SELECT id,username,avatar,role FROM users WHERE mid='$sa'");
            $rb=$conn->query("SELECT id,username,avatar,role FROM users WHERE mid='$sb'");
            $msg_a=$ra?$ra->fetch_assoc():null; $msg_b=$rb?$rb->fetch_assoc():null;
            if(!$msg_a) $msg_errors[]="MID「{$mid_a}」不存在";
            if(!$msg_b) $msg_errors[]="MID「{$mid_b}」不存在";
            if (empty($msg_errors)) {
                if ($msg_a['id']===$msg_b['id']) { $msg_errors[]='请输入两个不同用户的 MID'; }
                else {
                    $ua=(int)$msg_a['id']; $ub=(int)$msg_b['id'];
                    $tc=$conn->query("SELECT COUNT(*) c FROM messages WHERE (from_user_id=$ua AND to_user_id=$ub) OR (from_user_id=$ub AND to_user_id=$ua)");
                    $msg_total=$tc?(int)$tc->fetch_assoc()['c']:0;
                    $msg_pages=max(1,(int)ceil($msg_total/$per)); $mpage=min($mpage,$msg_pages);
                    $offset=($mpage-1)*$per;
                    $mr=$conn->query("SELECT m.id,m.from_user_id,m.content,m.created_at,u.username AS sender_name,u.avatar AS sender_avatar FROM messages m JOIN users u ON u.id=m.from_user_id WHERE (m.from_user_id=$ua AND m.to_user_id=$ub) OR (m.from_user_id=$ub AND m.to_user_id=$ua) ORDER BY m.id ASC LIMIT $per OFFSET $offset");
                    if ($mr) while ($r=$mr->fetch_assoc()) $msg_list[]=$r;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>后台管理中心 · 缪斯 MUSE</title>
<link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
<link rel="shortcut icon" href="../assets/logo.svg">
<style>
*,*::before,*::after{box-sizing:border-box;}

/* ── 后台双栏布局 ── */
.ap-layout{display:flex;min-height:calc(100vh - 60px);max-width:1700px;margin:0 auto;}
.ap-sidebar{width:200px;background:#0d1117;border-right:1px solid #21262d;padding:18px 0;flex-shrink:0;position:sticky;top:0;align-self:flex-start;max-height:100vh;overflow-y:auto;}
.ap-sidebar::-webkit-scrollbar{width:4px;}
.ap-sidebar::-webkit-scrollbar-thumb{background:#30363d;border-radius:2px;}
.ap-side-title{font-size:11px;font-weight:700;color:#3fb950;letter-spacing:1.5px;text-transform:uppercase;font-family:"Courier New",monospace;padding:0 18px 14px;border-bottom:1px solid #21262d;margin-bottom:10px;}
.ap-side-title::before{content:'// ';}
.ap-nav-section{font-size:10px;color:#484f58;letter-spacing:1.5px;text-transform:uppercase;font-family:"Courier New",monospace;padding:14px 18px 6px;}
.ap-nav-item{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 18px;color:#8b949e;text-decoration:none;border-left:2px solid transparent;font-size:13px;transition:.15s;}
.ap-nav-item:hover{background:rgba(63,185,80,.04);color:#c9d1d9;}
.ap-nav-item.active{background:rgba(63,185,80,.1);color:#3fb950;border-left-color:#3fb950;}
.ap-nav-item .badge{background:#21262d;border-radius:9px;padding:0 7px;font-size:10px;color:#6e7681;font-family:"Courier New",monospace;line-height:16px;min-width:18px;text-align:center;}
.ap-nav-item.active .badge{background:rgba(63,185,80,.18);color:#3fb950;}
.ap-nav-item .ic{font-size:14px;width:16px;text-align:center;}

.ap-main{flex:1;padding:18px 28px 60px;min-width:0;}
.ap-shell{max-width:none;margin:0;padding:0;}

/* ── 顶部信息条 ── */
.ap-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid #21262d;}
.ap-title{font-size:14px;font-weight:700;color:#e6edf3;font-family:"Microsoft YaHei",sans-serif;}
.ap-meta{font-size:12px;color:#6e7681;font-family:"Courier New",monospace;}
.ap-meta a{color:#3fb950;text-decoration:none;}

/* 兼容遗留 */
.ap-tabs{display:none;}

/* ── 通用卡片 ── */
.ap-card{background:#161b22;border:1px solid #30363d;border-radius:6px;margin-bottom:10px;transition:border-color .15s;}
.ap-card:hover{border-color:#3fb950;}
.ap-card-body{padding:16px 18px;display:flex;gap:16px;align-items:flex-start;}
.ap-card-info{flex:1;min-width:0;}
.ap-card-title{font-size:14px;font-weight:600;color:#c9d1d9;margin:0 0 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ap-card-title a{color:inherit;text-decoration:none;} .ap-card-title a:hover{color:#3fb950;}
.ap-card-preview{font-size:12px;color:#8b949e;line-height:1.55;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:10px;}
.ap-card-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:11px;color:#6e7681;font-family:"Courier New",monospace;}
.ap-card-actions{display:flex;flex-direction:column;gap:6px;flex-shrink:0;}

/* ── 状态 Tag ── */
.tag{padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;white-space:nowrap;font-family:"Courier New",monospace;}
.tag-pending{background:rgba(240,136,62,.15);color:#f0883e;border:1px solid rgba(240,136,62,.3);}
.tag-ok{background:rgba(63,185,80,.15);color:#3fb950;border:1px solid rgba(63,185,80,.3);}
.tag-draft{background:rgba(88,166,255,.15);color:#58a6ff;border:1px solid rgba(88,166,255,.3);}
.tag-report-pending{background:rgba(240,136,62,.12);color:#f0883e;border:1px solid rgba(240,136,62,.3);}
.tag-report-handled{background:rgba(63,185,80,.1);color:#3fb950;border:1px solid rgba(63,185,80,.3);}
.tag-report-dismissed{background:rgba(110,118,129,.1);color:#6e7681;border:1px solid rgba(110,118,129,.3);}
.tag-post{background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid rgba(88,166,255,.3);}
.tag-user{background:rgba(167,139,250,.12);color:#a78bfa;border:1px solid rgba(167,139,250,.3);}
.tag-ban-perm{background:rgba(248,81,73,.1);color:#f85149;border:1px solid rgba(248,81,73,.3);}
.tag-ban-timed{background:rgba(240,136,62,.1);color:#f0883e;border:1px solid rgba(240,136,62,.3);}

/* ── 按钮 ── */
.btn{padding:5px 12px;border:none;border-radius:4px;cursor:pointer;text-decoration:none;font-size:12px;display:inline-block;font-weight:600;transition:opacity .15s;white-space:nowrap;text-align:center;font-family:inherit;}
.btn:hover{opacity:.85;}
.btn-approve{background:#238636;color:#fff;border:1px solid rgba(63,185,80,.4);}
.btn-view{background:#21262d;color:#8b949e;border:1px solid #30363d;}
.btn-delete{background:rgba(248,81,73,.15);color:#f85149;border:1px solid rgba(248,81,73,.3);}
.btn-unban{background:rgba(63,185,80,.08);color:#3fb950;border:1px solid rgba(63,185,80,.4);}
.btn-handled{background:rgba(63,185,80,.08);color:#3fb950;border:1px solid rgba(63,185,80,.3);}
.btn-dismiss{background:transparent;color:#6e7681;border:1px solid #30363d;}
.btn-del-post{background:rgba(248,81,73,.1);color:#f85149;border:1px solid rgba(248,81,73,.3);}
.btn-ban{background:rgba(248,81,73,.08);color:#f0883e;border:1px solid rgba(248,81,73,.25);}
.btn-green{background:#3fb950;color:#fff;} .btn-green:hover{background:#2ea043;}
.btn-outline{background:transparent;border:1px solid #30363d;color:#8b949e;text-decoration:none;display:inline-block;}
.btn-outline:hover{border-color:#8b949e;color:#e6edf3;}
.btn-danger{background:rgba(248,81,73,.12);color:#f85149;border:1px solid rgba(248,81,73,.3);text-decoration:none;display:inline-block;}
.btn-danger:hover{background:rgba(248,81,73,.22);}
.btn-sm{padding:4px 12px;font-size:12px;}
.btn-query{padding:9px 22px;background:#3fb950;color:#0d1117;border:none;border-radius:5px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;}
.btn-query:hover{opacity:.85;}

/* ── 统计卡片 ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
.stat-card{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:14px 16px;}
.stat-card .num{font-size:26px;font-weight:700;line-height:1.1;font-family:"Courier New",monospace;}
.stat-card .label{font-size:11px;color:#6e7681;margin-top:4px;letter-spacing:.5px;text-transform:uppercase;font-family:"Courier New",monospace;}
.sc-pending .num{color:#f0883e;} .sc-pub .num{color:#3fb950;} .sc-draft .num{color:#58a6ff;} .sc-total .num{color:#e6edf3;}

/* ── 子过滤栏 ── */
.sub-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;}
.stab{padding:5px 14px;border-radius:20px;font-size:12px;text-decoration:none;border:1px solid #30363d;background:#161b22;color:#8b949e;transition:.15s;font-weight:600;white-space:nowrap;}
.stab:hover{border-color:#3fb950;color:#3fb950;} .stab.active{background:rgba(63,185,80,.12);color:#3fb950;border-color:rgba(63,185,80,.4);}
.stab .n{display:inline-block;background:#21262d;border-radius:9px;padding:0 6px;font-size:10px;margin-left:4px;color:#6e7681;}
.stab.active .n{background:rgba(63,185,80,.2);color:#3fb950;}
.sep-line{width:1px;height:20px;background:#30363d;display:inline-block;margin:0 4px;vertical-align:middle;}

/* ── 空状态 ── */
.empty-state{text-align:center;padding:50px 20px;background:#161b22;border:1px solid #30363d;border-radius:6px;color:#6e7681;font-size:13px;}

/* ── 搜索框 ── */
.search-card{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:18px 20px;margin-bottom:18px;}
.search-row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;}
.search-field{flex:1;min-width:160px;}
.search-field label{display:block;font-size:11px;color:#6e7681;margin-bottom:5px;font-family:"Courier New",monospace;}
.mid-input{width:100%;padding:9px 12px;background:#0d1117;border:1px solid #30363d;border-radius:5px;color:#3fb950;font-size:14px;font-family:"Courier New",monospace;outline:none;letter-spacing:1px;text-transform:uppercase;}
.mid-input:focus{border-color:#3fb950;} .mid-input::placeholder{color:#484f58;text-transform:none;letter-spacing:0;}

/* ── 私信样式 ── */
.user-pair{display:flex;align-items:center;gap:14px;margin-bottom:16px;flex-wrap:wrap;}
.user-card-sm{display:flex;align-items:center;gap:10px;flex:1;min-width:180px;background:#161b22;border:1px solid #30363d;border-radius:7px;padding:10px 14px;}
.user-card-sm img{width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid #30363d;}
.user-card-sm-name{font-size:13px;font-weight:700;color:#e6edf3;}
.user-card-sm-mid{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;}
.pair-sep{font-size:18px;color:#30363d;flex-shrink:0;}
.chat-log{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:16px;display:flex;flex-direction:column;gap:10px;}
.date-sep{display:flex;align-items:center;gap:10px;font-size:11px;color:#484f58;margin:2px 0;}
.date-sep::before,.date-sep::after{content:'';flex:1;height:1px;background:#21262d;}
.msg-row{display:flex;gap:10px;max-width:85%;} .msg-row.right{flex-direction:row-reverse;align-self:flex-end;}
.msg-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid #30363d;flex-shrink:0;}
.msg-body{display:flex;flex-direction:column;gap:3px;} .msg-row.right .msg-body{align-items:flex-end;}
.msg-meta{font-size:10px;color:#6e7681;} .msg-sender{color:#8b949e;font-weight:600;margin-right:5px;}
.msg-bubble{padding:7px 12px;border-radius:6px;font-size:13px;line-height:1.55;word-break:break-word;font-family:"Microsoft YaHei",sans-serif;}
.msg-row.left .msg-bubble{background:#21262d;border:1px solid #30363d;border-top-left-radius:2px;}
.msg-row.right .msg-bubble{background:rgba(63,185,80,.08);border:1px solid rgba(63,185,80,.2);border-top-right-radius:2px;}

/* ── 分页 ── */
.pag{display:flex;gap:6px;justify-content:center;margin-top:14px;flex-wrap:wrap;}
.pag a{padding:5px 12px;border-radius:4px;font-size:12px;text-decoration:none;border:1px solid #30363d;background:#161b22;color:#8b949e;transition:.15s;}
.pag a:hover{border-color:#3fb950;color:#3fb950;} .pag a.cur{background:#3fb950;color:#0d1117;border-color:#3fb950;font-weight:700;}

/* ── 分区表单 ── */
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group label{font-size:12px;color:#6e7681;font-family:"Courier New",monospace;}
.form-group input[type=text],.form-group input[type=number],.form-group textarea{background:#0d1117;border:1px solid #30363d;border-radius:4px;color:#e6edf3;padding:7px 10px;font-size:13px;font-family:inherit;outline:none;transition:border-color .15s;}
.form-group input:focus,.form-group textarea:focus{border-color:#3fb950;}
.form-group input[type=color]{width:46px;height:34px;padding:2px 4px;background:#0d1117;border:1px solid #30363d;border-radius:4px;cursor:pointer;}
.cat-row{display:flex;align-items:center;gap:14px;padding:12px 16px;border-bottom:1px solid #21262d;transition:background .15s;}
.cat-row:last-child{border-bottom:none;} .cat-row:hover{background:#1c2128;}
.cat-thumb{width:72px;height:46px;border-radius:4px;object-fit:cover;flex-shrink:0;border:1px solid #30363d;background:#0d1117;}
.cat-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
.cat-info{flex:1;min-width:0;}
.cat-name{font-size:14px;font-weight:700;color:#e6edf3;margin-bottom:2px;}
.cat-meta{font-size:12px;color:#6e7681;font-family:"Courier New",monospace;}
.cover-preview-box{width:220px;height:130px;border-radius:5px;background:#0d1117;border:1px solid #30363d;overflow:hidden;display:flex;align-items:center;justify-content:center;}
.cover-preview-box img{width:100%;height:100%;object-fit:cover;}
.cover-btn{display:flex;align-items:center;justify-content:center;gap:5px;background:#1c2128;border:1px solid #30363d;border-radius:4px;color:#8b949e;font-size:12px;padding:6px 12px;cursor:pointer;transition:.15s;font-family:inherit;width:220px;}
.cover-btn:hover{border-color:#3fb950;color:#3fb950;} .cover-btn input[type=file]{display:none;}

/* ── 评论卡片 ── */
.cmt-card{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:12px 16px;margin-bottom:8px;display:flex;gap:12px;align-items:flex-start;transition:border-color .15s;}
.cmt-card:hover{border-color:#f85149;}
.cmt-body{flex:1;min-width:0;}
.cmt-content{font-size:13px;color:#c9d1d9;line-height:1.55;margin-bottom:8px;word-break:break-word;}
.cmt-meta{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;display:flex;flex-wrap:wrap;gap:8px;}
.cmt-post-link{color:#58a6ff;text-decoration:none;} .cmt-post-link:hover{text-decoration:underline;}

.msg-bar{padding:9px 14px;border-radius:4px;font-size:12px;margin-bottom:14px;font-family:"Courier New",monospace;}
.msg-ok{background:rgba(63,185,80,.1);border:1px solid rgba(63,185,80,.3);color:#3fb950;}
.msg-err{background:rgba(248,81,73,.1);border:1px solid rgba(248,81,73,.3);color:#f85149;}
.alert-error{background:rgba(248,81,73,.1);border:1px solid rgba(248,81,73,.3);color:#f85149;border-radius:5px;padding:10px 14px;font-size:13px;margin-bottom:12px;}

@media(max-width:640px){
    .stats-row{grid-template-columns:repeat(2,1fr);}
    .ap-card-body{flex-direction:column;} .ap-card-actions{flex-direction:row;}
}
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<?php
// 计算待办徽章
$badge_pending_posts   = (int)$conn->query("SELECT COUNT(*) c FROM posts WHERE status='待审核'")->fetch_assoc()['c'];
$badge_pending_reports = (int)$conn->query("SELECT COUNT(*) c FROM reports WHERE status='pending'")->fetch_assoc()['c'];
$pr_tbl = $conn->query("SHOW TABLES LIKE 'profile_edit_requests'");
$badge_pending_pr = ($pr_tbl && $pr_tbl->num_rows>0) ? (int)$conn->query("SELECT COUNT(*) c FROM profile_edit_requests WHERE status='pending'")->fetch_assoc()['c'] : 0;
$cs_tbl = $conn->query("SHOW TABLES LIKE 'cs_tickets'");
$badge_pending_cs = ($cs_tbl && $cs_tbl->num_rows>0) ? (int)$conn->query("SELECT COUNT(*) c FROM cs_tickets WHERE status='pending'")->fetch_assoc()['c'] : 0;
?>
<div class="ap-layout">

    <aside class="ap-sidebar">
        <div class="ap-side-title">缪斯后台</div>

        <?php if ($is_admin_or_owner): ?>
        <div class="ap-nav-section">内容管理</div>
        <a href="admin.php?tab=posts" class="ap-nav-item <?= $tab==='posts'?'active':'' ?>">
            <span><span class="ic">📋</span> 帖子管理</span>
            <?php if($badge_pending_posts>0): ?><span class="badge"><?= $badge_pending_posts ?></span><?php endif; ?>
        </a>
        <a href="admin.php?tab=categories" class="ap-nav-item <?= $tab==='categories'?'active':'' ?>"><span><span class="ic">🗂</span> 分区管理</span></a>
        <a href="admin.php?tab=activities" class="ap-nav-item <?= $tab==='activities'?'active':'' ?>"><span><span class="ic">🎉</span> 活动页</span></a>
        <a href="admin.php?tab=carousel" class="ap-nav-item <?= $tab==='carousel'?'active':'' ?>"><span><span class="ic">🎞</span> 站内活动</span></a>
        <a href="admin.php?tab=decorations" class="ap-nav-item <?= $tab==='decorations'?'active':'' ?>"><span><span class="ic">✨</span> 装饰系统</span></a>
        <a href="admin.php?tab=sheets" class="ap-nav-item <?= $tab==='sheets'?'active':'' ?>"><span><span class="ic">♪</span> 曲库</span></a>
        <a href="admin.php?tab=cocktails" class="ap-nav-item <?= $tab==='cocktails'?'active':'' ?>"><span><span class="ic">🍸</span> 调酒配方</span></a>
        <a href="admin.php?tab=sidebar" class="ap-nav-item <?= $tab==='sidebar'?'active':'' ?>"><span><span class="ic">📑</span> 侧边栏</span></a>

        <div class="ap-nav-section">用户管理</div>
        <a href="admin.php?tab=reports" class="ap-nav-item <?= $tab==='reports'?'active':'' ?>">
            <span><span class="ic">⚠</span> 举报管理</span>
            <?php if($badge_pending_reports>0): ?><span class="badge"><?= $badge_pending_reports ?></span><?php endif; ?>
        </a>
        <a href="admin.php?tab=bans" class="ap-nav-item <?= $tab==='bans'?'active':'' ?>"><span><span class="ic">🚫</span> 封禁管理</span></a>
        <a href="admin.php?tab=profile_reviews" class="ap-nav-item <?= $tab==='profile_reviews'?'active':'' ?>">
            <span><span class="ic">📝</span> 资料审核</span>
            <?php if($badge_pending_pr>0): ?><span class="badge"><?= $badge_pending_pr ?></span><?php endif; ?>
        </a>
        <a href="admin.php?tab=comments" class="ap-nav-item <?= $tab==='comments'?'active':'' ?>"><span><span class="ic">💬</span> 评论查询</span></a>
        <?php endif; ?>

        <?php if ($is_cs): ?>
        <div class="ap-nav-section">客服中心</div>
        <a href="admin.php?tab=cs" class="ap-nav-item <?= $tab==='cs'?'active':'' ?>">
            <span><span class="ic">💬</span> 客服后台</span>
            <?php if($badge_pending_cs>0): ?><span class="badge"><?= $badge_pending_cs ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if ($can_msg): ?>
        <div class="ap-nav-section">数据查询</div>
        <a href="admin.php?tab=messages" class="ap-nav-item <?= $tab==='messages'?'active':'' ?>"><span><span class="ic">📨</span> 私信查询</span></a>
        <?php endif; ?>

        <?php if ($is_owner): ?>
        <div class="ap-nav-section">系统设置</div>
        <a href="admin.php?tab=ai" class="ap-nav-item <?= $tab==='ai'?'active':'' ?>"><span><span class="ic">🤖</span> AI 配置</span></a>
        <?php endif; ?>
    </aside>

    <main class="ap-main">
    <div class="ap-shell">

    <div class="ap-header">
        <div class="ap-title">后台管理中心</div>
        <div class="ap-meta"><?= $my_name ?> &nbsp;·&nbsp; <a href="../index.php">← 返回主页</a></div>
    </div>

    <!-- ══════════════ TAB: 帖子管理 ══════════════ -->
    <?php if ($tab === 'posts'): ?>

    <div class="stats-row">
        <div class="stat-card sc-pending"><div class="num"><?= $pending ?></div><div class="label">⏳ 待审核</div></div>
        <div class="stat-card sc-pub"><div class="num"><?= $pub ?></div><div class="label">✅ 已发布</div></div>
        <div class="stat-card sc-draft"><div class="num"><?= $draft ?></div><div class="label">📝 草稿</div></div>
        <div class="stat-card sc-total"><div class="num"><?= $total ?></div><div class="label">📊 总计</div></div>
    </div>

    <div class="sub-tabs">
        <a href="admin.php?tab=posts&sub=all"       class="stab <?= ($sub??'all')==='all'       ? 'active':'' ?>">全部<span class="n"><?= $total ?></span></a>
        <a href="admin.php?tab=posts&sub=pending"   class="stab <?= ($sub??'')==='pending'      ? 'active':'' ?>">待审核<span class="n"><?= $pending ?></span></a>
        <a href="admin.php?tab=posts&sub=published" class="stab <?= ($sub??'')==='published'    ? 'active':'' ?>">已发布<span class="n"><?= $pub ?></span></a>
        <a href="admin.php?tab=posts&sub=draft"     class="stab <?= ($sub??'')==='draft'        ? 'active':'' ?>">草稿<span class="n"><?= $draft ?></span></a>
    </div>

    <?php if (empty($posts)): ?>
        <div class="empty-state">暂无帖子</div>
    <?php else: foreach ($posts as $p):
        $scls = $p['status']==='待审核' ? 'tag-pending' : ($p['status']==='已发布' ? 'tag-ok' : 'tag-draft');
        $prev = mb_substr(strip_tags($p['content']), 0, 120);
        $ts   = strtotime($p['created_at']); $diff = time()-$ts;
        $tstr = $diff<60?'刚刚':($diff<3600?floor($diff/60).'分钟前':($diff<86400?floor($diff/3600).'小时前':date('m-d H:i',$ts)));
    ?>
    <div class="ap-card">
        <div class="ap-card-body">
            <div class="ap-card-info">
                <div class="ap-card-title"><a href="post.php?id=<?= $p['id'] ?>" target="_blank"><?= htmlspecialchars($p['title']) ?></a></div>
                <div class="ap-card-preview"><?= htmlspecialchars($prev) ?><?= mb_strlen(strip_tags($p['content']))>120?'…':'' ?></div>
                <div class="ap-card-meta">
                    <span class="tag <?= $scls ?>"><?= $p['status'] ?></span>
                    <span>·</span><span style="color:#3fb950;font-weight:600;"><?= htmlspecialchars($p['author_name']??'未知') ?></span>
                    <?php if(!empty($p['author_uid'])): ?><span>@<?= htmlspecialchars($p['author_uid']) ?></span><?php endif; ?>
                    <span>·</span><span><?= $tstr ?></span><span>· ID:<?= $p['id'] ?></span>
                    <?php if($p['status']==='已发布'&&!empty($p['approver_name'])): ?>
                    <span>· ✅ <?= htmlspecialchars($p['approver_name']) ?> 审核<?= $p['approved_at']?' · '.date('m-d H:i',strtotime($p['approved_at'])):'' ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ap-card-actions">
                <?php if($p['status']==='待审核'): ?>
                <a href="admin.php?tab=posts&action=approve&id=<?= $p['id'] ?>&sub=<?= urlencode($sub??'all') ?>" class="btn btn-approve" onclick="return confirm('确认发布？')">✅ 通过</a>
                <?php endif; ?>
                <a href="post.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-view">👁 查看</a>
                <a href="admin.php?tab=posts&action=delete&id=<?= $p['id'] ?>&sub=<?= urlencode($sub??'all') ?>" class="btn btn-delete" onclick="return confirm('确认删除？')">🗑 删除</a>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- ══════════════ TAB: 举报管理 ══════════════ -->
    <?php elseif ($tab === 'reports'): ?>

    <div class="sub-tabs">
        <?php foreach(['pending'=>'待处理','handled'=>'已处理','dismissed'=>'已驳回','all'=>'全部'] as $k=>$label): ?>
        <a href="admin.php?tab=reports&rf=<?= $k ?>&rt=<?= $rtype ?>" class="stab <?= $rfilter===$k?'active':'' ?>"><?= $label ?><span class="n"><?= $rcnt[$k] ?></span></a>
        <?php endforeach; ?>
        <span class="sep-line"></span>
        <a href="admin.php?tab=reports&rf=<?= $rfilter ?>&rt=all"  class="stab <?= $rtype==='all'?'active':'' ?>">全部类型</a>
        <a href="admin.php?tab=reports&rf=<?= $rfilter ?>&rt=post" class="stab <?= $rtype==='post'?'active':'' ?>">帖子</a>
        <a href="admin.php?tab=reports&rf=<?= $rfilter ?>&rt=user" class="stab <?= $rtype==='user'?'active':'' ?>">用户</a>
    </div>

    <?php if(empty($reports)): ?><div class="empty-state">暂无举报记录</div>
    <?php else: foreach($reports as $r):
        $stcls=['pending'=>'tag-report-pending','handled'=>'tag-report-handled','dismissed'=>'tag-report-dismissed'][$r['status']]??'';
        $is_post=$r['type']==='post';
    ?>
    <div class="ap-card <?= in_array($r['status'],['handled','dismissed'])?'':'' ?>" id="rcard-<?= $r['id'] ?>" style="<?= $r['status']!=='pending'?'opacity:.65':'' ?>">
        <div class="ap-card-body">
            <div style="flex-shrink:0;margin-top:2px;">
                <span class="tag <?= $is_post?'tag-post':'tag-user' ?>"><?= $is_post?'帖子':'用户' ?></span>
            </div>
            <div class="ap-card-info">
                <div class="ap-card-title" style="white-space:normal;">
                    <?= htmlspecialchars($r['reason']) ?>
                    <span class="tag <?= $stcls ?>" style="margin-left:8px;"><?= ['pending'=>'待处理','handled'=>'已处理','dismissed'=>'已驳回'][$r['status']]??$r['status'] ?></span>
                </div>
                <?php if($r['detail']): ?><div class="ap-card-preview" style="-webkit-line-clamp:1;"><?= htmlspecialchars($r['detail']) ?></div><?php endif; ?>
                <div class="ap-card-meta">
                    <span>举报人：<strong style="color:#c9d1d9;"><?= htmlspecialchars($r['reporter_name']??'—') ?></strong></span>
                    <span>目标：<?php if($is_post): ?><a href="post.php?id=<?= $r['target_id'] ?>" target="_blank" style="color:#58a6ff;">#<?= $r['target_id'] ?></a><?php else: ?><a href="profile.php?id=<?= $r['target_id'] ?>" target="_blank" style="color:#58a6ff;">UID <?= $r['target_id'] ?></a><?php endif; ?></span>
                    <span><?= date('m-d H:i',strtotime($r['created_at'])) ?></span>
                    <?php if($r['handler_name']): ?><span>处理：<?= htmlspecialchars($r['handler_name']) ?></span><?php endif; ?>
                </div>
                <?php if($r['status']==='pending'): ?>
                <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:10px;">
                    <?php if($is_post): ?>
                    <a href="post.php?id=<?= $r['target_id'] ?>" target="_blank" class="btn btn-view btn-sm">查看帖子</a>
                    <button class="btn btn-del-post btn-sm" onclick="rDelPost(<?= $r['id'] ?>,<?= $r['target_id'] ?>)">删除帖子</button>
                    <?php else: ?>
                    <a href="profile.php?id=<?= $r['target_id'] ?>" target="_blank" class="btn btn-view btn-sm">查看主页</a>
                    <button class="btn btn-ban btn-sm" onclick="rBanUser(<?= $r['id'] ?>,<?= $r['target_id'] ?>)">封禁用户</button>
                    <?php endif; ?>
                    <button class="btn btn-handled btn-sm" onclick="rHandle(<?= $r['id'] ?>,'handle')">✓ 已处理</button>
                    <button class="btn btn-dismiss btn-sm" onclick="rHandle(<?= $r['id'] ?>,'dismiss')">驳回</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- ══════════════ TAB: 封禁管理 ══════════════ -->
    <?php elseif ($tab === 'bans'): ?>

    <div class="sub-tabs">
        <a href="admin.php?tab=bans&bf=all"   class="stab <?= ($bfilter??'all')==='all'?'active':'' ?>">全部 <span class="n"><?= count($banned) ?></span></a>
        <a href="admin.php?tab=bans&bf=timed" class="stab <?= ($bfilter??'')==='timed'?'active':'' ?>">限时</a>
        <a href="admin.php?tab=bans&bf=perm"  class="stab <?= ($bfilter??'')==='perm'?'active':'' ?>">永久</a>
    </div>

    <?php if(empty($banned)): ?><div class="empty-state">当前没有被封禁的用户 ✓</div>
    <?php else: foreach($banned as $u):
        $isPerm=$u['ban_until']===null;
        $utag=$isPerm?'<span class="tag tag-ban-perm">永久封禁</span>':'<span class="tag tag-ban-timed">⏱ 至 '.date('Y-m-d',strtotime($u['ban_until'])).'</span>';
        $can_unban=$is_owner||in_array($u['role'],['user','sponsor']);
    ?>
    <div class="ap-card" id="bcard-<?= $u['id'] ?>">
        <div class="ap-card-body" style="align-items:center;">
            <img src="../uploads/<?= htmlspecialchars($u['avatar']?:'default.png') ?>" onerror="this.onerror=null;this.src='../uploads/default.png'" style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:1px solid #30363d;flex-shrink:0;">
            <div class="ap-card-info">
                <div class="ap-card-title" style="white-space:normal;">
                    <a href="profile.php?id=<?= $u['id'] ?>" target="_blank"><?= htmlspecialchars($u['username']) ?></a>
                    <?= $utag ?>
                    <?= get_role_badge($u['role']) ?>
                </div>
                <div class="ap-card-meta">
                    <span>MID: <?= htmlspecialchars($u['mid']??'—') ?></span>
                    <span>原因：<strong style="color:#c9d1d9;"><?= htmlspecialchars($u['ban_reason']?:'未填写') ?></strong></span>
                    <?php if($u['banned_by_name']): ?><span>操作人：<?= htmlspecialchars($u['banned_by_name']) ?></span><?php endif; ?>
                </div>
            </div>
            <?php if($can_unban): ?>
            <button class="btn btn-unban" onclick="doUnban(<?= $u['id'] ?>,'<?= htmlspecialchars(addslashes($u['username'])) ?>')">解除封禁</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- ══════════════ TAB: 分区管理 ══════════════ -->
    <?php elseif ($tab === 'categories'): ?>

    <?php if($cat_msg==='create_ok'): ?><div class="msg-bar msg-ok">✓ 分区创建成功</div>
    <?php elseif($cat_msg==='edit_ok'): ?><div class="msg-bar msg-ok">✓ 分区已更新</div>
    <?php elseif($cat_msg==='delete_ok'): ?><div class="msg-bar msg-ok">✓ 分区已删除</div><?php endif; ?>
    <?php if(!empty($cat_form_err)): ?><div class="msg-bar msg-err">✗ <?= htmlspecialchars($cat_form_err) ?></div><?php endif; ?>

    <div class="ap-card" style="margin-bottom:18px;">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
            <?= $edit_cat?'编辑分区':'新建分区' ?>
        </div>
        <div style="padding:18px;">
            <form method="POST" action="admin.php?tab=categories<?= $edit_cat?'&edit_cat='.$edit_cat['id']:'' ?>" enctype="multipart/form-data">
                <?php if($edit_cat): ?><input type="hidden" name="edit_id" value="<?= $edit_cat['id'] ?>"><?php endif; ?>
                <div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;">
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <div class="cover-preview-box">
                            <img id="cat-preview" <?= ($edit_cat&&!empty($edit_cat['cover_image']))?'src="../'.htmlspecialchars($edit_cat['cover_image']).'"':'style="display:none"' ?>>
                            <div id="cat-empty" style="color:#484f58;font-size:12px;font-family:'Courier New',monospace;text-align:center;<?= ($edit_cat&&!empty($edit_cat['cover_image']))?'display:none':'' ?>">🖼 暂无封面</div>
                        </div>
                        <label class="cover-btn">
                            <input type="file" name="cover_image" accept="image/*" onchange="prevCat(this)"> 📂 选择封面
                        </label>
                    </div>
                    <div style="flex:1;min-width:200px;display:flex;flex-direction:column;gap:12px;">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;">
                            <div class="form-group" style="flex:1;min-width:130px;">
                                <label>分区名称 *</label>
                                <input type="text" name="name" placeholder="如：游戏攻略" maxlength="50" required value="<?= htmlspecialchars($edit_cat['name']??'') ?>">
                            </div>
                            <div class="form-group" style="width:58px;">
                                <label>图标</label>
                                <input type="text" name="icon" placeholder="#" maxlength="4" value="<?= htmlspecialchars($edit_cat['icon']??'#') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>简介（可选）</label>
                            <input type="text" name="description" placeholder="一句话介绍" maxlength="200" value="<?= htmlspecialchars($edit_cat['description']??'') ?>">
                        </div>
                        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                            <div class="form-group"><label>主题色</label><input type="color" name="color" value="<?= htmlspecialchars($edit_cat['color']??'#3fb950') ?>"></div>
                            <div class="form-group" style="width:70px;"><label>排序</label><input type="number" name="sort_order" min="0" max="999" value="<?= intval($edit_cat['sort_order']??0) ?>"></div>
                            <div style="display:flex;gap:8px;margin-left:auto;">
                                <button type="submit" class="btn btn-green"><?= $edit_cat?'保存修改':'创建分区' ?></button>
                                <?php if($edit_cat): ?><a href="admin.php?tab=categories" class="btn btn-outline">取消</a><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="ap-card">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">现有分区（<?= count($cats) ?>）</div>
        <?php if(empty($cats)): ?><div style="padding:32px;text-align:center;color:#6e7681;font-family:'Courier New',monospace;font-size:13px;">// 还没有分区</div>
        <?php else: foreach($cats as $c): ?>
        <div class="cat-row">
            <?php if(!empty($c['cover_image'])): ?>
            <img class="cat-thumb" src="../<?= htmlspecialchars($c['cover_image']) ?>" alt="">
            <?php else: ?>
            <div class="cat-thumb" style="display:flex;align-items:center;justify-content:center;font-size:18px;color:#484f58;"><?= htmlspecialchars($c['icon']?:'#') ?></div>
            <?php endif; ?>
            <span class="cat-dot" style="background:<?= htmlspecialchars($c['color']) ?>"></span>
            <div class="cat-info">
                <div class="cat-name"><?= htmlspecialchars($c['name']) ?></div>
                <div class="cat-meta"><?= (int)$c['post_count'] ?> 篇帖子<?= !empty($c['description'])?' · '.htmlspecialchars(mb_substr($c['description'],0,30)):'' ?></div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <a href="admin.php?tab=categories&edit_cat=<?= $c['id'] ?>" class="btn btn-outline btn-sm">编辑</a>
                <a href="admin.php?tab=categories&delete_cat=<?= $c['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('删除「<?= htmlspecialchars($c['name']) ?>」？')">删除</a>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- ══════════════ TAB: 活动页 ══════════════ -->
    <?php elseif ($tab === 'activities'): ?>

    <?php if($ap_msg==='create_ok'): ?><div class="msg-bar msg-ok">✓ 活动页已创建</div>
    <?php elseif($ap_msg==='delete_ok'): ?><div class="msg-bar msg-ok">✓ 活动页已删除</div>
    <?php elseif($ap_msg==='status_ok'): ?><div class="msg-bar msg-ok">✓ 状态已切换</div>
    <?php elseif($ap_msg==='name_empty'): ?><div class="msg-bar msg-err">✗ 页面名称不能为空</div>
    <?php endif; ?>

    <div class="ap-card" style="margin-bottom:18px;">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
            创建新活动页面
        </div>
        <div style="padding:16px;">
            <form method="POST" action="../actions/activity_create.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <input type="text" name="title" placeholder="页面名称（如：周年庆活动）" maxlength="100" required
                       style="flex:1;min-width:240px;background:#0d1117;border:1px solid #30363d;border-radius:5px;color:#e6edf3;padding:9px 12px;font-size:13px;font-family:inherit;outline:none;">
                <button type="submit" class="btn btn-green">+ 创建并编辑</button>
            </form>
            <div style="font-size:11px;color:#6e7681;margin-top:8px;font-family:'Courier New',monospace;">// 创建后自动进入可视化编辑器</div>
        </div>
    </div>

    <div class="ap-card">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
            已有活动页（<?= count($activity_pages) ?>）
        </div>
        <?php if(empty($activity_pages)): ?>
        <div style="padding:32px;text-align:center;color:#6e7681;font-family:'Courier New',monospace;font-size:13px;">// 还没有活动页</div>
        <?php else: foreach($activity_pages as $a):
            $st_pub = $a['status']==='published';
            $st_cls = $st_pub ? 'tag-ok' : 'tag-draft';
            $st_txt = $st_pub ? '已发布' : '草稿';
        ?>
        <div class="cat-row">
            <div class="cat-info">
                <div class="cat-name">
                    <?= htmlspecialchars($a['title']) ?>
                    <span class="tag <?= $st_cls ?>" style="margin-left:6px;"><?= $st_txt ?></span>
                </div>
                <div class="cat-meta">
                    /activity.php?slug=<?= htmlspecialchars($a['slug']) ?>
                    · 创建：<?= htmlspecialchars($a['creator_name'] ?? '—') ?>
                    · <?= date('m-d H:i', strtotime($a['created_at'])) ?>
                    · 评论：<?= (int)$a['cmt_count'] ?>
                    <?= $a['comments_enabled']?'':' · <span style="color:#f0883e;">评论已关</span>' ?>
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <?php if($st_pub):
                    $full_url = SITE_URL . '/activity.php?slug=' . urlencode($a['slug']);
                ?>
                <a href="<?= htmlspecialchars($full_url) ?>" target="_blank" class="btn btn-view btn-sm">👁 预览</a>
                <button type="button" class="btn btn-outline btn-sm" onclick="copyActivityLink(this,'<?= htmlspecialchars(addslashes($full_url)) ?>')">🔗 复制链接</button>
                <?php endif; ?>
                <a href="activity_editor.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">✏ 编辑</a>
                <a href="admin.php?tab=activities&toggle_status=<?= $a['id'] ?>" class="btn btn-outline btn-sm"><?= $st_pub?'撤回':'发布' ?></a>
                <a href="admin.php?tab=activities&delete_page=<?= $a['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('删除「<?= htmlspecialchars(addslashes($a['title'])) ?>」及其所有评论？')">🗑</a>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- ══════════════ TAB: 站内活动（轮播）══════════════ -->
    <?php elseif ($tab === 'carousel'): ?>

    <?php if($ca_msg==='create_ok'): ?><div class="msg-bar msg-ok">✓ 已添加轮播</div>
    <?php elseif($ca_msg==='edit_ok'): ?><div class="msg-bar msg-ok">✓ 已更新</div>
    <?php elseif($ca_msg==='delete_ok'): ?><div class="msg-bar msg-ok">✓ 已删除</div>
    <?php elseif($ca_msg==='toggle_ok'): ?><div class="msg-bar msg-ok">✓ 状态已切换</div>
    <?php endif; ?>
    <?php if(!empty($ca_form_err)): ?><div class="msg-bar msg-err">✗ <?= htmlspecialchars($ca_form_err) ?></div><?php endif; ?>

    <div class="ap-card" style="margin-bottom:18px;">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
            <?= $ca_edit ? '编辑轮播项' : '添加轮播项' ?>
        </div>
        <div style="padding:18px;">
            <form method="POST" action="admin.php?tab=carousel<?= $ca_edit?'&edit_ca='.$ca_edit['id']:'' ?>" enctype="multipart/form-data">
                <?php if($ca_edit): ?><input type="hidden" name="ca_edit_id" value="<?= $ca_edit['id'] ?>"><?php endif; ?>
                <div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;">
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <div style="width:300px;height:160px;border-radius:5px;background:#0d1117;border:1px solid #30363d;overflow:hidden;display:flex;align-items:center;justify-content:center;">
                            <img id="ca-preview" <?= ($ca_edit && !empty($ca_edit['image_path'])) ? 'src="../'.htmlspecialchars($ca_edit['image_path']).'"' : 'style="display:none"' ?> style="width:100%;height:100%;object-fit:cover;">
                            <div id="ca-empty" style="color:#484f58;font-size:12px;font-family:'Courier New',monospace;<?= ($ca_edit && !empty($ca_edit['image_path']))?'display:none':'' ?>">🖼 暂无图片（建议 16:9）</div>
                        </div>
                        <label class="cover-btn" style="width:300px;">
                            <input type="file" name="ca_image" accept="image/*" onchange="prevCa(this)"> 📂 选择图片
                        </label>
                    </div>
                    <div style="flex:1;min-width:240px;display:flex;flex-direction:column;gap:12px;">
                        <div class="form-group"><label>标题 *</label>
                            <input type="text" name="ca_title" maxlength="120" required value="<?= htmlspecialchars($ca_edit['title'] ?? '') ?>" placeholder="如：周年庆典即将开启">
                        </div>
                        <div class="form-group"><label>副标题（可选）</label>
                            <input type="text" name="ca_subtitle" maxlength="200" value="<?= htmlspecialchars($ca_edit['subtitle'] ?? '') ?>" placeholder="一句话描述，会显示在图上">
                        </div>
                        <div class="form-group"><label>跳转链接 *</label>
                            <input type="text" name="ca_link" required value="<?= htmlspecialchars($ca_edit['link_url'] ?? '') ?>" placeholder="https://... 或 /activity.php?slug=xxx">
                        </div>
                        <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
                            <div class="form-group" style="width:90px;"><label>排序</label>
                                <input type="number" name="ca_sort" min="0" max="999" value="<?= intval($ca_edit['sort_order'] ?? 0) ?>">
                            </div>
                            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#c9d1d9;font-family:'Courier New',monospace;cursor:pointer;">
                                <input type="checkbox" name="ca_active" value="1" <?= (!$ca_edit || !empty($ca_edit['is_active']))?'checked':'' ?> style="accent-color:#3fb950;"> 启用展示
                            </label>
                            <div style="display:flex;gap:8px;margin-left:auto;">
                                <button type="submit" class="btn btn-green"><?= $ca_edit?'保存修改':'添加轮播' ?></button>
                                <?php if($ca_edit): ?><a href="admin.php?tab=carousel" class="btn btn-outline">取消</a><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="ap-card">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
            现有轮播项（<?= count($carousel_items) ?>）· 排序按 sort_order 升序，相同则按 ID 倒序
        </div>
        <?php if(empty($carousel_items)): ?>
        <div style="padding:32px;text-align:center;color:#6e7681;font-family:'Courier New',monospace;font-size:13px;">// 还没有轮播项</div>
        <?php else: foreach($carousel_items as $c): ?>
        <div class="cat-row">
            <img class="cat-thumb" src="../<?= htmlspecialchars($c['image_path']) ?>" alt="" style="width:120px;height:68px;object-fit:cover;">
            <div class="cat-info">
                <div class="cat-name">
                    <?= htmlspecialchars($c['title']) ?>
                    <?php if(!$c['is_active']): ?><span class="tag tag-pending" style="margin-left:6px;">已停用</span><?php endif; ?>
                </div>
                <div class="cat-meta">
                    排序 <?= (int)$c['sort_order'] ?>
                    · <a href="<?= htmlspecialchars($c['link_url']) ?>" target="_blank" style="color:#58a6ff;text-decoration:none;"><?= htmlspecialchars(mb_strlen($c['link_url'])>50 ? mb_substr($c['link_url'],0,50).'…' : $c['link_url']) ?></a>
                    <?php if(!empty($c['subtitle'])): ?><br>· <?= htmlspecialchars($c['subtitle']) ?><?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <a href="admin.php?tab=carousel&edit_ca=<?= $c['id'] ?>" class="btn btn-outline btn-sm">编辑</a>
                <a href="admin.php?tab=carousel&toggle_ca=<?= $c['id'] ?>" class="btn btn-outline btn-sm"><?= $c['is_active']?'停用':'启用' ?></a>
                <a href="admin.php?tab=carousel&delete_ca=<?= $c['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('删除「<?= htmlspecialchars(addslashes($c['title'])) ?>」？')">删除</a>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- ══════════════ TAB: 装饰系统 ══════════════ -->
    <?php elseif ($tab === 'decorations'): ?>

    <?php if($dc_msg==='create_ok'): ?><div class="msg-bar msg-ok">✓ 已添加装饰品</div>
    <?php elseif($dc_msg==='edit_ok'): ?><div class="msg-bar msg-ok">✓ 已更新</div>
    <?php elseif($dc_msg==='delete_ok'): ?><div class="msg-bar msg-ok">✓ 装饰品已删除（同时收回所有用户已装备）</div>
    <?php elseif($dc_msg==='toggle_ok'): ?><div class="msg-bar msg-ok">✓ 状态已切换</div>
    <?php elseif($dc_msg==='revoke_ok'): ?><div class="msg-bar msg-ok">✓ 已收回</div>
    <?php endif; ?>
    <?php if(!empty($dc_form_err)): ?><div class="msg-bar msg-err">✗ <?= htmlspecialchars($dc_form_err) ?></div><?php endif; ?>
    <?php if(!empty($dc_grant_msg)): ?><div class="msg-bar <?= str_starts_with($dc_grant_msg,'✓')?'msg-ok':'msg-err' ?>"><?= $dc_grant_msg ?></div><?php endif; ?>

    <!-- 添加装饰品表单 -->
    <div class="ap-card" style="margin-bottom:18px;">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
            <?= $dc_edit ? '编辑装饰品' : '添加装饰品' ?>
        </div>
        <div style="padding:18px;">
            <form method="POST" action="admin.php?tab=decorations<?= $dc_edit?'&edit_dc='.$dc_edit['id']:'' ?>" enctype="multipart/form-data">
                <input type="hidden" name="dc_form" value="1">
                <?php if($dc_edit): ?><input type="hidden" name="dc_edit_id" value="<?= $dc_edit['id'] ?>"><?php endif; ?>

                <div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;">
                    <div style="display:flex;flex-direction:column;gap:8px;width:200px;">
                        <div style="width:200px;height:200px;border-radius:5px;background:#0d1117;border:1px solid #30363d;overflow:hidden;display:flex;align-items:center;justify-content:center;position:relative;">
                            <img id="dc-preview" <?= ($dc_edit && !empty($dc_edit['image_path'])) ? 'src="../'.htmlspecialchars($dc_edit['image_path']).'"' : 'style="display:none"' ?> style="max-width:100%;max-height:100%;object-fit:contain;">
                            <div id="dc-empty" style="color:#484f58;font-size:11px;font-family:'Courier New',monospace;text-align:center;padding:0 10px;<?= ($dc_edit && !empty($dc_edit['image_path']))?'display:none':'' ?>">🖼 头像框需 PNG 透明<br>名牌图可选</div>
                        </div>
                        <label class="cover-btn" style="width:200px;">
                            <input type="file" name="dc_image" accept="image/png,image/webp,image/jpeg,image/gif" onchange="prevDc(this)"> 📂 选择图片
                        </label>
                    </div>

                    <div style="flex:1;min-width:280px;display:flex;flex-direction:column;gap:12px;">
                        <div class="form-group">
                            <label>类型 *</label>
                            <select name="dc_type" id="dc-type" onchange="dcTypeChanged()" <?= $dc_edit?'disabled':'' ?> style="background:#0d1117;border:1px solid #30363d;border-radius:4px;color:#e6edf3;padding:7px 10px;font-size:13px;font-family:inherit;outline:none;">
                                <?php $cur_t = $dc_edit['type'] ?? 'frame'; ?>
                                <option value="frame"          <?= $cur_t==='frame'?'selected':'' ?>>头像框（PNG 透明，叠在头像上）</option>
                                <option value="plate_skin"     <?= $cur_t==='plate_skin'?'selected':'' ?>>名牌外观（仅改色不改字）</option>
                                <option value="plate_suffix"   <?= $cur_t==='plate_suffix'?'selected':'' ?>>名牌后缀（在角色名后加文本）</option>
                            </select>
                            <?php if($dc_edit): ?><input type="hidden" name="dc_type" value="<?= $cur_t ?>"><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>名称 *</label>
                            <input type="text" name="dc_name" maxlength="80" required value="<?= htmlspecialchars($dc_edit['name'] ?? '') ?>" placeholder="如：金色光环 / 1 周年纪念后缀">
                        </div>

                        <div class="form-group">
                            <label>描述（可选）</label>
                            <input type="text" name="dc_desc" maxlength="200" value="<?= htmlspecialchars($dc_edit['description'] ?? '') ?>" placeholder="一句话介绍">
                        </div>

                        <!-- 名牌外观字段（仅 plate_skin 显示）-->
                        <div id="dc-skin-fields" style="display:<?= ($cur_t==='plate_skin'?'block':'none') ?>;background:rgba(167,139,250,.05);border:1px dashed rgba(167,139,250,.2);border-radius:5px;padding:12px;">
                            <div style="font-size:10px;color:#a78bfa;font-family:'Courier New',monospace;margin-bottom:8px;letter-spacing:1px;">// 名牌外观（留空使用角色默认）</div>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                                <div class="form-group"><label>背景色</label><input type="text" name="dc_bg" maxlength="40" value="<?= htmlspecialchars($dc_edit['bg_color'] ?? '') ?>" placeholder="如 #f85149 或 rgba(...)"></div>
                                <div class="form-group"><label>文字色</label><input type="text" name="dc_color" maxlength="40" value="<?= htmlspecialchars($dc_edit['text_color'] ?? '') ?>" placeholder="如 #fff"></div>
                                <div class="form-group"><label>边框色</label><input type="text" name="dc_border" maxlength="40" value="<?= htmlspecialchars($dc_edit['border_color'] ?? '') ?>" placeholder="如 #f0883e"></div>
                            </div>
                        </div>

                        <!-- 后缀字段（仅 plate_suffix 显示）-->
                        <div id="dc-suffix-fields" style="display:<?= ($cur_t==='plate_suffix'?'block':'none') ?>;background:rgba(63,185,80,.05);border:1px dashed rgba(63,185,80,.2);border-radius:5px;padding:12px;">
                            <div style="font-size:10px;color:#3fb950;font-family:'Courier New',monospace;margin-bottom:8px;letter-spacing:1px;">// 角色名后追加，如「成员 · 1 周年」</div>
                            <div class="form-group"><label>后缀文本 *</label><input type="text" name="dc_suffix" maxlength="40" value="<?= htmlspecialchars($dc_edit['suffix_text'] ?? '') ?>" placeholder="如：1 周年 / VIP / 创始用户"></div>
                        </div>

                        <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
                            <div class="form-group" style="width:90px;"><label>排序</label><input type="number" name="dc_sort" min="0" max="999" value="<?= intval($dc_edit['sort_order'] ?? 0) ?>"></div>
                            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#c9d1d9;font-family:'Courier New',monospace;cursor:pointer;">
                                <input type="checkbox" name="dc_active" value="1" <?= (!$dc_edit || !empty($dc_edit['is_active']))?'checked':'' ?> style="accent-color:#3fb950;"> 启用
                            </label>
                            <div style="display:flex;gap:8px;margin-left:auto;">
                                <button type="submit" class="btn btn-green"><?= $dc_edit?'保存修改':'添加装饰品' ?></button>
                                <?php if($dc_edit): ?><a href="admin.php?tab=decorations" class="btn btn-outline">取消</a><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 发放装饰 -->
    <div class="ap-card" style="margin-bottom:18px;">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
            发放装饰品给用户
        </div>
        <div style="padding:18px;">
            <form method="POST" action="admin.php?tab=decorations<?= $dc_view?'&dc_view='.$dc_view:'' ?>" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="grant_form" value="1">
                <div class="form-group" style="flex:1;min-width:240px;">
                    <label>装饰品</label>
                    <select name="grant_dc_id" required style="width:100%;background:#0d1117;border:1px solid #30363d;border-radius:4px;color:#e6edf3;padding:8px 10px;font-size:13px;font-family:inherit;outline:none;">
                        <option value="">— 选择 —</option>
                        <?php foreach ($decorations as $d):
                            $type_lbl = ['frame'=>'头像框','plate_skin'=>'名牌外观','plate_suffix'=>'名牌后缀'][$d['type']];
                        ?>
                        <option value="<?= $d['id'] ?>"<?= ($dc_view==(int)$d['id'])?' selected':'' ?>>[<?= $type_lbl ?>] <?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="width:200px;">
                    <label>用户 MID（8 位）</label>
                    <input type="text" name="grant_mid" required maxlength="8" placeholder="AB12CD34" class="mid-input">
                </div>
                <button type="submit" class="btn btn-green">发放</button>
            </form>
        </div>
    </div>

    <!-- 类型筛选 -->
    <div class="sub-tabs">
        <a href="admin.php?tab=decorations&dc_filter=all"           class="stab <?= $dc_filter==='all'?'active':'' ?>">全部</a>
        <a href="admin.php?tab=decorations&dc_filter=frame"         class="stab <?= $dc_filter==='frame'?'active':'' ?>">头像框</a>
        <a href="admin.php?tab=decorations&dc_filter=plate_skin"    class="stab <?= $dc_filter==='plate_skin'?'active':'' ?>">名牌外观</a>
        <a href="admin.php?tab=decorations&dc_filter=plate_suffix"  class="stab <?= $dc_filter==='plate_suffix'?'active':'' ?>">名牌后缀</a>
    </div>

    <!-- 列表 -->
    <div class="ap-card">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">现有装饰（<?= count($decorations) ?>）</div>
        <?php if(empty($decorations)): ?>
        <div style="padding:32px;text-align:center;color:#6e7681;font-family:'Courier New',monospace;font-size:13px;">// 还没有装饰</div>
        <?php else: foreach($decorations as $d):
            $type_lbl = ['frame'=>'头像框','plate_skin'=>'名牌外观','plate_suffix'=>'名牌后缀'][$d['type']];
            $type_cls = ['frame'=>'tag-post','plate_skin'=>'tag-user','plate_suffix'=>'tag-ok'][$d['type']];
        ?>
        <div class="cat-row">
            <?php if($d['type']==='frame' && !empty($d['image_path'])): ?>
                <div style="position:relative;width:62px;height:62px;flex-shrink:0;border-radius:8px;overflow:hidden;background:#0d1117;border:1px solid #30363d;display:flex;align-items:center;justify-content:center;">
                    <div style="width:42px;height:42px;border-radius:50%;background:#21262d;display:flex;align-items:center;justify-content:center;color:#6e7681;font-size:11px;font-family:'Courier New',monospace;">头像</div>
                    <img src="../<?= htmlspecialchars($d['image_path']) ?>" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none;">
                </div>
            <?php elseif($d['type']==='plate_skin'): ?>
                <div style="width:62px;height:62px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:#0d1117;border:1px solid #30363d;border-radius:5px;">
                    <span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;background:<?= htmlspecialchars($d['bg_color']?:'#21262d') ?>;color:<?= htmlspecialchars($d['text_color']?:'#8b949e') ?>;border:1px solid <?= htmlspecialchars($d['border_color']?:'#30363d') ?>;">样例</span>
                </div>
            <?php else: ?>
                <div class="cat-thumb" style="display:flex;align-items:center;justify-content:center;font-size:11px;color:#3fb950;font-family:'Courier New',monospace;text-align:center;padding:4px;line-height:1.3;">+<?= htmlspecialchars(mb_substr($d['suffix_text'], 0, 6)) ?></div>
            <?php endif; ?>
            <div class="cat-info">
                <div class="cat-name">
                    <?= htmlspecialchars($d['name']) ?>
                    <span class="tag <?= $type_cls ?>" style="margin-left:6px;"><?= $type_lbl ?></span>
                    <?php if(!$d['is_active']): ?><span class="tag tag-pending" style="margin-left:4px;">已停用</span><?php endif; ?>
                </div>
                <div class="cat-meta">
                    持有人 <strong style="color:#3fb950;"><?= (int)$d['owners'] ?></strong>
                    <?php if($d['type']==='plate_suffix'): ?> · 后缀：<?= htmlspecialchars($d['suffix_text']) ?><?php endif; ?>
                    <?php if(!empty($d['description'])): ?> · <?= htmlspecialchars(mb_substr($d['description'],0,30)) ?><?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <a href="admin.php?tab=decorations&dc_view=<?= $d['id'] ?>" class="btn btn-outline btn-sm">持有人</a>
                <a href="admin.php?tab=decorations&edit_dc=<?= $d['id'] ?>" class="btn btn-outline btn-sm">编辑</a>
                <a href="admin.php?tab=decorations&toggle_dc=<?= $d['id'] ?>" class="btn btn-outline btn-sm"><?= $d['is_active']?'停用':'启用' ?></a>
                <a href="admin.php?tab=decorations&delete_dc=<?= $d['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('删除「<?= htmlspecialchars(addslashes($d['name'])) ?>」？\n所有持有该装饰的用户会失去它。')">删除</a>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if(!empty($dc_view_item)): ?>
    <div class="ap-card" style="margin-top:14px;">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;display:flex;align-items:center;gap:10px;">
            <span>「<?= htmlspecialchars($dc_view_item['name']) ?>」持有人（<?= count($dc_view_owners) ?>）</span>
            <a href="admin.php?tab=decorations" style="margin-left:auto;font-size:11px;color:#6e7681;text-decoration:none;text-transform:none;letter-spacing:0;">关闭 ✕</a>
        </div>
        <?php if(empty($dc_view_owners)): ?>
        <div style="padding:24px;text-align:center;color:#6e7681;font-family:'Courier New',monospace;font-size:13px;">// 暂无持有人</div>
        <?php else: foreach($dc_view_owners as $u): ?>
        <div class="cat-row">
            <div class="cat-info">
                <div class="cat-name"><?= htmlspecialchars($u['username']) ?></div>
                <div class="cat-meta">MID <?= htmlspecialchars($u['mid']) ?> · 获得 <?= htmlspecialchars($u['obtained_at']) ?></div>
            </div>
            <a href="admin.php?tab=decorations&revoke_dc=<?= $dc_view_item['id'] ?>&revoke_uid=<?= $u['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('从该用户处收回「<?= htmlspecialchars(addslashes($dc_view_item['name'])) ?>」？')">收回</a>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══════════════ TAB: 资料审核 ══════════════ -->
    <?php elseif ($tab === 'profile_reviews'): ?>

    <?php if($pr_msg==='approved'): ?><div class="msg-bar msg-ok">✓ 已通过审核，资料已更新</div>
    <?php elseif($pr_msg==='rejected'): ?><div class="msg-bar msg-ok">✓ 已拒绝申请</div>
    <?php endif; ?>

    <div class="sub-tabs">
        <a href="admin.php?tab=profile_reviews&pr_filter=pending"  class="stab <?= $pr_filter==='pending'?'active':'' ?>">待审核<span class="n"><?= $pr_counts['pending'] ?></span></a>
        <a href="admin.php?tab=profile_reviews&pr_filter=approved" class="stab <?= $pr_filter==='approved'?'active':'' ?>">已通过<span class="n"><?= $pr_counts['approved'] ?></span></a>
        <a href="admin.php?tab=profile_reviews&pr_filter=rejected" class="stab <?= $pr_filter==='rejected'?'active':'' ?>">已拒绝<span class="n"><?= $pr_counts['rejected'] ?></span></a>
        <a href="admin.php?tab=profile_reviews&pr_filter=all"      class="stab <?= $pr_filter==='all'?'active':'' ?>">全部</a>
    </div>

    <?php if (!$pr_reqs || $pr_reqs->num_rows === 0): ?>
        <div class="empty-state">暂无<?= $pr_filter==='pending'?'待审核':'' ?>记录</div>
    <?php else: while ($r = $pr_reqs->fetch_assoc()):
        $st_cls = ['pending'=>'tag-pending','approved'=>'tag-ok','rejected'=>'tag-report-handled'][$r['status']] ?? '';
        $st_lbl = ['pending'=>'待审核','approved'=>'已通过','rejected'=>'已拒绝'][$r['status']] ?? $r['status'];
    ?>
    <div class="ap-card">
        <div class="ap-card-body" style="flex-direction:column;align-items:stretch;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                <span style="font-size:13px;font-weight:700;color:#e6edf3;"><?= htmlspecialchars($r['cur_username']) ?></span>
                <span style="font-size:11px;color:#6e7681;font-family:'Courier New',monospace;">UID <?= $r['user_id'] ?></span>
                <span class="tag <?= $st_cls ?>"><?= $st_lbl ?></span>
                <span style="margin-left:auto;font-size:11px;color:#6e7681;font-family:'Courier New',monospace;"><?= $r['created_at'] ?></span>
            </div>

            <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:12px;">
                <thead>
                    <tr><th style="text-align:left;color:#6e7681;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;padding:4px 8px;border-bottom:1px solid #21262d;">字段</th>
                        <th style="text-align:left;color:#6e7681;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;padding:4px 8px;border-bottom:1px solid #21262d;">当前</th>
                        <th></th>
                        <th style="text-align:left;color:#6e7681;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;padding:4px 8px;border-bottom:1px solid #21262d;">申请改为</th></tr>
                </thead>
                <tbody>
                <?php
                $fields = ['username'=>'用户名','gender'=>'性别','phone'=>'电话','birthday'=>'生日','signature'=>'签名'];
                foreach ($fields as $key => $label) {
                    $old = $r['cur_'.$key] ?? '';
                    $new = $r['new_'.$key];
                    if ($new === null) continue;
                    $changed = (string)$new !== (string)$old;
                    echo '<tr>';
                    echo '<td style="color:#8b949e;font-size:12px;padding:6px 8px;border-bottom:1px solid #21262d;">'.htmlspecialchars($label).'</td>';
                    echo '<td style="color:#8b949e;padding:6px 8px;border-bottom:1px solid #21262d;">'.htmlspecialchars($old ?: '—').'</td>';
                    echo '<td style="color:#3fb950;padding:6px 4px;border-bottom:1px solid #21262d;">→</td>';
                    echo '<td style="padding:6px 8px;border-bottom:1px solid #21262d;color:'.($changed?'#3fb950;font-weight:600':'#6e7681;font-style:italic').';">'.htmlspecialchars($new ?: '—').($changed?'':' (未变)').'</td>';
                    echo '</tr>';
                }
                if ($r['new_avatar']): ?>
                <tr>
                    <td style="color:#8b949e;font-size:12px;padding:6px 8px;">头像</td>
                    <td style="padding:6px 8px;"><img src="../uploads/avatars/<?= htmlspecialchars($r['cur_avatar'] ?: 'default.png') ?>" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:1px solid #30363d;" onerror="this.src='../uploads/avatars/default.png'"></td>
                    <td style="color:#3fb950;padding:6px 4px;">→</td>
                    <td style="padding:6px 8px;"><img src="../uploads/avatars/<?= htmlspecialchars($r['new_avatar']) ?>" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:1px solid #30363d;" onerror="this.src='../uploads/avatars/default.png'"></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($r['status'] === 'pending'): ?>
            <form method="POST" action="admin.php?tab=profile_reviews">
                <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" name="note" placeholder="拒绝原因（可选）" style="flex:1;min-width:160px;padding:7px 10px;background:#0d1117;border:1px solid #30363d;border-radius:4px;color:#e6edf3;font-size:12px;font-family:inherit;outline:none;">
                    <button type="submit" name="action" value="approve" class="btn btn-approve">✓ 通过</button>
                    <button type="submit" name="action" value="reject" class="btn btn-delete">✗ 拒绝</button>
                </div>
            </form>
            <?php elseif ($r['admin_note']): ?>
            <div style="font-size:12px;color:#d29922;margin-top:4px;">拒绝原因：<?= htmlspecialchars($r['admin_note']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endwhile; endif; ?>

    <!-- ══════════════ TAB: 曲库管理 ══════════════ -->
    <?php elseif ($tab === 'sheets'): ?>

    <div class="sub-tabs">
        <a href="admin.php?tab=sheets&sh_filter=all"          class="stab <?= $sh_filter==='all'?'active':'' ?>">全部<span class="n"><?= count($sheets) ?></span></a>
        <a href="admin.php?tab=sheets&sh_filter=published"    class="stab <?= $sh_filter==='published'?'active':'' ?>">已发布</a>
        <a href="admin.php?tab=sheets&sh_filter=rejected"     class="stab <?= $sh_filter==='rejected'?'active':'' ?>">已下架</a>
        <a href="admin.php?tab=sheets&sh_filter=recommended"  class="stab <?= $sh_filter==='recommended'?'active':'' ?>">★ 精选</a>
    </div>

    <div class="ap-card" style="padding:0;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;background:transparent;">
            <thead>
                <tr style="background:#0d1117;">
                    <th style="padding:10px 12px;text-align:left;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;color:#8b949e;font-weight:700;border-bottom:1px solid #30363d;">ID</th>
                    <th style="padding:10px 12px;text-align:left;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;color:#8b949e;font-weight:700;border-bottom:1px solid #30363d;">曲名</th>
                    <th style="padding:10px 12px;text-align:left;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;color:#8b949e;font-weight:700;border-bottom:1px solid #30363d;">原唱</th>
                    <th style="padding:10px 12px;text-align:left;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;color:#8b949e;font-weight:700;border-bottom:1px solid #30363d;">上传者</th>
                    <th style="padding:10px 12px;text-align:left;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;color:#8b949e;font-weight:700;border-bottom:1px solid #30363d;">状态</th>
                    <th style="padding:10px 12px;text-align:left;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;color:#8b949e;font-weight:700;border-bottom:1px solid #30363d;">下载</th>
                    <th style="padding:10px 12px;text-align:left;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;color:#8b949e;font-weight:700;border-bottom:1px solid #30363d;">♥</th>
                    <th style="padding:10px 12px;text-align:left;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;color:#8b949e;font-weight:700;border-bottom:1px solid #30363d;">时间</th>
                    <th style="padding:10px 12px;text-align:left;font-size:11px;font-family:'Courier New',monospace;text-transform:uppercase;color:#8b949e;font-weight:700;border-bottom:1px solid #30363d;">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sheets)): ?>
                <tr><td colspan="9" style="text-align:center;color:#6e7681;padding:30px;font-family:'Courier New',monospace;font-size:13px;">// 没有数据</td></tr>
            <?php else: foreach ($sheets as $s): ?>
                <tr style="border-bottom:1px solid #21262d;">
                    <td style="padding:10px 12px;font-size:13px;color:#c9d1d9;">#<?= (int)$s['id'] ?></td>
                    <td style="padding:10px 12px;font-size:13px;"><a href="sheet_detail.php?id=<?= (int)$s['id'] ?>" target="_blank" style="color:#58a6ff;text-decoration:none;"><?= htmlspecialchars($s['title']) ?></a><?php if ($s['is_recommended']): ?> <span style="background:rgba(227,179,65,.15);color:#e3b341;padding:1px 6px;border-radius:3px;font-size:10px;font-family:'Courier New',monospace;font-weight:700;">★</span><?php endif; ?></td>
                    <td style="padding:10px 12px;font-size:13px;color:#c9d1d9;"><?= htmlspecialchars($s['artist'] ?: '—') ?></td>
                    <td style="padding:10px 12px;font-size:13px;"><a href="profile.php?id=<?= (int)$s['uploader_id'] ?>" target="_blank" style="color:#58a6ff;text-decoration:none;"><?= htmlspecialchars($s['username']) ?></a></td>
                    <td style="padding:10px 12px;font-size:13px;"><?= $s['status']==='published' ? '<span style="color:#3fb950;">● 已发布</span>' : '<span style="color:#f85149;">● 已下架</span>' ?></td>
                    <td style="padding:10px 12px;font-size:13px;color:#c9d1d9;"><?= (int)$s['downloads'] ?></td>
                    <td style="padding:10px 12px;font-size:13px;color:#c9d1d9;"><?= (int)$s['likes'] ?></td>
                    <td style="padding:10px 12px;font-size:11px;color:#6e7681;font-family:'Courier New',monospace;"><?= htmlspecialchars(date('m-d H:i', strtotime($s['created_at']))) ?></td>
                    <td style="padding:10px 12px;">
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <form method="POST" action="admin.php?tab=sheets&sh_filter=<?= urlencode($sh_filter) ?>" style="display:inline;">
                                <input type="hidden" name="sheet_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" name="sheet_action" value="recommend" style="background:transparent;border:1px solid #30363d;color:#8b949e;padding:3px 9px;border-radius:3px;cursor:pointer;font-family:'Courier New',monospace;font-size:11px;"><?= $s['is_recommended'] ? '取消精选' : '★ 精选' ?></button>
                            </form>
                            <?php if ($s['status']==='published'): ?>
                            <form method="POST" action="admin.php?tab=sheets&sh_filter=<?= urlencode($sh_filter) ?>" style="display:inline;">
                                <input type="hidden" name="sheet_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" name="sheet_action" value="reject" style="background:transparent;border:1px solid rgba(248,81,73,.4);color:#f85149;padding:3px 9px;border-radius:3px;cursor:pointer;font-family:'Courier New',monospace;font-size:11px;">下架</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="admin.php?tab=sheets&sh_filter=<?= urlencode($sh_filter) ?>" style="display:inline;">
                                <input type="hidden" name="sheet_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" name="sheet_action" value="restore" style="background:transparent;border:1px solid #30363d;color:#3fb950;padding:3px 9px;border-radius:3px;cursor:pointer;font-family:'Courier New',monospace;font-size:11px;">恢复</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" action="admin.php?tab=sheets&sh_filter=<?= urlencode($sh_filter) ?>" style="display:inline;" onsubmit="return confirm('硬删除该曲谱？文件将一并删除！');">
                                <input type="hidden" name="sheet_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" name="sheet_action" value="delete" style="background:transparent;border:1px solid rgba(248,81,73,.4);color:#f85149;padding:3px 9px;border-radius:3px;cursor:pointer;font-family:'Courier New',monospace;font-size:11px;">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ══════════════ TAB: 客服后台 ══════════════ -->
    <?php elseif ($tab === 'cs'): ?>

    <style>
    .cs-grid{display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start;}
    .cs-section{background:#161b22;border:1px solid #30363d;border-radius:6px;overflow:hidden;}
    .cs-sec-head{padding:12px 16px;border-bottom:1px solid #21262d;display:flex;align-items:center;justify-content:space-between;}
    .cs-sec-head h3{margin:0;font-size:11px;font-weight:700;color:#6e7681;letter-spacing:1.5px;text-transform:uppercase;font-family:"Courier New",monospace;}
    .cs-sec-head h3::before{content:'// ';opacity:.6;}
    .cs-count{font-size:12px;color:#6e7681;font-family:"Courier New",monospace;}
    .cs-section + .cs-section{margin-top:12px;}
    .ticket-item{padding:12px 16px;border-bottom:1px solid #21262d;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:10px;}
    .ticket-item:last-child{border-bottom:none;}
    .ticket-item:hover{background:rgba(255,255,255,.03);}
    .ticket-item.active-item{background:rgba(63,185,80,.05);border-left:2px solid #3fb950;}
    .ti-type{font-size:11px;font-weight:700;padding:2px 8px;border-radius:3px;letter-spacing:.3px;white-space:nowrap;}
    .ti-info{flex:1;min-width:0;}
    .ti-id{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;}
    .ti-desc{font-size:12px;color:#8b949e;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .ti-time{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;white-space:nowrap;}
    .btn-join{font-size:12px;color:#3fb950;border:1px solid rgba(63,185,80,.4);background:none;border-radius:3px;padding:4px 12px;cursor:pointer;font-family:inherit;white-space:nowrap;}
    .btn-join:hover{background:rgba(63,185,80,.1);}
    .empty-list{padding:20px 16px;font-size:12px;color:#6e7681;font-family:"Courier New",monospace;text-align:center;}
    .empty-list::before{content:'// ';}
    .cs-mgmt-input{display:flex;gap:8px;padding:12px 14px;border-bottom:1px solid #21262d;}
    .mid-input-cs{flex:1;background:#0d1117;border:1px solid #30363d;border-radius:4px;color:#e6edf3;font-size:13px;font-family:"Courier New",monospace;padding:7px 10px;outline:none;letter-spacing:1px;}
    .mid-input-cs:focus{border-color:#3fb950;}
    .btn-add-cs{background:#3fb950;color:#fff;border:none;border-radius:4px;padding:0 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
    .agent-item{display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid #21262d;}
    .agent-item:last-child{border-bottom:none;}
    .agent-name{flex:1;font-size:13px;color:#c9d1d9;}
    .agent-mid{font-size:11px;color:#6e7681;font-family:"Courier New",monospace;}
    .btn-remove-cs{font-size:11px;color:#f85149;border:1px solid rgba(248,81,73,.35);background:none;border-radius:3px;padding:3px 10px;cursor:pointer;font-family:inherit;}
    .cs-mgmt-msg{padding:8px 14px;font-size:12px;font-family:"Courier New",monospace;}
    .chat-panel{background:#161b22;border:1px solid #30363d;border-radius:6px;overflow:hidden;}
    .btn-resolve{font-size:12px;color:#f85149;border:1px solid rgba(248,81,73,.4);background:none;border-radius:3px;padding:5px 14px;cursor:pointer;font-family:inherit;white-space:nowrap;}
    .chat-messages{height:480px;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#0d1117;}
    .chat-messages::-webkit-scrollbar{width:4px;}
    .chat-messages::-webkit-scrollbar-thumb{background:#30363d;border-radius:2px;}
    .msg-row-cs{display:flex;align-items:flex-end;gap:8px;}
    .msg-row-cs.mine{flex-direction:row-reverse;}
    .msg-col-cs{display:flex;flex-direction:column;max-width:72%;}
    .msg-bubble-cs{padding:9px 13px;border-radius:6px;font-size:13px;line-height:1.6;word-break:break-word;}
    .msg-row-cs.mine .msg-bubble-cs{background:rgba(167,139,250,.15);border:1px solid rgba(167,139,250,.3);color:#e6edf3;border-radius:6px 6px 0 6px;}
    .msg-row-cs.theirs .msg-bubble-cs{background:#161b22;border:1px solid #30363d;color:#c9d1d9;border-radius:6px 6px 6px 0;}
    .msg-label-cs{font-size:10px;color:#6e7681;margin-bottom:3px;font-family:"Courier New",monospace;}
    .msg-row-cs.mine .msg-label-cs{text-align:right;}
    .msg-time-cs{font-size:10px;color:#6e7681;margin-top:3px;font-family:"Courier New",monospace;}
    .msg-row-cs.mine .msg-time-cs{text-align:right;}
    .chat-input-area{padding:12px 14px;border-top:1px solid #21262d;display:flex;gap:8px;}
    .chat-input-cs{flex:1;background:#0d1117;border:1px solid #30363d;border-radius:4px;color:#e6edf3;font-size:13px;font-family:inherit;padding:9px 12px;outline:none;}
    .chat-input-cs:focus{border-color:#a78bfa;}
    .btn-send-cs{background:#a78bfa;color:#fff;border:none;border-radius:4px;padding:0 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}
    .btn-send-cs:hover{background:#9063f0;}
    .btn-send-cs:disabled{background:#21262d;color:#6e7681;cursor:not-allowed;}
    .privacy-notice{padding:10px 14px;background:rgba(167,139,250,.06);border-bottom:1px solid #21262d;font-size:11px;color:#6e7681;font-family:"Courier New",monospace;}
    .privacy-notice::before{content:'🔒 ';}
    .no-chat{display:flex;align-items:center;justify-content:center;height:300px;flex-direction:column;gap:12px;color:#6e7681;font-family:"Courier New",monospace;font-size:13px;}
    .no-chat::before{content:'// ';}
    .resolved-overlay{padding:60px 20px;text-align:center;}
    .resolved-overlay h3{color:#3fb950;margin:0 0 10px;}
    .resolved-overlay p{color:#8b949e;font-size:13px;}
    @media(max-width:900px){.cs-grid{grid-template-columns:1fr;}}
    </style>

    <div class="cs-grid">
        <div>
            <?php if ($is_owner): ?>
            <div class="cs-section">
                <div class="cs-sec-head"><h3>客服团队管理</h3><span class="cs-count" id="agentCount"><?= count($cs_agents) ?> 人</span></div>
                <div class="cs-mgmt-input">
                    <input class="mid-input-cs" id="addMidInput" type="text" maxlength="8" placeholder="输入用户 MID（8位）">
                    <button class="btn-add-cs" onclick="addCsAgent()">添加客服</button>
                </div>
                <div id="agentMsg" class="cs-mgmt-msg" style="display:none;"></div>
                <div id="agentList">
                    <?php if (empty($cs_agents)): ?>
                        <div class="empty-list" id="agentEmpty">暂无客服人员</div>
                    <?php else: foreach ($cs_agents as $ag): ?>
                        <div class="agent-item" id="ag-<?= $ag['id'] ?>">
                            <div style="flex:1;">
                                <div class="agent-name"><?= htmlspecialchars($ag['username']) ?></div>
                                <div class="agent-mid">MID <?= htmlspecialchars($ag['mid'] ?? '—') ?></div>
                            </div>
                            <button class="btn-remove-cs" onclick="removeCsAgent(<?= $ag['id'] ?>, this)">移除</button>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="cs-section">
                <div class="cs-sec-head"><h3>待接入工单</h3><span class="cs-count" id="pendingCount"><?= count($cs_pending) ?> 个</span></div>
                <div id="pendingList">
                    <?php if (empty($cs_pending)): ?>
                        <div class="empty-list">暂无待处理工单</div>
                    <?php else: foreach ($cs_pending as $t):
                        $tc = $cs_type_colors[$t['type']] ?? '#8b949e';
                        $tl = $cs_issue_types[$t['type']] ?? $t['type'];
                    ?>
                        <div class="ticket-item" id="pt-<?= $t['id'] ?>">
                            <span class="ti-type" style="color:<?= $tc ?>;border:1px solid <?= $tc ?>30;background:<?= $tc ?>15;"><?= $tl ?></span>
                            <div class="ti-info">
                                <div class="ti-id">#<?= $t['id'] ?> · 用户 #<?= $t['id']*7%9973 ?></div>
                                <?php if ($t['description']): ?><div class="ti-desc"><?= htmlspecialchars(mb_substr($t['description'], 0, 40)) ?></div><?php endif; ?>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                                <span class="ti-time"><?= date('H:i', strtotime($t['created_at'])) ?></span>
                                <button class="btn-join" onclick="joinTicket(<?= $t['id'] ?>, this)">接入</button>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="cs-section">
                <div class="cs-sec-head"><h3>我的进行中</h3><span class="cs-count"><?= count($cs_my_active) ?> 个</span></div>
                <div>
                    <?php if (empty($cs_my_active)): ?>
                        <div class="empty-list">暂无进行中会话</div>
                    <?php else: foreach ($cs_my_active as $t):
                        $tc = $cs_type_colors[$t['type']] ?? '#8b949e';
                        $tl = $cs_issue_types[$t['type']] ?? $t['type'];
                        $is_cur = ($cs_active_tid === (int)$t['id']);
                    ?>
                        <div class="ticket-item <?= $is_cur?'active-item':'' ?>" onclick="openChat(<?= $t['id'] ?>)">
                            <span class="ti-type" style="color:<?= $tc ?>;border:1px solid <?= $tc ?>30;background:<?= $tc ?>15;"><?= $tl ?></span>
                            <div class="ti-info">
                                <div class="ti-id">#<?= $t['id'] ?> · 用户 #<?= $t['id']*7%9973 ?></div>
                                <?php if ($t['description']): ?><div class="ti-desc"><?= htmlspecialchars(mb_substr($t['description'], 0, 40)) ?></div><?php endif; ?>
                            </div>
                            <span class="ti-time"><?= date('H:i', strtotime($t['created_at'])) ?></span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div class="chat-panel">
            <?php if ($cs_active_ticket):
                $tc = $cs_type_colors[$cs_active_ticket['type']] ?? '#8b949e';
                $tl = $cs_issue_types[$cs_active_ticket['type']] ?? $cs_active_ticket['type'];
            ?>
                <div class="cs-sec-head">
                    <h3>工单 #<?= $cs_active_ticket['id'] ?> — <span style="color:<?= $tc ?>;"><?= $tl ?></span></h3>
                    <button class="btn-resolve" onclick="resolveTicket(<?= $cs_active_ticket['id'] ?>)">✓ 标记解决</button>
                </div>
                <div class="privacy-notice">隐私保护模式：用户身份已匿名，请勿索取个人信息</div>
                <div class="chat-messages" id="chatBox">
                    <?php foreach ($cs_active_messages as $m): $is_mine = (bool)$m['is_cs']; ?>
                    <div class="msg-row-cs <?= $is_mine?'mine':'theirs' ?>">
                        <div class="msg-col-cs">
                            <div class="msg-label-cs"><?= $m['is_cs'] ? '客服（我）' : '用户 #'.((int)$cs_active_ticket['id']*7%9973) ?></div>
                            <div class="msg-bubble-cs"><?= nl2br(htmlspecialchars($m['content'])) ?></div>
                            <div class="msg-time-cs"><?= date('H:i', strtotime($m['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="chat-input-area" id="inputArea">
                    <input class="chat-input-cs" id="msgInput" type="text" placeholder="回复用户…" onkeydown="if(event.key==='Enter')sendMsg()">
                    <button class="btn-send-cs" id="sendBtn" onclick="sendMsg()">发送</button>
                </div>
            <?php else: ?>
                <div class="no-chat">选择一个进行中的工单开始对话</div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    var ticketId = <?= $cs_active_tid ?>;
    var lastId   = <?= $cs_last_msg_id ?>;
    var anonId   = <?= $cs_active_ticket ? ((int)$cs_active_ticket['id']*7%9973) : 0 ?>;
    var isOwnerCs = <?= $is_owner ? 'true' : 'false' ?>;

    function addCsAgent(){
        var mid = document.getElementById('addMidInput').value.trim();
        if(!/^\d{8}$/.test(mid)){ showAgentMsg('MID 必须是 8 位数字','#d29922'); return; }
        var fd = new FormData(); fd.append('action','add_cs_agent'); fd.append('mid',mid);
        fetch('../actions/cs_action.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            if(d.status==='ok'){
                document.getElementById('addMidInput').value='';
                var empty = document.getElementById('agentEmpty'); if(empty) empty.remove();
                var list = document.getElementById('agentList');
                var div = document.createElement('div');
                div.className='agent-item'; div.id='ag-'+d.user_id;
                div.innerHTML='<div style="flex:1;"><div class="agent-name">'+escHtml(d.username)+'</div><div class="agent-mid">MID '+escHtml(mid)+'</div></div><button class="btn-remove-cs" onclick="removeCsAgent('+d.user_id+', this)">移除</button>';
                list.appendChild(div);
                var cnt = document.getElementById('agentCount');
                if(cnt) cnt.textContent = (parseInt(cnt.textContent)+1)+' 人';
                showAgentMsg('已添加客服：'+escHtml(d.username),'#3fb950');
            } else showAgentMsg(d.msg||'添加失败','#f85149');
        });
    }
    function removeCsAgent(uid, btn){
        if(!confirm('确认移除该客服权限？')) return;
        btn.disabled=true;
        var fd = new FormData(); fd.append('action','remove_cs_agent'); fd.append('target_id',uid);
        fetch('../actions/cs_action.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            if(d.status==='ok'){
                var row=document.getElementById('ag-'+uid); if(row) row.remove();
                var cnt=document.getElementById('agentCount');
                if(cnt){ var n=parseInt(cnt.textContent)-1; cnt.textContent=n+' 人'; }
                var list=document.getElementById('agentList');
                if(list && list.children.length===0) list.innerHTML='<div class="empty-list" id="agentEmpty">暂无客服人员</div>';
            } else btn.disabled=false;
        });
    }
    function showAgentMsg(msg,color){
        var el=document.getElementById('agentMsg'); if(!el) return;
        el.style.color=color; el.textContent=msg; el.style.display='block';
        setTimeout(()=>el.style.display='none',3000);
    }
    function joinTicket(tid, btn){
        btn.disabled=true; btn.textContent='接入中…';
        var fd=new FormData(); fd.append('action','join_ticket'); fd.append('ticket_id',tid);
        fetch('../actions/cs_action.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            if(d.status==='ok') location.href='admin.php?tab=cs&tid='+tid;
            else { btn.disabled=false; btn.textContent='接入'; alert(d.msg||'接入失败'); }
        });
    }
    function openChat(tid){ location.href='admin.php?tab=cs&tid='+tid; }
    function resolveTicket(tid){
        if(!confirm('确认将此工单标记为已解决？对话将结束，不再触发积分补偿。')) return;
        var fd=new FormData(); fd.append('action','resolve_ticket'); fd.append('ticket_id',tid);
        fetch('../actions/cs_action.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            if(d.status==='ok'){
                var box=document.getElementById('chatBox'), ia=document.getElementById('inputArea');
                if(ia) ia.style.display='none';
                if(box){
                    var div=document.createElement('div'); div.className='resolved-overlay';
                    div.innerHTML='<h3>&gt; SESSION_RESOLVED_</h3><p>会话已结束，已通知用户。</p>';
                    box.appendChild(div); box.scrollTop=box.scrollHeight;
                }
            }
        });
    }
    function sendMsg(){
        if(!ticketId) return;
        var input=document.getElementById('msgInput'), content=input.value.trim();
        if(!content) return;
        var btn=document.getElementById('sendBtn'); btn.disabled=true;
        var fd=new FormData(); fd.append('action','send_message'); fd.append('ticket_id',ticketId); fd.append('content',content);
        fetch('../actions/cs_action.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            if(d.status==='ok'){ input.value=''; pollMessages(); }
            btn.disabled=false;
        });
    }
    function appendMessage(m){
        var box=document.getElementById('chatBox'); if(!box) return;
        var isMine = m.is_cs==1;
        var t=new Date(m.created_at.replace(' ','T'));
        var hm=(t.getHours()<10?'0':'')+t.getHours()+':'+(t.getMinutes()<10?'0':'')+t.getMinutes();
        var label=isMine?'客服（我）':'用户 #'+anonId;
        var div=document.createElement('div');
        div.className='msg-row-cs '+(isMine?'mine':'theirs');
        div.innerHTML='<div class="msg-col-cs"><div class="msg-label-cs">'+label+'</div><div class="msg-bubble-cs">'+escHtml(m.content).replace(/\n/g,'<br>')+'</div><div class="msg-time-cs">'+hm+'</div></div>';
        box.appendChild(div); box.scrollTop=box.scrollHeight;
        lastId=Math.max(lastId, parseInt(m.id));
    }
    function escHtml(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function pollMessages(){
        if(!ticketId) return;
        fetch('../actions/cs_action.php?action=poll_messages&ticket_id='+ticketId+'&last_id='+lastId).then(r=>r.json()).then(d=>{
            if(d.status!=='ok') return;
            d.messages.forEach(appendMessage);
            if(d.ticket_status==='resolved'){ var ia=document.getElementById('inputArea'); if(ia) ia.style.display='none'; }
        });
    }
    function pollPending(){
        fetch('../actions/cs_action.php?action=list_pending').then(r=>r.json()).then(d=>{
            if(d.status!=='ok') return;
            var el=document.getElementById('pendingCount'); if(el) el.textContent=d.tickets.length+' 个';
        });
    }
    if(ticketId){
        var box=document.getElementById('chatBox'); if(box) box.scrollTop=box.scrollHeight;
        setInterval(pollMessages, 3000);
    }
    setInterval(pollPending, 8000);
    </script>

    <!-- ══════════════ TAB: 评论查询 ══════════════ -->
    <?php elseif ($tab === 'comments'): ?>

    <?php if($cmt_msg==='deleted'): ?><div class="msg-bar msg-ok">✓ 评论已删除</div><?php endif; ?>

    <div class="search-card">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="comments">
            <div class="search-field" style="max-width:260px;">
                <label>用户 MID</label>
                <input class="mid-input" type="text" name="mid" value="<?= htmlspecialchars($cmt_mid) ?>" placeholder="输入 MID 查询该用户全部评论" maxlength="8">
            </div>
            <button type="submit" class="btn-query">查询评论</button>
        </form>
    </div>

    <?php if($cmt_mid!==''&&!$cmt_user): ?>
        <div class="alert-error">MID「<?= htmlspecialchars($cmt_mid) ?>」不存在</div>
    <?php elseif($cmt_user): ?>
        <!-- 用户信息 -->
        <div style="display:flex;align-items:center;gap:12px;background:#161b22;border:1px solid #30363d;border-radius:6px;padding:12px 16px;margin-bottom:16px;">
            <img src="../uploads/<?= htmlspecialchars($cmt_user['avatar']?:'default.png') ?>" onerror="this.onerror=null;this.src='../uploads/default.png'" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid #30363d;">
            <div>
                <div style="font-size:14px;font-weight:700;color:#e6edf3;"><?= htmlspecialchars($cmt_user['username']) ?> <?= get_role_badge($cmt_user['role']) ?></div>
                <div style="font-size:11px;color:#6e7681;font-family:'Courier New',monospace;">MID: <?= htmlspecialchars($cmt_mid) ?> &nbsp;·&nbsp; 共 <strong style="color:#3fb950;"><?= count($cmt_list) ?></strong> 条评论</div>
            </div>
        </div>

        <?php if(empty($cmt_list)): ?>
        <div class="empty-state">该用户暂无评论</div>
        <?php else: foreach($cmt_list as $c): ?>
        <div class="cmt-card">
            <div class="cmt-body">
                <?php if($c['parent_id']): ?><div style="font-size:11px;color:#6e7681;margin-bottom:4px;font-family:'Courier New',monospace;">↩ 回复评论</div><?php endif; ?>
                <div class="cmt-content"><?= htmlspecialchars($c['content']) ?></div>
                <div class="cmt-meta">
                    <span>帖子：<a href="post.php?id=<?= $c['post_id'] ?>" target="_blank" class="cmt-post-link"><?= htmlspecialchars($c['post_title']) ?></a></span>
                    <span>·</span>
                    <span><?= date('Y-m-d H:i', strtotime($c['created_at'])) ?></span>
                    <?php if($c['likes']>0): ?><span>· 👍 <?= $c['likes'] ?></span><?php endif; ?>
                    <span>· ID:<?= $c['id'] ?></span>
                </div>
            </div>
            <div style="flex-shrink:0;">
                <a href="admin.php?tab=comments&mid=<?= urlencode($cmt_mid) ?>&delete_comment=<?= $c['id'] ?>" class="btn btn-delete btn-sm" onclick="return confirm('删除此评论（含子回复）？')">🗑</a>
            </div>
        </div>
        <?php endforeach; endif; ?>
    <?php endif; ?>

    <!-- ══════════════ TAB: 私信查询 ══════════════ -->
    <?php elseif ($tab === 'messages'): ?>

    <div class="search-card">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="messages">
            <div class="search-field">
                <label>用户 A 的 MID</label>
                <input class="mid-input" type="text" name="mid_a" value="<?= htmlspecialchars($mid_a??'') ?>" placeholder="例：AB12CD34" maxlength="8">
            </div>
            <div class="search-field">
                <label>用户 B 的 MID</label>
                <input class="mid-input" type="text" name="mid_b" value="<?= htmlspecialchars($mid_b??'') ?>" placeholder="例：EF56GH78" maxlength="8">
            </div>
            <button type="submit" class="btn-query">查询记录</button>
        </form>
    </div>

    <?php foreach($msg_errors as $e): ?><div class="alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <?php if($msg_a&&$msg_b&&empty($msg_errors)): ?>
    <div class="user-pair">
        <div class="user-card-sm">
            <img src="../uploads/<?= htmlspecialchars($msg_a['avatar']?:'default.png') ?>" onerror="this.onerror=null;this.src='../uploads/default.png'" alt="">
            <div><div class="user-card-sm-name"><?= htmlspecialchars($msg_a['username']) ?></div><div class="user-card-sm-mid">MID: <?= htmlspecialchars($mid_a) ?></div></div>
        </div>
        <div class="pair-sep">↔</div>
        <div class="user-card-sm">
            <img src="../uploads/<?= htmlspecialchars($msg_b['avatar']?:'default.png') ?>" onerror="this.onerror=null;this.src='../uploads/default.png'" alt="">
            <div><div class="user-card-sm-name"><?= htmlspecialchars($msg_b['username']) ?></div><div class="user-card-sm-mid">MID: <?= htmlspecialchars($mid_b) ?></div></div>
        </div>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;font-size:12px;color:#6e7681;font-family:'Courier New',monospace;">
        <span>共 <strong style="color:#3fb950;"><?= $msg_total ?></strong> 条消息</span>
        <span>第 <?= $mpage ?>/<?= $msg_pages ?> 页</span>
    </div>

    <?php if(empty($msg_list)): ?><div class="empty-state">双方之间暂无私信记录</div>
    <?php else: ?>
    <div class="chat-log">
        <?php $last_date=''; $uid_a_int=(int)$msg_a['id'];
        foreach($msg_list as $m):
            $mdate=date('Y-m-d',strtotime($m['created_at']));
            if($mdate!==$last_date){$last_date=$mdate;?><div class="date-sep"><?= htmlspecialchars($mdate) ?></div><?php }
            $is_right=((int)$m['from_user_id']!==$uid_a_int);
            $av=htmlspecialchars($m['sender_avatar']?:'default.png');
        ?>
        <div class="msg-row <?= $is_right?'right':'left' ?>">
            <img class="msg-avatar" src="../uploads/<?= $av ?>" alt="" onerror="this.onerror=null;this.src='../uploads/default.png'">
            <div class="msg-body">
                <div class="msg-meta"><span class="msg-sender"><?= htmlspecialchars($m['sender_name']) ?></span><?= date('H:i',strtotime($m['created_at'])) ?></div>
                <div class="msg-bubble"><?= nl2br(htmlspecialchars($m['content'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    if($msg_pages>1):
        $qs=http_build_query(['tab'=>'messages','mid_a'=>$mid_a,'mid_b'=>$mid_b]);
        echo '<div class="pag">';
        if($mpage>1) echo '<a href="admin.php?'.$qs.'&mpage='.($mpage-1).'">‹ 上一页</a>';
        for($i=1;$i<=$msg_pages;$i++) echo '<a href="admin.php?'.$qs.'&mpage='.$i.'" '.($i===$mpage?'class="cur"':'').'>'.$i.'</a>';
        if($mpage<$msg_pages) echo '<a href="admin.php?'.$qs.'&mpage='.($mpage+1).'">下一页 ›</a>';
        echo '</div>';
    endif; ?>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ══════════════ TAB: AI 配置 ══════════════ -->
    <?php elseif ($tab === 'cocktails'): ?>

    <div style="display:flex;gap:8px;margin-bottom:18px;border-bottom:1px solid #21262d;padding-bottom:0;">
        <a href="admin.php?tab=cocktails&sub=cocktails" style="padding:8px 16px;font-size:13px;text-decoration:none;border-bottom:2px solid <?= $ck_sub==='cocktails'?'#3fb950':'transparent' ?>;color:<?= $ck_sub==='cocktails'?'#3fb950':'#8b949e' ?>;font-family:'Courier New',monospace;">🍸 鸡尾酒（<?= count($all_cocktails) ?>）</a>
        <a href="admin.php?tab=cocktails&sub=ingredients" style="padding:8px 16px;font-size:13px;text-decoration:none;border-bottom:2px solid <?= $ck_sub==='ingredients'?'#3fb950':'transparent' ?>;color:<?= $ck_sub==='ingredients'?'#3fb950':'#8b949e' ?>;font-family:'Courier New',monospace;">🧂 食材（<?= count($all_ingredients) ?>）</a>
    </div>

    <?php if ($ck_msg): ?>
    <div class="msg-bar msg-ok" style="margin-bottom:14px;">
        <?php
        $msgs = ['ing_new'=>'✓ 食材已添加','ing_edit'=>'✓ 食材已更新','ing_del'=>'✓ 食材已删除','ing_toggle'=>'✓ 食材状态已切换',
                 'ck_new'=>'✓ 鸡尾酒已添加','ck_edit'=>'✓ 鸡尾酒已更新','ck_del'=>'✓ 鸡尾酒已删除','ck_toggle'=>'✓ 鸡尾酒状态已切换'];
        echo $msgs[$ck_msg] ?? '✓ 操作完成';
        ?>
    </div>
    <?php endif; ?>
    <?php if ($ck_form_err): ?><div class="msg-bar msg-err" style="margin-bottom:14px;">✗ <?= htmlspecialchars($ck_form_err) ?></div><?php endif; ?>

    <?php if ($ck_sub === 'ingredients'):
        $ck_edit_ing = null;
        if (isset($_GET['edit_ing']) && is_numeric($_GET['edit_ing'])) {
            $r = $conn->query("SELECT * FROM ingredients WHERE id=".(int)$_GET['edit_ing']);
            $ck_edit_ing = ($r && $r->num_rows>0) ? $r->fetch_assoc() : null;
        }
    ?>
    <div style="display:grid;grid-template-columns:320px 1fr;gap:18px;">
        <!-- 食材表单 -->
        <div class="ap-card">
            <div style="padding:10px 14px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
                <?= $ck_edit_ing ? '编辑食材' : '新增食材' ?>
            </div>
            <form method="POST" action="admin.php?tab=cocktails&sub=ingredients" style="padding:14px;">
                <input type="hidden" name="ing_form" value="1">
                <?php if ($ck_edit_ing): ?><input type="hidden" name="ing_edit_id" value="<?= (int)$ck_edit_ing['id'] ?>"><?php endif; ?>
                <div style="margin-bottom:10px;">
                    <label style="display:block;font-size:11px;color:#8b949e;margin-bottom:5px;font-family:'Courier New',monospace;">名称</label>
                    <input type="text" name="ing_name" value="<?= htmlspecialchars($ck_edit_ing['name'] ?? '') ?>" required maxlength="50"
                        style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:4px;font-size:13px;outline:none;box-sizing:border-box;">
                </div>
                <div style="margin-bottom:10px;">
                    <label style="display:block;font-size:11px;color:#8b949e;margin-bottom:5px;font-family:'Courier New',monospace;">类型</label>
                    <select name="ing_type" style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:4px;font-size:13px;outline:none;">
                        <?php foreach ($ingredient_types as $k => $v):
                            $sel = (($ck_edit_ing['type'] ?? 'other') === $k) ? 'selected' : '';
                        ?><option value="<?= $k ?>" <?= $sel ?>><?= $v['label'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#8b949e;margin-bottom:12px;cursor:pointer;">
                    <input type="checkbox" name="ing_active" value="1" <?= (!$ck_edit_ing || (int)$ck_edit_ing['is_active']) ? 'checked' : '' ?>> 启用
                </label>
                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-green"><?= $ck_edit_ing ? '保存' : '添加' ?></button>
                    <?php if ($ck_edit_ing): ?><a href="admin.php?tab=cocktails&sub=ingredients" class="btn">取消</a><?php endif; ?>
                </div>
            </form>
        </div>

        <!-- 食材列表 -->
        <div class="ap-card">
            <script>function adminFilter(i,c){var k=i.value.trim().toLowerCase();document.querySelectorAll('.'+c).forEach(function(e){e.style.display=(!k||(e.dataset.n||'').indexOf(k)>=0)?'':'none';});}</script>
            <div style="padding:10px 14px;border-bottom:1px solid #30363d;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">食材库（共 <?= count($all_ingredients) ?>）</span>
                <input type="text" oninput="adminFilter(this,'ai-item')" placeholder="🔍 搜食材" style="width:150px;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:5px 10px;border-radius:4px;font-size:12px;outline:none;">
            </div>
            <?php if (!$all_ingredients): ?>
            <div style="padding:30px;text-align:center;color:#6e7681;font-size:13px;">还没有食材，请在左侧添加</div>
            <?php else: ?>
            <?php $grouped = []; foreach ($all_ingredients as $ig) $grouped[$ig['type']][] = $ig; ?>
            <div style="padding:14px;">
                <?php foreach ($ingredient_types as $tk => $tv):
                    if (empty($grouped[$tk])) continue; ?>
                <div style="margin-bottom:14px;">
                    <div style="font-size:11px;color:<?= $tv['color'] ?>;font-family:'Courier New',monospace;letter-spacing:1px;margin-bottom:6px;text-transform:uppercase;">// <?= $tv['label'] ?></div>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach ($grouped[$tk] as $ig): ?>
                        <div class="ai-item" data-n="<?= htmlspecialchars(mb_strtolower($ig['name'])) ?>" style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;background:#0d1117;border:1px solid <?= $tv['color'] ?>33;border-radius:14px;font-size:12px;color:<?= (int)$ig['is_active']?'#e6edf3':'#6e7681' ?>;opacity:<?= (int)$ig['is_active']?'1':'.55' ?>;">
                            <?= htmlspecialchars($ig['name']) ?>
                            <a href="admin.php?tab=cocktails&sub=ingredients&edit_ing=<?= (int)$ig['id'] ?>" title="编辑" style="color:#58a6ff;text-decoration:none;font-size:11px;">✎</a>
                            <a href="admin.php?tab=cocktails&sub=ingredients&toggle_ing=<?= (int)$ig['id'] ?>" title="<?= (int)$ig['is_active']?'停用':'启用' ?>" style="color:#d29922;text-decoration:none;font-size:11px;"><?= (int)$ig['is_active']?'◐':'◑' ?></a>
                            <a href="admin.php?tab=cocktails&sub=ingredients&delete_ing=<?= (int)$ig['id'] ?>" onclick="return confirm('确认删除？关联配方会失去该材料');" title="删除" style="color:#f85149;text-decoration:none;font-size:11px;">✕</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: /* sub=cocktails */ ?>

    <div style="display:grid;grid-template-columns:420px 1fr;gap:18px;">
        <!-- 鸡尾酒表单 -->
        <div class="ap-card">
            <div style="padding:10px 14px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
                <?= $ck_edit_cocktail ? '编辑配方' : '新增配方' ?>
            </div>
            <form method="POST" action="admin.php?tab=cocktails&sub=cocktails" enctype="multipart/form-data" style="padding:14px;">
                <input type="hidden" name="ck_form" value="1">
                <?php if ($ck_edit_cocktail): ?><input type="hidden" name="ck_edit_id" value="<?= (int)$ck_edit_cocktail['id'] ?>"><?php endif; ?>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label style="display:block;font-size:11px;color:#8b949e;margin-bottom:5px;font-family:'Courier New',monospace;">中文名</label>
                        <input type="text" name="ck_name" required maxlength="80" value="<?= htmlspecialchars($ck_edit_cocktail['name'] ?? '') ?>"
                            style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:4px;font-size:13px;outline:none;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#8b949e;margin-bottom:5px;font-family:'Courier New',monospace;">英文名</label>
                        <input type="text" name="ck_name_en" maxlength="80" value="<?= htmlspecialchars($ck_edit_cocktail['name_en'] ?? '') ?>"
                            style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:4px;font-size:13px;outline:none;box-sizing:border-box;">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label style="display:block;font-size:11px;color:#8b949e;margin-bottom:5px;font-family:'Courier New',monospace;">酒杯</label>
                        <?php
                        $g_cur = $ck_edit_cocktail['glass'] ?? '';
                        preg_match('/^(.*?)[（(]?\s*([\d.]+)\s*ml[）)]?\s*$/u', $g_cur, $gm);
                        $g_cur_type = $gm ? trim($gm[1]) : $g_cur;
                        $g_cur_ml   = $gm[2] ?? '';
                        $GLASS_TYPES = ['马天尼杯','古典杯','岩石杯','高球杯','柯林杯','香槟杯','葡萄酒杯','烈酒杯','子弹杯','飓风杯','玛格丽特杯','铜杯','库佩杯','海波杯','宴球杯','果汁杯','热饮杯'];
                        $ist = "background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:4px;font-size:13px;outline:none;box-sizing:border-box;";
                        ?>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <select name="ck_glass_type" style="flex:1;<?= $ist ?>">
                                <option value="">选杯型</option>
                                <?php foreach ($GLASS_TYPES as $gt): ?><option<?= $gt===$g_cur_type?' selected':'' ?>><?= $gt ?></option><?php endforeach; ?>
                                <?php if ($g_cur_type !== '' && !in_array($g_cur_type, $GLASS_TYPES, true)): ?><option selected><?= htmlspecialchars($g_cur_type) ?></option><?php endif; ?>
                            </select>
                            <input type="text" name="ck_glass_ml" inputmode="decimal" placeholder="容量" value="<?= htmlspecialchars($g_cur_ml) ?>" style="width:64px;<?= $ist ?>">
                            <span style="color:#8b949e;font-size:12px;">ml</span>
                        </div>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#8b949e;margin-bottom:5px;font-family:'Courier New',monospace;">调法</label>
                        <select name="ck_method" style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:4px;font-size:13px;outline:none;">
                            <?php foreach ($cocktail_methods_map as $mk => $mv):
                                $sel = (($ck_edit_cocktail['method'] ?? 'shake') === $mk) ? 'selected' : '';
                            ?><option value="<?= $mk ?>" <?= $sel ?>><?= $mv ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label style="display:block;font-size:11px;color:#8b949e;margin-bottom:5px;font-family:'Courier New',monospace;">装饰</label>
                        <input type="text" name="ck_garnish" maxlength="120" placeholder="例：柠檬皮" value="<?= htmlspecialchars($ck_edit_cocktail['garnish'] ?? '') ?>"
                            style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:4px;font-size:13px;outline:none;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#8b949e;margin-bottom:5px;font-family:'Courier New',monospace;">酒精度</label>
                        <?php
                        $a_cur = $ck_edit_cocktail['abv_hint'] ?? '';
                        preg_match('/^(\D*?)\s*([\d.]+)\s*%?\s*$/u', $a_cur, $am);
                        $a_cur_preset = $am ? trim($am[1]) : $a_cur;
                        $a_cur_num    = $am[2] ?? '';
                        $ABV_PRESETS = ['无酒精','低度','低到中度','中度','中到高度','高度'];
                        ?>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <select name="ck_abv_preset" style="flex:1;<?= $ist ?? 'background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:4px;font-size:13px;outline:none;box-sizing:border-box;' ?>">
                                <option value="">选度数</option>
                                <?php foreach ($ABV_PRESETS as $ap): ?><option<?= $ap===$a_cur_preset?' selected':'' ?>><?= $ap ?></option><?php endforeach; ?>
                                <?php if ($a_cur_preset !== '' && !in_array($a_cur_preset, $ABV_PRESETS, true)): ?><option selected><?= htmlspecialchars($a_cur_preset) ?></option><?php endif; ?>
                            </select>
                            <input type="text" name="ck_abv_num" inputmode="decimal" placeholder="数值" value="<?= htmlspecialchars($a_cur_num) ?>" style="width:56px;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:4px;font-size:13px;outline:none;box-sizing:border-box;">
                            <span style="color:#8b949e;font-size:12px;">%</span>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:10px;">
                    <label style="display:block;font-size:11px;color:#8b949e;margin-bottom:5px;font-family:'Courier New',monospace;">图片（≤5MB，可选）</label>
                    <input type="file" name="ck_image" accept="image/*" onchange="prevCk(this)" style="font-size:12px;color:#8b949e;width:100%;">
                    <img id="ck-preview" src="<?= !empty($ck_edit_cocktail['image']) ? '../'.htmlspecialchars($ck_edit_cocktail['image']) : '' ?>"
                         style="<?= !empty($ck_edit_cocktail['image']) ? '' : 'display:none;' ?>max-width:100%;margin-top:8px;border-radius:4px;border:1px solid #30363d;">
                </div>

                <!-- 配方材料 -->
                <div style="margin-bottom:10px;border:1px dashed #30363d;border-radius:4px;padding:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span style="font-size:11px;color:#8b949e;font-family:'Courier New',monospace;letter-spacing:.8px;">// 所需材料</span>
                        <button type="button" onclick="ckAddIng()" class="btn btn-green" style="padding:3px 10px;font-size:11px;">+ 添加</button>
                    </div>
                    <div id="ck-ing-rows"></div>
                </div>

                <!-- 调制步骤（多步） -->
                <div style="margin-bottom:10px;border:1px dashed #30363d;border-radius:4px;padding:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span style="font-size:11px;color:#8b949e;font-family:'Courier New',monospace;letter-spacing:.8px;">// 调制步骤</span>
                        <button type="button" onclick="ckAddStep()" class="btn btn-green" style="padding:3px 10px;font-size:11px;">+ 添加步骤</button>
                    </div>
                    <div id="ck-step-rows"></div>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#8b949e;cursor:pointer;">
                        <input type="checkbox" name="ck_active" value="1" <?= (!$ck_edit_cocktail || (int)$ck_edit_cocktail['is_active']) ? 'checked' : '' ?>> 启用（在调酒页可见）
                    </label>
                </div>

                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-green"><?= $ck_edit_cocktail ? '保存' : '添加' ?></button>
                    <?php if ($ck_edit_cocktail): ?><a href="admin.php?tab=cocktails&sub=cocktails" class="btn">取消</a><?php endif; ?>
                </div>
            </form>
        </div>

        <!-- 鸡尾酒列表 -->
        <div class="ap-card">
            <div style="padding:10px 14px;border-bottom:1px solid #30363d;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">配方库（共 <?= count($all_cocktails) ?>）</span>
                <input type="text" oninput="adminFilter(this,'ac-item')" placeholder="🔍 搜配方（中/英文）" style="width:190px;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:5px 10px;border-radius:4px;font-size:12px;outline:none;">
            </div>
            <?php if (!$all_cocktails): ?>
            <div style="padding:30px;text-align:center;color:#6e7681;font-size:13px;">还没有鸡尾酒，请在左侧添加</div>
            <?php else: ?>
            <div style="padding:14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;">
                <?php foreach ($all_cocktails as $ck): ?>
                <div class="ac-item" data-n="<?= htmlspecialchars(mb_strtolower($ck['name'].' '.($ck['name_en']??''))) ?>" style="background:#0d1117;border:1px solid #30363d;border-radius:6px;overflow:hidden;opacity:<?= (int)$ck['is_active']?'1':'.55' ?>;">
                    <?php if (!empty($ck['image'])): ?>
                    <img src="../<?= htmlspecialchars($ck['image']) ?>" style="width:100%;height:120px;object-fit:cover;display:block;">
                    <?php else: ?>
                    <div style="width:100%;height:120px;background:linear-gradient(135deg,#21262d,#161b22);display:flex;align-items:center;justify-content:center;font-size:32px;color:#30363d;">🍸</div>
                    <?php endif; ?>
                    <div style="padding:10px;">
                        <div style="font-size:13px;font-weight:700;color:#e6edf3;margin-bottom:2px;"><?= htmlspecialchars($ck['name']) ?></div>
                        <div style="font-size:10px;color:#6e7681;font-family:'Courier New',monospace;margin-bottom:6px;"><?= htmlspecialchars($ck['name_en'] ?: '—') ?> · <?= (int)$ck['ing_count'] ?> 材料</div>
                        <div style="display:flex;gap:6px;font-size:11px;">
                            <a href="admin.php?tab=cocktails&sub=cocktails&edit_ck=<?= (int)$ck['id'] ?>" style="color:#58a6ff;text-decoration:none;">✎ 编辑</a>
                            <a href="admin.php?tab=cocktails&sub=cocktails&toggle_ck=<?= (int)$ck['id'] ?>" style="color:#d29922;text-decoration:none;"><?= (int)$ck['is_active']?'◐ 隐藏':'◑ 启用' ?></a>
                            <a href="admin.php?tab=cocktails&sub=cocktails&delete_ck=<?= (int)$ck['id'] ?>" onclick="return confirm('确认删除「<?= htmlspecialchars($ck['name'], ENT_QUOTES) ?>」？');" style="color:#f85149;text-decoration:none;">✕ 删除</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // 食材列表（用于材料行下拉）
    const _allIngs = <?= json_encode(array_map(function($i) use ($ingredient_types){
        return ['id'=>(int)$i['id'],'name'=>$i['name'],'type'=>$i['type'],'label'=>$ingredient_types[$i['type']]['label'] ?? '其他'];
    }, $all_ingredients), JSON_UNESCAPED_UNICODE) ?>;
    const _editIngs = <?= json_encode($ck_edit_cocktail ? ($ck_edit_cocktail['ings'] ?? []) : [], JSON_UNESCAPED_UNICODE) ?>;
    const _editSteps = <?= json_encode($ck_edit_cocktail ? ($ck_edit_cocktail['steps'] ?? []) : [], JSON_UNESCAPED_UNICODE) ?>;
    function ckBuildIngOptions(selectedId){
        let html = '<option value="">— 选择食材 —</option>';
        // 按类型分组
        const groups = {};
        _allIngs.forEach(ig => { (groups[ig.label] = groups[ig.label] || []).push(ig); });
        Object.keys(groups).forEach(label => {
            html += '<optgroup label="'+label+'">';
            groups[label].forEach(ig => {
                html += '<option value="'+ig.id+'"'+(parseInt(selectedId)===ig.id?' selected':'')+'>'+ig.name+'</option>';
            });
            html += '</optgroup>';
        });
        return html;
    }
    const CK_UNITS = ['ml','cl','oz','块','片','滴','个','茶匙','吧匙','满杯','适量','少许','dash'];
    function ckUnitOptions(sel){
        let h = '<option value="">单位</option>';
        CK_UNITS.forEach(u => h += `<option value="${u}"${u===sel?' selected':''}>${u}</option>`);
        if (sel && !CK_UNITS.includes(sel)) h += `<option value="${sel}" selected>${sel}</option>`;
        return h;
    }
    function ckAddIng(selId, amount){
        const wrap = document.getElementById('ck-ing-rows');
        const row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:6px;margin-bottom:6px;';
        const m = String(amount||'').match(/^\s*([\d.]*)\s*(.*?)\s*$/);
        const num = m ? m[1] : '', unit = m ? m[2] : '';
        row.innerHTML = `
            <select name="ck_ing_id[]" style="flex:1;min-width:0;background:#161b22;border:1px solid #30363d;color:#e6edf3;padding:5px 8px;border-radius:4px;font-size:12px;outline:none;">${ckBuildIngOptions(selId)}</select>
            <input type="text" name="ck_ing_num[]" inputmode="decimal" placeholder="量" value="${num.replace(/"/g,'&quot;')}" style="width:56px;background:#161b22;border:1px solid #30363d;color:#e6edf3;padding:5px 8px;border-radius:4px;font-size:12px;outline:none;">
            <select name="ck_ing_unit[]" style="width:84px;background:#161b22;border:1px solid #30363d;color:#e6edf3;padding:5px 6px;border-radius:4px;font-size:12px;outline:none;">${ckUnitOptions(unit)}</select>
            <button type="button" onclick="this.parentElement.remove()" style="background:transparent;border:1px solid #30363d;color:#f85149;padding:0 8px;border-radius:4px;cursor:pointer;font-size:12px;">✕</button>
        `;
        wrap.appendChild(row);
    }
    // 步骤行（自动增高 textarea）
    function ckEsc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function ckAutoGrow(el){ el.style.height='auto'; el.style.height=(el.scrollHeight+2)+'px'; }
    function ckAddStep(content){
        const wrap = document.getElementById('ck-step-rows');
        const row = document.createElement('div');
        row.className = 'ck-step-row';
        row.style.cssText = 'display:flex;gap:6px;margin-bottom:6px;align-items:flex-start;';
        const idx = wrap.children.length + 1;
        row.innerHTML = `
            <span class="ck-step-num" style="width:22px;text-align:center;color:#3fb950;font-family:'Courier New',monospace;font-size:12px;flex-shrink:0;padding-top:7px;">${idx}.</span>
            <textarea name="ck_step[]" maxlength="500" rows="1" oninput="ckAutoGrow(this)" placeholder="例：杯中放入薄荷叶与糖，轻捣释香" style="flex:1;min-width:0;background:#161b22;border:1px solid #30363d;color:#e6edf3;padding:6px 8px;border-radius:4px;font-size:12px;outline:none;resize:none;overflow:hidden;line-height:1.5;font-family:inherit;">${ckEsc(content)}</textarea>
            <button type="button" onclick="ckRemoveStep(this)" style="background:transparent;border:1px solid #30363d;color:#f85149;padding:0 8px;border-radius:4px;cursor:pointer;font-size:12px;flex-shrink:0;margin-top:3px;">✕</button>
        `;
        wrap.appendChild(row);
        const ta = row.querySelector('textarea'); if (ta) ckAutoGrow(ta);
    }
    function ckRemoveStep(btn){
        btn.parentElement.remove();
        ckRenumberSteps();
    }
    function ckRenumberSteps(){
        document.querySelectorAll('#ck-step-rows .ck-step-row .ck-step-num').forEach((el,i)=>{ el.textContent = (i+1) + '.'; });
    }

    // 编辑时回填
    if (_editIngs.length) {
        _editIngs.forEach(r => ckAddIng(r.ingredient_id, r.amount));
    } else {
        ckAddIng();
    }
    if (_editSteps.length) {
        _editSteps.forEach(s => ckAddStep(s));
    } else {
        ckAddStep();
    }
    function prevCk(input){
        const p=document.getElementById('ck-preview');
        if(input.files&&input.files[0]){
            const r=new FileReader(); r.onload=ev=>{p.src=ev.target.result;p.style.display='block';};
            r.readAsDataURL(input.files[0]);
        }
    }
    </script>

    <?php endif; /* end sub */ ?>

    <?php elseif ($tab === 'ai'):
        $ds_url   = get_setting($conn, 'deepseek_base_url', 'https://api.deepseek.com');
        $ds_model = get_setting($conn, 'deepseek_model',    'deepseek-chat');
        $ds_key   = get_setting($conn, 'deepseek_api_key',  '');
    ?>

    <?php if ($ai_msg === 'saved'): ?><div class="msg-bar msg-ok">✓ AI 配置已保存</div><?php endif; ?>
    <?php if ($ai_msg === 'owcn_saved'): ?><div class="msg-bar msg-ok">✓ 守望国服凭证已保存</div><?php endif; ?>

    <div class="ap-card" style="margin-bottom:18px;">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
            DeepSeek
        </div>
        <div style="padding:18px;">
            <p style="margin:0 0 14px;color:#8b949e;font-size:12px;line-height:1.7;font-family:'Courier New',monospace;">
                ⓘ 用于战绩分析（<a href="../pages/ow_analyzer.php" style="color:#58a6ff;">/pages/ow_analyzer.php</a>）等需要调用 LLM 的功能。<br>
                ⓘ 在 <a href="https://platform.deepseek.com" target="_blank" style="color:#58a6ff;">platform.deepseek.com</a> 申请 API key。
            </p>
            <form method="POST" action="admin.php?tab=ai">
                <input type="hidden" name="save_ai" value="1">

                <div class="form-group" style="margin-bottom:12px;">
                    <label style="display:block;font-size:11px;color:#8b949e;font-family:'Courier New',monospace;letter-spacing:.5px;margin-bottom:6px;">API 入口（Base URL）</label>
                    <input type="text" name="deepseek_base_url" value="<?= htmlspecialchars($ds_url) ?>" placeholder="https://api.deepseek.com"
                        style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:8px 10px;border-radius:4px;font-size:13px;font-family:'Courier New',monospace;outline:none;box-sizing:border-box;">
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label style="display:block;font-size:11px;color:#8b949e;font-family:'Courier New',monospace;letter-spacing:.5px;margin-bottom:6px;">默认模型</label>
                    <input type="text" name="deepseek_model" value="<?= htmlspecialchars($ds_model) ?>" placeholder="deepseek-chat"
                        style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:8px 10px;border-radius:4px;font-size:13px;font-family:'Courier New',monospace;outline:none;box-sizing:border-box;">
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block;font-size:11px;color:#8b949e;font-family:'Courier New',monospace;letter-spacing:.5px;margin-bottom:6px;">
                        API Key
                        <?php if ($ds_key !== ''): ?><span style="color:#3fb950;">（当前：<?= htmlspecialchars(mask_secret($ds_key)) ?>）</span>
                        <?php else: ?><span style="color:#f0883e;">（未配置）</span><?php endif; ?>
                    </label>
                    <input type="password" name="deepseek_api_key" value="" placeholder="<?= $ds_key !== '' ? '留空则不修改现有 key' : '粘贴你的 sk-... key' ?>" autocomplete="off"
                        style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:8px 10px;border-radius:4px;font-size:13px;font-family:'Courier New',monospace;outline:none;box-sizing:border-box;">
                </div>

                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="submit" class="btn btn-green">保存配置</button>
                    <?php if ($ds_key !== ''): ?>
                    <button type="submit" name="clear_key" value="1" class="btn btn-danger" onclick="return confirm('确认清空当前 DeepSeek API Key？');">清空 Key</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php
        $ow_tok  = get_setting($conn, 'ow_ds163_token',   '');
        $ow_role = get_setting($conn, 'ow_ds163_roleid',  '');
        $ow_uid  = get_setting($conn, 'ow_ds163_uid',     '');
        $ow_dev  = get_setting($conn, 'ow_ds163_deviceid','');
        $ow_xsrf = get_setting($conn, 'ow_ds163_xsrf',    '');
        $ow_sess = get_setting($conn, 'ow_ds163_ntes_sess','');
        $ow_dts  = get_setting($conn, 'ow_ds163_dts',     '2026');
        $ow_srv  = get_setting($conn, 'ow_ds163_server',  '1');
        $owIst   = "width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:8px 10px;border-radius:4px;font-size:13px;font-family:'Courier New',monospace;outline:none;box-sizing:border-box;";
        $owLst   = "display:block;font-size:11px;color:#8b949e;font-family:'Courier New',monospace;letter-spacing:.5px;margin-bottom:6px;";
    ?>
    <div class="ap-card" style="margin-bottom:18px;">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">
            守望先锋国服（网易大神）
        </div>
        <div style="padding:18px;">
            <p style="margin:0 0 14px;color:#8b949e;font-size:12px;line-height:1.7;font-family:'Courier New',monospace;">
                ⓘ 用于 <a href="../pages/ow_analyzer.php" style="color:#58a6ff;">国服战绩查询</a>（直连 datamsapi.ds.163.com）。这是一份“服务账号”登录态，配一次即可查任意人。<br>
                ⓘ 抓法：浏览器登录 <code style="color:#e3b341;">act.ds.163.com/f0834ac50394246e</code> → F12 Network 找 <code style="color:#e3b341;">datamsapi.ds.163.com</code> 的请求 → 复制请求头里的 <code style="color:#e3b341;">gl-bigdata-auth-token</code>(=token)、<code style="color:#e3b341;">gl-bigdata-role-id</code>(=roleId)、<code style="color:#e3b341;">gl-uid</code>、<code style="color:#e3b341;">gl-deviceid</code>、<code style="color:#e3b341;">gl-x-xsrf-token</code>。<br>
                ⚠ token 会过期，过期后重新抓一份覆盖即可。
            </p>
            <form method="POST" action="admin.php?tab=ai">
                <input type="hidden" name="save_owcn" value="1">
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="<?= $owLst ?>">token（登录态）
                        <?php if ($ow_tok !== ''): ?><span style="color:#3fb950;">（当前：<?= htmlspecialchars(mask_secret($ow_tok)) ?>）</span>
                        <?php else: ?><span style="color:#f0883e;">（未配置）</span><?php endif; ?>
                    </label>
                    <input type="password" name="ow_ds163_token" value="" placeholder="<?= $ow_tok !== '' ? '留空则不修改' : 'd94e563...' ?>" autocomplete="off" style="<?= $owIst ?>">
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="<?= $owLst ?>">roleId</label>
                    <input type="text" name="ow_ds163_roleid" value="<?= htmlspecialchars($ow_role) ?>" placeholder="672951967" style="<?= $owIst ?>">
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="<?= $owLst ?>">gl-uid（GOD_UUID）</label>
                    <input type="text" name="ow_ds163_uid" value="<?= htmlspecialchars($ow_uid) ?>" style="<?= $owIst ?>">
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="<?= $owLst ?>">gl-deviceid</label>
                    <input type="text" name="ow_ds163_deviceid" value="<?= htmlspecialchars($ow_dev) ?>" style="<?= $owIst ?>">
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="<?= $owLst ?>">gl-x-xsrf-token</label>
                    <input type="text" name="ow_ds163_xsrf" value="<?= htmlspecialchars($ow_xsrf) ?>" style="<?= $owIst ?>">
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="<?= $owLst ?>">NTES_YD_SESS（查别人必需，会过期需跟着更新）</label>
                    <textarea name="ow_ds163_ntes_sess" rows="2" style="<?= $owIst ?>resize:vertical;word-break:break-all;"><?= htmlspecialchars($ow_sess) ?></textarea>
                </div>
                <div style="display:flex;gap:10px;">
                    <div class="form-group" style="margin-bottom:14px;flex:1;">
                        <label style="<?= $owLst ?>">dts</label>
                        <input type="text" name="ow_ds163_dts" value="<?= htmlspecialchars($ow_dts) ?>" placeholder="2026" style="<?= $owIst ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;flex:1;">
                        <label style="<?= $owLst ?>">server</label>
                        <input type="text" name="ow_ds163_server" value="<?= htmlspecialchars($ow_srv) ?>" placeholder="1" style="<?= $owIst ?>">
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="submit" class="btn btn-green">保存凭证</button>
                    <?php if ($ow_tok !== ''): ?>
                    <button type="submit" name="ow_clear_token" value="1" class="btn btn-danger" onclick="return confirm('确认清空 token？');">清空 token</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'sidebar'):
        $sb_groups = get_sidebar($conn);
        $sbI = "background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 9px;border-radius:4px;font-size:13px;font-family:'Courier New',monospace;outline:none;box-sizing:border-box;";
    ?>
    <?php if (($_GET['sb_msg'] ?? '') === 'ok'): ?><div class="msg-bar msg-ok">✓ 侧边栏已更新</div><?php endif; ?>

    <div class="ap-card" style="margin-bottom:18px;">
        <div style="padding:12px 16px;border-bottom:1px solid #30363d;font-size:12px;font-weight:700;color:#6e7681;letter-spacing:1.2px;text-transform:uppercase;font-family:'Courier New',monospace;">新增分类</div>
        <div style="padding:16px;">
            <p style="margin:0 0 12px;color:#8b949e;font-size:12px;line-height:1.7;font-family:'Courier New',monospace;">ⓘ 管理网站左上角 ☰ 展开的侧边栏。分类下挂若干快捷入口。<br>ⓘ 链接填站内相对路径，如 <code style="color:#e3b341;">bartender.php</code> 或 <code style="color:#e3b341;">pages/ow_analyzer.php</code>（自动适配子目录）。图标填 1 个 emoji/字符。</p>
            <form method="POST" action="admin.php?tab=sidebar" style="display:flex;gap:8px;">
                <input type="hidden" name="sb_action" value="add_group">
                <input type="text" name="gname" maxlength="50" placeholder="分类名，如：社区" required style="<?= $sbI ?>flex:1;">
                <button type="submit" class="btn btn-green">+ 加分类</button>
            </form>
        </div>
    </div>

    <?php foreach ($sb_groups as $g): ?>
    <div class="ap-card" style="margin-bottom:14px;">
        <div style="padding:10px 14px;border-bottom:1px solid #30363d;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <form method="POST" action="admin.php?tab=sidebar" style="display:flex;gap:6px;align-items:center;flex:1;min-width:200px;">
                <input type="hidden" name="sb_action" value="rename_group">
                <input type="hidden" name="gid" value="<?= (int)$g['id'] ?>">
                <input type="text" name="gname" value="<?= htmlspecialchars($g['name']) ?>" maxlength="50" style="<?= $sbI ?>font-weight:700;flex:1;">
                <button type="submit" class="btn" style="font-size:12px;">改名</button>
            </form>
            <form method="POST" action="admin.php?tab=sidebar" onsubmit="return confirm('删除分类「<?= htmlspecialchars($g['name']) ?>」及其下所有链接？');">
                <input type="hidden" name="sb_action" value="del_group">
                <input type="hidden" name="gid" value="<?= (int)$g['id'] ?>">
                <button type="submit" class="btn btn-danger" style="font-size:12px;">删分类</button>
            </form>
        </div>
        <div style="padding:12px 14px;">
            <?php if (empty($g['links'])): ?>
                <p style="margin:0 0 10px;color:#6e7681;font-size:12px;">（暂无链接）</p>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;margin-bottom:10px;">
                <?php foreach ($g['links'] as $l): ?>
                <tr style="border-bottom:1px solid #21262d;">
                    <td style="padding:6px 8px;width:30px;text-align:center;font-size:15px;"><?= htmlspecialchars($l['icon']) ?></td>
                    <td style="padding:6px 8px;color:#e6edf3;font-size:13px;"><?= htmlspecialchars($l['label']) ?></td>
                    <td style="padding:6px 8px;color:#8b949e;font-size:12px;font-family:'Courier New',monospace;word-break:break-all;"><?= htmlspecialchars($l['url']) ?></td>
                    <td style="padding:6px 8px;width:46px;text-align:right;">
                        <form method="POST" action="admin.php?tab=sidebar" onsubmit="return confirm('删除链接「<?= htmlspecialchars($l['label']) ?>」？');">
                            <input type="hidden" name="sb_action" value="del_link">
                            <input type="hidden" name="lid" value="<?= (int)$l['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="font-size:11px;padding:2px 8px;">删</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
            <form method="POST" action="admin.php?tab=sidebar" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="sb_action" value="add_link">
                <input type="hidden" name="gid" value="<?= (int)$g['id'] ?>">
                <input type="text" name="icon"  maxlength="10" placeholder="🍸" style="<?= $sbI ?>width:50px;text-align:center;">
                <input type="text" name="label" maxlength="50" placeholder="名称" required style="<?= $sbI ?>width:120px;">
                <input type="text" name="url"   maxlength="255" placeholder="路径，如 bartender.php" required style="<?= $sbI ?>flex:1;min-width:160px;">
                <button type="submit" class="btn btn-green" style="font-size:12px;">+ 加链接</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; /* end tab switch */ ?>

    </div><!-- /ap-shell -->
    </main>
</div><!-- /ap-layout -->

<!-- 举报：快速封禁弹窗 -->
<div id="ban-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:22px;width:340px;max-width:92vw;font-family:'Courier New',monospace;">
        <p style="margin:0 0 12px;color:#f85149;font-weight:700;font-size:13px;">快速封禁用户</p>
        <input id="qban-reason" type="text" placeholder="封禁原因（必填）" maxlength="200"
               style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:8px 10px;border-radius:4px;font-size:13px;font-family:inherit;outline:none;box-sizing:border-box;">
        <div style="display:flex;gap:8px;margin-top:12px;">
            <button onclick="confirmBan()" style="flex:1;padding:8px;border:none;border-radius:4px;background:#f85149;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">封禁</button>
            <button onclick="document.getElementById('ban-modal').style.display='none'" style="flex:1;padding:8px;border:1px solid #30363d;border-radius:4px;background:transparent;color:#8b949e;font-size:13px;cursor:pointer;font-family:inherit;">取消</button>
        </div>
    </div>
</div>

<script>
// 举报操作
let _qbRid=0,_qbUid=0;
function rHandle(rid,action){
    const fd=new FormData(); fd.append('action',action); fd.append('report_id',rid);
    fetch('admin_reports.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){const c=document.getElementById('rcard-'+rid);if(c)c.style.transition='opacity .3s',c.style.opacity='0',setTimeout(()=>c.remove(),300);}
    });
}
function rDelPost(rid,pid){
    if(!confirm('确认删除该帖子并标记举报为已处理？'))return;
    const fd=new FormData(); fd.append('action','delete_post'); fd.append('report_id',rid); fd.append('target_id',pid);
    fetch('admin_reports.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){const c=document.getElementById('rcard-'+rid);if(c)c.style.transition='opacity .3s',c.style.opacity='0',setTimeout(()=>c.remove(),300);}
    });
}
function rBanUser(rid,uid){_qbRid=rid;_qbUid=uid;document.getElementById('qban-reason').value='';document.getElementById('ban-modal').style.display='flex';}
function confirmBan(){
    const reason=document.getElementById('qban-reason').value.trim();
    if(!reason){alert('请填写封禁原因');return;}
    const fd=new FormData(); fd.append('action','ban_user'); fd.append('report_id',_qbRid); fd.append('target_id',_qbUid); fd.append('ban_reason',reason);
    fetch('admin_reports.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('ban-modal').style.display='none';
        if(d.ok){const c=document.getElementById('rcard-'+_qbRid);if(c)c.style.transition='opacity .3s',c.style.opacity='0',setTimeout(()=>c.remove(),300);}
    });
}
document.getElementById('ban-modal').addEventListener('click',function(e){if(e.target===this)this.style.display='none';});

// 解封
function doUnban(uid,name){
    if(!confirm('确认解除「'+name+'」的封禁？'))return;
    const fd=new FormData(); fd.append('action','unban'); fd.append('user_id',uid);
    fetch('admin_ban.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){const c=document.getElementById('bcard-'+uid);if(c)c.style.transition='opacity .3s',c.style.opacity='0',setTimeout(()=>c.remove(),300);}
        else alert(d.msg||'操作失败');
    });
}

// 分区封面预览
function prevCat(input){
    const p=document.getElementById('cat-preview'),e=document.getElementById('cat-empty');
    if(input.files&&input.files[0]){
        const r=new FileReader(); r.onload=ev=>{p.src=ev.target.result;p.style.display='block';if(e)e.style.display='none';};
        r.readAsDataURL(input.files[0]);
    }
}
// 轮播图预览
function prevCa(input){
    const p=document.getElementById('ca-preview'),e=document.getElementById('ca-empty');
    if(input.files&&input.files[0]){
        const r=new FileReader(); r.onload=ev=>{p.src=ev.target.result;p.style.display='block';if(e)e.style.display='none';};
        r.readAsDataURL(input.files[0]);
    }
}
// 装饰品图片预览
function prevDc(input){
    const p=document.getElementById('dc-preview'),e=document.getElementById('dc-empty');
    if(input.files&&input.files[0]){
        const r=new FileReader(); r.onload=ev=>{p.src=ev.target.result;p.style.display='block';if(e)e.style.display='none';};
        r.readAsDataURL(input.files[0]);
    }
}
function dcTypeChanged(){
    const sel = document.getElementById('dc-type'); if (!sel) return;
    const v = sel.value;
    const sk = document.getElementById('dc-skin-fields');
    const sf = document.getElementById('dc-suffix-fields');
    if (sk) sk.style.display = (v==='plate_skin') ? 'block' : 'none';
    if (sf) sf.style.display = (v==='plate_suffix') ? 'block' : 'none';
}

// 复制活动页链接
function copyActivityLink(btn, url){
    const done = ok => {
        const orig = btn.innerHTML;
        btn.innerHTML = ok ? '✓ 已复制' : '✗ 复制失败';
        btn.style.color = ok ? '#3fb950' : '#f85149';
        btn.style.borderColor = ok ? 'rgba(63,185,80,.5)' : 'rgba(248,81,73,.5)';
        setTimeout(()=>{ btn.innerHTML=orig; btn.style.color=''; btn.style.borderColor=''; }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(()=>done(true)).catch(()=>fallback());
    } else { fallback(); }
    function fallback(){
        const ta = document.createElement('textarea');
        ta.value = url; ta.style.position='fixed'; ta.style.left='-9999px';
        document.body.appendChild(ta); ta.select();
        let ok=false; try{ ok=document.execCommand('copy'); }catch(e){}
        document.body.removeChild(ta); done(ok);
    }
}
</script>
</body>
</html>
<?php $conn->close(); ?>

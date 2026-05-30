<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/cocktail_helper.php';

$conn->set_charset('utf8mb4');
ensure_cocktail_tables($conn);

$ingredient_types = cocktail_ingredient_types();
$methods_map      = cocktail_methods();

// 食材
$ingredients = [];
$ir = $conn->query("SELECT id, name, type FROM ingredients WHERE is_active=1 ORDER BY CONVERT(name USING gbk) ASC, id ASC");
if ($ir) while ($row = $ir->fetch_assoc()) $ingredients[] = $row;

// 鸡尾酒 + 材料
$cocktails = [];
$cr = $conn->query("SELECT c.*,
    (SELECT COUNT(*) FROM cocktail_likes WHERE cocktail_id=c.id) AS like_count,
    (SELECT COUNT(*) FROM cocktail_favorites WHERE cocktail_id=c.id) AS fav_count
    FROM cocktails c WHERE c.is_active=1 ORDER BY CONVERT(c.name USING gbk) ASC, c.id ASC");
if ($cr) while ($row = $cr->fetch_assoc()) {
    $cid = (int)$row['id'];
    $row['ings'] = [];
    $ig = $conn->query("SELECT ci.ingredient_id, ci.amount, i.name FROM cocktail_ingredients ci JOIN ingredients i ON i.id=ci.ingredient_id WHERE ci.cocktail_id=$cid ORDER BY ci.sort_order ASC");
    if ($ig) while ($r = $ig->fetch_assoc()) $row['ings'][] = $r;
    $row['steps'] = [];
    $sr = $conn->query("SELECT content FROM cocktail_steps WHERE cocktail_id=$cid ORDER BY step_order ASC");
    if ($sr) while ($r = $sr->fetch_assoc()) $row['steps'][] = $r['content'];
    $cocktails[] = $row;
}

// 按类型分组食材
$ing_grouped = [];
foreach ($ingredients as $i) $ing_grouped[$i['type']][] = $i;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>调酒 - <?= SITE_NAME ?></title>
<link rel="icon" type="image/svg+xml" href="assets/logo.svg">
<link rel="shortcut icon" href="assets/logo.svg">
<style>
* { box-sizing: border-box; }

.bt-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 60px; }

.bt-head {
    border-bottom: 1px solid #21262d;
    padding-bottom: 14px;
    margin-bottom: 18px;
}
.bt-title {
    font-size: 22px; font-weight: 700; color: #e6edf3;
    margin: 0 0 6px;
}
.bt-title .accent { color: #3fb950; font-family: "Courier New", monospace; }
.bt-sub {
    font-size: 12px; color: #6e7681;
    font-family: "Courier New", monospace;
}
.bt-sub .num { color: #3fb950; }

/* ── 食材选择区 ── */
.bt-picker {
    background: #161b22;
    border: 1px solid #21262d;
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 18px;
}
.bt-picker-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 10px; flex-wrap: wrap; gap: 8px;
}
.bt-picker-title {
    font-size: 11px; color: #8b949e; font-family: "Courier New", monospace;
    letter-spacing: 1px; text-transform: uppercase;
}
.bt-picker-actions { display: flex; gap: 6px; }
.bt-mini-btn {
    background: transparent; border: 1px solid #30363d;
    color: #8b949e; padding: 4px 10px; border-radius: 4px;
    font-size: 11px; cursor: pointer; font-family: "Courier New", monospace;
    transition: .15s;
}
.bt-mini-btn:hover { color: #3fb950; border-color: #3fb950; }

.bt-group {
    margin-bottom: 12px;
}
.bt-group:last-child { margin-bottom: 0; }
.bt-group-label {
    font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase;
    font-family: "Courier New", monospace;
    margin-bottom: 6px;
}
.bt-group-label::before { content: '// '; opacity: .6; }
.bt-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.bt-chip {
    display: inline-flex; align-items: center;
    padding: 6px 12px;
    background: #0d1117;
    border: 1px solid #30363d;
    border-radius: 16px;
    font-size: 12px; color: #c9d1d9;
    cursor: pointer; user-select: none;
    transition: .15s;
    font-family: "Microsoft YaHei", sans-serif;
}
.bt-chip:hover { border-color: #58a6ff; color: #fff; }
.bt-chip.active {
    background: rgba(63, 185, 80, .14);
    border-color: #3fb950; color: #3fb950;
    box-shadow: 0 0 0 1px rgba(63, 185, 80, .25);
}
.bt-chip.active::before { content: '✓ '; }

/* ── 结果区 ── */
.bt-result-head {
    display: flex; align-items: baseline; justify-content: space-between;
    margin-bottom: 12px; gap: 12px; flex-wrap: wrap;
}
.bt-result-title {
    font-size: 14px; color: #e6edf3; font-weight: 700;
    font-family: "Courier New", monospace;
}
.bt-result-title .num { color: #3fb950; }
.bt-empty-tip {
    padding: 60px 20px; text-align: center;
    color: #6e7681; font-size: 13px;
    background: #0d1117;
    border: 1px dashed #21262d;
    border-radius: 8px;
}
.bt-empty-tip .big { font-size: 36px; opacity: .35; display: block; margin-bottom: 10px; }

.bt-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 14px;
}
.bt-card {
    background: #161b22; border: 1px solid #21262d;
    border-radius: 8px; overflow: hidden; cursor: pointer;
    transition: .2s;
    display: flex; flex-direction: column;
}
.bt-card:hover {
    border-color: #3fb950;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(63, 185, 80, .12);
}
.bt-card-img {
    width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block;
    background: linear-gradient(135deg, #21262d, #161b22);
}
.bt-card-img-empty {
    width: 100%; aspect-ratio: 4 / 3;
    display: flex; align-items: center; justify-content: center;
    font-size: 44px; color: #30363d;
    background: linear-gradient(135deg, #21262d, #161b22);
}
.bt-card-body { padding: 10px 12px; }
.bt-card-name {
    font-size: 14px; font-weight: 700; color: #e6edf3;
    margin-bottom: 2px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.bt-card-en {
    font-size: 10px; color: #6e7681;
    font-family: "Courier New", monospace;
    margin-bottom: 6px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.bt-card-meta {
    font-size: 10px; color: #8b949e;
    display: flex; gap: 6px; flex-wrap: wrap;
}
.bt-card-meta .tag {
    padding: 1px 6px; background: #0d1117; border: 1px solid #21262d; border-radius: 8px;
    font-family: "Courier New", monospace;
}

/* ── 详情弹窗 ── */
.bt-modal-mask {
    display: none; position: fixed; inset: 0;
    background: rgba(0, 0, 0, .75); z-index: 9999;
    align-items: center; justify-content: center;
    padding: 20px;
    overflow-y: auto;
}
.bt-modal-mask.show { display: flex; }
.bt-modal {
    background: #161b22; border: 1px solid #30363d;
    border-radius: 10px;
    width: 100%; max-width: 560px;
    max-height: 90vh; overflow-y: auto;
    position: relative;
}
.bt-modal-img {
    width: 100%; aspect-ratio: 16 / 9; object-fit: cover;
    display: block;
    border-bottom: 1px solid #21262d;
}
.bt-modal-img-empty {
    width: 100%; aspect-ratio: 16 / 9;
    display: flex; align-items: center; justify-content: center;
    font-size: 72px; color: #30363d;
    background: linear-gradient(135deg, #21262d, #161b22);
    border-bottom: 1px solid #21262d;
}
.bt-modal-close {
    position: absolute; top: 8px; right: 10px;
    width: 28px; height: 28px; border-radius: 50%;
    background: rgba(13, 17, 23, .85);
    color: #e6edf3; border: 1px solid #30363d;
    font-size: 16px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}
.bt-modal-body { padding: 18px 22px 22px; }
.bt-modal-title {
    font-size: 20px; font-weight: 700; color: #e6edf3;
    margin: 0 0 4px;
}
.bt-modal-en {
    font-size: 12px; color: #6e7681;
    font-family: "Courier New", monospace;
    margin-bottom: 14px;
}
.bt-modal-meta {
    display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;
}
.bt-modal-meta .tag {
    padding: 3px 10px; background: #0d1117;
    border: 1px solid #30363d; border-radius: 12px;
    font-size: 11px; color: #c9d1d9;
    font-family: "Courier New", monospace;
}
.bt-modal-section {
    margin-bottom: 14px;
}
.bt-modal-section-title {
    font-size: 11px; letter-spacing: 1px; text-transform: uppercase;
    color: #3fb950; font-family: "Courier New", monospace;
    margin-bottom: 6px;
}
.bt-modal-section-title::before { content: '// '; }
.bt-ing-list {
    list-style: none; padding: 0; margin: 0;
    font-size: 13px; color: #c9d1d9;
}
.bt-ing-list li {
    display: flex; justify-content: space-between;
    padding: 4px 0;
    border-bottom: 1px dashed #21262d;
}
.bt-ing-list li:last-child { border-bottom: none; }
.bt-ing-list .amt {
    color: #8b949e; font-family: "Courier New", monospace; font-size: 12px;
}
.bt-step-body {
    font-size: 13px; line-height: 1.7; color: #c9d1d9;
    white-space: pre-wrap;
}
.bt-step-list {
    list-style: none; padding: 0; margin: 0;
    counter-reset: step;
}
.bt-step-list li {
    counter-increment: step;
    position: relative;
    padding: 8px 0 8px 32px;
    font-size: 13px; line-height: 1.6; color: #c9d1d9;
    border-bottom: 1px dashed #21262d;
}
.bt-step-list li:last-child { border-bottom: none; }
.bt-step-list li::before {
    content: counter(step);
    position: absolute;
    left: 0; top: 8px;
    width: 22px; height: 22px;
    border-radius: 50%;
    background: rgba(63, 185, 80, .15);
    border: 1px solid #3fb950;
    color: #3fb950;
    font-size: 11px; font-weight: 700;
    font-family: "Courier New", monospace;
    display: flex; align-items: center; justify-content: center;
}

.bt-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #21262d;}
.bt-act{background:#0d1117;border:1px solid #30363d;color:#c9d1d9;padding:6px 14px;border-radius:16px;font-size:12px;cursor:pointer;transition:.15s;font-family:"Microsoft YaHei",sans-serif;}
.bt-act:hover{border-color:#58a6ff;}
.bt-act.on{border-color:#3fb950;color:#3fb950;background:rgba(63,185,80,.1);}
.bt-act .ic{font-size:13px;}
.bt-rate{display:flex;align-items:center;gap:8px;margin-left:auto;}
.bt-rate-stars{font-size:18px;letter-spacing:2px;}
.bt-rate-stars .star{color:#30363d;cursor:pointer;transition:.1s;}
.bt-rate-stars .star.on{color:#e3b341;}
.bt-rate-stars .star:hover{color:#e3b341;}
.bt-rate-avg{font-size:11px;color:#8b949e;font-family:"Courier New",monospace;}
.bt-cmt-box{display:flex;gap:8px;margin-bottom:12px;}
.bt-cmt-box textarea{flex:1;min-height:40px;max-height:120px;resize:vertical;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#e6edf3;padding:8px 10px;font-size:13px;font-family:"Microsoft YaHei",sans-serif;box-sizing:border-box;}
.bt-cmt-send{background:#238636;border:1px solid #2ea043;color:#fff;border-radius:6px;padding:0 16px;cursor:pointer;font-size:13px;}
.bt-cmt-send:hover{background:#2ea043;}
.bt-cmt-list{display:flex;flex-direction:column;gap:12px;}
.bt-cmt-item{display:flex;gap:10px;}
.bt-cmt-av img{width:34px;height:34px;border-radius:50%;object-fit:cover;border:1px solid #30363d;display:block;}
.bt-cmt-main{flex:1;min-width:0;background:#0d1117;border:1px solid #21262d;border-radius:6px;padding:8px 12px;}
.bt-cmt-h{display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap;}
.bt-cmt-u{font-size:12px;color:#58a6ff;font-weight:600;text-decoration:none;}
.bt-cmt-u:hover{text-decoration:underline;}
.bt-cmt-floor{font-size:10px;color:#3fb950;font-family:"Courier New",monospace;}
.bt-cmt-t{font-size:10px;color:#6e7681;font-family:"Courier New",monospace;}
.bt-cmt-del{font-size:11px;color:#6e7681;cursor:pointer;margin-left:auto;}
.bt-cmt-del:hover{color:#f85149;}
.bt-cmt-c{font-size:13px;color:#c9d1d9;line-height:1.5;white-space:pre-wrap;word-break:break-word;}
.bt-cmt-hidden{cursor:pointer;}
.bt-cmt-hidden .bt-cmt-mask{color:#8b949e;font-size:12px;font-style:italic;}
.bt-cmt-react{display:flex;gap:16px;margin-top:7px;}
.bt-react{font-size:12px;color:#8b949e;cursor:pointer;user-select:none;transition:.15s;}
.bt-react:hover{color:#e6edf3;}
.bt-react.on{color:#3fb950;}
.bt-react.on-dis{color:#f85149;}
.bt-cmt-empty{color:#6e7681;font-size:12px;text-align:center;padding:14px;}

@media (max-width: 720px) {
    .bt-title { font-size: 18px; }
    .bt-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
}
</style>
</head>
<body style="background:#0d1117;color:#e6edf3;font-family:'Microsoft YaHei',sans-serif;margin:0;">

<?php include __DIR__ . '/includes/header.php'; ?>

<div class="bt-wrap">
    <div class="bt-head">
        <h1 class="bt-title">🍸 <span class="accent">调酒台</span></h1>
        <div class="bt-sub">
            点选材料，找包含它们的配方 ·
            <span class="num" id="bt-stat-total"><?= count($cocktails) ?></span> 款配方，
            <span class="num" id="bt-stat-ings"><?= count($ingredients) ?></span> 种食材
        </div>
    </div>

    <?php if (!$ingredients): ?>
    <div class="bt-empty-tip">
        <span class="big">🍃</span>
        食材库还是空的。<br>
        管理员可以在 <a href="pages/admin.php?tab=cocktails" style="color:#3fb950;">后台 → 调酒配方</a> 添加食材和配方。
    </div>
    <?php else: ?>

    <div style="margin-bottom:14px;">
        <input id="bt-search" type="text" placeholder="🔍 搜配方名（中文 / 英文）" oninput="btRender()"
            style="width:100%;background:#161b22;border:1px solid #30363d;color:#e6edf3;padding:10px 14px;border-radius:8px;font-size:14px;outline:none;box-sizing:border-box;font-family:'Microsoft YaHei',sans-serif;">
    </div>

    <div class="bt-picker">
        <div class="bt-picker-head">
            <div class="bt-picker-title">// 必须含的材料 (<span id="bt-sel-count">0</span>)</div>
            <div class="bt-picker-actions">
                <button class="bt-mini-btn" onclick="btRandom()">🎲 随机一杯</button>
                <button class="bt-mini-btn" onclick="btSelectAll()">全选</button>
                <button class="bt-mini-btn" onclick="btClearAll()">清空</button>
            </div>
        </div>

        <?php foreach ($ingredient_types as $tk => $tv):
            if (empty($ing_grouped[$tk])) continue;
        ?>
        <div class="bt-group">
            <div class="bt-group-label" style="color:<?= $tv['color'] ?>;"><?= $tv['label'] ?></div>
            <div class="bt-chips">
                <?php foreach ($ing_grouped[$tk] as $ig): ?>
                <span class="bt-chip" data-id="<?= (int)$ig['id'] ?>" onclick="btToggle(this)"><?= htmlspecialchars($ig['name']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="bt-group">
            <div class="bt-group-label" style="color:#e3b341;">酒精度</div>
            <div class="bt-chips">
                <?php foreach (['无酒精','低度','低到中度','中度','中到高度','高度'] as $ap): ?>
                <span class="bt-chip bt-abv-chip" data-abv="<?= $ap ?>" onclick="btAbvToggle(this)"><?= $ap ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="bt-result-head">
        <div class="bt-result-title">
            找到 <span class="num" id="bt-result-count">0</span> 款
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <select id="bt-sort" onchange="btRender()" style="background:#161b22;border:1px solid #30363d;color:#c9d1d9;padding:4px 8px;border-radius:4px;font-size:11px;font-family:'Courier New',monospace;outline:none;cursor:pointer;">
                <option value="default">默认排序</option>
                <option value="like">点赞最多</option>
                <option value="fav">收藏最多</option>
            </select>
            <span style="font-size:11px;color:#6e7681;font-family:'Courier New',monospace;" id="bt-hint">未选食材 — 显示全部</span>
        </div>
    </div>

    <div id="bt-result" class="bt-grid"></div>
    <div id="bt-result-empty" class="bt-empty-tip" style="display:none;">
        <span class="big">🥲</span>
        没有同时包含这些材料的配方<br>
        <span style="font-size:11px;color:#484f58;">提示：取消几个材料，缩小条件</span>
    </div>

    <?php endif; ?>
</div>

<!-- 详情弹窗 -->
<div class="bt-modal-mask" id="bt-modal" onclick="if(event.target===this)btCloseModal()">
    <div class="bt-modal">
        <button class="bt-modal-close" onclick="btCloseModal()">✕</button>
        <div id="bt-modal-img-wrap"></div>
        <div class="bt-modal-body">
            <h2 class="bt-modal-title" id="bt-m-name"></h2>
            <div class="bt-modal-en" id="bt-m-en"></div>
            <div class="bt-modal-meta" id="bt-m-meta"></div>

            <div class="bt-modal-section">
                <div class="bt-modal-section-title">材料</div>
                <ul class="bt-ing-list" id="bt-m-ings"></ul>
            </div>

            <div class="bt-modal-section">
                <div class="bt-modal-section-title">步骤</div>
                <div class="bt-step-body" id="bt-m-steps"></div>
            </div>

            <div class="bt-modal-section" id="bt-m-garnish-sec" style="display:none;">
                <div class="bt-modal-section-title">装饰</div>
                <div class="bt-step-body" id="bt-m-garnish"></div>
            </div>

            <!-- 互动：收藏 / 点赞 / 评分 -->
            <div class="bt-actions">
                <button class="bt-act" id="bt-act-fav" onclick="btFav()"><span class="ic">☆</span> 收藏 <span id="bt-fav-c">0</span></button>
                <button class="bt-act" id="bt-act-like" onclick="btLike()"><span class="ic">♡</span> 点赞 <span id="bt-like-c">0</span></button>
                <div class="bt-rate">
                    <span class="bt-rate-stars" id="bt-rate-stars"></span>
                    <span class="bt-rate-avg" id="bt-rate-avg">—</span>
                </div>
            </div>

            <!-- 评论区 -->
            <div class="bt-modal-section">
                <div class="bt-modal-section-title">评论 <span id="bt-cmt-c">0</span></div>
                <div class="bt-cmt-box">
                    <textarea id="bt-cmt-input" placeholder="调了这杯？分享一下心得…" maxlength="1000"></textarea>
                    <button class="bt-cmt-send" onclick="btComment()">发表</button>
                </div>
                <div id="bt-cmt-list" class="bt-cmt-list"></div>
            </div>
        </div>
    </div>
</div>

<script>
const COCKTAILS = <?= json_encode(array_map(function($c){
    return [
        'id' => (int)$c['id'],
        'name' => $c['name'],
        'name_en' => $c['name_en'],
        'glass' => $c['glass'],
        'method' => $c['method'],
        'garnish' => $c['garnish'],
        'image' => $c['image'],
        'abv_hint' => $c['abv_hint'],
        'abv_preset' => trim(preg_replace('/[\s\d.%]+$/u', '', (string)$c['abv_hint'])),
        'like_count' => (int)($c['like_count'] ?? 0),
        'fav_count'  => (int)($c['fav_count'] ?? 0),
        'ings' => array_map(function($i){ return ['id'=>(int)$i['ingredient_id'],'name'=>$i['name'],'amount'=>$i['amount']]; }, $c['ings']),
        'steps' => $c['steps'],
    ];
}, $cocktails), JSON_UNESCAPED_UNICODE) ?>;
const METHODS = <?= json_encode($methods_map, JSON_UNESCAPED_UNICODE) ?>;
const selected = new Set();
let abvSelected = '';
function btAbvToggle(el){
    const a = el.dataset.abv;
    document.querySelectorAll('.bt-abv-chip').forEach(c => c.classList.remove('active'));
    if (abvSelected === a) { abvSelected = ''; }       // 再点一次取消
    else { abvSelected = a; el.classList.add('active'); }
    btRender();
}

function btToggle(el) {
    const id = parseInt(el.dataset.id);
    if (selected.has(id)) { selected.delete(id); el.classList.remove('active'); }
    else { selected.add(id); el.classList.add('active'); }
    btRender();
}

function btSelectAll() {
    document.querySelectorAll('.bt-chip[data-id]').forEach(c => {
        selected.add(parseInt(c.dataset.id));
        c.classList.add('active');
    });
    btRender();
}
function btClearAll() {
    selected.clear();
    abvSelected = '';
    document.querySelectorAll('.bt-chip').forEach(c => c.classList.remove('active'));
    btRender();
}
function btRandom() {
    const list = btMatchable();
    if (!list.length) return;
    btShow(list[Math.floor(Math.random() * list.length)].id);
}

function btMatchable() {
    const kw = (document.getElementById('bt-search')?.value || '').trim().toLowerCase();
    let list = COCKTAILS.slice();
    if (selected.size > 0) {
        list = list.filter(c => {
            if (!c.ings.length) return false;
            const ingSet = new Set(c.ings.map(i => i.id));
            for (const id of selected) if (!ingSet.has(id)) return false;
            return true;
        });
    }
    if (abvSelected) {
        list = list.filter(c => c.abv_preset === abvSelected);
    }
    if (kw) {
        list = list.filter(c => (c.name || '').toLowerCase().includes(kw) || (c.name_en || '').toLowerCase().includes(kw));
    }
    return list;
}

function btCardHTML(c) {
    const img = c.image
        ? `<img class="bt-card-img" src="${c.image}" alt="${c.name}" loading="lazy">`
        : `<div class="bt-card-img-empty">🍸</div>`;
    const method = METHODS[c.method] || '';
    const tags = [];
    if (c.glass)    tags.push(`<span class="tag">🥃 ${c.glass}</span>`);
    if (method)     tags.push(`<span class="tag">${method.split('（')[0]}</span>`);
    if (c.abv_hint) tags.push(`<span class="tag">${c.abv_hint}</span>`);
    return `
    <div class="bt-card" onclick="btShow(${c.id})">
        ${img}
        <div class="bt-card-body">
            <div class="bt-card-name">${c.name}</div>
            <div class="bt-card-en">${c.name_en || '—'}</div>
            <div class="bt-card-meta">${tags.join('')}</div>
        </div>
    </div>`;
}

function btRender() {
    const list = btMatchable();
    const sort = document.getElementById('bt-sort')?.value || 'default';
    if (sort === 'like') list.sort((a,b)=>(b.like_count||0)-(a.like_count||0));
    else if (sort === 'fav') list.sort((a,b)=>(b.fav_count||0)-(a.fav_count||0));
    document.getElementById('bt-sel-count').textContent = selected.size;
    document.getElementById('bt-result-count').textContent = list.length;
    document.getElementById('bt-hint').textContent = selected.size === 0
        ? '未选食材 — 显示全部'
        : `已选 ${selected.size} 种 — 配方必须全部包含`;

    const wrap = document.getElementById('bt-result');
    const empty = document.getElementById('bt-result-empty');
    if (list.length === 0) {
        wrap.innerHTML = '';
        empty.style.display = 'block';
    } else {
        empty.style.display = 'none';
        wrap.innerHTML = list.map(btCardHTML).join('');
    }
}

function escapeHTML(s) {
    return String(s ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function btShow(id) {
    const c = COCKTAILS.find(x => x.id === id);
    if (!c) return;
    document.getElementById('bt-m-name').textContent = c.name;
    document.getElementById('bt-m-en').textContent = c.name_en || '';

    const imgWrap = document.getElementById('bt-modal-img-wrap');
    imgWrap.innerHTML = c.image
        ? `<img class="bt-modal-img" src="${escapeHTML(c.image)}" alt="${escapeHTML(c.name)}" style="cursor:zoom-in;" onclick="btZoom('${escapeHTML(c.image)}')" title="点击放大">`
        : `<div class="bt-modal-img-empty">🍸</div>`;

    const meta = [];
    if (c.glass)            meta.push(`🥃 ${escapeHTML(c.glass)}`);
    if (METHODS[c.method])  meta.push(escapeHTML(METHODS[c.method]));
    if (c.abv_hint)         meta.push(escapeHTML(c.abv_hint));
    document.getElementById('bt-m-meta').innerHTML = meta.map(m => `<span class="tag">${m}</span>`).join('');

    document.getElementById('bt-m-ings').innerHTML = c.ings.length
        ? c.ings.map(i => `<li><span>${escapeHTML(i.name)}</span><span class="amt">${escapeHTML(i.amount || '')}</span></li>`).join('')
        : '<li><span style="color:#6e7681;">未配置材料</span></li>';

    const stepsEl = document.getElementById('bt-m-steps');
    if (c.steps && c.steps.length) {
        stepsEl.innerHTML = '<ol class="bt-step-list">' + c.steps.map(s => `<li>${escapeHTML(s)}</li>`).join('') + '</ol>';
    } else {
        stepsEl.innerHTML = '<span style="color:#6e7681;">（暂无步骤）</span>';
    }

    const gSec = document.getElementById('bt-m-garnish-sec');
    if (c.garnish) {
        gSec.style.display = '';
        document.getElementById('bt-m-garnish').textContent = c.garnish;
    } else {
        gSec.style.display = 'none';
    }

    document.getElementById('bt-modal').classList.add('show');
    btCurCid = id;
    btLoadState(id);
}

// ── 互动：收藏/点赞/评分/评论 ──
let btCurCid = 0;
const btMyUid = <?= (int)($_SESSION['user_id'] ?? 0) ?>;
const btIsAdmin = <?= in_array($_SESSION['role'] ?? '', ['admin','owner'], true) ? 'true' : 'false' ?>;
function btPost(params){
    return fetch('actions/cocktail_action.php', {method:'POST', body:new URLSearchParams(params), credentials:'same-origin'}).then(r=>r.json());
}
function btNeedLogin(){ if(confirm('登录后才能互动，去登录？')) location.href='pages/login.php'; }
function btLoadState(cid){ btPost({action:'state', cid}).then(d=>{ if(d.ok) btRenderState(d); }); }
function btRenderState(d){
    const s=d.stats;
    document.getElementById('bt-fav-c').textContent=s.fav_count;
    document.getElementById('bt-like-c').textContent=s.like_count;
    const fb=document.getElementById('bt-act-fav'); fb.classList.toggle('on',s.my.faved); fb.querySelector('.ic').textContent=s.my.faved?'★':'☆';
    const lb=document.getElementById('bt-act-like'); lb.classList.toggle('on',s.my.liked); lb.querySelector('.ic').textContent=s.my.liked?'♥':'♡';
    btRenderStars(s.my.score, s.rate_avg, s.rate_count);
    btRenderComments(d.comments);
}
function btRenderStars(my, avg, cnt){
    let h=''; for(let i=1;i<=5;i++) h+=`<span class="star${i<=my?' on':''}" onclick="btRate(${i})">★</span>`;
    document.getElementById('bt-rate-stars').innerHTML=h;
    document.getElementById('bt-rate-avg').textContent=cnt?`${avg} 分 · ${cnt} 人`:'暂无评分';
}
function btRenderComments(list){
    document.getElementById('bt-cmt-c').textContent=list.length;
    const box=document.getElementById('bt-cmt-list');
    if(!list.length){ box.innerHTML='<div class="bt-cmt-empty">还没有评论，来抢沙发～</div>'; return; }
    box.innerHTML=list.map((c,i)=>{
        const floor=i+1;
        const canDel=(btMyUid&&c.uid===btMyUid)||btIsAdmin;
        const del=canDel?`<span class="bt-cmt-del" onclick="btDelComment(${c.id})">删除</span>`:'';
        const av='uploads/avatars/'+(c.avatar||'default.png');
        const prof='pages/profile.php?id='+c.uid;
        const hidden=(c.dislike_count>c.like_count && c.dislike_count>=3);
        const body=hidden
            ? `<div class="bt-cmt-c bt-cmt-hidden" onclick="this.classList.remove('bt-cmt-hidden');this.querySelector('.bt-cmt-real').style.display='';this.querySelector('.bt-cmt-mask').remove();"><span class="bt-cmt-mask">⚠ 该评论引起较多反感，点击查看</span><span class="bt-cmt-real" style="display:none;">${escapeHTML(c.content)}</span></div>`
            : `<div class="bt-cmt-c">${escapeHTML(c.content)}</div>`;
        const likeOn=c.my_react==='like'?' on':'';
        const disOn=c.my_react==='dislike'?' on-dis':'';
        return `<div class="bt-cmt-item">
            <a href="${prof}" class="bt-cmt-av"><img src="${av}" onerror="this.onerror=null;this.src='uploads/avatars/default.png'"></a>
            <div class="bt-cmt-main">
                <div class="bt-cmt-h">
                    <a href="${prof}" class="bt-cmt-u">${escapeHTML(c.username)}</a>
                    <span class="bt-cmt-floor">#${floor}</span>
                    <span class="bt-cmt-t">${escapeHTML((c.created_at||'').slice(5,16))}</span>
                    ${del}
                </div>
                ${body}
                <div class="bt-cmt-react">
                    <span class="bt-react${likeOn}" onclick="btCmtReact(${c.id},'like')">👍 <b>${c.like_count}</b></span>
                    <span class="bt-react${disOn}" onclick="btCmtReact(${c.id},'dislike')">👎 <b>${c.dislike_count}</b></span>
                </div>
            </div>
        </div>`;
    }).join('');
}
function btFav(){ btPost({action:'fav',cid:btCurCid}).then(d=>{ if(d.need_login)return btNeedLogin(); if(d.ok){document.getElementById('bt-fav-c').textContent=d.fav_count; const b=document.getElementById('bt-act-fav'); b.classList.toggle('on',d.faved); b.querySelector('.ic').textContent=d.faved?'★':'☆';} }); }
function btLike(){ btPost({action:'like',cid:btCurCid}).then(d=>{ if(d.need_login)return btNeedLogin(); if(d.ok){document.getElementById('bt-like-c').textContent=d.like_count; const b=document.getElementById('bt-act-like'); b.classList.toggle('on',d.liked); b.querySelector('.ic').textContent=d.liked?'♥':'♡';} }); }
function btRate(score){ btPost({action:'rate',cid:btCurCid,score}).then(d=>{ if(d.need_login)return btNeedLogin(); if(d.ok) btRenderStars(d.my_score,d.rate_avg,d.rate_count); }); }
function btComment(){ const el=document.getElementById('bt-cmt-input'); const t=el.value.trim(); if(!t)return; btPost({action:'comment',cid:btCurCid,content:t}).then(d=>{ if(d.need_login)return btNeedLogin(); if(d.ok){el.value=''; btLoadState(btCurCid);} else alert(d.msg||'发表失败'); }); }
function btDelComment(coid){ if(!confirm('删除这条评论？'))return; btPost({action:'comment_delete',cid:btCurCid,coid}).then(d=>{ if(d.ok) btLoadState(btCurCid); else alert(d.msg||'删除失败'); }); }
function btCmtReact(coid, type){ btPost({action:'comment_react',cid:btCurCid,coid,rtype:type}).then(d=>{ if(d.need_login)return btNeedLogin(); if(d.ok) btLoadState(btCurCid); }); }

function btCloseModal() {
    document.getElementById('bt-modal').classList.remove('show');
}
// 图片放大查看（lightbox，完整不裁切）
function btZoom(src){
    let z = document.getElementById('bt-zoom');
    if (!z){
        z = document.createElement('div');
        z.id = 'bt-zoom';
        z.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:10001;display:none;align-items:center;justify-content:center;cursor:zoom-out;padding:20px;';
        z.onclick = function(){ z.style.display = 'none'; };
        z.innerHTML = '<img style="max-width:100%;max-height:100%;object-fit:contain;border-radius:6px;box-shadow:0 8px 40px rgba(0,0,0,.6);">';
        document.body.appendChild(z);
    }
    z.querySelector('img').src = src;
    z.style.display = 'flex';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') btCloseModal(); });

btRender();
</script>

</body>
</html>
<?php $conn->close(); ?>

<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/exp_helper.php';
ensure_song_sheets_table($conn);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (!empty($_SESSION['is_banned'])) {
    http_response_code(403);
    include __DIR__ . '/../403.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>上传曲谱 - 缪斯 MUSE</title>
<style>
    body { background:#0d1117; color:#e6edf3; margin:0; font-family:"Microsoft YaHei", sans-serif; }
    .up-wrap { max-width:680px; margin:30px auto; padding:0 24px; }
    .up-back { color:#6e7681; text-decoration:none; font-family:"Courier New",monospace; font-size:12px; }
    .up-back:hover { color:#3fb950; }
    .up-card {
        background:#161b22; border:1px solid #30363d; border-radius:8px;
        padding:28px; margin-top:14px;
    }
    .up-title { font-family:"Courier New",monospace; color:#3fb950; font-size:18px; margin:0 0 18px; }
    .up-row { margin-bottom:14px; }
    .up-row label { display:block; font-size:12px; color:#8b949e; margin-bottom:5px;
                    font-family:"Courier New",monospace; }
    .up-row label .req { color:#f85149; }
    .up-input, .up-textarea, .up-select {
        width:100%; box-sizing:border-box;
        background:#0d1117; border:1px solid #30363d; color:#e6edf3;
        padding:9px 12px; border-radius:4px; font-size:13px;
        font-family:inherit; outline:none;
    }
    .up-input:focus, .up-textarea:focus, .up-select:focus { border-color:#3fb950; }
    .up-textarea { min-height:80px; resize:vertical; font-family:inherit; }
    .up-row-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
    @media(max-width:560px) { .up-row-grid { grid-template-columns:1fr; } }

    .up-file-wrap {
        border:2px dashed #30363d; border-radius:6px; padding:24px;
        text-align:center; transition:.18s; cursor:pointer; background:#0d1117;
    }
    .up-file-wrap:hover { border-color:#3fb950; }
    .up-file-wrap input[type=file] { display:none; }
    .up-file-wrap .ico { font-size:32px; color:#6e7681; }
    .up-file-wrap .hint { color:#8b949e; font-size:12px; margin-top:6px; font-family:"Courier New",monospace; }
    .up-file-wrap.has-file { border-color:#3fb950; background:rgba(63,185,80,.05); }
    .up-file-wrap.has-file .ico { color:#3fb950; }

    .up-actions { display:flex; gap:10px; margin-top:18px; }
    .up-btn {
        background:#3fb950; color:#0d1117; padding:10px 22px;
        border:none; border-radius:4px; font-weight:700; cursor:pointer;
        font-family:"Courier New",monospace; font-size:13px;
    }
    .up-btn:disabled { opacity:.5; cursor:not-allowed; }
    .up-btn:hover:not(:disabled) { background:#5fdd70; }
    .up-btn-line {
        background:transparent; color:#8b949e; padding:10px 22px;
        border:1px solid #30363d; border-radius:4px; cursor:pointer;
        font-family:"Courier New",monospace; font-size:13px;
        text-decoration:none; display:inline-flex; align-items:center;
    }
    .up-btn-line:hover { border-color:#f85149; color:#f85149; }

    .up-msg { padding:10px 14px; border-radius:4px; font-family:"Courier New",monospace; font-size:13px; margin-top:14px; display:none; }
    .up-msg.err { background:rgba(248,81,73,.1); border:1px solid rgba(248,81,73,.3); color:#f85149; }
    .up-msg.ok  { background:rgba(63,185,80,.1); border:1px solid rgba(63,185,80,.3); color:#3fb950; }

    .up-tip { background:#0d1117; border-left:3px solid #3fb950; padding:10px 14px;
              color:#8b949e; font-size:12px; margin-bottom:18px; line-height:1.7;
              font-family:"Courier New",monospace; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="up-wrap">
    <a class="up-back" href="../sheets.php">‹ 返回曲库</a>
    <div class="up-card">
        <h2 class="up-title">&gt; 上传曲谱</h2>

        <div class="up-tip">
            # 接受 SkyStudio 格式的 <b style="color:#e6edf3;">.txt</b> / <b style="color:#e6edf3;">.json</b> 曲谱<br>
            # 单文件最大 5MB，上传后系统自动解析元数据<br>
            # SkyMusic 桌面端用户可以从在线曲库直接拉取你的曲谱
        </div>

        <form id="uploadForm">
            <div class="up-row">
                <label>曲谱文件 <span class="req">*</span></label>
                <label class="up-file-wrap" id="dropArea">
                    <div class="ico">↥</div>
                    <div class="hint" id="fileHint">点击或拖入 .txt / .json 文件</div>
                    <input type="file" id="fileInput" name="file" accept=".txt,.json" required>
                </label>
            </div>

            <div class="up-row">
                <label>曲名 <span class="req">*</span></label>
                <input class="up-input" type="text" name="title" id="titleInput" maxlength="150" required>
            </div>

            <div class="up-row-grid">
                <div class="up-row">
                    <label>原唱 / 作曲</label>
                    <input class="up-input" type="text" name="artist" id="artistInput" maxlength="100">
                </div>
                <div class="up-row">
                    <label>创谱人</label>
                    <input class="up-input" type="text" name="transcribed_by" id="transInput" maxlength="100">
                </div>
                <div class="up-row">
                    <label>难度</label>
                    <select class="up-select" name="difficulty">
                        <option value="1">★ 入门</option>
                        <option value="2">★★ 简单</option>
                        <option value="3" selected>★★★ 中等</option>
                        <option value="4">★★★★ 困难</option>
                        <option value="5">★★★★★ 大师</option>
                    </select>
                </div>
            </div>

            <div class="up-row-grid" style="grid-template-columns:1fr 1fr;">
                <div class="up-row">
                    <label>BPM</label>
                    <input class="up-input" type="number" name="bpm" id="bpmInput" min="0" max="999">
                </div>
                <div class="up-row">
                    <label>细分（1/N）</label>
                    <input class="up-input" type="number" name="subdiv" id="subdivInput" min="0" max="32">
                </div>
            </div>

            <div class="up-row">
                <label>标签（逗号分隔，最多 5 个）</label>
                <input class="up-input" type="text" name="tags" maxlength="255"
                       placeholder="如：古风, 流行, 二次元">
            </div>

            <div class="up-row">
                <label>简介（500 字内）</label>
                <textarea class="up-textarea" name="description" maxlength="500"
                          placeholder="选填：曲谱来源 / 改编说明 / 演奏技巧..."></textarea>
            </div>

            <div class="up-msg" id="msgBox"></div>

            <div class="up-actions">
                <button class="up-btn" type="submit" id="submitBtn">提交上传</button>
                <a class="up-btn-line" href="../sheets.php">取消</a>
            </div>
        </form>
    </div>
</div>

<script>
const fileInput = document.getElementById('fileInput');
const dropArea  = document.getElementById('dropArea');
const fileHint  = document.getElementById('fileHint');

function handleFile(file) {
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        alert('文件不能超过 5MB');
        fileInput.value = '';
        return;
    }
    dropArea.classList.add('has-file');
    fileHint.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
    // 自动解析填表
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            let txt = e.target.result;
            if (txt.charCodeAt(0) === 0xFEFF) txt = txt.slice(1);
            const data = JSON.parse(txt);
            const song = Array.isArray(data) ? data[0] : data;
            if (!song) return;
            if (song.name && !document.getElementById('titleInput').value)
                document.getElementById('titleInput').value = song.name;
            if (song.author && !document.getElementById('artistInput').value)
                document.getElementById('artistInput').value = song.author;
            if (song.transcribedBy && !document.getElementById('transInput').value)
                document.getElementById('transInput').value = song.transcribedBy;
            if (song.bpm && !document.getElementById('bpmInput').value)
                document.getElementById('bpmInput').value = song.bpm;
            if (song.subdiv && !document.getElementById('subdivInput').value)
                document.getElementById('subdivInput').value = song.subdiv;
        } catch (err) {
            // 解析失败不阻止提交
        }
    };
    reader.readAsText(file);
}
fileInput.addEventListener('change', e => handleFile(e.target.files[0]));

['dragenter', 'dragover'].forEach(ev =>
    dropArea.addEventListener(ev, e => { e.preventDefault(); dropArea.style.borderColor = '#3fb950'; }));
['dragleave', 'drop'].forEach(ev =>
    dropArea.addEventListener(ev, e => { e.preventDefault(); dropArea.style.borderColor = ''; }));
dropArea.addEventListener('drop', e => {
    e.preventDefault();
    if (e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        handleFile(e.dataTransfer.files[0]);
    }
});

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const msgBox = document.getElementById('msgBox');
    const btn    = document.getElementById('submitBtn');
    msgBox.style.display = 'none';
    msgBox.className = 'up-msg';
    btn.disabled = true; btn.textContent = '上传中...';

    const fd = new FormData(this);
    fetch('../actions/sheet_upload.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                msgBox.className = 'up-msg ok';
                msgBox.textContent = '✓ 上传成功，跳转中...';
                msgBox.style.display = 'block';
                setTimeout(() => location.href = 'sheet_detail.php?id=' + data.id, 800);
            } else {
                msgBox.className = 'up-msg err';
                msgBox.textContent = '✗ ' + (data.msg || '上传失败');
                msgBox.style.display = 'block';
                btn.disabled = false; btn.textContent = '提交上传';
            }
        })
        .catch(err => {
            msgBox.className = 'up-msg err';
            msgBox.textContent = '✗ 网络错误：' + err.message;
            msgBox.style.display = 'block';
            btn.disabled = false; btn.textContent = '提交上传';
        });
});
</script>
</body>
</html>

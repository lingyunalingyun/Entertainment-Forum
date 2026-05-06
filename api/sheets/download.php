<?php
// 透传到 actions/sheet_download.php，保持路径稳定供客户端调用
$_GET['id'] = (int)($_GET['id'] ?? 0);
require __DIR__ . '/../../actions/sheet_download.php';

<?php
// Streams a purchase's scanned supplier bill to signed-in users. Files live outside public_html.
require __DIR__ . '/boot.php';
require APP_ROOT . '/app/ai_ocr.php';
require_login();

$file = (string)q('SELECT bill_file FROM purchases WHERE id = ?', [(int)get('id')])->fetchColumn();
$path = $file ? bill_file_path($file) : null;
if (!$path) {
    http_response_code(404);
    exit('Bill file not found.');
}
$mime = array_search(pathinfo($path, PATHINFO_EXTENSION), BILL_MIME, true);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="supplier-bill-' . (int)get('id') . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, max-age=3600');
readfile($path);

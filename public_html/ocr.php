<?php
// Receives a supplier bill photo/PDF, stores it privately, reads it with Gemini and returns
// purchase-form rows as JSON.
require __DIR__ . '/boot.php';
require APP_ROOT . '/app/ai_ocr.php';

header('Content-Type: application/json; charset=utf-8');
$fail = function (string $msg, int $code = 422) {
    http_response_code($code);
    exit(json_encode(['error' => $msg]));
};

if (!user()) $fail('You are signed out. Please sign in again.', 401);
if (!is_post()) $fail('Method not allowed', 405);
if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) $fail('Form expired. Refresh the page and try again.', 400);

$f = $_FILES['bill'] ?? null;
if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    $fail(in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'File is too large (max 10 MB).' : 'Choose a photo or PDF of the bill.');
}
if ($f['size'] > BILL_MAX_BYTES) $fail('File is too large (max 10 MB).');
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
if (!isset(BILL_MIME[$mime])) $fail('Only JPG, PNG, WEBP photos or PDF files are allowed.');

$name = bin2hex(random_bytes(16)) . '.' . BILL_MIME[$mime];
if (!move_uploaded_file($f['tmp_name'], bills_dir() . '/' . $name)) $fail('Could not save the uploaded file.', 500);

// Release the session lock so the rest of the app stays usable during the slow AI call.
session_write_close();
set_time_limit(150);

try {
    $prefill = build_purchase_prefill(gemini_read_bill(bills_dir() . '/' . $name, $mime));
} catch (RuntimeException $e) {
    @unlink(bills_dir() . '/' . $name);
    $fail($e->getMessage());
}
$prefill['bill_file'] = $name;
echo json_encode($prefill);

<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration missing: copy config/config.example.php to config/config.php');
}
$GLOBALS['config'] = require $configFile;

function config(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

date_default_timezone_set(config('timezone', 'Asia/Kolkata'));
$isProd = config('env') === 'production';
error_reporting(E_ALL);
ini_set('display_errors', $isProd ? '0' : '1');

if (PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (config('force_https') && !$https) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'");
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    session_name('msm_session');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/layout.php';

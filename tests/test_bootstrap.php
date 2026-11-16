<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// Minimal config for test runner
$GLOBALS['config'] = [
    'env' => 'testing',
    'app_key' => '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff',
    'timezone' => 'Asia/Kolkata',
    'demo_mode' => false,
    'gemini_api_key' => '',
];

function config(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

// Require isolated helpers without triggering session_start or db connect
require_once APP_ROOT . '/app/helpers.php';

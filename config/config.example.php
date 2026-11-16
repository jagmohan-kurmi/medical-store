<?php
/**
 * Medical Store Management - Configuration Template
 *
 * Copy this file to config/config.php (or set environment variables) ON THE SERVER.
 * The config/ directory must sit OUTSIDE public_html, never inside it.
 *
 * Generate secrets with:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 */

return [
    // 'production' hides errors and sets secure cookies. Use 'development' only for local debugging.
    'env' => getenv('APP_ENV') ?: 'production',

    // 64-character hexadecimal key used for IP hashing (rate limiting) and cryptographic operations.
    'app_key' => getenv('APP_KEY') ?: '',

    // Database connection parameters
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: '',
        'user' => getenv('DB_USER') ?: '',
        'pass' => getenv('DB_PASS') ?: '',
    ],

    // One-time web installer token.
    // Set a long random value, open /setup.php?token=<value>, create the first admin,
    // then set this back to ''.
    'setup_token' => getenv('SETUP_TOKEN') ?: '',

    // Automatically redirect HTTP to HTTPS. Recommended true in production.
    'force_https' => (bool)(getenv('FORCE_HTTPS') ?: true),

    // Standard timezone for billing, stock timestamps, and tax reports.
    'timezone' => getenv('TIMEZONE') ?: 'Asia/Kolkata',

    // Demo mode: allows one-click sign-in without password.
    // WARNING: MUST NEVER BE TRUE ON A LIVE PRODUCTION STORE.
    'demo_mode' => (bool)(getenv('DEMO_MODE') ?: false),

    // Google Gemini API key for AI supplier bill OCR scanning.
    // Can also be configured securely in Store Settings within the web app.
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
];

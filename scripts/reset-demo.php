<?php
// Cron for the public demo: wipes all data and reloads fresh sample data. Only works with demo_mode.
//   php scripts/reset-demo.php            reset now
//   php scripts/reset-demo.php --hour=3   reset only during 03:00-03:59 India time (for an hourly cron,
//                                         so the server's own time zone does not matter)
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/demo.php';

foreach ($argv as $arg) {
    if (preg_match('/^--hour=(\d{1,2})$/', $arg, $m) && (int)date('G') !== (int)$m[1]) {
        exit;
    }
}
echo date('c'), ' ', demo_reset(), PHP_EOL;

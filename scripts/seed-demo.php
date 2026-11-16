<?php
// Loads demo data into an EMPTY database: php scripts/seed-demo.php
// Users: admin / admin12345 and staff / staff12345. Refuses to run if any sale already exists.
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/demo.php';

echo demo_seed(), PHP_EOL;

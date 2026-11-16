<?php
require __DIR__ . '/boot.php';

if (is_post()) {
    csrf_check();
    $_SESSION = [];
    session_destroy();
}
redirect('login.php');

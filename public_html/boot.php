<?php
// Loads the private app. It lives next to public_html when the host allows that, otherwise in
// public_html/_app, which .htaccess locks against all web access.
require is_file(__DIR__ . '/../app/bootstrap.php') ? __DIR__ . '/../app/bootstrap.php' : __DIR__ . '/_app/app/bootstrap.php';

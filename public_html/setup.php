<?php
// One-time installer: creates the tables and the first admin. Locked once an admin exists
// or when config 'setup_token' is empty.
require __DIR__ . '/boot.php';

$token = (string)config('setup_token', '');
if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? $_POST['token'] ?? ''))) {
    http_response_code(404);
    exit('Not found');
}

require APP_ROOT . '/app/demo.php';
apply_schema();

if (config('demo_mode') === true) {
    // Public demo: load the sample store (does nothing if bills already exist) and open it.
    demo_seed();
    redirect('login.php?demo=admin');
}

if ((int)q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0) {
    exit('Setup already completed. Set setup_token to an empty value in config/config.php.');
}

$error = '';
if (is_post()) {
    csrf_check();
    $name = post('name');
    $username = post('username');
    $pass = post('password');
    if ($name === '' || !preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
        $error = 'Enter a name and a username of 3-50 letters/digits.';
    } elseif (strlen($pass) < 10) {
        $error = 'Password must be at least 10 characters.';
    } else {
        q("INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, 'admin')",
            [$name, $username, password_hash($pass, PASSWORD_DEFAULT)]);
        $defaults = [
            'store_name' => post('store_name') ?: 'Medical Store',
            'address' => '', 'phone' => '', 'gstin' => '', 'dl_no' => '',
            'expiry_alert_days' => '90',
            'invoice_footer' => 'Goods once sold will not be taken back without bill. Get well soon!',
        ];
        foreach ($defaults as $k => $v) {
            q('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)', [$k, $v]);
        }
        flash('Setup complete. Now remove setup_token from config/config.php and sign in.');
        redirect('login.php');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Store Setup</title>
<link rel="stylesheet" href="assets/app.css?v=2">
</head>
<body class="auth-page">
  <form method="post" class="auth-card">
    <h1>First-time setup</h1>
    <p class="muted">Tables are ready. Create the store admin account.</p>
    <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label>Store name<input name="store_name" required value="<?= e(post('store_name')) ?>"></label>
    <label>Admin full name<input name="name" required value="<?= e(post('name')) ?>"></label>
    <label>Username<input name="username" required value="<?= e(post('username')) ?>"></label>
    <label>Password (10+ characters)<input type="password" name="password" minlength="10" required></label>
    <button class="btn btn-primary btn-block">Create admin</button>
  </form>
</body>
</html>

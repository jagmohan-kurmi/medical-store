<?php
require __DIR__ . '/boot.php';

// Demo builds only: one-click sign-in (and user switching) as the seeded accounts.
$demo = config('demo_mode') === true;
if ($demo && in_array(get('demo'), ['admin', 'staff'], true)) {
    $u = q('SELECT id FROM users WHERE username = ? AND active = 1', [get('demo')])->fetch();
    if ($u) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        redirect(get('demo') === 'staff' ? 'billing.php' : 'index.php');
    }
}

if (user()) {
    redirect('index.php');
}

$error = '';
if (is_post()) {
    csrf_check();
    $ip = client_ip_hash();
    $recent = (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE', [$ip])->fetchColumn();
    if ($recent >= 8) {
        $error = 'Too many wrong attempts. Please wait 15 minutes and try again.';
    } else {
        $u = q('SELECT id, password_hash FROM users WHERE username = ? AND active = 1', [post('username')])->fetch();
        if ($u && password_verify(post('password'), $u['password_hash'])) {
            q('DELETE FROM login_attempts WHERE ip_hash = ?', [$ip]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            redirect('index.php');
        }
        q('INSERT INTO login_attempts (ip_hash) VALUES (?)', [$ip]);
        $error = 'Wrong username or password.';
    }
}
$store = setting('store_name', 'Medical Store');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · <?= e($store) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=2">
</head>
<body class="auth-page">
  <form method="post" class="auth-card">
    <div class="brand brand-center"><span class="brand-mark">+</span><div><strong><?= e($store) ?></strong><small>Store Manager</small></div></div>
    <h1>Sign in</h1>
    <?php foreach (flash() as [$type, $msg]): ?><div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div><?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label>Username<input name="username" autocomplete="username" required autofocus value="<?= e(post('username')) ?>"></label>
    <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn btn-primary btn-block">Sign in</button>
    <?php if ($demo): ?>
    <div class="demo-box">
      <p>Demo — one click, no password</p>
      <div><a class="btn btn-block" href="login.php?demo=admin">Try as Owner</a><a class="btn btn-block" href="login.php?demo=staff">Try as Counter Staff</a></div>
    </div>
    <?php endif; ?>
  </form>
</body>
</html>

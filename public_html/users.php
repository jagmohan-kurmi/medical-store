<?php
require __DIR__ . '/boot.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    $id = (int)post('id');
    $name = mb_substr(post('name'), 0, 100);
    $username = post('username');
    $role = post('role') === 'admin' ? 'admin' : 'staff';
    $active = post('active') === '1' ? 1 : 0;
    $pass = post('password');
    $err = null;
    if ($name === '' || !preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) $err = 'Enter a name and a username of 3-50 letters/digits.';
    elseif (!$id && strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
    elseif ($pass !== '' && strlen($pass) < 8) $err = 'New password must be at least 8 characters.';
    elseif ($id === (int)$me['id'] && ($role !== 'admin' || !$active)) $err = 'You cannot remove your own admin access.';
    elseif (q('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id])->fetch()) $err = 'This username is already taken.';
    if ($err) {
        flash($err, 'err');
        redirect('users.php?edit=' . $id);
    }
    if ($id) {
        q('UPDATE users SET name = ?, username = ?, role = ?, active = ? WHERE id = ?', [$name, $username, $role, $active, $id]);
        if ($pass !== '') q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
        flash('Staff account updated.');
    } else {
        q('INSERT INTO users (name, username, password_hash, role, active) VALUES (?, ?, ?, ?, ?)', [$name, $username, password_hash($pass, PASSWORD_DEFAULT), $role, $active]);
        flash('Staff account created.');
    }
    redirect('users.php');
}

if (isset($_GET['edit'])) {
    $u = (int)$_GET['edit'] ? q('SELECT * FROM users WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;
    $u = $u ?: ['id' => 0, 'name' => '', 'username' => '', 'role' => 'staff', 'active' => 1];
    layout_header($u['id'] ? 'Edit staff' : 'Add staff');
    ?>
    <form method="post" class="card form-grid">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <label class="span-2">Full name<input name="name" required value="<?= e($u['name']) ?>"></label>
      <label class="span-2">Username<input name="username" required value="<?= e($u['username']) ?>" autocomplete="off"></label>
      <label class="span-2"><?= $u['id'] ? 'New password (leave blank to keep)' : 'Password' ?><input type="password" name="password" minlength="8" <?= $u['id'] ? '' : 'required' ?> autocomplete="new-password"></label>
      <label>Role<select name="role"><option value="staff">Staff (billing, stock entry)</option><option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin (everything)</option></select></label>
      <label>Status<select name="active"><option value="1">Active</option><option value="0" <?= !$u['active'] ? 'selected' : '' ?>>Disabled</option></select></label>
      <div class="span-4 form-actions"><a class="btn" href="users.php">Cancel</a><button class="btn btn-primary">Save</button></div>
    </form>
    <?php
    layout_footer();
    exit;
}

$rows = q('SELECT * FROM users ORDER BY active DESC, name')->fetchAll();
layout_header('Staff');
?>
<div class="toolbar"><p class="muted">Staff can make bills, add medicines and purchases. Only admins can cancel bills, remove stock, see reports and change settings.</p><a class="btn btn-primary" href="users.php?edit=0">+ Add staff</a></div>
<div class="card flush"><div class="table-wrap"><table class="table">
  <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th></th></tr></thead>
  <?php foreach ($rows as $u): ?>
    <tr><td><?= e($u['name']) ?></td><td><?= e($u['username']) ?></td><td><?= e(ucfirst($u['role'])) ?></td>
      <td><span class="tag <?= $u['active'] ? 'tag-ok' : '' ?>"><?= $u['active'] ? 'Active' : 'Disabled' ?></span></td>
      <td class="num"><a class="btn btn-sm" href="users.php?edit=<?= (int)$u['id'] ?>">Edit</a></td></tr>
  <?php endforeach; ?>
</table></div></div>
<?php layout_footer();

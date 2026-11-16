<?php
declare(strict_types=1);

const NAV = [
    ['index.php', 'Dashboard', 'grid', false],
    ['billing.php', 'New Bill', 'plus', false],
    ['sales.php', 'Sales', 'receipt', false],
    ['customers.php', 'Customers (उधार)', 'user-check', false],
    ['shortage.php', 'Shortage Book', 'bookmark', false],
    ['medicines.php', 'Medicines', 'pill', false],
    ['stock.php', 'Stock & Expiry', 'box', false],
    ['purchases.php', 'Purchases', 'truck', false],
    ['suppliers.php', 'Suppliers', 'users', false],
    ['reports.php', 'Reports', 'chart', true],
    ['users.php', 'Staff', 'shield', true],
    ['settings.php', 'Settings', 'gear', true],
];

const ICONS = [
    'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'plus' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
    'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
    'pill' => '<rect x="3" y="8.5" width="18" height="7" rx="3.5" transform="rotate(-35 12 12)"/><path d="M9.2 8l5.6 8"/>',
    'box' => '<path d="M3 7l9-4 9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7M12 11v10"/>',
    'truck' => '<path d="M3 6h11v10H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
    'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 010 7M18.5 14.8c1.6.8 2.6 2.6 3 5.2"/>',
    'user-check' => '<path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
    'bookmark' => '<path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="13" y2="13"/>',
    'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/>',
    'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
    'logout' => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/>',
    'moon' => '<path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>',
    'sun' => '<circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>',
];

function icon(string $name): string
{
    return '<svg class="ico ico-' . e($name) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . (ICONS[$name] ?? '') . '</svg>';
}

function layout_header(string $title): void
{
    $u = user();
    $current = basename($_SERVER['SCRIPT_NAME']);
    $store = setting('store_name', 'Medical Store');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($store) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=3">
<script>
  (function(){
    var t = localStorage.getItem('theme');
    if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.classList.add('dark');
    }
  })();
</script>
<script src="assets/app.js?v=3" defer></script>
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="brand-mark">+</span>
      <div><strong><?= e($store) ?></strong><small>Store Manager</small></div>
    </div>
    <nav>
      <?php foreach (NAV as [$href, $label, $ic, $adminOnly]):
          if ($adminOnly && $u['role'] !== 'admin') continue; ?>
        <a href="<?= $href ?>" class="<?= $current === $href ? 'active' : '' ?>"><?= icon($ic) ?><span><?= $label ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-user">
      <div><strong><?= e($u['name']) ?></strong><small><?= e(ucfirst($u['role'])) ?></small></div>
      <form method="post" action="logout.php"><?= csrf_field() ?><button class="icon-btn" title="Log out"><?= icon('logout') ?></button></form>
    </div>
  </aside>
  <main class="main">
    <?php if (config('demo_mode') === true): ?>
      <div class="demo-banner">Demo mode — sample data. Switch user:
        <a href="login.php?demo=admin">Owner</a> · <a href="login.php?demo=staff">Counter Staff</a></div>
    <?php endif; ?>
    <header class="topbar">
      <button class="menu-btn" id="menuBtn" aria-label="Menu">☰</button>
      <h1><?= e($title) ?></h1>
      <div class="topbar-actions">
        <button type="button" class="icon-btn theme-toggle" id="themeBtn" title="Toggle Dark/Light Mode"><?= icon('moon') ?></button>
        <a class="btn btn-primary btn-sm topbar-cta" href="billing.php">+ New Bill</a>
      </div>
    </header>
    <div class="content">
    <?php foreach (flash() as [$type, $msg]): ?>
      <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
    <?php endforeach;
}

function layout_footer(): void
{
    ?>
    </div>
  </main>
</div>
</body>
</html>
<?php
}

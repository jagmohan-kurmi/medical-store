<?php
require __DIR__ . '/boot.php';
$me = require_login();

if (is_post() && post('action') === 'cancel') {
    csrf_check();
    require_admin();
    $id = (int)post('id');
    $pdo = db();
    $pdo->beginTransaction();
    $s = q("SELECT id, bill_no, customer_id, total, payment_mode FROM sales WHERE id = ? AND status = 'active' FOR UPDATE", [$id])->fetch();
    if ($s) {
        foreach (q('SELECT batch_id, qty FROM sale_items WHERE sale_id = ?', [$id])->fetchAll() as $i) {
            q('UPDATE batches SET qty_available = qty_available + ? WHERE id = ?', [$i['qty'], $i['batch_id']]);
        }
        // If it was Credit, reverse customer balance
        if ($s['payment_mode'] === 'Credit' && $s['customer_id']) {
            q('UPDATE customers SET balance = balance - ? WHERE id = ?', [$s['total'], $s['customer_id']]);
        }
        q("UPDATE sales SET status = 'cancelled' WHERE id = ?", [$id]);
        flash("Bill {$s['bill_no']} cancelled, stock returned" . ($s['payment_mode'] === 'Credit' ? " and customer balance reversed." : "."));
    }
    $pdo->commit();
    redirect('sales.php');
}

$from = valid_date(get('from')) ? get('from') : date('Y-m-d');
$to = valid_date(get('to')) ? get('to') : date('Y-m-d');
$search = get('q');
$modeF = get('mode');
$where = ['s.bill_date >= ?', 's.bill_date < ? + INTERVAL 1 DAY'];
$params = [$from, $to];
if ($search !== '') {
    $where[] = '(s.bill_no LIKE ? OR s.customer_name LIKE ? OR s.customer_phone LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if (in_array($modeF, PAYMENT_MODES, true)) {
    $where[] = 's.payment_mode = ?';
    $params[] = $modeF;
}
$rows = q('SELECT s.*, u.name cashier,
        (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) items,
        (SELECT COUNT(*) FROM sale_returns sr WHERE sr.sale_id = s.id) return_count
    FROM sales s LEFT JOIN users u ON u.id = s.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id DESC LIMIT 500', $params)->fetchAll();
$active = array_filter($rows, fn($r) => $r['status'] === 'active');

layout_header('Sales');
?>
<div class="toolbar">
  <form class="search" method="get">
    <input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>">
    <input name="q" value="<?= e($search) ?>" placeholder="Bill no, name or mobile">
    <select name="mode"><option value="">All modes</option><?php foreach (PAYMENT_MODES as $pm): ?><option <?= $modeF === $pm ? 'selected' : '' ?>><?= $pm ?></option><?php endforeach; ?></select>
    <button class="btn">Show</button>
  </form>
</div>
<div class="stats stats-3">
  <div class="stat"><span>Bills</span><strong><?= count($active) ?></strong></div>
  <div class="stat"><span>Net sales</span><strong><?= money(array_sum(array_column($active, 'total'))) ?></strong></div>
  <div class="stat"><span>Discount given</span><strong><?= money(array_sum(array_column($active, 'discount'))) ?></strong></div>
</div>
<div class="card flush">
  <?php if (!$rows): ?><p class="empty">No bills in this period.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Bill</th><th>Customer</th><th>Items</th><th>Mode</th><th>By</th><th class="num">Total</th><th class="num">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr class="<?= $s['status'] === 'cancelled' ? 'row-void' : '' ?>">
        <td>
          <a href="invoice.php?id=<?= (int)$s['id'] ?>"><strong><?= e($s['bill_no']) ?></strong></a>
          <small class="block muted"><?= date('d M Y, h:i A', strtotime($s['bill_date'])) ?></small>
          <?php if ($s['return_count'] > 0): ?><small class="block tag tag-warn">Return Issued</small><?php endif; ?>
        </td>
        <td>
          <?php if ($s['customer_id']): ?>
            <a href="customers.php?id=<?= (int)$s['customer_id'] ?>"><strong><?= e($s['customer_name'] ?: 'Customer') ?></strong></a>
          <?php else: ?>
            <strong><?= e($s['customer_name'] ?: 'Walk-in') ?></strong>
          <?php endif; ?>
          <?php if ($s['customer_phone']): ?><small class="block muted"><?= e($s['customer_phone']) ?></small><?php endif; ?>
        </td>
        <td><?= (int)$s['items'] ?></td>
        <td><span class="tag <?= $s['payment_mode'] === 'Credit' ? 'tag-warn' : '' ?>"><?= e($s['payment_mode']) ?></span></td>
        <td><?= e($s['cashier'] ?? '') ?></td>
        <td class="num">
          <?= money($s['total']) ?>
          <?php if ($s['status'] === 'cancelled'): ?><small class="block tag tag-danger">Cancelled</small><?php endif; ?>
        </td>
        <td class="num" style="white-space: nowrap;">
          <a class="btn btn-sm" href="invoice.php?id=<?= (int)$s['id'] ?>">View</a>
          <?php if ($s['status'] === 'active'): ?>
            <a class="btn btn-sm btn-outline" href="returns.php?sale_id=<?= (int)$s['id'] ?>" title="Return items from this bill">Return</a>
          <?php endif; ?>
          <?php if ($me['role'] === 'admin' && $s['status'] === 'active'): ?>
            <form method="post" data-confirm="Cancel bill <?= e($s['bill_no']) ?>? Stock will be added back." style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-sm">Cancel</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>

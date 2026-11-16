<?php
require __DIR__ . '/boot.php';
$me = require_login();

$alertDays = max(1, (int)setting('expiry_alert_days', '90'));

$today = q("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM sales WHERE status='active' AND DATE(bill_date) = CURDATE()")->fetch();
$month = q("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM sales WHERE status='active' AND bill_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetch();
$credit = q("SELECT COALESCE(SUM(total),0) FROM sales WHERE status='active' AND payment_mode='Credit' AND bill_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();

$totalCustBalance = (float)q("SELECT COALESCE(SUM(balance),0) FROM customers WHERE balance > 0")->fetchColumn();
$debtorCount = (int)q("SELECT COUNT(*) FROM customers WHERE balance > 0")->fetchColumn();
$shortagePending = (int)q("SELECT COUNT(*) FROM shortage_book WHERE status = 'pending'")->fetchColumn();

$lowStock = q("SELECT m.id, m.name, m.pack_size, m.unit_label, m.reorder_level,
        COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.qty_available END),0) stock
    FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
    WHERE m.active = 1 GROUP BY m.id HAVING stock <= m.reorder_level ORDER BY stock ASC LIMIT 8")->fetchAll();
$lowCount = (int)q("SELECT COUNT(*) FROM (SELECT m.id FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
    WHERE m.active = 1 GROUP BY m.id
    HAVING COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.qty_available END),0) <= MAX(m.reorder_level)) x")->fetchColumn();

$expiring = q("SELECT b.id, b.batch_no, b.expiry_date, b.qty_available, m.name, m.pack_size, m.unit_label
    FROM batches b JOIN medicines m ON m.id = b.medicine_id
    WHERE b.qty_available > 0 AND b.expiry_date < CURDATE() + INTERVAL ? DAY
    ORDER BY b.expiry_date ASC LIMIT 8", [$alertDays])->fetchAll();
$expiredCount = (int)q("SELECT COUNT(*) FROM batches WHERE qty_available > 0 AND expiry_date < CURDATE()")->fetchColumn();
$expiringCount = (int)q("SELECT COUNT(*) FROM batches WHERE qty_available > 0 AND expiry_date >= CURDATE() AND expiry_date < CURDATE() + INTERVAL ? DAY", [$alertDays])->fetchColumn();

$recent = q("SELECT id, bill_no, bill_date, customer_name, total, payment_mode, status FROM sales ORDER BY id DESC LIMIT 8")->fetchAll();

$week = q("SELECT DATE(bill_date) d, SUM(total) t FROM sales WHERE status='active' AND bill_date >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(bill_date)")->fetchAll(PDO::FETCH_KEY_PAIR);
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $days[$d] = (float)($week[$d] ?? 0);
}
$maxDay = max(1, max($days));

layout_header('Dashboard');
?>
<div class="stats stats-6" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px;">
  <div class="stat"><span>Today's Sales</span><strong><?= money($today['t']) ?></strong><small><?= (int)$today['n'] ?> bills</small></div>
  <div class="stat"><span>This Month</span><strong><?= money($month['t']) ?></strong><small><?= (int)$month['n'] ?> bills · <?= money($credit) ?> credit</small></div>
  <a class="stat stat-warn" href="customers.php"><span>Customer Udhaar (उधार)</span><strong><?= money($totalCustBalance) ?></strong><small><?= $debtorCount ?> customers with pending balance</small></a>
  <a class="stat stat-warn" href="shortage.php"><span>Shortage Book</span><strong><?= $shortagePending ?></strong><small>pending medicine demands</small></a>
  <a class="stat stat-warn" href="stock.php?filter=low"><span>Low Stock</span><strong><?= $lowCount ?></strong><small>medicines at / below reorder</small></a>
  <a class="stat stat-danger" href="stock.php?filter=expiring"><span>Expiry Alerts</span><strong><?= $expiredCount + $expiringCount ?></strong><small><?= $expiredCount ?> expired · <?= $expiringCount ?> expiring soon</small></a>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Sales Trend (Last 7 Days)</h2></div>
    <div class="bars">
      <?php foreach ($days as $d => $t): ?>
        <div class="bar-col" title="<?= e(fmt_date($d) . ': ' . money($t)) ?>">
          <span class="bar-val"><?= $t > 0 ? '₹' . number_format($t / 1000, 1) . 'k' : '' ?></span>
          <div class="bar" data-h="<?= (int)round($t / $maxDay * 100) ?>"></div>
          <small><?= date('D', strtotime($d)) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Recent Bills</h2><a href="sales.php">View all</a></div>
    <?php if (!$recent): ?><p class="empty">No bills yet. <a href="billing.php">Create the first bill</a>.</p><?php else: ?>
    <table class="table compact">
      <?php foreach ($recent as $s): ?>
        <tr>
          <td><a href="invoice.php?id=<?= (int)$s['id'] ?>"><?= e($s['bill_no']) ?></a><small class="block muted"><?= date('d M, h:i A', strtotime($s['bill_date'])) ?></small></td>
          <td><?= e($s['customer_name'] ?: 'Walk-in') ?></td>
          <td class="num"><?= money($s['total']) ?><?php if ($s['status'] === 'cancelled'): ?><span class="tag tag-danger">Cancelled</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Low Stock Alerts</h2><a href="stock.php?filter=low">See all</a></div>
    <?php if (!$lowStock): ?><p class="empty">All medicines are above reorder level.</p><?php else: ?>
    <table class="table compact">
      <?php foreach ($lowStock as $m): ?>
        <tr>
          <td><a href="medicines.php?view=<?= (int)$m['id'] ?>"><?= e($m['name']) ?></a></td>
          <td class="num"><span class="tag <?= $m['stock'] == 0 ? 'tag-danger' : 'tag-warn' ?>"><?= e(units_label((int)$m['stock'], (int)$m['pack_size'], $m['unit_label'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Expiring / Expired</h2><a href="stock.php?filter=expiring">See all</a></div>
    <?php if (!$expiring): ?><p class="empty">No batches expiring in the next <?= $alertDays ?> days.</p><?php else: ?>
    <table class="table compact">
      <?php foreach ($expiring as $b): $expired = $b['expiry_date'] < date('Y-m-d'); ?>
        <tr>
          <td><a href="medicines.php?view=<?= (int)$b['medicine_id'] ?>"><?= e($b['name']) ?></a><small class="block muted">Batch <?= e($b['batch_no']) ?></small></td>
          <td class="num"><span class="tag <?= $expired ? 'tag-danger' : 'tag-warn' ?>"><?= fmt_expiry($b['expiry_date']) ?></span><small class="block muted"><?= (int)$b['qty_available'] ?> <?= e($b['unit_label']) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>
</div>
<?php layout_footer(); ?>

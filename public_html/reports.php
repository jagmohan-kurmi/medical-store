<?php
require __DIR__ . '/boot.php';
require_admin();

$from = valid_date(get('from')) ? get('from') : date('Y-m-01');
$to = valid_date(get('to')) ? get('to') : date('Y-m-d');
$range = [$from, $to];
$inRange = "s.status = 'active' AND s.bill_date >= ? AND s.bill_date < ? + INTERVAL 1 DAY";
$tab = get('tab', 'summary');

// 1. Export Sales CSV
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Bill No', 'Date', 'Customer', 'Mobile', 'Doctor', 'Payment', 'Gross', 'Discount', 'Taxable', 'GST', 'Round off', 'Total', 'Status']);
    $st = q('SELECT bill_no, bill_date, customer_name, customer_phone, doctor_name, payment_mode, gross, discount, taxable, gst_amount, round_off, total, status
        FROM sales s WHERE s.bill_date >= ? AND s.bill_date < ? + INTERVAL 1 DAY ORDER BY id', $range);
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
        fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r));
    }
    exit;
}

// 2. Export Schedule H1 Register CSV
if (get('export') === 'h1_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="schedule-h1-register-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date of Supply', 'Bill No', 'Patient Name', 'Patient Mobile', 'Prescribing Doctor', 'Drug Name', 'Composition (Salt)', 'Schedule', 'Batch No', 'Expiry', 'Quantity Supplied', 'Manufacturer']);
    $st = q("SELECT s.bill_date, s.bill_no, s.customer_name, s.customer_phone, s.doctor_name,
            m.name, m.generic_name, m.schedule, b.batch_no, b.expiry_date, si.qty, m.manufacturer
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN medicines m ON m.id = si.medicine_id
        JOIN batches b ON b.id = si.batch_id
        WHERE $inRange AND m.schedule IN ('H1', 'X', 'H')
        ORDER BY s.bill_date DESC, s.id DESC", $range);
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
        fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r));
    }
    exit;
}

layout_header('Reports & Audits');
?>
<div class="toolbar">
  <div class="tabs">
    <a href="?<?= http_build_query(['from' => $from, 'to' => $to, 'tab' => 'summary']) ?>" class="<?= $tab === 'summary' ? 'active' : '' ?>">Financial &amp; GST Summary</a>
    <a href="?<?= http_build_query(['from' => $from, 'to' => $to, 'tab' => 'h1']) ?>" class="<?= $tab === 'h1' ? 'active' : '' ?>">Schedule H1 / Rx Register</a>
  </div>
  <form class="search" method="get">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>">
    <button class="btn">Filter</button>
  </form>
  <?php if ($tab === 'summary'): ?>
    <a class="btn" href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>">Download Sales CSV</a>
  <?php else: ?>
    <a class="btn btn-primary" href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'h1_csv'])) ?>">📥 Export Schedule H1 Register CSV</a>
  <?php endif; ?>
</div>

<?php if ($tab === 'h1'):
  // Schedule H1 / H / X Audit Log
  $h1Items = q("SELECT s.bill_date, s.bill_no, s.customer_name, s.customer_phone, s.doctor_name, s.id sid,
          m.name, m.generic_name, m.schedule, m.unit_label, b.batch_no, b.expiry_date, si.qty, m.manufacturer
      FROM sale_items si
      JOIN sales s ON s.id = si.sale_id
      JOIN medicines m ON m.id = si.medicine_id
      JOIN batches b ON b.id = si.batch_id
      WHERE $inRange AND m.schedule IN ('H1', 'X', 'H')
      ORDER BY s.bill_date DESC, s.id DESC LIMIT 500", $range)->fetchAll();
?>
  <div class="card">
    <div class="card-head">
      <div>
        <h2>Schedule H1, H &amp; X Drug Register</h2>
        <p class="muted">Statutory record of prescription drugs sold under Drugs and Cosmetics Act for Drug Inspector audits.</p>
      </div>
      <button class="btn btn-outline" onclick="window.print()">Print Register</button>
    </div>
    <?php if (!$h1Items): ?>
      <p class="empty">No Schedule H/H1/X medicines sold in this date range.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="table compact">
      <thead>
        <tr>
          <th>Date of Supply</th>
          <th>Bill No</th>
          <th>Patient Name &amp; Contact</th>
          <th>Prescribing Doctor</th>
          <th>Drug &amp; Strength</th>
          <th>Schedule</th>
          <th>Batch No</th>
          <th>Exp</th>
          <th class="num">Qty</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($h1Items as $h): ?>
          <tr>
            <td><?= date('d-m-Y h:i A', strtotime($h['bill_date'])) ?></td>
            <td><a href="invoice.php?id=<?= (int)$h['sid'] ?>"><strong><?= e($h['bill_no']) ?></strong></a></td>
            <td>
              <strong><?= e($h['customer_name'] ?: 'Walk-in') ?></strong>
              <?php if ($h['customer_phone']): ?><small class="block muted"><?= e($h['customer_phone']) ?></small><?php endif; ?>
            </td>
            <td><span style="font-weight: 600;"><?= e($h['doctor_name'] ?: 'Dr. Not Recorded') ?></span></td>
            <td>
              <strong><?= e($h['name']) ?></strong>
              <small class="block muted"><?= e($h['generic_name']) ?></small>
            </td>
            <td><span class="tag <?= $h['schedule'] === 'H1' ? 'tag-danger' : 'tag-warn' ?>">Rx <?= e($h['schedule']) ?></span></td>
            <td><?= e($h['batch_no']) ?></td>
            <td><?= fmt_expiry($h['expiry_date']) ?></td>
            <td class="num"><strong><?= (int)$h['qty'] ?></strong> <?= e($h['unit_label']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php else:
  // Summary Tab
  $sum = q("SELECT COUNT(*) bills, COALESCE(SUM(gross),0) gross, COALESCE(SUM(discount),0) discount, COALESCE(SUM(taxable),0) taxable,
      COALESCE(SUM(gst_amount),0) gst, COALESCE(SUM(total),0) total FROM sales s WHERE $inRange", $range)->fetch();
  $cost = (float)q("SELECT COALESCE(SUM(si.qty * si.unit_cost),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE $inRange", $range)->fetchColumn();
  $modes = q("SELECT payment_mode, COUNT(*) n, SUM(total) t FROM sales s WHERE $inRange GROUP BY payment_mode ORDER BY t DESC", $range)->fetchAll();
  $gst = q("SELECT si.gst_rate, SUM(si.taxable) taxable, SUM(si.gst_amount) tax FROM sale_items si JOIN sales s ON s.id = si.sale_id
      WHERE $inRange GROUP BY si.gst_rate ORDER BY si.gst_rate", $range)->fetchAll();
  $top = q("SELECT m.name, m.unit_label, SUM(si.qty) qty, SUM(si.amount) amount, SUM(si.amount - si.qty * si.unit_cost) margin
      FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN medicines m ON m.id = si.medicine_id
      WHERE $inRange GROUP BY m.id ORDER BY amount DESC LIMIT 15", $range)->fetchAll();
  $purch = q('SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM purchases WHERE invoice_date BETWEEN ? AND ?', $range)->fetch();
  $daily = q("SELECT DATE(bill_date) d, COUNT(*) n, SUM(total) t FROM sales s WHERE $inRange GROUP BY DATE(bill_date) ORDER BY d", $range)->fetchAll();
  $returnsSum = q("SELECT COUNT(*) n, COALESCE(SUM(total_refund),0) t FROM sale_returns WHERE return_date >= ? AND return_date < ? + INTERVAL 1 DAY", $range)->fetch();
?>
  <div class="stats">
    <div class="stat"><span>Net Sales</span><strong><?= money($sum['total']) ?></strong><small><?= (int)$sum['bills'] ?> bills</small></div>
    <div class="stat"><span>GST Collected</span><strong><?= money($sum['gst']) ?></strong><small>on taxable <?= money($sum['taxable']) ?></small></div>
    <div class="stat"><span>Gross Margin (approx.)</span><strong><?= money($sum['total'] - $cost) ?></strong><small>sales minus purchase cost</small></div>
    <div class="stat"><span>Purchases</span><strong><?= money($purch['t']) ?></strong><small><?= (int)$purch['n'] ?> invoices</small></div>
  </div>

  <?php if ($returnsSum['n'] > 0): ?>
    <div style="margin-bottom: 20px; padding: 12px 16px; background: #fee2e2; border-radius: 8px; font-size: 13px; color: #991b1b; display: flex; justify-content: space-between; align-items: center;">
      <span><strong>Sales Returns / Credit Notes:</strong> <?= (int)$returnsSum['n'] ?> returns processed in this period</span>
      <strong style="font-size: 16px;"><?= money($returnsSum['t']) ?></strong>
    </div>
  <?php endif; ?>

  <div class="grid-2">
    <section class="card">
      <div class="card-head"><h2>GST Summary</h2></div>
      <?php if (!$gst): ?><p class="empty">No sales.</p><?php else: ?>
      <table class="table compact">
        <thead><tr><th>Rate</th><th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">Total GST</th></tr></thead>
        <?php foreach ($gst as $g): ?>
          <tr><td><?= (float)$g['gst_rate'] ?>%</td><td class="num"><?= money($g['taxable']) ?></td><td class="num"><?= money($g['tax'] / 2) ?></td><td class="num"><?= money($g['tax'] / 2) ?></td><td class="num"><?= money($g['tax']) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </section>
    <section class="card">
      <div class="card-head"><h2>Payment Modes</h2></div>
      <?php if (!$modes): ?><p class="empty">No sales.</p><?php else: ?>
      <table class="table compact">
        <?php foreach ($modes as $m): ?><tr><td><?= e($m['payment_mode']) ?></td><td class="num"><?= (int)$m['n'] ?> bills</td><td class="num"><?= money($m['t']) ?></td></tr><?php endforeach; ?>
        <tr><td>Discount given</td><td></td><td class="num"><?= money($sum['discount']) ?></td></tr>
      </table>
      <?php endif; ?>
    </section>
    <section class="card">
      <div class="card-head"><h2>Top Selling Medicines</h2></div>
      <?php if (!$top): ?><p class="empty">No sales.</p><?php else: ?>
      <table class="table compact">
        <thead><tr><th>Medicine</th><th class="num">Qty</th><th class="num">Sales</th><th class="num">Margin</th></tr></thead>
        <?php foreach ($top as $t): ?><tr><td><?= e($t['name']) ?></td><td class="num"><?= (int)$t['qty'] ?> <?= e($t['unit_label']) ?></td><td class="num"><?= money($t['amount']) ?></td><td class="num"><?= money($t['margin']) ?></td></tr><?php endforeach; ?>
      </table>
      <?php endif; ?>
    </section>
    <section class="card">
      <div class="card-head"><h2>Day-wise Sales</h2></div>
      <?php if (!$daily): ?><p class="empty">No sales.</p><?php else: ?>
      <table class="table compact">
        <?php foreach ($daily as $d): ?><tr><td><a href="sales.php?from=<?= e($d['d']) ?>&amp;to=<?= e($d['d']) ?>"><?= fmt_date($d['d']) ?></a></td><td class="num"><?= (int)$d['n'] ?> bills</td><td class="num"><?= money($d['t']) ?></td></tr><?php endforeach; ?>
      </table>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>

<?php layout_footer(); ?>

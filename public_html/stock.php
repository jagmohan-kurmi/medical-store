<?php
require __DIR__ . '/boot.php';
$me = require_login();

const ADJUST_REASONS = ['Expired - returned to supplier', 'Expired - destroyed', 'Damaged / broken', 'Physical count correction', 'Returned to supplier'];

if (is_post()) {
    csrf_check();
    require_admin();
    $bid = (int)post('batch_id');
    $qty = (int)post('qty');
    $reason = post('reason');
    $back = 'stock.php?' . http_build_query(['filter' => post('filter'), 'q' => post('q')]);
    if (!in_array($reason, ADJUST_REASONS, true) || $qty <= 0) {
        flash('Choose a reason and a quantity above 0.', 'err');
        redirect($back);
    }
    $pdo = db();
    $pdo->beginTransaction();
    $b = q('SELECT qty_available FROM batches WHERE id = ? FOR UPDATE', [$bid])->fetch();
    if (!$b || $qty > (int)$b['qty_available']) {
        $pdo->rollBack();
        flash('Quantity is more than the stock in this batch.', 'err');
        redirect($back);
    }
    q('UPDATE batches SET qty_available = qty_available - ? WHERE id = ?', [$qty, $bid]);
    q('INSERT INTO stock_adjustments (batch_id, qty_change, reason, created_by) VALUES (?, ?, ?, ?)', [$bid, -$qty, $reason, $me['id']]);
    $pdo->commit();
    flash("Removed $qty unit(s) from stock: $reason.");
    redirect($back);
}

$alertDays = max(1, (int)setting('expiry_alert_days', '90'));
$filter = get('filter', 'all');
$search = get('q');

if ($filter === 'low') {
    $rows = q("SELECT m.id, m.name, m.generic_name, m.pack_size, m.unit_label, m.reorder_level, m.rack,
            COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.qty_available END),0) stock,
            (SELECT COALESCE(SUM(si.qty),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
              WHERE si.medicine_id = m.id AND s.status = 'active' AND s.bill_date >= CURDATE() - INTERVAL 30 DAY) sold30
        FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
        WHERE m.active = 1 GROUP BY m.id HAVING stock <= m.reorder_level ORDER BY stock, m.name")->fetchAll();
} else {
    $where = ['b.qty_available > 0'];
    $params = [];
    if ($filter === 'expiring') {
        $where[] = 'b.expiry_date < CURDATE() + INTERVAL ? DAY';
        $params[] = $alertDays;
    } elseif ($filter === 'expired') {
        $where[] = 'b.expiry_date < CURDATE()';
    }
    if ($search !== '') {
        $where[] = '(m.name LIKE ? OR m.generic_name LIKE ? OR b.batch_no LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%");
    }
    $rows = q('SELECT b.*, m.name, m.generic_name, m.pack_size, m.unit_label, m.rack, s.id sup_id, s.name sup_name
        FROM batches b
        JOIN medicines m ON m.id = b.medicine_id
        LEFT JOIN purchases p ON p.id = b.purchase_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . ($filter === 'all' ? 'm.name, b.expiry_date' : 'b.expiry_date, m.name') . ' LIMIT 1000', $params)->fetchAll();
}

$value = q('SELECT COALESCE(SUM(b.qty_available * b.cost_rate / m.pack_size),0) cost, COALESCE(SUM(b.qty_available * b.mrp / m.pack_size),0) mrp
    FROM batches b JOIN medicines m ON m.id = b.medicine_id WHERE b.qty_available > 0 AND b.expiry_date >= CURDATE()')->fetch();

$suppliersList = q('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll();

layout_header('Stock & Expiry');
?>
<div class="stats stats-3">
  <div class="stat"><span>Stock value (cost)</span><strong><?= money($value['cost']) ?></strong></div>
  <div class="stat"><span>Stock value (MRP)</span><strong><?= money($value['mrp']) ?></strong></div>
  <div class="stat"><span>Expiry alert window</span><strong><?= $alertDays ?> days</strong></div>
</div>
<div class="toolbar">
  <div class="tabs">
    <?php foreach (['all' => 'All batches', 'low' => 'Low stock', 'expiring' => 'Expiring soon', 'expired' => 'Expired'] as $k => $v): ?>
      <a href="?filter=<?= $k ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <div style="display: flex; gap: 8px;">
    <?php if ($filter === 'expired' && $rows && $me['role'] === 'admin'): ?>
      <button class="btn btn-outline" onclick="document.getElementById('debitNoteModal').showModal()">📄 Return to Supplier (Debit Note)</button>
    <?php endif; ?>
    <?php if ($filter !== 'low'): ?>
      <form class="search" method="get">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <input name="q" value="<?= e($search) ?>" placeholder="Medicine or batch no.">
        <button class="btn">Search</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card flush">
<?php if (!$rows): ?><p class="empty">Nothing to show here.</p>
<?php elseif ($filter === 'low'): ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Medicine</th><th>Rack</th><th class="num">Sold (30 d)</th><th class="num">Reorder at</th><th class="num">In stock</th></tr></thead>
    <?php foreach ($rows as $m): ?>
      <tr><td><a href="medicines.php?view=<?= (int)$m['id'] ?>"><strong><?= e($m['name']) ?></strong></a><small class="block muted"><?= e($m['generic_name']) ?></small></td>
        <td><?= e($m['rack']) ?></td><td class="num"><?= (int)$m['sold30'] ?></td><td class="num"><?= (int)$m['reorder_level'] ?></td>
        <td class="num"><span class="tag <?= $m['stock'] == 0 ? 'tag-danger' : 'tag-warn' ?>"><?= e(units_label((int)$m['stock'], (int)$m['pack_size'], $m['unit_label'])) ?></span></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead>
      <tr>
        <th>Medicine</th>
        <th>Batch</th>
        <th>Expiry</th>
        <th>Supplier</th>
        <th>Rack</th>
        <th class="num">MRP / pack</th>
        <th class="num">Available</th>
        <?php if ($me['role'] === 'admin'): ?><th>Remove stock</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $b):
          $days = (int)floor((strtotime($b['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
          $cls = $days < 0 ? 'tag-danger' : ($days < $alertDays ? 'tag-warn' : 'tag-ok'); ?>
        <tr>
          <td><a href="medicines.php?view=<?= (int)$b['medicine_id'] ?>"><strong><?= e($b['name']) ?></strong></a></td>
          <td><?= e($b['batch_no']) ?></td>
          <td><span class="tag <?= $cls ?>"><?= fmt_expiry($b['expiry_date']) ?></span><small class="block muted"><?= $days < 0 ? 'Expired' : $days . ' days left' ?></small></td>
          <td><?= e($b['sup_name'] ?: '—') ?></td>
          <td><?= e($b['rack']) ?></td>
          <td class="num"><?= money($b['mrp']) ?></td>
          <td class="num"><strong><?= e(units_label((int)$b['qty_available'], (int)$b['pack_size'], $b['unit_label'])) ?></strong></td>
          <?php if ($me['role'] === 'admin'): ?>
          <td><form method="post" class="inline-form" data-confirm="Remove this stock? This cannot be undone.">
            <?= csrf_field() ?><input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>"><input type="hidden" name="q" value="<?= e($search) ?>">
            <input type="number" name="qty" min="1" max="<?= (int)$b['qty_available'] ?>" value="<?= (int)$b['qty_available'] ?>" class="w-xs" aria-label="Units">
            <select name="reason" aria-label="Reason"><?php foreach (ADJUST_REASONS as $r): ?><option <?= $days < 0 && $r === 'Expired - returned to supplier' ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm">Remove</button></form></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>

<?php if ($filter === 'expired' && $rows && $me['role'] === 'admin'): ?>
<!-- Debit Note Modal -->
<dialog id="debitNoteModal" class="modal-dialog">
  <form method="post" action="supplier-return.php" class="card" style="margin: 0; min-width: 320px; max-width: 600px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_debit_note">
    <div class="card-head">
      <h2>Return Expired Goods to Supplier (Debit Note)</h2>
      <button type="button" class="icon-btn" onclick="document.getElementById('debitNoteModal').close()">✕</button>
    </div>
    <label>Distributor / Supplier *
      <select name="supplier_id" required>
        <option value="">Choose Supplier</option>
        <?php foreach ($suppliersList as $sl): ?>
          <option value="<?= (int)$sl['id'] ?>"><?= e($sl['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Reason
      <input name="reason" value="Expired stock returned to distributor">
    </label>

    <p style="font-weight: 600; margin: 12px 0 6px;">Select Batches &amp; Quantity:</p>
    <div style="max-height: 240px; overflow-y: auto; border: 1px solid var(--line); border-radius: 6px; padding: 6px 12px;">
      <?php foreach ($rows as $b): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px dotted #ccc;">
          <div>
            <strong><?= e($b['name']) ?></strong> (Batch <?= e($b['batch_no']) ?>)
            <small class="block muted">Avail: <?= (int)$b['qty_available'] ?> <?= e($b['unit_label']) ?></small>
          </div>
          <input type="number" name="batches[<?= (int)$b['id'] ?>]" min="0" max="<?= (int)$b['qty_available'] ?>" value="<?= (int)$b['qty_available'] ?>" class="w-xs" style="text-align: right;">
        </div>
      <?php endforeach; ?>
    </div>

    <div class="form-actions" style="margin-top: 16px;">
      <button type="button" class="btn" onclick="document.getElementById('debitNoteModal').close()">Cancel</button>
      <button class="btn btn-primary">Generate Debit Note</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php layout_footer(); ?>

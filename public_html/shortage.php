<?php
require __DIR__ . '/boot.php';
$me = require_login();

// Add or update shortage item
if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'add') {
        $med = mb_substr(trim((string)post('medicine_name')), 0, 150);
        $gen = mb_substr(trim((string)post('generic_name')), 0, 200);
        $qty = max(1, (int)post('qty_requested', '1'));
        $cname = mb_substr(trim((string)post('customer_name')), 0, 100);
        $cphone = mb_substr(preg_replace('/[^0-9]/', '', (string)post('customer_phone')), 0, 20);
        $notes = mb_substr(trim((string)post('notes')), 0, 255);

        if ($med === '') {
            flash('Medicine name is required.', 'err');
            redirect('shortage.php');
        }

        q('INSERT INTO shortage_book (medicine_name, generic_name, qty_requested, customer_name, customer_phone, notes, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?)', [$med, $gen, $qty, $cname, $cphone, $notes, $me['id']]);
        flash("Added '$med' to Shortage Book.");
        redirect('shortage.php');
    }

    if ($action === 'status') {
        $id = (int)post('id');
        $status = post('status');
        if (in_array($status, ['pending', 'ordered', 'arrived', 'cancelled'], true)) {
            q('UPDATE shortage_book SET status = ? WHERE id = ?', [$status, $id]);
            flash('Item status updated to ' . ucfirst($status) . '.');
        }
        redirect('shortage.php');
    }

    if ($action === 'delete') {
        require_admin();
        $id = (int)post('id');
        q('DELETE FROM shortage_book WHERE id = ?', [$id]);
        flash('Item removed from Shortage Book.');
        redirect('shortage.php');
    }
}

$statusFilter = get('status', 'pending');
$where = ['1=1'];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}

$items = q('SELECT sb.*, u.name staff FROM shortage_book sb LEFT JOIN users u ON u.id = sb.created_by
    WHERE ' . implode(' AND ', $where) . ' ORDER BY sb.id DESC', $params)->fetchAll();

// Generate WhatsApp order message for pending items
$pendingItems = q("SELECT medicine_name, generic_name, qty_requested FROM shortage_book WHERE status = 'pending' ORDER BY id ASC")->fetchAll();
$waOrderText = "*Purchase Order / Shortage List*\n" . setting('store_name', 'Medical Store') . " (" . date('d-M-Y') . ")\n\n";
if ($pendingItems) {
    foreach ($pendingItems as $idx => $pi) {
        $waOrderText .= ($idx + 1) . ". *" . $pi['medicine_name'] . "* (" . $pi['qty_requested'] . " pack/units)";
        if ($pi['generic_name']) $waOrderText .= " - " . $pi['generic_name'];
        $waOrderText .= "\n";
    }
    $waOrderText .= "\nPlease supply urgently.";
}
$waOrderUrl = "https://api.whatsapp.com/send?text=" . rawurlencode($waOrderText);

layout_header('Shortage Book (दवा शॉर्टेज डायरी)');
?>
<div class="stats stats-3">
  <div class="stat stat-warn">
    <span>Pending Orders</span>
    <strong><?= (int)q("SELECT COUNT(*) FROM shortage_book WHERE status = 'pending'")->fetchColumn() ?></strong>
    <small>items waiting to be ordered</small>
  </div>
  <div class="stat">
    <span>Ordered</span>
    <strong><?= (int)q("SELECT COUNT(*) FROM shortage_book WHERE status = 'ordered'")->fetchColumn() ?></strong>
    <small>with distributor</small>
  </div>
  <div class="stat stat-ok">
    <span>Arrived</span>
    <strong><?= (int)q("SELECT COUNT(*) FROM shortage_book WHERE status = 'arrived'")->fetchColumn() ?></strong>
    <small>ready for customer pickup</small>
  </div>
</div>

<div class="toolbar">
  <div class="tabs">
    <a href="?status=pending" class="<?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending</a>
    <a href="?status=ordered" class="<?= $statusFilter === 'ordered' ? 'active' : '' ?>">Ordered</a>
    <a href="?status=arrived" class="<?= $statusFilter === 'arrived' ? 'active' : '' ?>">Arrived</a>
    <a href="?status=all" class="<?= $statusFilter === 'all' ? 'active' : '' ?>">All Items</a>
  </div>
  <div style="display:flex; gap: 8px;">
    <?php if ($pendingItems): ?>
      <a class="btn btn-outline" target="_blank" rel="noopener" href="<?= e($waOrderUrl) ?>" title="Send order list to distributor on WhatsApp">📲 WhatsApp Order to Distributor</a>
    <?php endif; ?>
    <button class="btn btn-primary" onclick="document.getElementById('newShortageModal').showModal()">+ Add Medicine</button>
  </div>
</div>

<div class="card flush">
  <?php if (!$items): ?><p class="empty">No shortage records for this filter.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead>
      <tr>
        <th>Medicine / Salt</th>
        <th class="num">Qty</th>
        <th>Customer (Patient)</th>
        <th>Status</th>
        <th>Date & Staff</th>
        <th class="num">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td>
            <strong><?= e($it['medicine_name']) ?></strong>
            <?php if ($it['generic_name']): ?><small class="block muted"><?= e($it['generic_name']) ?></small><?php endif; ?>
            <?php if ($it['notes']): ?><small class="block muted">Note: <?= e($it['notes']) ?></small><?php endif; ?>
          </td>
          <td class="num"><strong><?= (int)$it['qty_requested'] ?></strong></td>
          <td>
            <?php if ($it['customer_name'] || $it['customer_phone']): ?>
              <?= e($it['customer_name'] ?: 'Customer') ?>
              <?php if ($it['customer_phone']): ?>
                <small class="block"><a href="tel:<?= e($it['customer_phone']) ?>"><?= e($it['customer_phone']) ?></a>
                <?php if ($it['status'] === 'arrived'): ?>
                  · <a target="_blank" rel="noopener" href="https://api.whatsapp.com/send?phone=91<?= e($it['customer_phone']) ?>&text=<?= rawurlencode("Namaste {$it['customer_name']} ji, aapki dawa '{$it['medicine_name']}' store par aa gayi hai. Kripya le jayein.") ?>">WhatsApp Call</a>
                <?php endif; ?>
                </small>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">Counter Demand</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" style="display: inline-block;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <select name="status" onchange="this.form.submit()" class="tag <?= match($it['status']) { 'arrived' => 'tag-ok', 'ordered' => 'tag-warn', 'cancelled' => 'tag-danger', default => '' } ?>" style="padding: 2px 6px; font-size: 12px; cursor: pointer;">
                <option value="pending" <?= $it['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="ordered" <?= $it['status'] === 'ordered' ? 'selected' : '' ?>>Ordered</option>
                <option value="arrived" <?= $it['status'] === 'arrived' ? 'selected' : '' ?>>Arrived</option>
                <option value="cancelled" <?= $it['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
              </select>
            </form>
          </td>
          <td>
            <?= date('d M Y', strtotime($it['created_at'])) ?>
            <small class="block muted">By <?= e($it['staff'] ?: 'Staff') ?></small>
          </td>
          <td class="num">
            <?php if ($me['role'] === 'admin'): ?>
              <form method="post" data-confirm="Remove this shortage item?" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <button class="icon-btn row-del" title="Delete">✕</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<!-- Modal to add to shortage book -->
<dialog id="newShortageModal" class="modal-dialog">
  <form method="post" class="card" style="margin: 0; min-width: 320px; max-width: 480px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="card-head">
      <h2>Add to Shortage Book</h2>
      <button type="button" class="icon-btn" onclick="document.getElementById('newShortageModal').close()">✕</button>
    </div>
    <label>Medicine / Brand Name *<input name="medicine_name" required placeholder="e.g. Augmentin 625"></label>
    <label>Salt / Composition<input name="generic_name" placeholder="e.g. Amoxycillin + Clavulanic"></label>
    <label>Quantity Required (packs/strips)<input type="number" name="qty_requested" value="1" min="1"></label>
    <label>Patient / Customer Name<input name="customer_name" placeholder="Optional"></label>
    <label>Customer Mobile<input name="customer_phone" inputmode="tel" placeholder="Optional"></label>
    <label>Notes / Distributor preference<input name="notes" placeholder="Optional"></label>
    <div class="form-actions" style="margin-top: 16px;">
      <button type="button" class="btn" onclick="document.getElementById('newShortageModal').close()">Cancel</button>
      <button class="btn btn-primary">Save to Shortage Book</button>
    </div>
  </form>
</dialog>
<?php layout_footer(); ?>

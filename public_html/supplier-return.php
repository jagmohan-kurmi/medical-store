<?php
require __DIR__ . '/boot.php';
$me = require_login();

// View single Debit Note
if ($viewId = (int)get('id')) {
    $ret = q('SELECT sr.*, s.name sup_name, s.phone sup_phone, s.gstin sup_gstin, s.dl_no sup_dl, s.address sup_address, u.name cashier
        FROM supplier_returns sr
        JOIN suppliers s ON s.id = sr.supplier_id
        LEFT JOIN users u ON u.id = sr.created_by
        WHERE sr.id = ?', [$viewId])->fetch();
    if (!$ret) redirect('stock.php?filter=expired');

    $items = q('SELECT sri.*, m.name med_name, m.unit_label, b.batch_no, b.expiry_date, b.mrp
        FROM supplier_return_items sri
        JOIN batches b ON b.id = sri.batch_id
        JOIN medicines m ON m.id = b.medicine_id
        WHERE sri.supplier_return_id = ?', [$viewId])->fetchAll();

    $store = setting('store_name', 'Medical Store');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Debit Note <?= e($ret['return_no']) ?> · <?= e($store) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=3">
<script src="assets/app.js?v=3" defer></script>
</head>
<body class="invoice-page" <?= get('new') ? 'data-autoprint="1"' : '' ?>>
<div class="no-print invoice-bar">
  <a class="btn" href="stock.php?filter=expired">← Expired Stock</a>
  <a class="btn" href="suppliers.php">Suppliers</a>
  <button class="btn btn-primary" id="printBtn">Print Debit Note</button>
</div>
<article class="invoice">
  <header class="inv-head">
    <div>
      <h1><?= e($store) ?></h1>
      <p><?= nl2br(e(setting('address'))) ?></p>
      <p><?= setting('phone') ? 'Ph: ' . e(setting('phone')) : '' ?></p>
    </div>
    <div class="inv-ids">
      <?php if (setting('gstin')): ?><p>GSTIN: <b><?= e(setting('gstin')) ?></b></p><?php endif; ?>
      <?php if (setting('dl_no')): ?><p>D.L. No: <b><?= e(setting('dl_no')) ?></b></p><?php endif; ?>
    </div>
  </header>
  <div class="inv-title" style="color: #b91c1c;">PURCHASE RETURN / DEBIT NOTE</div>
  <div class="inv-meta">
    <div>
      <p>Debit Note No: <b><?= e($ret['return_no']) ?></b></p>
      <p>Date: <b><?= date('d-m-Y', strtotime($ret['return_date'])) ?></b></p>
      <p>Reason: <b><?= e($ret['reason']) ?></b></p>
    </div>
    <div>
      <p>To Distributor: <b><?= e($ret['sup_name']) ?></b></p>
      <?php if ($ret['sup_gstin']): ?><p>GSTIN: <?= e($ret['sup_gstin']) ?></p><?php endif; ?>
      <?php if ($ret['sup_dl']): ?><p>D.L. No: <?= e($ret['sup_dl']) ?></p><?php endif; ?>
      <?php if ($ret['sup_address']): ?><p>Address: <?= e($ret['sup_address']) ?></p><?php endif; ?>
    </div>
  </div>
  <table class="inv-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Medicine / Product</th>
        <th>Batch</th>
        <th>Expiry</th>
        <th class="num">MRP</th>
        <th class="num">Return Qty</th>
        <th class="num">Purchase Cost/Unit</th>
        <th class="num">Debit Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $idx => $i): ?>
        <tr>
          <td><?= $idx + 1 ?></td>
          <td><strong><?= e($i['med_name']) ?></strong></td>
          <td><?= e($i['batch_no']) ?></td>
          <td><span class="tag tag-danger"><?= fmt_expiry($i['expiry_date']) ?></span></td>
          <td class="num"><?= money($i['mrp']) ?></td>
          <td class="num"><strong><?= (int)$i['qty'] ?></strong> <?= e($i['unit_label']) ?></td>
          <td class="num"><?= money($i['rate_per_unit']) ?></td>
          <td class="num"><strong><?= money($i['amount']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="inv-foot" style="justify-content: flex-end; margin-top: 16px;">
    <div class="inv-totals" style="min-width: 280px;">
      <div class="grand" style="color: #b91c1c;">
        <span>Total Debited</span>
        <b><?= money($ret['total_amount']) ?></b>
      </div>
    </div>
  </div>
  <p class="inv-words" style="margin-top: 16px;"><?= e(amount_in_words((float)$ret['total_amount'])) ?></p>
  <footer class="inv-end" style="margin-top: 30px;">
    <p>Prepared By: <?= e($ret['cashier'] ?? 'Pharmacist') ?><br><br>Pharmacist / Store In-charge</p>
    <p class="sign">Received with stamp &amp; signature<br><br>For <?= e($ret['sup_name']) ?></p>
  </footer>
</article>
</body>
</html>
    <?php
    exit;
}

// Create new Debit Note from Expired Batches
if (is_post() && post('action') === 'create_debit_note') {
    csrf_check();
    require_admin();
    $supplierId = (int)post('supplier_id');
    $reason = mb_substr(trim((string)post('reason')), 0, 255) ?: 'Expired stock return to distributor';
    $batchQtys = is_array($_POST['batches'] ?? null) ? $_POST['batches'] : [];

    if (!$supplierId) {
        flash('Choose a distributor / supplier.', 'err');
        redirect('stock.php?filter=expired');
    }

    $lines = [];
    $totalAmount = 0.0;
    foreach ($batchQtys as $bid => $qty) {
        $bid = (int)$bid;
        $qty = (int)$qty;
        if ($bid > 0 && $qty > 0) {
            $b = q('SELECT b.*, m.name med_name, m.pack_size FROM batches b JOIN medicines m ON m.id = b.medicine_id WHERE b.id = ?', [$bid])->fetch();
            if ($b && $qty <= (int)$b['qty_available']) {
                $costPerUnit = (float)$b['cost_rate'] / max(1, (int)$b['pack_size']);
                $amt = round($costPerUnit * $qty, 2);
                $lines[] = ['batch_id' => $bid, 'qty' => $qty, 'rate' => $costPerUnit, 'amount' => $amt];
                $totalAmount += $amt;
            }
        }
    }

    if (!$lines) {
        flash('Select at least one expired batch with quantity to return.', 'err');
        redirect('stock.php?filter=expired');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO supplier_returns (supplier_id, return_date, total_amount, reason, created_by)
           VALUES (?, CURDATE(), ?, ?, ?)', [$supplierId, $totalAmount, $reason, $me['id']]);
        $retId = (int)$pdo->lastInsertId();
        $retNo = 'DN-' . str_pad((string)$retId, 6, '0', STR_PAD_LEFT);
        q('UPDATE supplier_returns SET return_no = ? WHERE id = ?', [$retNo, $retId]);

        foreach ($lines as $l) {
            q('INSERT INTO supplier_return_items (supplier_return_id, batch_id, qty, rate_per_unit, amount)
               VALUES (?, ?, ?, ?, ?)', [$retId, $l['batch_id'], $l['qty'], $l['rate'], $l['amount']]);

            // Deduct stock and log adjustment
            q('UPDATE batches SET qty_available = qty_available - ? WHERE id = ?', [$l['qty'], $l['batch_id']]);
            q('INSERT INTO stock_adjustments (batch_id, qty_change, reason, created_by) VALUES (?, ?, ?, ?)',
                [$l['batch_id'], -$l['qty'], "Returned to supplier (Debit Note $retNo)", $me['id']]);
        }

        // Record adjustment on supplier account (credit adjustment reducing store payable)
        q("INSERT INTO supplier_payments (supplier_id, pay_date, amount, mode, reference, created_by)
           VALUES (?, CURDATE(), ?, 'Adjustment', ?, ?)", [$supplierId, $totalAmount, "Debit Note $retNo", $me['id']]);

        $pdo->commit();
        flash("Supplier return recorded. Debit Note: $retNo");
        redirect('supplier-return.php?id=' . $retId . '&new=1');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('Failed to create debit note: ' . $e->getMessage(), 'err');
        redirect('stock.php?filter=expired');
    }
}

redirect('stock.php?filter=expired');

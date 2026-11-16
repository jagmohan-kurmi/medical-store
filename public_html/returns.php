<?php
require __DIR__ . '/boot.php';
$me = require_login();

// View single Credit Note / Return Voucher
if ($viewId = (int)get('id')) {
    $ret = q('SELECT sr.*, s.bill_no, s.bill_date, u.name cashier, c.name cust_name, c.phone cust_phone
        FROM sale_returns sr
        JOIN sales s ON s.id = sr.sale_id
        LEFT JOIN users u ON u.id = sr.created_by
        LEFT JOIN customers c ON c.id = sr.customer_id
        WHERE sr.id = ?', [$viewId])->fetch();
    if (!$ret) redirect('sales.php');

    $items = q('SELECT sri.*, m.name, m.unit_label, b.batch_no, b.expiry_date
        FROM sale_return_items sri
        JOIN medicines m ON m.id = sri.medicine_id
        JOIN batches b ON b.id = sri.batch_id
        WHERE sri.sale_return_id = ?', [$viewId])->fetchAll();

    $store = setting('store_name', 'Medical Store');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Credit Note <?= e($ret['return_no']) ?> · <?= e($store) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=3">
<script src="assets/app.js?v=3" defer></script>
</head>
<body class="invoice-page" <?= get('new') ? 'data-autoprint="1"' : '' ?>>
<div class="no-print invoice-bar">
  <a class="btn" href="sales.php">← All Sales</a>
  <a class="btn" href="billing.php">+ New Bill</a>
  <button class="btn btn-primary" id="printBtn">Print Credit Note</button>
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
  <div class="inv-title" style="color: #b91c1c;">CREDIT NOTE / SALES RETURN</div>
  <div class="inv-meta">
    <div>
      <p>Credit Note No: <b><?= e($ret['return_no']) ?></b></p>
      <p>Date: <b><?= date('d-m-Y h:i A', strtotime($ret['return_date'])) ?></b></p>
      <p>Against Bill: <b><a href="invoice.php?id=<?= (int)$ret['sale_id'] ?>"><?= e($ret['bill_no']) ?></a></b> (<?= date('d-m-Y', strtotime($ret['bill_date'])) ?>)</p>
    </div>
    <div>
      <p>Patient / Customer: <b><?= e($ret['cust_name'] ?: 'Walk-in') ?></b></p>
      <?php if ($ret['cust_phone']): ?><p>Mobile: <?= e($ret['cust_phone']) ?></p><?php endif; ?>
      <p>Refund Mode: <b><?= e($ret['refund_mode']) ?></b></p>
      <?php if ($ret['reason']): ?><p>Reason: <?= e($ret['reason']) ?></p><?php endif; ?>
    </div>
  </div>
  <table class="inv-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Medicine Returned</th>
        <th>Batch</th>
        <th>Exp</th>
        <th class="num">Returned Qty</th>
        <th class="num">Rate</th>
        <th class="num">Refund Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $n => $i): ?>
        <tr>
          <td><?= $n + 1 ?></td>
          <td><strong><?= e($i['name']) ?></strong></td>
          <td><?= e($i['batch_no']) ?></td>
          <td><?= fmt_expiry($i['expiry_date']) ?></td>
          <td class="num"><?= (int)$i['qty'] ?> <?= e($i['unit_label']) ?></td>
          <td class="num"><?= money($i['unit_price']) ?></td>
          <td class="num"><?= money($i['refund_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="inv-foot" style="justify-content: flex-end; margin-top: 16px;">
    <div class="inv-totals" style="min-width: 280px;">
      <div class="grand" style="color: #b91c1c;">
        <span>Total Refund / Credit</span>
        <b><?= money($ret['total_refund']) ?></b>
      </div>
    </div>
  </div>
  <p class="inv-words" style="margin-top: 16px;"><?= e(amount_in_words((float)$ret['total_refund'])) ?></p>
  <footer class="inv-end" style="margin-top: 24px;">
    <p>Cashier: <?= e($ret['cashier'] ?? 'Staff') ?></p>
    <p class="sign">For <?= e($store) ?><br><br>Authorised Signatory</p>
  </footer>
</article>
</body>
</html>
    <?php
    exit;
}

// Process New Return Form
$saleId = (int)get('sale_id');
$sale = q('SELECT s.*, u.name cashier, c.id cid, c.name cname, c.phone cphone
    FROM sales s
    LEFT JOIN users u ON u.id = s.created_by
    LEFT JOIN customers c ON c.id = s.customer_id
    WHERE s.id = ?', [$saleId])->fetch();

if (!$sale || $sale['status'] === 'cancelled') {
    flash('Sale bill not found or is already cancelled.', 'err');
    redirect('sales.php');
}

// Fetch sale items with any prior returns deducted
$saleItems = q('SELECT si.*, m.name, m.unit_label, b.batch_no, b.expiry_date,
    COALESCE((SELECT SUM(sri.qty) FROM sale_return_items sri WHERE sri.sale_item_id = si.id), 0) already_returned
    FROM sale_items si
    JOIN medicines m ON m.id = si.medicine_id
    JOIN batches b ON b.id = si.batch_id
    WHERE si.sale_id = ?', [$saleId])->fetchAll();

if (is_post()) {
    csrf_check();
    $returnItems = is_array($_POST['returns'] ?? null) ? $_POST['returns'] : [];
    $refundMode = post('refund_mode', 'Cash');
    $reason = mb_substr(trim((string)post('reason')), 0, 255);
    $errors = [];

    $toReturn = [];
    $totalRefund = 0.0;

    foreach ($saleItems as $si) {
        $sid = (int)$si['id'];
        $qty = (int)($returnItems[$sid]['qty'] ?? 0);
        $maxReturn = (int)$si['qty'] - (int)$si['already_returned'];
        if ($qty > 0) {
            if ($qty > $maxReturn) {
                $errors[] = "Cannot return more than $maxReturn of {$si['name']}.";
                continue;
            }
            // Effective refund rate per unit after discount
            $rate = (float)$si['amount'] / max(1, (int)$si['qty']);
            $amt = round($rate * $qty, 2);
            $toReturn[] = [
                'sale_item_id' => $si['id'],
                'medicine_id' => $si['medicine_id'],
                'batch_id' => $si['batch_id'],
                'qty' => $qty,
                'unit_price' => $rate,
                'refund_amount' => $amt,
            ];
            $totalRefund += $amt;
        }
    }

    if (!$toReturn) $errors[] = 'Enter return quantity for at least one item.';

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $totalRefund = round($totalRefund, 2);
            $cid = $sale['customer_id'] ?: null;

            q('INSERT INTO sale_returns (sale_id, customer_id, total_refund, refund_mode, reason, created_by)
               VALUES (?, ?, ?, ?, ?, ?)', [$saleId, $cid, $totalRefund, $refundMode, $reason, $me['id']]);
            $retId = (int)$pdo->lastInsertId();
            $retNo = 'CRN-' . str_pad((string)$retId, 6, '0', STR_PAD_LEFT);
            q('UPDATE sale_returns SET return_no = ? WHERE id = ?', [$retNo, $retId]);

            foreach ($toReturn as $item) {
                q('INSERT INTO sale_return_items (sale_return_id, sale_item_id, medicine_id, batch_id, qty, unit_price, refund_amount)
                   VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$retId, $item['sale_item_id'], $item['medicine_id'], $item['batch_id'], $item['qty'], $item['unit_price'], $item['refund_amount']]);

                // Restock batch
                q('UPDATE batches SET qty_available = qty_available + ? WHERE id = ?', [$item['qty'], $item['batch_id']]);
            }

            // If Credit Adjustment and customer exists, reduce customer balance
            if ($refundMode === 'Credit Adjustment' && $cid) {
                q('UPDATE customers SET balance = balance - ? WHERE id = ?', [$totalRefund, $cid]);
                q("INSERT INTO customer_payments (customer_id, sale_id, pay_date, amount, mode, reference, notes, created_by)
                   VALUES (?, ?, CURDATE(), ?, 'Adjustment', ?, 'Sales Return Credit Adjustment', ?)",
                    [$cid, $saleId, $totalRefund, $retNo, $me['id']]);
            }

            $pdo->commit();
            flash("Sales return processed. Credit Note: $retNo");
            redirect('returns.php?id=' . $retId . '&new=1');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Return failed: ' . $e->getMessage();
        }
    }
    foreach ($errors as $err) flash($err, 'err');
}

layout_header('Sales Return · Bill ' . $sale['bill_no']);
?>
<div class="toolbar">
  <a class="btn" href="invoice.php?id=<?= $saleId ?>">← Back to Bill</a>
</div>

<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="card-head">
    <h2>Return Medicines from Bill <?= e($sale['bill_no']) ?></h2>
    <small class="muted"><?= date('d M Y, h:i A', strtotime($sale['bill_date'])) ?> · <?= e($sale['customer_name'] ?: 'Walk-in') ?></small>
  </div>

  <p class="muted">Enter the quantity of tablets/units the customer is returning. The stock will be returned to the batch and refund recorded.</p>

  <div class="table-wrap"><table class="table entry-table">
    <thead>
      <tr>
        <th>Medicine</th>
        <th>Batch · Exp</th>
        <th class="num">Billed Qty</th>
        <th class="num">Already Ret.</th>
        <th class="num">Available to Return</th>
        <th class="num">Return Qty</th>
        <th class="num">Unit Refund Rate</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($saleItems as $i):
        $maxReturn = (int)$i['qty'] - (int)$i['already_returned'];
        $unitRate = (float)$i['amount'] / max(1, (int)$i['qty']);
      ?>
        <tr>
          <td><strong><?= e($i['name']) ?></strong></td>
          <td><?= e($i['batch_no']) ?> (<?= fmt_expiry($i['expiry_date']) ?>)</td>
          <td class="num"><?= (int)$i['qty'] ?> <?= e($i['unit_label']) ?></td>
          <td class="num"><?= (int)$i['already_returned'] ?></td>
          <td class="num"><strong><?= $maxReturn ?></strong></td>
          <td class="num">
            <?php if ($maxReturn > 0): ?>
              <input type="number" class="w-xs" name="returns[<?= (int)$i['id'] ?>][qty]" min="0" max="<?= $maxReturn ?>" value="0" style="text-align: right;">
            <?php else: ?>
              <span class="muted">Fully returned</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= money($unitRate) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>

  <div class="form-grid" style="margin-top: 20px;">
    <label class="span-2">Refund Mode
      <select name="refund_mode">
        <option value="Cash">Cash Refund (नकद वापस)</option>
        <option value="UPI">UPI / Online Refund</option>
        <?php if ($sale['customer_id'] || $sale['payment_mode'] === 'Credit'): ?>
          <option value="Credit Adjustment" selected>Credit Adjustment (Customer Balance से कम करें)</option>
        <?php endif; ?>
      </select>
    </label>
    <label class="span-2">Return Reason
      <input name="reason" placeholder="e.g. Unused medication / Doctor changed prescription / Patient discharged" maxlength="255">
    </label>
  </div>

  <div class="form-actions" style="margin-top: 20px;">
    <a class="btn" href="invoice.php?id=<?= $saleId ?>">Cancel</a>
    <button class="btn btn-primary">Process Return &amp; Generate Credit Note</button>
  </div>
</form>

<?php layout_footer(); ?>

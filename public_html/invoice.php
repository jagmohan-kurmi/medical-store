<?php
require __DIR__ . '/boot.php';
require_login();

$s = q('SELECT s.*, u.name cashier, c.id cid, c.balance cust_balance
    FROM sales s
    LEFT JOIN users u ON u.id = s.created_by
    LEFT JOIN customers c ON c.id = s.customer_id
    WHERE s.id = ?', [(int)get('id')])->fetch();
if (!$s) redirect('sales.php');

$items = q('SELECT si.*, m.name, m.hsn, m.unit_label, m.pack_size, b.batch_no, b.expiry_date, b.mrp
    FROM sale_items si JOIN medicines m ON m.id = si.medicine_id JOIN batches b ON b.id = si.batch_id
    WHERE si.sale_id = ? ORDER BY si.id', [$s['id']])->fetchAll();

$gst = [];
foreach ($items as $i) {
    $r = (string)(float)$i['gst_rate'];
    $gst[$r] = ['taxable' => ($gst[$r]['taxable'] ?? 0) + $i['taxable'], 'tax' => ($gst[$r]['tax'] ?? 0) + $i['gst_amount']];
}
ksort($gst, SORT_NUMERIC);
$store = setting('store_name', 'Medical Store');
$storeUpi = trim((string)setting('store_upi_id', ''));

$format = get('format', setting('default_invoice_format', 'a4'));
$isThermal = $format === 'thermal';

// Check if any credit notes / returns exist for this sale
$returns = q('SELECT id, return_no, return_date, total_refund FROM sale_returns WHERE sale_id = ?', [$s['id']])->fetchAll();

// WhatsApp text formulation
$cleanPhone = preg_replace('/[^0-9]/', '', (string)$s['customer_phone']);
if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;

$waMsg = "🏥 *" . $store . "*\n";
$waMsg .= "📄 *Tax Invoice: " . $s['bill_no'] . "*\n";
$waMsg .= "📅 Date: " . date('d-m-Y, h:i A', strtotime($s['bill_date'])) . "\n";
$waMsg .= "👤 Patient: " . ($s['customer_name'] ?: 'Walk-in') . "\n";
if ($s['doctor_name']) $waMsg .= "👨‍⚕️ Doctor: " . $s['doctor_name'] . "\n";
$waMsg .= "\n💊 *Medicines:*\n";
foreach ($items as $idx => $it) {
    $waMsg .= ($idx + 1) . ". " . $it['name'] . " (" . (int)$it['qty'] . " " . $it['unit_label'] . ") - ₹" . number_format((float)$it['amount'], 2) . "\n";
}
$waMsg .= "-----------------------------\n";
$waMsg .= "*Net Payable: " . money($s['total']) . "*\n";
$waMsg .= "Payment Mode: " . $s['payment_mode'] . "\n";
if ($s['discount'] > 0) $waMsg .= "💰 You saved: " . money($s['discount']) . "\n";
$waMsg .= "\nThank you! Get well soon! 🙏";

$waUrl = "https://api.whatsapp.com/send?phone=" . $cleanPhone . "&text=" . rawurlencode($waMsg);

// UPI Payment Link
$upiUri = '';
if ($storeUpi !== '') {
    $upiUri = "upi://pay?pa=" . rawurlencode($storeUpi) . "&pn=" . rawurlencode($store) . "&am=" . rawurlencode((string)$s['total']) . "&tr=" . rawurlencode($s['bill_no']) . "&cu=INR&tn=" . rawurlencode("Bill " . $s['bill_no']);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Bill <?= e($s['bill_no']) ?> · <?= e($store) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=3">
<script src="assets/qrcode.min.js"></script>
<script src="assets/app.js?v=3" defer></script>
</head>
<body class="invoice-page" <?= get('new') ? 'data-autoprint="1"' : '' ?>>
<div class="no-print invoice-bar" style="flex-wrap: wrap; gap: 8px;">
  <div class="tabs" style="background: #fff; padding: 3px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
    <a href="?id=<?= $s['id'] ?>&format=a4" class="<?= !$isThermal ? 'active' : '' ?>">A4 Tax Invoice</a>
    <a href="?id=<?= $s['id'] ?>&format=thermal" class="<?= $isThermal ? 'active' : '' ?>">80mm Thermal Slip</a>
  </div>
  <a class="btn" href="billing.php">+ New Bill</a>
  <a class="btn" href="sales.php">All Sales</a>
  <a class="btn btn-outline" target="_blank" rel="noopener" href="<?= e($waUrl) ?>">📲 WhatsApp Bill</a>
  <button class="btn btn-outline" id="copyTextBtn" data-text="<?= e($waMsg) ?>" title="Copy bill text for SMS or messaging">📋 Copy Text</button>
  <?php if ($s['status'] === 'active'): ?>
    <a class="btn btn-outline" href="returns.php?sale_id=<?= (int)$s['id'] ?>">↩ Return Items</a>
  <?php endif; ?>
  <button class="btn btn-primary" id="printBtn">Print (Ctrl+P)</button>
</div>

<?php if ($returns): ?>
  <div class="no-print" style="max-width: 820px; margin: 0 auto 12px; padding: 10px 16px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px; font-size: 13px;">
    <strong>Credit Note(s) against this bill:</strong>
    <?php foreach ($returns as $ret): ?>
      <span style="margin-left: 8px;"><a href="returns.php?id=<?= (int)$ret['id'] ?>"><?= e($ret['return_no']) ?> (<?= money($ret['total_refund']) ?>)</a></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($isThermal): ?>
  <!-- 80mm / 3-inch POS Thermal Receipt Layout -->
  <article class="invoice invoice-thermal" style="max-width: 380px; padding: 16px 14px; font-family: monospace, sans-serif; font-size: 12px; background: #fff; margin: 0 auto 30px; box-shadow: var(--shadow);">
    <?php if ($s['status'] === 'cancelled'): ?><div class="void" style="font-size:24px;">CANCELLED</div><?php endif; ?>
    <div style="text-align: center; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 8px;">
      <h2 style="font-size: 16px; margin: 0; font-weight: 700;"><?= e($store) ?></h2>
      <p style="margin: 2px 0;"><?= nl2br(e(setting('address'))) ?></p>
      <?php if (setting('phone')): ?><p style="margin: 2px 0;">Ph: <?= e(setting('phone')) ?></p><?php endif; ?>
      <?php if (setting('gstin')): ?><p style="margin: 2px 0;">GSTIN: <b><?= e(setting('gstin')) ?></b></p><?php endif; ?>
      <?php if (setting('dl_no')): ?><p style="margin: 2px 0;">D.L.: <?= e(setting('dl_no')) ?></p><?php endif; ?>
    </div>

    <div style="text-align: center; font-weight: bold; margin-bottom: 6px;">RETAIL TAX INVOICE</div>
    <div style="border-bottom: 1px dashed #000; padding-bottom: 6px; margin-bottom: 6px; font-size: 11px;">
      <div>Bill: <b><?= e($s['bill_no']) ?></b> · <?= date('d/m/Y h:i A', strtotime($s['bill_date'])) ?></div>
      <div>Pt: <b><?= e($s['customer_name'] ?: 'Walk-in') ?></b> <?= $s['customer_phone'] ? '(' . e($s['customer_phone']) . ')' : '' ?></div>
      <?php if ($s['doctor_name']): ?><div>Dr: <?= e($s['doctor_name']) ?></div><?php endif; ?>
      <div>Mode: <?= e($s['payment_mode']) ?> · Cashier: <?= e($s['cashier'] ?? 'Staff') ?></div>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 8px;">
      <thead>
        <tr style="border-bottom: 1px dashed #000;">
          <th style="text-align: left; padding: 2px 0;">Item · Batch</th>
          <th style="text-align: right; padding: 2px 0;">Qty</th>
          <th style="text-align: right; padding: 2px 0;">Rate</th>
          <th style="text-align: right; padding: 2px 0;">Amt</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i): ?>
          <tr>
            <td style="padding: 3px 0;" colspan="4">
              <b><?= e($i['name']) ?></b>
              <div style="font-size: 10px; color: #555;"><?= e($i['batch_no']) ?> · Exp: <?= fmt_expiry($i['expiry_date']) ?></div>
            </td>
          </tr>
          <tr style="border-bottom: 1px dotted #ccc;">
            <td></td>
            <td style="text-align: right;"><?= (int)$i['qty'] ?></td>
            <td style="text-align: right;"><?= number_format((float)$i['unit_price'], 2) ?></td>
            <td style="text-align: right; font-weight: 600;"><?= number_format((float)$i['amount'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="border-top: 1px dashed #000; padding-top: 6px; font-size: 12px;">
      <div style="display: flex; justify-content: space-between;"><span>Gross Total</span><span><?= number_format((float)$s['gross'], 2) ?></span></div>
      <?php if ($s['discount'] > 0): ?>
        <div style="display: flex; justify-content: space-between; color: #b91c1c;"><span>Discount (<?= (float)$s['discount_pct'] ?>%)</span><span>-<?= number_format((float)$s['discount'], 2) ?></span></div>
      <?php endif; ?>
      <?php if ($s['round_off'] != 0): ?>
        <div style="display: flex; justify-content: space-between;"><span>Round off</span><span><?= number_format((float)$s['round_off'], 2) ?></span></div>
      <?php endif; ?>
      <div style="display: flex; justify-content: space-between; font-size: 14px; font-weight: 700; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 4px 0; margin-top: 4px;">
        <span>NET PAYABLE</span><span><?= money($s['total']) ?></span>
      </div>
      <?php if ($s['discount'] > 0): ?>
        <div style="text-align: center; font-size: 11px; margin-top: 4px; font-weight: 600;">You Saved <?= money($s['discount']) ?>!</div>
      <?php endif; ?>
    </div>

    <!-- Dynamic UPI QR Code on Thermal Slip -->
    <?php if ($upiUri !== ''): ?>
      <div style="text-align: center; margin-top: 12px; padding: 6px; border: 1px dashed #bbb; border-radius: 4px;">
        <div class="upi-qr" data-qr="<?= e($upiUri) ?>" style="display: inline-block;"></div>
        <div style="font-size: 10px; font-weight: 600; margin-top: 2px;">Scan &amp; Pay via UPI (<?= money($s['total']) ?>)</div>
      </div>
    <?php endif; ?>

    <div style="text-align: center; font-size: 10px; margin-top: 12px; border-top: 1px dashed #000; padding-top: 8px;">
      <p style="margin: 2px 0;"><?= e(setting('invoice_footer')) ?></p>
      <p style="margin: 2px 0; font-weight: bold;">*** GET WELL SOON ***</p>
    </div>
  </article>

<?php else: ?>
  <!-- Full A4 Tax Invoice Layout -->
  <article class="invoice">
    <?php if ($s['status'] === 'cancelled'): ?><div class="void">CANCELLED</div><?php endif; ?>
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
    <div class="inv-title">TAX INVOICE</div>
    <div class="inv-meta">
      <div>
        <p>Bill No: <b><?= e($s['bill_no']) ?></b></p>
        <p>Date: <b><?= date('d-m-Y h:i A', strtotime($s['bill_date'])) ?></b></p>
        <p>Payment: <b><?= e($s['payment_mode']) ?></b></p>
      </div>
      <div>
        <p>Patient: <b><?= e($s['customer_name'] ?: 'Walk-in') ?></b></p>
        <?php if ($s['customer_phone']): ?><p>Mobile: <?= e($s['customer_phone']) ?></p><?php endif; ?>
        <?php if ($s['doctor_name']): ?><p>Doctor: <?= e($s['doctor_name']) ?></p><?php endif; ?>
      </div>
    </div>
    <table class="inv-table">
      <thead><tr><th>#</th><th>Medicine</th><th>HSN</th><th>Batch</th><th>Exp</th><th class="num">Qty</th><th class="num">MRP</th><th class="num">GST%</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $n => $i): ?>
        <tr><td><?= $n + 1 ?></td><td><?= e($i['name']) ?></td><td><?= e($i['hsn']) ?></td><td><?= e($i['batch_no']) ?></td><td><?= fmt_expiry($i['expiry_date']) ?></td>
          <td class="num"><?= (int)$i['qty'] ?> <?= e($i['unit_label']) ?></td><td class="num"><?= number_format((float)$i['mrp'], 2) ?><?= $i['pack_size'] > 1 ? '/' . (int)$i['pack_size'] : '' ?></td>
          <td class="num"><?= (float)$i['gst_rate'] ?></td><td class="num"><?= number_format((float)$i['amount'], 2) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="inv-foot">
      <div style="flex: 1;">
        <table class="inv-gst">
          <thead><tr><th>GST%</th><th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th></tr></thead>
          <?php foreach ($gst as $r => $g): ?>
            <tr><td><?= $r ?>%</td><td class="num"><?= number_format($g['taxable'], 2) ?></td><td class="num"><?= number_format($g['tax'] / 2, 2) ?></td><td class="num"><?= number_format($g['tax'] / 2, 2) ?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php if ($upiUri !== ''): ?>
          <div style="display: flex; align-items: center; gap: 12px; margin-top: 14px; padding: 10px; border: 1px solid var(--line); border-radius: 6px; width: fit-content;">
            <div class="upi-qr" data-qr="<?= e($upiUri) ?>"></div>
            <div>
              <strong style="display: block; font-size: 13px;">UPI Instant Payment</strong>
              <small class="muted">Scan with GPay, PhonePe, Paytm</small>
              <div style="font-size: 12px; font-weight: 600; color: var(--brand-dark);"><?= money($s['total']) ?></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <div class="inv-totals">
        <div><span>Gross (MRP)</span><b><?= number_format((float)$s['gross'], 2) ?></b></div>
        <?php if ($s['discount'] > 0): ?><div><span>Discount (<?= (float)$s['discount_pct'] ?>%)</span><b>-<?= number_format((float)$s['discount'], 2) ?></b></div><?php endif; ?>
        <div><span>Round off</span><b><?= number_format((float)$s['round_off'], 2) ?></b></div>
        <div class="grand"><span>Net payable</span><b><?= money($s['total']) ?></b></div>
        <?php if ($s['discount'] > 0): ?><div class="saved">You saved <?= money($s['discount']) ?></div><?php endif; ?>
      </div>
    </div>
    <p class="inv-words"><?= e(amount_in_words((float)$s['total'])) ?></p>
    <footer class="inv-end">
      <p><?= e(setting('invoice_footer')) ?></p>
      <p class="sign">For <?= e($store) ?><br><br>Pharmacist / Authorised signatory</p>
    </footer>
  </article>
<?php endif; ?>

</body>
</html>

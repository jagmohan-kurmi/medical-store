<?php
require __DIR__ . '/boot.php';
require APP_ROOT . '/app/ai_ocr.php';
$me = require_login();

const SUPPLIER_PAY_MODES = ['Cash', 'UPI', 'Bank', 'Cheque'];

if (is_post() && post('action') === 'delete') {
    csrf_check();
    require_admin();
    $id = (int)post('id');
    $pdo = db();
    $pdo->beginTransaction();
    $used = (int)q('SELECT COUNT(*) FROM batches b WHERE b.purchase_id = ? AND (b.qty_available <> b.qty_in
        OR EXISTS (SELECT 1 FROM sale_items si WHERE si.batch_id = b.id)
        OR EXISTS (SELECT 1 FROM stock_adjustments a WHERE a.batch_id = b.id)) FOR UPDATE', [$id])->fetchColumn();
    if ($used) {
        $pdo->rollBack();
        flash('This purchase cannot be deleted because some of its stock has already been sold or adjusted.', 'err');
        redirect('purchases.php?view=' . $id);
    }
    $file = (string)q('SELECT bill_file FROM purchases WHERE id = ?', [$id])->fetchColumn();
    q('DELETE FROM batches WHERE purchase_id = ?', [$id]);
    q('DELETE FROM purchases WHERE id = ?', [$id]); // linked supplier payments cascade
    $pdo->commit();
    if ($file && ($path = bill_file_path($file))) @unlink($path);
    flash('Purchase deleted, its stock removed and the supplier account corrected.');
    redirect('purchases.php');
}

if (is_post()) {
    csrf_check();
    $supplierSel = post('supplier_id');
    $invoiceNo = mb_substr(post('invoice_no'), 0, 50);
    $invoiceDate = post('invoice_date');
    $items = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $billFile = post('bill_file');
    $scannedTotal = post('scanned_total') === '' ? null : round((float)post('scanned_total'), 2);
    $roundOff = round((float)post('round_off', '0'), 2);
    $paidNow = round((float)post('paid_now', '0'), 2);
    $payMode = post('pay_mode', 'Cash');
    $errors = [];

    if (!valid_date($invoiceDate)) $errors[] = 'Enter a valid invoice date.';
    if (abs($roundOff) > 10) $errors[] = 'Round off can be at most ±10.';
    if ($billFile !== '' && !bill_file_path($billFile)) $billFile = '';
    $supplierId = null;
    $newSupplier = null;
    if ($supplierSel === 'new') {
        $newSupplier = ['name' => mb_substr(post('ns_name'), 0, 150), 'gstin' => mb_substr(post('ns_gstin'), 0, 20), 'dl_no' => mb_substr(post('ns_dl_no'), 0, 60),
            'phone' => mb_substr(post('ns_phone'), 0, 20), 'address' => mb_substr(post('ns_address'), 0, 255)];
        if ($newSupplier['name'] === '') $errors[] = 'Enter the new supplier\'s name.';
    } elseif ($supplierSel !== '') {
        $supplierId = (int)$supplierSel;
        if (!q('SELECT id FROM suppliers WHERE id = ?', [$supplierId])->fetch()) $errors[] = 'Choose a valid supplier.';
    }
    if (($paidNow > 0) && $supplierSel === '') $errors[] = 'Choose a supplier to record a payment.';
    if ($paidNow < 0 || !in_array($payMode, SUPPLIER_PAY_MODES, true)) $errors[] = 'Enter a valid payment.';
    if ($supplierId && $invoiceNo !== '' && q('SELECT id FROM purchases WHERE supplier_id = ? AND invoice_no = ?', [$supplierId, $invoiceNo])->fetch()) {
        $errors[] = "Invoice $invoiceNo from this supplier is already entered.";
    }

    $clean = [];
    foreach ($items as $n => $it) {
        if (!is_array($it)) continue;
        $row = count($clean) + 1;
        $mid = (int)($it['medicine_id'] ?? 0);
        $newName = trim((string)($it['new_name'] ?? ''));
        if (!$mid && $newName === '') continue;
        if ($mid) {
            $med = q('SELECT id, name, pack_size FROM medicines WHERE id = ?', [$mid])->fetch();
            if (!$med) { $errors[] = "Row $row: medicine not found."; continue; }
        } else {
            $med = ['id' => 0, 'name' => mb_substr($newName, 0, 150), 'pack_size' => max(1, min(1000, (int)($it['new_pack'] ?? 1))),
                'unit_label' => mb_substr(trim((string)($it['new_unit'] ?? 'Unit')), 0, 20) ?: 'Unit',
                'category' => in_array($it['new_category'] ?? '', MED_CATEGORIES, true) ? $it['new_category'] : 'Other',
                'hsn' => mb_substr(preg_replace('/\D/', '', (string)($it['new_hsn'] ?? '')), 0, 10) ?: '3004'];
        }
        $batch = mb_substr(trim((string)($it['batch_no'] ?? '')), 0, 40);
        $exp = (string)($it['expiry'] ?? '');
        $packs = max(0, (int)($it['packs'] ?? 0));
        $free = max(0, (int)($it['free_packs'] ?? 0));
        $rate = round((float)($it['rate'] ?? 0), 2);
        $mrp = round((float)($it['mrp'] ?? 0), 2);
        $disc = round((float)($it['disc_pct'] ?? 0), 2);
        $gst = (float)($it['gst_rate'] ?? -1);
        $label = "Row $row ({$med['name']})";
        if ($batch === '') $errors[] = "$label: batch number is required.";
        if (!preg_match('/^\d{4}-\d{2}$/', $exp)) { $errors[] = "$label: expiry month is required."; continue; }
        if ($packs + $free <= 0) $errors[] = "$label: quantity must be more than 0.";
        if ($mrp <= 0) $errors[] = "$label: MRP is required.";
        if ($rate < 0 || $rate > $mrp) $errors[] = "$label: purchase rate must be between 0 and MRP.";
        if ($disc < 0 || $disc > 100) $errors[] = "$label: discount must be 0-100%.";
        if (!in_array($gst, array_map('floatval', GST_RATES), true)) $errors[] = "$label: choose a GST rate.";
        $taxable = round($packs * $rate * (1 - $disc / 100), 2);
        $clean[] = ['med' => $med, 'batch' => $batch, 'exp' => date('Y-m-t', strtotime($exp . '-01')), 'packs' => $packs, 'free' => $free,
            'rate' => $rate, 'mrp' => $mrp, 'disc' => $disc, 'gst' => $gst, 'taxable' => $taxable, 'tax' => round($taxable * $gst / 100, 2)];
    }
    if (!$clean && !$errors) $errors[] = 'Add at least one medicine.';

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        if ($newSupplier) {
            q('INSERT INTO suppliers (name, gstin, dl_no, phone, address) VALUES (?, ?, ?, ?, ?)', array_values($newSupplier));
            $supplierId = (int)$pdo->lastInsertId();
        }
        $taxable = array_sum(array_column($clean, 'taxable'));
        $tax = array_sum(array_column($clean, 'tax'));
        $total = round($taxable + $tax + $roundOff, 2);
        q('INSERT INTO purchases (supplier_id, invoice_no, invoice_date, taxable, gst_amount, round_off, total, scanned_total, bill_file, notes, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$supplierId, $invoiceNo, $invoiceDate, round($taxable, 2), round($tax, 2), $roundOff, $total, $scannedTotal, $billFile, mb_substr(post('notes'), 0, 255), $me['id']]);
        $pid = (int)$pdo->lastInsertId();
        $created = 0;
        foreach ($clean as $c) {
            if (!$c['med']['id']) {
                // A scanned medicine we did not have yet: create it once, reuse if the same name repeats.
                $existing = q('SELECT id, pack_size FROM medicines WHERE name = ?', [$c['med']['name']])->fetch();
                if ($existing) {
                    $c['med'] = ['id' => (int)$existing['id'], 'pack_size' => (int)$existing['pack_size']] + $c['med'];
                } else {
                    q('INSERT INTO medicines (name, category, hsn, gst_rate, pack_size, unit_label, reorder_level) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$c['med']['name'], $c['med']['category'], $c['med']['hsn'], $c['gst'], $c['med']['pack_size'], $c['med']['unit_label'], $c['med']['pack_size'] * 2]);
                    $c['med']['id'] = (int)$pdo->lastInsertId();
                    $created++;
                }
            }
            $units = ($c['packs'] + $c['free']) * (int)$c['med']['pack_size'];
            q('INSERT INTO batches (medicine_id, purchase_id, batch_no, expiry_date, mrp, purchase_rate, disc_pct, gst_rate, cost_rate, packs, free_packs, qty_in, qty_available)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$c['med']['id'], $pid, $c['batch'], $c['exp'], $c['mrp'], $c['rate'], $c['disc'], $c['gst'], round($c['rate'] * (1 - $c['disc'] / 100), 4),
                    $c['packs'], $c['free'], $units, $units]);
        }
        if ($paidNow > 0) {
            q('INSERT INTO supplier_payments (supplier_id, pay_date, amount, mode, reference, purchase_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$supplierId, $invoiceDate, $paidNow, $payMode, 'Paid with bill ' . $invoiceNo, $pid, $me['id']]);
        }
        $pdo->commit();
        $msg = 'Purchase saved. Stock updated for ' . count($clean) . ' item(s)';
        if ($created) $msg .= ", $created new medicine(s) added";
        if ($newSupplier) $msg .= ', new supplier added';
        flash($msg . '.');
        redirect('purchases.php?view=' . $pid);
    }
    foreach ($errors as $er) flash($er, 'err');
    $_GET['new'] = '1';
}

$suppliers = q('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();

if (isset($_GET['new'])) {
    // After a failed save, hand the typed rows back to the form so nothing is lost.
    $prefill = null;
    if (is_post()) {
        $prefill = ['items' => [], 'supplier_sel' => post('supplier_id'), 'scanned_total' => post('scanned_total'), 'bill_file' => post('bill_file'),
            'round_off' => post('round_off'), 'new_supplier' => ['name' => post('ns_name'), 'gstin' => post('ns_gstin'), 'dl_no' => post('ns_dl_no'), 'phone' => post('ns_phone'), 'address' => post('ns_address')]];
        foreach (is_array($_POST['items'] ?? null) ? $_POST['items'] : [] as $it) {
            if (!is_array($it)) continue;
            $mid = (int)($it['medicine_id'] ?? 0);
            $m = $mid ? q('SELECT id, name, pack_size, unit_label FROM medicines WHERE id = ?', [$mid])->fetch() : null;
            $prefill['items'][] = [
                'medicine' => $m ? ['id' => (int)$m['id'], 'name' => $m['name'], 'pack_size' => (int)$m['pack_size'], 'unit_label' => $m['unit_label']] : null,
                'new_medicine' => !$m && trim((string)($it['new_name'] ?? '')) !== '' ? ['name' => (string)$it['new_name'], 'pack_size' => (int)($it['new_pack'] ?? 1),
                    'unit_label' => (string)($it['new_unit'] ?? 'Unit'), 'category' => (string)($it['new_category'] ?? 'Other'), 'hsn' => (string)($it['new_hsn'] ?? '')] : null,
                'batch_no' => (string)($it['batch_no'] ?? ''), 'expiry' => (string)($it['expiry'] ?? ''), 'packs' => (int)($it['packs'] ?? 0),
                'free_packs' => (int)($it['free_packs'] ?? 0), 'rate' => (float)($it['rate'] ?? 0), 'mrp' => (float)($it['mrp'] ?? 0),
                'disc_pct' => (float)($it['disc_pct'] ?? 0), 'gst_rate' => (float)($it['gst_rate'] ?? 12),
            ];
        }
    }
    $aiReady = gemini_api_key() !== '';
    layout_header('New purchase / stock entry');
    ?>
    <section class="card scan-card <?= $aiReady ? '' : 'scan-off' ?>" id="scanCard">
      <div class="scan-intro">
        <div class="scan-icon">AI</div>
        <div>
          <h2>Scan supplier bill</h2>
          <p class="muted">Take a clear photo or upload the PDF. Supplier, invoice, batch, expiry, qty, rate, MRP and GST are filled in automatically. Check the rows, then save.</p>
        </div>
      </div>
      <?php if ($aiReady): ?>
        <label class="scan-drop" id="scanDrop">
          <input type="file" id="billFile" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment">
          <strong>📷 Click to scan / upload bill</strong><small>JPG, PNG or PDF · max 10 MB · or drop the file here</small>
        </label>
        <div class="scan-status" id="scanStatus" hidden></div>
      <?php else: ?>
        <p class="alert alert-err">AI scanning is off. <?= $me['role'] === 'admin' ? '<a href="settings.php#ai">Add your Gemini API key in Settings</a> to turn it on.' : 'Ask the store owner to add a Gemini API key in Settings.' ?></p>
      <?php endif; ?>
    </section>

    <form method="post" class="card" id="purchaseForm" data-prefill="<?= e(json_encode($prefill)) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="bill_file" id="billFileName" value="">
      <input type="hidden" name="scanned_total" id="scannedTotal" value="">
      <div class="form-grid">
        <label class="span-2">Supplier<select name="supplier_id" id="supplierSel"><option value="">— Opening stock / no supplier —</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= post('supplier_id') === (string)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
          <option value="new">+ New supplier…</option>
        </select></label>
        <label>Supplier invoice no.<input name="invoice_no" id="invoiceNo" value="<?= e(post('invoice_no')) ?>"></label>
        <label>Invoice date<input type="date" name="invoice_date" id="invoiceDate" required value="<?= e(post('invoice_date') ?: date('Y-m-d')) ?>"></label>
        <div class="span-4 new-supplier" id="newSupplier" hidden>
          <div class="form-grid">
            <label class="span-2">New supplier name *<input name="ns_name" id="nsName"></label>
            <label>GSTIN<input name="ns_gstin" id="nsGstin"></label>
            <label>Phone<input name="ns_phone" id="nsPhone"></label>
            <label class="span-2">Drug licence no.<input name="ns_dl_no" id="nsDl"></label>
            <label class="span-2">Address<input name="ns_address" id="nsAddress"></label>
          </div>
        </div>
      </div>
      <div class="scan-warnings" id="scanWarnings" hidden></div>
      <h3 class="section-title">Items</h3>
      <div class="table-wrap"><table class="table entry-table">
        <thead><tr><th class="w-med">Medicine</th><th>Batch no.</th><th>Expiry</th><th>Packs</th><th>Free</th><th>Rate / pack</th><th>Disc %</th><th>GST %</th><th>MRP / pack</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody id="purchaseRows"></tbody>
      </table></div>
      <button type="button" class="btn btn-sm" id="addPurchaseRow">+ Add row</button>

      <div class="purchase-foot">
        <div class="pay-now">
          <label>Paid now (₹)<input type="number" name="paid_now" min="0" step="0.01" value="<?= e(post('paid_now', '0')) ?>"><small>Leave 0 for a credit purchase. It goes to the supplier's account.</small></label>
          <label>Paid by<select name="pay_mode"><?php foreach (SUPPLIER_PAY_MODES as $pm): ?><option <?= post('pay_mode') === $pm ? 'selected' : '' ?>><?= $pm ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="sum purchase-sum">
          <div><span>Taxable</span><b id="pTaxable">₹0.00</b></div>
          <div><span>GST</span><b id="pGst">₹0.00</b></div>
          <div><span>Round off</span><input type="number" name="round_off" id="pRound" step="0.01" min="-10" max="10" value="<?= e(post('round_off', '0')) ?>" class="w-sm"></div>
          <div class="sum-total"><span>Bill total</span><b id="purchaseTotal">₹0.00</b></div>
          <div class="scan-check" id="scanCheck" hidden></div>
        </div>
      </div>
      <label>Notes<input name="notes" maxlength="255" value="<?= e(post('notes')) ?>"></label>
      <div class="form-actions"><a class="btn" href="purchases.php">Cancel</a><button class="btn btn-primary">Save purchase &amp; add stock</button></div>
    </form>
    <template id="purchaseRowTpl">
      <tr>
        <td class="w-med">
          <div class="ac"><input class="ac-input" placeholder="Type medicine name…" autocomplete="off"><input type="hidden" class="ac-id" name="items[__i][medicine_id]"><div class="ac-list"></div></div>
          <small class="muted ac-meta"></small>
          <div class="new-med" hidden>
            <span class="tag tag-warn">New medicine — will be added</span>
            <input type="hidden" class="nm-name" name="items[__i][new_name]"><input type="hidden" class="nm-cat" name="items[__i][new_category]"><input type="hidden" class="nm-hsn" name="items[__i][new_hsn]">
            <span class="nm-fields">Units/pack <input type="number" class="w-xs nm-pack" name="items[__i][new_pack]" min="1" value="1"> Unit <input class="w-xs nm-unit" name="items[__i][new_unit]" value="Unit"></span>
          </div>
        </td>
        <td><input name="items[__i][batch_no]" required maxlength="40" class="w-sm"></td>
        <td><input type="month" name="items[__i][expiry]" required class="w-sm"></td>
        <td><input type="number" name="items[__i][packs]" min="0" value="1" class="w-xs calc"></td>
        <td><input type="number" name="items[__i][free_packs]" min="0" value="0" class="w-xs"></td>
        <td><input type="number" name="items[__i][rate]" min="0" step="0.01" class="w-sm calc" required></td>
        <td><input type="number" name="items[__i][disc_pct]" min="0" max="100" step="0.01" value="0" class="w-xs calc"></td>
        <td><select name="items[__i][gst_rate]" class="calc"><?php foreach (GST_RATES as $r): ?><option value="<?= $r ?>" <?= $r === 12 ? 'selected' : '' ?>><?= $r ?>%</option><?php endforeach; ?></select></td>
        <td><input type="number" name="items[__i][mrp]" min="0.01" step="0.01" class="w-sm" required></td>
        <td class="num row-amt">₹0.00</td>
        <td><button type="button" class="icon-btn row-del" title="Remove">✕</button></td>
      </tr>
    </template>
    <?php
    layout_footer();
    exit;
}

if (isset($_GET['view'])) {
    $p = q('SELECT p.*, s.name supplier, u.name user FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id
        LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?', [(int)$_GET['view']])->fetch();
    if (!$p) redirect('purchases.php');
    $items = q('SELECT b.*, m.name, m.pack_size, m.unit_label FROM batches b JOIN medicines m ON m.id = b.medicine_id WHERE b.purchase_id = ? ORDER BY b.id', [$p['id']])->fetchAll();
    $paid = (float)q('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE purchase_id = ?', [$p['id']])->fetchColumn();
    layout_header('Purchase #' . $p['id']);
    ?>
    <div class="card">
      <div class="card-head">
        <div><h2><?= $p['supplier_id'] ? '<a href="suppliers.php?ledger=' . (int)$p['supplier_id'] . '">' . e($p['supplier']) . '</a>' : 'Opening stock' ?></h2>
          <p class="muted">Invoice <?= e($p['invoice_no'] ?: '—') ?> · <?= fmt_date($p['invoice_date']) ?> · entered by <?= e($p['user'] ?? '—') ?></p></div>
        <div class="actions">
          <?php if ($p['bill_file'] && bill_file_path($p['bill_file'])): ?><a class="btn btn-sm" href="bill-file.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener">View scanned bill</a><?php endif; ?>
          <?php if ($me['role'] === 'admin'): ?>
          <form method="post" data-confirm="Delete this purchase, remove its stock and its entry in the supplier account?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-danger">Delete</button></form>
          <?php endif; ?>
        </div>
      </div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th class="num">Packs + free</th><th class="num">Rate</th><th class="num">Disc</th><th class="num">GST</th><th class="num">MRP</th><th class="num">Taxable</th><th class="num">Left now</th></tr></thead>
        <?php foreach ($items as $b): ?>
          <tr><td><?= e($b['name']) ?></td><td><?= e($b['batch_no']) ?></td><td><?= fmt_expiry($b['expiry_date']) ?></td>
            <td class="num"><?= (int)$b['packs'] ?><?= $b['free_packs'] ? ' + ' . (int)$b['free_packs'] : '' ?></td>
            <td class="num"><?= money($b['purchase_rate']) ?></td><td class="num"><?= (float)$b['disc_pct'] ?>%</td><td class="num"><?= (float)$b['gst_rate'] ?>%</td><td class="num"><?= money($b['mrp']) ?></td>
            <td class="num"><?= money($b['packs'] * $b['cost_rate']) ?></td>
            <td class="num"><?= e(units_label((int)$b['qty_available'], (int)$b['pack_size'], $b['unit_label'])) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
      <div class="purchase-foot">
        <div><?php if ($p['notes']): ?><p class="muted">Note: <?= e($p['notes']) ?></p><?php endif; ?>
          <?php if ($p['scanned_total'] !== null): ?><p class="muted">AI-scanned bill total: <?= money($p['scanned_total']) ?></p><?php endif; ?></div>
        <div class="sum purchase-sum">
          <div><span>Taxable</span><b><?= money($p['taxable']) ?></b></div>
          <div><span>GST</span><b><?= money($p['gst_amount']) ?></b></div>
          <div><span>Round off</span><b><?= money($p['round_off']) ?></b></div>
          <div class="sum-total"><span>Bill total</span><b><?= money($p['total']) ?></b></div>
          <?php if ($p['supplier_id']): ?><div><span>Paid with bill</span><b><?= money($paid) ?></b></div><?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    layout_footer();
    exit;
}

$from = valid_date(get('from')) ? get('from') : date('Y-m-01');
$to = valid_date(get('to')) ? get('to') : date('Y-m-d');
$rows = q('SELECT p.*, s.name supplier, (SELECT COUNT(*) FROM batches b WHERE b.purchase_id = p.id) items
    FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.invoice_date BETWEEN ? AND ? ORDER BY p.invoice_date DESC, p.id DESC', [$from, $to])->fetchAll();

layout_header('Purchases');
?>
<div class="toolbar">
  <form class="search" method="get">
    <input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"><button class="btn">Show</button>
  </form>
  <a class="btn btn-primary" href="purchases.php?new=1">+ New purchase / Scan bill</a>
</div>
<div class="card flush">
  <?php if (!$rows): ?><p class="empty">No purchases in this period.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Supplier</th><th>Invoice</th><th class="num">Items</th><th class="num">Amount</th></tr></thead>
    <?php foreach ($rows as $p): ?>
      <tr><td><a href="purchases.php?view=<?= (int)$p['id'] ?>"><?= fmt_date($p['invoice_date']) ?></a></td><td><?= e($p['supplier'] ?: 'Opening stock') ?></td>
        <td><?= e($p['invoice_no']) ?><?= $p['bill_file'] ? ' <span class="tag tag-ok">Scanned</span>' : '' ?></td><td class="num"><?= (int)$p['items'] ?></td><td class="num"><?= money($p['total']) ?></td></tr>
    <?php endforeach; ?>
    <tfoot><tr><td colspan="4">Total</td><td class="num"><strong><?= money(array_sum(array_column($rows, 'total'))) ?></strong></td></tr></tfoot>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_footer();

<?php
require __DIR__ . '/boot.php';
$me = require_login();

$maxDisc = (float)setting('max_discount_pct', '20');
$prefill = [];

if (is_post()) {
    csrf_check();
    $items = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $discPct = round((float)post('discount_pct', '0'), 2);
    $mode = post('payment_mode', 'Cash');
    $cust = mb_substr(trim((string)post('customer_name')), 0, 100);
    $phone = mb_substr(preg_replace('/[^0-9]/', '', (string)post('customer_phone')), 0, 20);
    $doctor = mb_substr(trim((string)post('doctor_name')), 0, 100);
    $errors = [];

    $wanted = [];
    foreach ($items as $it) {
        $bid = (int)($it['batch_id'] ?? 0);
        $qty = (int)($it['qty'] ?? 0);
        if ($bid > 0 && $qty > 0) {
            $wanted[$bid] = ($wanted[$bid] ?? 0) + $qty;
        }
    }
    if (!$wanted) $errors[] = 'Add at least one medicine to the bill.';
    if ($discPct < 0 || $discPct > $maxDisc) $errors[] = "Discount must be between 0 and $maxDisc%.";
    if (!in_array($mode, PAYMENT_MODES, true)) $errors[] = 'Choose a payment mode.';
    if ($mode === 'Credit' && ($cust === '' || strlen($phone) < 10)) $errors[] = 'Customer name and 10-digit mobile are required for a credit bill.';

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lines = [];
            $needsRx = false;
            foreach ($wanted as $bid => $qty) {
                $b = q('SELECT b.*, m.name, m.pack_size, m.gst_rate, m.schedule, m.active FROM batches b JOIN medicines m ON m.id = b.medicine_id
                    WHERE b.id = ? FOR UPDATE', [$bid])->fetch();
                if (!$b) { $errors[] = 'A selected batch no longer exists.'; continue; }
                if (!$b['active']) $errors[] = "{$b['name']} is inactive.";
                if ($b['expiry_date'] < date('Y-m-d')) $errors[] = "{$b['name']} batch {$b['batch_no']} is expired and cannot be sold.";
                if ($qty > (int)$b['qty_available']) $errors[] = "{$b['name']} batch {$b['batch_no']}: only {$b['qty_available']} in stock.";
                if ($b['schedule'] !== 'OTC') $needsRx = true;
                $unit = (float)$b['mrp'] / max(1, (int)$b['pack_size']);
                $gross = round($unit * $qty, 2);
                $amount = round($gross * (1 - $discPct / 100), 2);
                $taxable = round($amount / (1 + (float)$b['gst_rate'] / 100), 2);
                $lines[] = ['b' => $b, 'qty' => $qty, 'unit' => $unit, 'cost' => (float)$b['cost_rate'] / max(1, (int)$b['pack_size']),
                    'gross' => $gross, 'amount' => $amount, 'taxable' => $taxable, 'gst' => round($amount - $taxable, 2)];
            }
            if ($needsRx && $doctor === '') $errors[] = 'This bill has Schedule H/H1/X medicine. Enter the prescribing doctor\'s name.';
            if ($errors) throw new RuntimeException('validation');

            $gross = array_sum(array_column($lines, 'gross'));
            $net = array_sum(array_column($lines, 'amount'));
            $total = round($net);

            // Customer record linking / creation
            $customerId = null;
            if ($phone !== '') {
                $cRow = q('SELECT id, name, doctor_name, balance, credit_limit FROM customers WHERE phone = ? FOR UPDATE', [$phone])->fetch();
                if ($cRow) {
                    $customerId = (int)$cRow['id'];
                    if ($cust !== '' && $cRow['name'] !== $cust) {
                        q('UPDATE customers SET name = ? WHERE id = ?', [$cust, $customerId]);
                    }
                    if ($doctor !== '' && !$cRow['doctor_name']) {
                        q('UPDATE customers SET doctor_name = ? WHERE id = ?', [$doctor, $customerId]);
                    }
                } elseif ($cust !== '') {
                    q('INSERT INTO customers (name, phone, doctor_name, balance) VALUES (?, ?, ?, 0)',
                        [$cust, $phone, $doctor]);
                    $customerId = (int)$pdo->lastInsertId();
                }
            }

            // If Credit, update customer balance
            if ($mode === 'Credit') {
                if (!$customerId) {
                    throw new RuntimeException('Customer account required for credit billing.');
                }
                q('UPDATE customers SET balance = balance + ? WHERE id = ?', [$total, $customerId]);
            }

            q('INSERT INTO sales (customer_id, customer_name, customer_phone, doctor_name, gross, discount_pct, discount, taxable, gst_amount, round_off, total, payment_mode, created_by)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $customerId, $cust, $phone, $doctor, round($gross, 2), $discPct, round($gross - $net, 2),
                round(array_sum(array_column($lines, 'taxable')), 2), round(array_sum(array_column($lines, 'gst')), 2),
                round($total - $net, 2), $total, $mode, $me['id'],
            ]);
            $sid = (int)$pdo->lastInsertId();
            q('UPDATE sales SET bill_no = ? WHERE id = ?', [setting('bill_prefix', 'INV') . '-' . str_pad((string)$sid, 6, '0', STR_PAD_LEFT), $sid]);

            foreach ($lines as $l) {
                q('INSERT INTO sale_items (sale_id, medicine_id, batch_id, qty, unit_price, unit_cost, gross, amount, gst_rate, taxable, gst_amount)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$sid, $l['b']['medicine_id'], $l['b']['id'], $l['qty'], round($l['unit'], 4), round($l['cost'], 4), $l['gross'], $l['amount'], $l['b']['gst_rate'], $l['taxable'], $l['gst']]);
                q('UPDATE batches SET qty_available = qty_available - ? WHERE id = ?', [$l['qty'], $l['b']['id']]);
            }
            $pdo->commit();
            redirect('invoice.php?id=' . $sid . '&new=1');
        } catch (RuntimeException $ex) {
            $pdo->rollBack();
            if ($ex->getMessage() !== 'validation') throw $ex;
        }
    }
    foreach ($errors as $er) flash($er, 'err');

    // Rebuild the cart so the cashier does not have to enter it again.
    foreach ($wanted as $bid => $qty) {
        $m = q('SELECT m.id, m.name, m.generic_name, m.pack_size, m.unit_label, m.gst_rate, m.schedule, m.rack, m.barcode FROM batches b
            JOIN medicines m ON m.id = b.medicine_id WHERE b.id = ?', [$bid])->fetch();
        if (!$m) continue;
        $m['batches'] = array_map(fn($b) => ['id' => (int)$b['id'], 'batch_no' => $b['batch_no'], 'expiry' => fmt_expiry($b['expiry_date']),
            'mrp' => (float)$b['mrp'], 'qty' => (int)$b['qty_available']],
            q('SELECT id, batch_no, expiry_date, mrp, qty_available FROM batches WHERE medicine_id = ? AND qty_available > 0
               AND expiry_date >= CURDATE() ORDER BY expiry_date, id', [$m['id']])->fetchAll());
        $prefill[] = ['med' => $m + ['pack_size' => (int)$m['pack_size'], 'gst_rate' => (float)$m['gst_rate']], 'batch_id' => $bid, 'qty' => $qty];
    }
}

layout_header('New Bill (POS)');
?>
<form method="post" class="pos" id="billForm" data-prefill="<?= e(json_encode($prefill)) ?>" data-max-disc="<?= e((string)$maxDisc) ?>">
  <?= csrf_field() ?>
  <section class="card pos-items">
    <div class="pos-search-bar" style="display: flex; gap: 8px; align-items: center;">
      <div class="ac ac-big" style="flex: 1;">
        <input id="medSearch" class="ac-input" placeholder="Search medicine, salt, or scan barcode (F2)…" autocomplete="off" autofocus>
        <div class="ac-list"></div>
      </div>
      <button type="button" class="btn btn-outline" id="quickShortageBtn" title="Add out-of-stock medicine to Shortage Book">+ Shortage Book</button>
    </div>

    <div class="table-wrap"><table class="table entry-table">
      <thead>
        <tr>
          <th>Medicine</th>
          <th>Batch · Exp</th>
          <th class="num">MRP / unit</th>
          <th>Qty (units)</th>
          <th class="num">Amount</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="billRows">
        <tr class="empty-row"><td colspan="6" class="empty">Search or scan barcode above to add medicines to this bill.</td></tr>
      </tbody>
    </table></div>

    <!-- Keyboard shortcut footer bar -->
    <div class="shortcut-bar" style="margin-top: 16px; padding: 8px 12px; background: var(--bg-alt); border-radius: 6px; font-size: 12px; display: flex; gap: 16px; flex-wrap: wrap;">
      <span><kbd>F2</kbd> Search Medicine</span>
      <span><kbd>F4</kbd> Customer Phone</span>
      <span><kbd>F9</kbd> Save &amp; Print</span>
      <span><kbd>Enter</kbd> in Qty jumps to Search</span>
    </div>
  </section>

  <aside class="card pos-side">
    <h2>Customer &amp; Rx</h2>
    <div class="ac ac-cust" style="position: relative;">
      <label>Mobile Number (F4)
        <input name="customer_phone" id="custPhone" inputmode="tel" maxlength="20" value="<?= e(post('customer_phone')) ?>" placeholder="10-digit mobile" autocomplete="off">
      </label>
      <div id="custAcList" class="ac-list"></div>
    </div>
    <div id="custBalanceNotice" style="display: none; padding: 6px 10px; background: #fee2e2; color: #991b1b; border-radius: 6px; font-size: 12px; margin-bottom: 8px; font-weight: 600;"></div>

    <label>Patient / Customer Name
      <input name="customer_name" id="custName" maxlength="100" value="<?= e(post('customer_name')) ?>" placeholder="Walk-in customer">
    </label>
    <label>Prescribing Doctor (Rx)
      <input name="doctor_name" id="docName" maxlength="100" value="<?= e(post('doctor_name')) ?>" placeholder="Required for Schedule H/H1/X">
    </label>

    <div class="sum">
      <div><span>Gross (MRP)</span><b id="sGross">₹0.00</b></div>
      <div><span>Discount %</span><input type="number" name="discount_pct" id="discPct" min="0" max="<?= e((string)$maxDisc) ?>" step="0.5" value="<?= e(post('discount_pct', '0')) ?>" class="w-xs"></div>
      <div><span>Discount</span><b id="sDisc">₹0.00</b></div>
      <div><span>Incl. GST</span><b id="sGst">₹0.00</b></div>
      <div><span>Round off</span><b id="sRound">₹0.00</b></div>
      <div class="sum-total"><span>Total</span><b id="sTotal">₹0</b></div>
    </div>

    <div class="pay-modes">
      <?php foreach (PAYMENT_MODES as $pm): ?>
        <label class="chip"><input type="radio" name="payment_mode" value="<?= $pm ?>" <?= post('payment_mode', 'Cash') === $pm ? 'checked' : '' ?>><span><?= $pm ?></span></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-block btn-lg" id="saveBill">Save &amp; Print Bill (F9)</button>
  </aside>
</form>

<!-- Substitute Finder Modal -->
<dialog id="substituteModal" class="modal-dialog">
  <div class="card" style="margin: 0; min-width: 320px; max-width: 600px;">
    <div class="card-head">
      <h2 id="subModalTitle">Same-Salt Substitutes (विकल्प)</h2>
      <button type="button" class="icon-btn" onclick="document.getElementById('substituteModal').close()">✕</button>
    </div>
    <p id="subModalDesc" class="muted" style="margin-bottom: 12px;"></p>
    <div id="subModalContent" class="table-wrap"><div class="empty">Loading in-stock substitutes…</div></div>
  </div>
</dialog>

<!-- Quick Shortage Book Modal -->
<dialog id="quickShortageModal" class="modal-dialog">
  <form method="post" action="shortage.php" class="card" style="margin: 0; min-width: 320px; max-width: 480px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="card-head">
      <h2>Add to Shortage Book</h2>
      <button type="button" class="icon-btn" onclick="document.getElementById('quickShortageModal').close()">✕</button>
    </div>
    <label>Medicine / Brand Name *<input name="medicine_name" id="qsMedName" required placeholder="e.g. Augmentin 625"></label>
    <label>Salt / Composition<input name="generic_name" id="qsGeneric" placeholder="e.g. Amoxycillin + Clavulanic"></label>
    <label>Quantity Required (packs/strips)<input type="number" name="qty_requested" value="1" min="1"></label>
    <label>Patient / Customer Name<input name="customer_name" id="qsCustName" placeholder="Optional"></label>
    <label>Customer Mobile<input name="customer_phone" id="qsCustPhone" inputmode="tel" placeholder="Optional"></label>
    <div class="form-actions" style="margin-top: 16px;">
      <button type="button" class="btn" onclick="document.getElementById('quickShortageModal').close()">Cancel</button>
      <button class="btn btn-primary">Save to Shortage Book</button>
    </div>
  </form>
</dialog>

<?php layout_footer(); ?>

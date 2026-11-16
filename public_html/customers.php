<?php
require __DIR__ . '/boot.php';
$me = require_login();

// Record Payment
if (is_post() && post('action') === 'record_payment') {
    csrf_check();
    $cid = (int)post('customer_id');
    $amount = round((float)post('amount', '0'), 2);
    $payDate = valid_date(post('pay_date')) ? post('pay_date') : date('Y-m-d');
    $mode = post('mode', 'Cash');
    $ref = mb_substr(trim((string)post('reference')), 0, 60);
    $notes = mb_substr(trim((string)post('notes')), 0, 255);

    if ($amount <= 0) {
        flash('Payment amount must be greater than 0.', 'err');
        redirect('customers.php?id=' . $cid);
    }

    $pdo = db();
    $pdo->beginTransaction();
    $cust = q('SELECT id, name, balance FROM customers WHERE id = ? FOR UPDATE', [$cid])->fetch();
    if (!$cust) {
        $pdo->rollBack();
        flash('Customer not found.', 'err');
        redirect('customers.php');
    }

    q('INSERT INTO customer_payments (customer_id, pay_date, amount, mode, reference, notes, created_by)
       VALUES (?, ?, ?, ?, ?, ?, ?)', [$cid, $payDate, $amount, $mode, $ref, $notes, $me['id']]);

    q('UPDATE customers SET balance = balance - ? WHERE id = ?', [$amount, $cid]);
    $pdo->commit();

    flash("Payment of " . money($amount) . " recorded for {$cust['name']}.");
    redirect('customers.php?id=' . $cid);
}

// Add / Edit Customer
if (is_post() && post('action') === 'save_customer') {
    csrf_check();
    $cid = (int)post('customer_id');
    $name = mb_substr(trim((string)post('name')), 0, 100);
    $phone = mb_substr(preg_replace('/[^0-9]/', '', (string)post('phone')), 0, 20);
    $address = mb_substr(trim((string)post('address')), 0, 255);
    $doc = mb_substr(trim((string)post('doctor_name')), 0, 100);
    $limit = round((float)post('credit_limit', '5000'), 2);

    if ($name === '' || strlen($phone) < 10) {
        flash('Valid customer name and 10-digit mobile number are required.', 'err');
        redirect($cid ? 'customers.php?id=' . $cid : 'customers.php?new=1');
    }

    // Check duplicate phone
    $existing = q('SELECT id FROM customers WHERE phone = ? AND id <> ?', [$phone, $cid])->fetch();
    if ($existing) {
        flash('A customer with this phone number already exists.', 'err');
        redirect($cid ? 'customers.php?id=' . $cid : 'customers.php?new=1');
    }

    if ($cid) {
        q('UPDATE customers SET name = ?, phone = ?, address = ?, doctor_name = ?, credit_limit = ? WHERE id = ?',
            [$name, $phone, $address, $doc, $limit, $cid]);
        flash('Customer details updated.');
        redirect('customers.php?id=' . $cid);
    } else {
        q('INSERT INTO customers (name, phone, address, doctor_name, credit_limit, balance) VALUES (?, ?, ?, ?, ?, 0)',
            [$name, $phone, $address, $doc, $limit]);
        $newId = (int)db()->lastInsertId();
        flash('Customer added successfully.');
        redirect('customers.php?id=' . $newId);
    }
}

// Single Customer View
$viewId = (int)get('id');
if ($viewId) {
    $cust = q('SELECT * FROM customers WHERE id = ?', [$viewId])->fetch();
    if (!$cust) redirect('customers.php');

    $sales = q('SELECT id, bill_no, bill_date, total, payment_mode, status FROM sales WHERE customer_id = ? ORDER BY id DESC', [$viewId])->fetchAll();
    $payments = q('SELECT cp.*, u.name cashier FROM customer_payments cp LEFT JOIN users u ON u.id = cp.created_by WHERE cp.customer_id = ? ORDER BY cp.id DESC', [$viewId])->fetchAll();

    // Build chronological ledger entries
    $ledger = [];
    foreach ($sales as $s) {
        if ($s['payment_mode'] === 'Credit') {
            $ledger[] = [
                'date' => $s['bill_date'],
                'type' => 'Sale (Credit)',
                'ref' => $s['bill_no'],
                'link' => 'invoice.php?id=' . $s['id'],
                'debit' => (float)$s['total'],
                'credit' => 0.0,
                'status' => $s['status'],
            ];
        }
    }
    foreach ($payments as $p) {
        $ledger[] = [
            'date' => $p['pay_date'] . ' ' . date('H:i:s', strtotime($p['created_at'])),
            'type' => 'Payment (' . $p['mode'] . ($p['reference'] ? ' - ' . $p['reference'] : '') . ')',
            'ref' => 'RCP-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT),
            'link' => null,
            'debit' => 0.0,
            'credit' => (float)$p['amount'],
            'status' => 'active',
        ];
    }
    usort($ledger, fn($a, $b) => strcmp($b['date'], $a['date']));

    $storeName = setting('store_name', 'Medical Store');
    $cleanPhone = preg_replace('/[^0-9]/', '', $cust['phone']);
    if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;
    $waText = rawurlencode("Namaste {$cust['name']} ji,\n\n{$storeName} par aapka kul baaki balance " . money($cust['balance']) . " hai.\nKripya samay par bhugtan karein.\n\nDhanyawad!");
    $waUrl = "https://api.whatsapp.com/send?phone={$cleanPhone}&text={$waText}";

    layout_header('Customer: ' . $cust['name']);
    ?>
    <div class="toolbar">
      <a class="btn" href="customers.php">← All Customers</a>
      <a class="btn btn-outline" target="_blank" rel="noopener" href="<?= e($waUrl) ?>">💬 WhatsApp Reminder</a>
    </div>

    <div class="grid-2">
      <div class="card">
        <div class="card-head">
          <h2>Customer Details</h2>
        </div>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_customer">
          <input type="hidden" name="customer_id" value="<?= (int)$cust['id'] ?>">
          <label>Full Name<input name="name" required value="<?= e($cust['name']) ?>"></label>
          <label>Mobile Number<input name="phone" required inputmode="tel" value="<?= e($cust['phone']) ?>"></label>
          <label>Address<textarea name="address" rows="2"><?= e($cust['address']) ?></textarea></label>
          <label>Preferred Doctor<input name="doctor_name" value="<?= e($cust['doctor_name']) ?>"></label>
          <label>Credit Limit (₹)<input type="number" name="credit_limit" min="0" step="100" value="<?= (float)$cust['credit_limit'] ?>"></label>
          <div class="form-actions"><button class="btn btn-primary">Update Details</button></div>
        </form>
      </div>

      <div>
        <div class="card" style="margin-bottom: 20px;">
          <div class="card-head"><h2>Current Outstanding (उधार)</h2></div>
          <div style="font-size: 32px; font-weight: 700; color: <?= $cust['balance'] > 0 ? '#b91c1c' : '#15803d' ?>; margin: 12px 0;">
            <?= money($cust['balance']) ?>
          </div>
          <p class="muted">Credit Limit: <?= money($cust['credit_limit']) ?>
            <?php if ($cust['balance'] > $cust['credit_limit']): ?>
              <span class="tag tag-danger" style="margin-left:8px;">Exceeded Limit</span>
            <?php endif; ?>
          </p>
        </div>

        <div class="card">
          <div class="card-head"><h2>Record Payment (रसीद)</h2></div>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="customer_id" value="<?= (int)$cust['id'] ?>">
            <label>Amount Received (₹)<input type="number" name="amount" min="1" step="0.5" required placeholder="₹0.00" autofocus></label>
            <div class="grid-2">
              <label>Payment Date<input type="date" name="pay_date" value="<?= date('Y-m-d') ?>"></label>
              <label>Mode<select name="mode">
                <option value="Cash">Cash (नकद)</option>
                <option value="UPI">UPI / GPay / PhonePe</option>
                <option value="Card">Card</option>
                <option value="Bank">Bank Transfer</option>
                <option value="Adjustment">Adjustment / Discount</option>
              </select></label>
            </div>
            <label>Reference / UTR / Cheque No.<input name="reference" placeholder="e.g. UTR 4210992389"></label>
            <label>Notes<input name="notes" placeholder="Optional remark"></label>
            <div class="form-actions"><button class="btn btn-primary btn-block">Save Payment</button></div>
          </form>
        </div>
      </div>
    </div>

    <div class="card flush" style="margin-top: 24px;">
      <div class="card-head" style="padding: 16px 20px;"><h2>Credit & Payment Ledger</h2></div>
      <?php if (!$ledger): ?><p class="empty">No credit bills or payments recorded yet.</p><?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead>
          <tr>
            <th>Date & Time</th>
            <th>Type</th>
            <th>Reference</th>
            <th class="num">Bill Amount (Debit)</th>
            <th class="num">Payment Received (Credit)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger as $entry): ?>
            <tr class="<?= $entry['status'] === 'cancelled' ? 'row-void' : '' ?>">
              <td><?= date('d M Y, h:i A', strtotime($entry['date'])) ?></td>
              <td><strong><?= e($entry['type']) ?></strong></td>
              <td>
                <?php if ($entry['link']): ?>
                  <a href="<?= e($entry['link']) ?>"><?= e($entry['ref']) ?></a>
                <?php else: ?>
                  <?= e($entry['ref']) ?>
                <?php endif; ?>
                <?php if ($entry['status'] === 'cancelled'): ?><span class="tag tag-danger">Cancelled</span><?php endif; ?>
              </td>
              <td class="num" style="color: #b91c1c;"><?= $entry['debit'] > 0 ? money($entry['debit']) : '-' ?></td>
              <td class="num" style="color: #15803d;"><?= $entry['credit'] > 0 ? money($entry['credit']) : '-' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <?php
    layout_footer();
    exit;
}

// List of Customers View
$search = trim((string)get('q'));
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE ? OR phone LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$customers = q('SELECT * FROM customers WHERE ' . implode(' AND ', $where) . ' ORDER BY balance DESC, name ASC LIMIT 200', $params)->fetchAll();

$stats = q('SELECT COUNT(*) total_cust, COALESCE(SUM(balance),0) total_balance,
    COUNT(CASE WHEN balance > 0 THEN 1 END) debtor_count,
    COALESCE(SUM(CASE WHEN balance > credit_limit THEN 1 ELSE 0 END),0) overdue_count FROM customers')->fetch();

layout_header('Customers (उधार खाता)');
?>
<div class="stats">
  <div class="stat"><span>Total Customers</span><strong><?= (int)$stats['total_cust'] ?></strong></div>
  <div class="stat stat-warn"><span>Total Outstanding (उधार)</span><strong><?= money($stats['total_balance']) ?></strong><small><?= (int)$stats['debtor_count'] ?> customers with pending balance</small></div>
  <div class="stat stat-danger"><span>Limit Exceeded</span><strong><?= (int)$stats['overdue_count'] ?></strong><small>customers above credit limit</small></div>
</div>

<div class="toolbar">
  <form class="search" method="get">
    <input name="q" value="<?= e($search) ?>" placeholder="Search customer by name or mobile…" autofocus>
    <button class="btn">Search</button>
  </form>
  <button class="btn btn-primary" onclick="document.getElementById('newCustModal').showModal()">+ New Customer</button>
</div>

<div class="card flush">
  <?php if (!$customers): ?>
    <p class="empty">No customers found. Click <strong>+ New Customer</strong> to add one.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead>
      <tr>
        <th>Customer Name</th>
        <th>Mobile</th>
        <th>Address</th>
        <th class="num">Credit Limit</th>
        <th class="num">Pending Balance</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($customers as $c): ?>
        <tr>
          <td><a href="customers.php?id=<?= (int)$c['id'] ?>"><strong><?= e($c['name']) ?></strong></a></td>
          <td><a href="tel:<?= e($c['phone']) ?>"><?= e($c['phone']) ?></a></td>
          <td><?= e($c['address'] ?: '—') ?></td>
          <td class="num"><?= money($c['credit_limit']) ?></td>
          <td class="num" style="font-weight: 700; color: <?= $c['balance'] > 0 ? '#b91c1c' : '#15803d' ?>;">
            <?= money($c['balance']) ?>
            <?php if ($c['balance'] > $c['credit_limit']): ?><small class="block tag tag-danger">Exceeded</small><?php endif; ?>
          </td>
          <td class="num">
            <a class="btn btn-sm" href="customers.php?id=<?= (int)$c['id'] ?>">View Ledger</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<!-- New Customer Modal -->
<dialog id="newCustModal" class="modal-dialog">
  <form method="post" class="card" style="margin: 0; min-width: 320px; max-width: 480px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_customer">
    <input type="hidden" name="customer_id" value="0">
    <div class="card-head">
      <h2>Add New Customer</h2>
      <button type="button" class="icon-btn" onclick="document.getElementById('newCustModal').close()">✕</button>
    </div>
    <label>Customer Name *<input name="name" required placeholder="e.g. Ramesh Patel"></label>
    <label>Mobile Number *<input name="phone" required inputmode="tel" placeholder="10-digit mobile"></label>
    <label>Address<textarea name="address" rows="2" placeholder="Locality / Village"></textarea></label>
    <label>Credit Limit (₹)<input type="number" name="credit_limit" value="5000" step="500"></label>
    <div class="form-actions" style="margin-top: 16px;">
      <button type="button" class="btn" onclick="document.getElementById('newCustModal').close()">Cancel</button>
      <button class="btn btn-primary">Save Customer</button>
    </div>
  </form>
</dialog>

<?php
layout_footer();

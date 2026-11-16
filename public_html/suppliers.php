<?php
require __DIR__ . '/boot.php';
$me = require_login();

$fields = ['name', 'contact_person', 'phone', 'email', 'gstin', 'dl_no', 'address'];
const LEDGER_PAY_MODES = ['Cash', 'UPI', 'Bank', 'Cheque', 'Adjustment'];

if (is_post() && post('action') === 'payment') {
    csrf_check();
    $sid = (int)post('supplier_id');
    $amount = round((float)post('amount'), 2);
    $date = post('pay_date');
    $mode = post('mode');
    if (!q('SELECT id FROM suppliers WHERE id = ?', [$sid])->fetch() || $amount <= 0 || !valid_date($date) || !in_array($mode, LEDGER_PAY_MODES, true)) {
        flash('Enter a valid date, amount and payment mode.', 'err');
    } else {
        q('INSERT INTO supplier_payments (supplier_id, pay_date, amount, mode, reference, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$sid, $date, $amount, $mode, mb_substr(post('reference'), 0, 60), $me['id']]);
        flash('Payment of ' . money($amount) . ' recorded.');
    }
    redirect('suppliers.php?ledger=' . $sid);
}

if (is_post() && post('action') === 'delete_payment') {
    csrf_check();
    require_admin();
    $pay = q('SELECT supplier_id FROM supplier_payments WHERE id = ?', [(int)post('id')])->fetch();
    if ($pay) {
        q('DELETE FROM supplier_payments WHERE id = ?', [(int)post('id')]);
        flash('Payment entry deleted.');
        redirect('suppliers.php?ledger=' . (int)$pay['supplier_id']);
    }
    redirect('suppliers.php');
}

if (is_post()) {
    csrf_check();
    $id = (int)post('id');
    $d = [];
    foreach ($fields as $f) {
        $d[$f] = mb_substr(post($f), 0, $f === 'address' ? 255 : 150);
    }
    $d['opening_balance'] = round((float)post('opening_balance', '0'), 2);
    if ($d['name'] === '') {
        flash('Supplier name is required.', 'err');
        redirect('suppliers.php?edit=' . $id);
    }
    $cols = array_keys($d);
    if ($id) {
        q('UPDATE suppliers SET ' . implode(', ', array_map(fn($f) => "$f = ?", $cols)) . ' WHERE id = ?', [...array_values($d), $id]);
        flash('Supplier updated.');
    } else {
        q('INSERT INTO suppliers (' . implode(', ', $cols) . ') VALUES (' . rtrim(str_repeat('?, ', count($cols)), ', ') . ')', array_values($d));
        flash('Supplier added.');
    }
    redirect('suppliers.php');
}

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $s = $id ? q('SELECT * FROM suppliers WHERE id = ?', [$id])->fetch() : null;
    $s = $s ?: array_fill_keys(['id', ...$fields], '') + ['opening_balance' => 0];
    layout_header($s['id'] ? 'Edit supplier' : 'Add supplier');
    ?>
    <form method="post" class="card form-grid">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <label class="span-2">Firm name *<input name="name" required value="<?= e($s['name']) ?>" placeholder="e.g. Shree Pharma Distributors"></label>
      <label>Contact person<input name="contact_person" value="<?= e($s['contact_person']) ?>"></label>
      <label>Phone<input name="phone" inputmode="tel" value="<?= e($s['phone']) ?>"></label>
      <label>Email<input type="email" name="email" value="<?= e($s['email']) ?>"></label>
      <label>GSTIN<input name="gstin" value="<?= e($s['gstin']) ?>"></label>
      <label class="span-2">Drug licence no.<input name="dl_no" value="<?= e($s['dl_no']) ?>"></label>
      <label class="span-2">Address<input name="address" value="<?= e($s['address']) ?>"></label>
      <label class="span-2">Opening balance (₹ we owe)<input type="number" step="0.01" name="opening_balance" value="<?= e((string)$s['opening_balance']) ?>"><small>Old dues before you started using this system. Use minus if the supplier owes you.</small></label>
      <div class="span-4 form-actions"><a class="btn" href="suppliers.php">Cancel</a><button class="btn btn-primary">Save supplier</button></div>
    </form>
    <?php
    layout_footer();
    exit;
}

if (isset($_GET['ledger'])) {
    $s = q('SELECT * FROM suppliers WHERE id = ?', [(int)$_GET['ledger']])->fetch();
    if (!$s) redirect('suppliers.php');
    $entries = q("SELECT 'bill' kind, id, invoice_date d, CONCAT('Purchase bill ', invoice_no) info, total amount, created_at FROM purchases WHERE supplier_id = ?
        UNION ALL
        SELECT 'pay' kind, id, pay_date d, CONCAT(mode, IF(reference = '', '', CONCAT(' · ', reference))) info, amount, created_at FROM supplier_payments WHERE supplier_id = ?
        ORDER BY d, created_at, kind", [$s['id'], $s['id']])->fetchAll();
    $bal = (float)$s['opening_balance'];
    $billed = $paid = 0.0;
    layout_header($s['name']);
    ?>
    <div class="ledger-top">
      <div class="card">
        <div class="card-head"><div><h2><?= e($s['name']) ?></h2><p class="muted"><?= e(implode(' · ', array_filter([$s['phone'], $s['gstin'] ? 'GSTIN ' . $s['gstin'] : '', $s['address']]))) ?></p></div>
          <a class="btn btn-sm" href="suppliers.php?edit=<?= (int)$s['id'] ?>">Edit</a></div>
        <div class="table-wrap"><table class="table compact ledger">
          <thead><tr><th>Date</th><th>Details</th><th class="num">Bill (+)</th><th class="num">Paid (−)</th><th class="num">Balance</th><?php if ($me['role'] === 'admin'): ?><th></th><?php endif; ?></tr></thead>
          <tr><td></td><td>Opening balance</td><td></td><td></td><td class="num"><?= money($bal) ?></td><?php if ($me['role'] === 'admin'): ?><td></td><?php endif; ?></tr>
          <?php foreach ($entries as $en):
              if ($en['kind'] === 'bill') { $bal += $en['amount']; $billed += $en['amount']; } else { $bal -= $en['amount']; $paid += $en['amount']; } ?>
            <tr>
              <td><?= fmt_date($en['d']) ?></td>
              <td><?= $en['kind'] === 'bill' ? '<a href="purchases.php?view=' . (int)$en['id'] . '">' . e($en['info']) . '</a>' : 'Payment · ' . e($en['info']) ?></td>
              <td class="num"><?= $en['kind'] === 'bill' ? money($en['amount']) : '' ?></td>
              <td class="num"><?= $en['kind'] === 'pay' ? money($en['amount']) : '' ?></td>
              <td class="num"><strong><?= money($bal) ?></strong></td>
              <?php if ($me['role'] === 'admin'): ?><td class="num"><?php if ($en['kind'] === 'pay'): ?>
                <form method="post" data-confirm="Delete this payment entry?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_payment"><input type="hidden" name="id" value="<?= (int)$en['id'] ?>"><button class="icon-btn" title="Delete payment">✕</button></form>
              <?php endif; ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </table></div>
      </div>
      <aside>
        <div class="stat <?= $bal > 0 ? 'stat-danger' : '' ?>"><span><?= $bal >= 0 ? 'Balance to pay' : 'Advance with supplier' ?></span><strong><?= money(abs($bal)) ?></strong><small>Billed <?= money($billed) ?> · Paid <?= money($paid) ?></small></div>
        <form method="post" class="card pay-form">
          <h2>Record payment</h2>
          <?= csrf_field() ?><input type="hidden" name="action" value="payment"><input type="hidden" name="supplier_id" value="<?= (int)$s['id'] ?>">
          <label>Amount (₹)<input type="number" name="amount" min="0.01" step="0.01" required value="<?= $bal > 0 ? e(number_format($bal, 2, '.', '')) : '' ?>"></label>
          <label>Date<input type="date" name="pay_date" required value="<?= date('Y-m-d') ?>"></label>
          <label>Mode<select name="mode"><?php foreach (LEDGER_PAY_MODES as $pm): ?><option><?= $pm ?></option><?php endforeach; ?></select></label>
          <label>Reference<input name="reference" maxlength="60" placeholder="UTR / cheque no."></label>
          <button class="btn btn-primary btn-block">Save payment</button>
        </form>
      </aside>
    </div>
    <?php
    layout_footer();
    exit;
}

$rows = q('SELECT s.*,
        (SELECT COUNT(*) FROM purchases p WHERE p.supplier_id = s.id) bills,
        (SELECT COALESCE(SUM(total),0) FROM purchases p WHERE p.supplier_id = s.id) billed,
        (SELECT COALESCE(SUM(amount),0) FROM supplier_payments sp WHERE sp.supplier_id = s.id) paid,
        (SELECT MAX(invoice_date) FROM purchases p WHERE p.supplier_id = s.id) last_purchase
    FROM suppliers s ORDER BY s.name')->fetchAll();
$totalDue = array_sum(array_map(fn($s) => $s['opening_balance'] + $s['billed'] - $s['paid'], $rows));

layout_header('Suppliers');
?>
<div class="toolbar"><div class="stat stat-danger stat-inline"><span>Total payable to suppliers</span><strong><?= money($totalDue) ?></strong></div><a class="btn btn-primary" href="suppliers.php?edit=0">+ Add supplier</a></div>
<div class="card flush">
  <?php if (!$rows): ?><p class="empty">No suppliers yet. They are also added automatically when you scan a bill.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Supplier</th><th>Phone</th><th>GSTIN</th><th>Last purchase</th><th class="num">Purchases</th><th class="num">Balance due</th><th></th></tr></thead>
    <?php foreach ($rows as $s): $due = $s['opening_balance'] + $s['billed'] - $s['paid']; ?>
      <tr>
        <td><a href="suppliers.php?ledger=<?= (int)$s['id'] ?>"><strong><?= e($s['name']) ?></strong></a><small class="block muted"><?= e($s['contact_person']) ?></small></td>
        <td><?= e($s['phone']) ?></td>
        <td><?= e($s['gstin']) ?></td>
        <td><?= $s['last_purchase'] ? fmt_date($s['last_purchase']) : '—' ?></td>
        <td class="num"><?= money($s['billed']) ?><small class="block muted"><?= (int)$s['bills'] ?> bills</small></td>
        <td class="num"><span class="tag <?= $due > 0.004 ? 'tag-danger' : 'tag-ok' ?>"><?= money($due) ?></span></td>
        <td class="num"><a class="btn btn-sm" href="suppliers.php?ledger=<?= (int)$s['id'] ?>">Account</a></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_footer();

<?php
require __DIR__ . '/boot.php';
$me = require_login();

$fields = ['name', 'generic_name', 'manufacturer', 'category', 'schedule', 'hsn', 'barcode', 'gst_rate', 'pack_size', 'unit_label', 'rack', 'reorder_level'];

// Download CSV template
if (get('download_template') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="medicines-import-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'generic_name', 'manufacturer', 'category', 'schedule', 'hsn', 'barcode', 'gst_rate', 'pack_size', 'unit_label', 'rack', 'reorder_level']);
    fputcsv($out, ['Dolo 650', 'Paracetamol 650mg', 'Micro Labs', 'Tablet', 'OTC', '3004', '8901234567890', '12', '15', 'Tab', 'A-1', '30']);
    fputcsv($out, ['Azithral 500', 'Azithromycin 500mg', 'Alembic', 'Tablet', 'H', '3004', '', '12', '5', 'Tab', 'B-2', '10']);
    fputcsv($out, ['Pan 40', 'Pantoprazole 40mg', 'Alkem', 'Tablet', 'H', '3004', '', '12', '15', 'Tab', 'B-1', '20']);
    exit;
}

// Bulk CSV Import
if (is_post() && post('action') === 'import_csv') {
    csrf_check();
    require_admin();
    if (empty($_FILES['csv_file']['tmp_name'])) {
        flash('Please select a valid CSV file to upload.', 'err');
        redirect('medicines.php');
    }
    $f = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$f) {
        flash('Could not read the uploaded CSV file.', 'err');
        redirect('medicines.php');
    }
    $header = fgetcsv($f);
    if (!$header) {
        flash('The CSV file is empty.', 'err');
        redirect('medicines.php');
    }
    $map = [];
    foreach ($header as $idx => $col) {
        $cleanCol = strtolower(trim((string)$col));
        if (in_array($cleanCol, $fields, true)) {
            $map[$cleanCol] = $idx;
        }
    }
    if (!isset($map['name'])) {
        flash("CSV must contain at least a 'name' column.", 'err');
        redirect('medicines.php');
    }

    $imported = 0;
    $errors = 0;
    while (($row = fgetcsv($f)) !== false) {
        if (!array_filter($row)) continue;
        $name = trim((string)($row[$map['name']] ?? ''));
        if ($name === '') continue;

        $gen = trim((string)($row[$map['generic_name'] ?? -1] ?? ''));
        $mfg = trim((string)($row[$map['manufacturer'] ?? -1] ?? ''));
        $cat = trim((string)($row[$map['category'] ?? -1] ?? 'Tablet'));
        if (!in_array($cat, MED_CATEGORIES, true)) $cat = 'Tablet';
        $sch = strtoupper(trim((string)($row[$map['schedule'] ?? -1] ?? 'OTC')));
        if (!in_array($sch, ['OTC', 'H', 'H1', 'X'], true)) $sch = 'OTC';
        $hsn = preg_replace('/\D/', '', (string)($row[$map['hsn'] ?? -1] ?? '3004')) ?: '3004';
        $barcode = trim((string)($row[$map['barcode'] ?? -1] ?? ''));
        $gst = (float)($row[$map['gst_rate'] ?? -1] ?? 12);
        if (!in_array($gst, array_map('floatval', GST_RATES), true)) $gst = 12.0;
        $pack = max(1, (int)($row[$map['pack_size'] ?? -1] ?? 10));
        $unit = trim((string)($row[$map['unit_label'] ?? -1] ?? 'Tab')) ?: 'Tab';
        $rack = trim((string)($row[$map['rack'] ?? -1] ?? ''));
        $reorder = max(0, (int)($row[$map['reorder_level'] ?? -1] ?? 20));

        try {
            q('INSERT INTO medicines (name, generic_name, manufacturer, category, schedule, hsn, barcode, gst_rate, pack_size, unit_label, rack, reorder_level)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$name, $gen, $mfg, $cat, $sch, $hsn, $barcode, $gst, $pack, $unit, $rack, $reorder]);
            $imported++;
        } catch (Exception $e) {
            $errors++;
        }
    }
    fclose($f);
    flash("Import complete: $imported medicine(s) imported." . ($errors ? " ($errors skipped due to errors)" : ""));
    redirect('medicines.php');
}

// Single Add/Edit
if (is_post() && post('action') !== 'import_csv') {
    csrf_check();
    $id = (int)post('id');
    if (post('action') === 'toggle' && $id) {
        q('UPDATE medicines SET active = 1 - active WHERE id = ?', [$id]);
        flash('Medicine status updated.');
        redirect('medicines.php?view=' . $id);
    }
    $d = [];
    foreach ($fields as $f) {
        $d[$f] = post($f);
    }
    $errors = [];
    if ($d['name'] === '') $errors[] = 'Medicine name is required.';
    if (!in_array($d['category'], MED_CATEGORIES, true)) $errors[] = 'Choose a valid category.';
    if (!in_array($d['schedule'], ['OTC', 'H', 'H1', 'X'], true)) $errors[] = 'Choose a valid schedule.';
    if (!in_array((float)$d['gst_rate'], array_map('floatval', GST_RATES), true)) $errors[] = 'Choose a valid GST rate.';
    $d['pack_size'] = max(1, (int)$d['pack_size']);
    $d['reorder_level'] = max(0, (int)$d['reorder_level']);
    $d['unit_label'] = $d['unit_label'] ?: 'Unit';
    $d['barcode'] = trim((string)$d['barcode']);

    if (!$errors) {
        $vals = array_values($d);
        if ($id) {
            q('UPDATE medicines SET ' . implode(', ', array_map(fn($f) => "$f = ?", $fields)) . ' WHERE id = ?', [...$vals, $id]);
            flash('Medicine updated.');
        } else {
            q('INSERT INTO medicines (' . implode(', ', $fields) . ') VALUES (' . rtrim(str_repeat('?, ', count($fields)), ', ') . ')', $vals);
            $id = (int)db()->lastInsertId();
            flash('Medicine added. Add stock from Purchases.');
        }
        redirect('medicines.php?view=' . $id);
    }
    foreach ($errors as $er) flash($er, 'err');
    $_GET['edit'] = (string)$id;
    $form = $d + ['id' => $id];
}

// ---- Add / edit form ----
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    if (!isset($form)) {
        $form = $id ? q('SELECT * FROM medicines WHERE id = ?', [$id])->fetch() : null;
        if ($id && !$form) redirect('medicines.php');
        $form = $form ?: ['id' => 0, 'name' => '', 'generic_name' => '', 'manufacturer' => '', 'category' => 'Tablet', 'schedule' => 'OTC',
            'hsn' => '3004', 'barcode' => '', 'gst_rate' => '12', 'pack_size' => 10, 'unit_label' => 'Tab', 'rack' => '', 'reorder_level' => 20];
    }
    layout_header($form['id'] ? 'Edit medicine' : 'Add medicine');
    ?>
    <form method="post" class="card form-grid">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
      <label class="span-2">Brand / medicine name *<input name="name" required maxlength="150" value="<?= e($form['name']) ?>" placeholder="e.g. Dolo 650"></label>
      <label class="span-2">Generic / salt composition<input name="generic_name" maxlength="200" value="<?= e($form['generic_name']) ?>" placeholder="e.g. Paracetamol 650mg"></label>
      <label>Manufacturer<input name="manufacturer" maxlength="150" value="<?= e($form['manufacturer']) ?>"></label>
      <label>Category<select name="category"><?php foreach (MED_CATEGORIES as $c): ?><option <?= $form['category'] === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></label>
      <label>Schedule<select name="schedule">
        <?php foreach (['OTC' => 'OTC (no prescription)', 'H' => 'Schedule H', 'H1' => 'Schedule H1', 'X' => 'Schedule X'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $form['schedule'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <label>Barcode / EAN<input name="barcode" maxlength="50" value="<?= e($form['barcode'] ?? '') ?>" placeholder="Scan or enter barcode"></label>
      <label>Rack / shelf<input name="rack" maxlength="30" value="<?= e($form['rack']) ?>" placeholder="e.g. A-3"></label>
      <label>HSN code<input name="hsn" maxlength="10" value="<?= e($form['hsn']) ?>"></label>
      <label>GST rate<select name="gst_rate"><?php foreach (GST_RATES as $r): ?><option value="<?= $r ?>" <?= (float)$form['gst_rate'] == $r ? 'selected' : '' ?>><?= $r ?>%</option><?php endforeach; ?></select></label>
      <label>Units per pack<input type="number" name="pack_size" min="1" value="<?= (int)$form['pack_size'] ?>"><small>e.g. 10 tablets per strip, 1 for a bottle</small></label>
      <label>Unit name<input name="unit_label" maxlength="20" value="<?= e($form['unit_label']) ?>" placeholder="Tab / Cap / Btl"></label>
      <label>Reorder level (units)<input type="number" name="reorder_level" min="0" value="<?= (int)$form['reorder_level'] ?>"><small>Alert when stock falls to this</small></label>
      <div class="span-4 form-actions">
        <a class="btn" href="<?= $form['id'] ? 'medicines.php?view=' . (int)$form['id'] : 'medicines.php' ?>">Cancel</a>
        <button class="btn btn-primary">Save medicine</button>
      </div>
    </form>
    <?php
    layout_footer();
    exit;
}

// ---- Single medicine view ----
if (isset($_GET['view'])) {
    $m = q('SELECT * FROM medicines WHERE id = ?', [(int)$_GET['view']])->fetch();
    if (!$m) redirect('medicines.php');
    $batches = q('SELECT b.*, s.name sup_name FROM batches b
        LEFT JOIN purchases p ON p.id = b.purchase_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE b.medicine_id = ? AND b.qty_available > 0 ORDER BY b.expiry_date', [$m['id']])->fetchAll();
    $sold = q("SELECT COALESCE(SUM(si.qty),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
        WHERE si.medicine_id = ? AND s.status = 'active' AND s.bill_date >= CURDATE() - INTERVAL 30 DAY", [$m['id']])->fetchColumn();
    $total = array_sum(array_map(fn($b) => $b['expiry_date'] >= date('Y-m-d') ? (int)$b['qty_available'] : 0, $batches));

    // Find in-stock substitute medicines with the same salt composition
    $substitutes = [];
    if ($m['generic_name']) {
        $substitutes = q("SELECT m2.*, COALESCE(SUM(b2.qty_available),0) stock, MIN(b2.mrp) min_mrp
            FROM medicines m2
            JOIN batches b2 ON b2.medicine_id = m2.id
            WHERE m2.id <> ? AND m2.generic_name = ? AND m2.active = 1
              AND b2.qty_available > 0 AND b2.expiry_date >= CURDATE()
            GROUP BY m2.id ORDER BY stock DESC", [$m['id'], $m['generic_name']])->fetchAll();
    }

    layout_header($m['name']);
    ?>
    <div class="card">
      <div class="card-head">
        <div>
          <h2><?= e($m['name']) ?>
            <?php if (!$m['active']): ?><span class="tag">Inactive</span><?php endif; ?>
            <?php if ($m['schedule'] !== 'OTC'): ?><span class="tag tag-danger">Sch <?= e($m['schedule']) ?></span><?php endif; ?>
          </h2>
          <p class="muted"><?= e($m['generic_name']) ?><?= $m['manufacturer'] ? ' · ' . e($m['manufacturer']) : '' ?></p>
        </div>
        <div class="actions">
          <a class="btn btn-sm" href="medicines.php?edit=<?= (int)$m['id'] ?>">Edit</a>
          <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button class="btn btn-sm"><?= $m['active'] ? 'Deactivate' : 'Activate' ?></button></form>
        </div>
      </div>
      <div class="stats stats-4" style="margin: 16px 0;">
        <div class="stat"><span>Stock Available</span><strong><?= e(units_label($total, (int)$m['pack_size'], $m['unit_label'])) ?></strong></div>
        <div class="stat"><span>Sold (last 30 d)</span><strong><?= (int)$sold ?> <?= e($m['unit_label']) ?></strong></div>
        <div class="stat"><span>Reorder Level</span><strong><?= (int)$m['reorder_level'] ?> <?= e($m['unit_label']) ?></strong></div>
        <div class="stat"><span>Rack / Shelf</span><strong><?= e($m['rack'] ?: '—') ?></strong></div>
      </div>
      <div style="font-size: 13px; color: var(--ink); line-height: 1.8;">
        <span>HSN: <b><?= e($m['hsn']) ?></b></span> ·
        <span>GST Rate: <b><?= (float)$m['gst_rate'] ?>%</b></span> ·
        <span>Category: <b><?= e($m['category']) ?></b></span>
        <?php if (!empty($m['barcode'])): ?> · <span>Barcode: <b><?= e($m['barcode']) ?></b></span><?php endif; ?>
      </div>
    </div>

    <!-- Same Salt / Substitute Medicines -->
    <?php if ($m['generic_name']): ?>
      <div class="card flush" style="margin-top: 20px;">
        <div class="card-head" style="padding: 14px 20px;">
          <h2>Same-Salt In-Stock Alternatives (विकल्प)</h2>
          <small class="muted">Medicines with composition: <?= e($m['generic_name']) ?></small>
        </div>
        <?php if (!$substitutes): ?>
          <p class="empty">No other in-stock medicines with this exact salt.</p>
        <?php else: ?>
          <div class="table-wrap"><table class="table compact">
            <thead>
              <tr>
                <th>Brand Name</th>
                <th>Manufacturer</th>
                <th>Pack Size</th>
                <th>Rack</th>
                <th class="num">Available Stock</th>
                <th class="num">MRP / pack</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($substitutes as $sub): ?>
                <tr>
                  <td><a href="medicines.php?view=<?= (int)$sub['id'] ?>"><strong><?= e($sub['name']) ?></strong></a></td>
                  <td><?= e($sub['manufacturer']) ?></td>
                  <td><?= (int)$sub['pack_size'] ?> <?= e($sub['unit_label']) ?>/pk</td>
                  <td><?= e($sub['rack'] ?: '—') ?></td>
                  <td class="num"><span class="tag tag-ok"><?= e(units_label((int)$sub['stock'], (int)$sub['pack_size'], $sub['unit_label'])) ?></span></td>
                  <td class="num"><?= money($sub['min_mrp']) ?></td>
                  <td class="num"><a class="btn btn-sm" href="billing.php">Sell</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Active Batches -->
    <div class="card flush" style="margin-top: 20px;">
      <div class="card-head" style="padding: 14px 20px;"><h2>Batches in Stock</h2></div>
      <?php if (!$batches): ?><p class="empty">No active stock. Add stock from <a href="purchases.php">Purchases</a>.</p><?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead>
          <tr>
            <th>Batch No</th>
            <th>Expiry</th>
            <th>Supplier</th>
            <th class="num">Purchase Rate</th>
            <th class="num">MRP</th>
            <th class="num">Available</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($batches as $b): ?>
            <tr>
              <td><strong><?= e($b['batch_no']) ?></strong></td>
              <td><?= fmt_expiry($b['expiry_date']) ?></td>
              <td><?= e($b['sup_name'] ?: '—') ?></td>
              <td class="num"><?= money($b['purchase_rate']) ?></td>
              <td class="num"><?= money($b['mrp']) ?></td>
              <td class="num"><strong><?= (int)$b['qty_available'] ?></strong> <?= e($m['unit_label']) ?></td>
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

// ---- Medicine Master List ----
$search = trim((string)get('q'));
$catF = get('cat');
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(m.name LIKE ? OR m.generic_name LIKE ? OR m.barcode LIKE ? OR m.manufacturer LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($catF && in_array($catF, MED_CATEGORIES, true)) {
    $where[] = 'm.category = ?';
    $params[] = $catF;
}

$rows = q("SELECT m.*, COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.qty_available END), 0) stock
    FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
    WHERE " . implode(' AND ', $where) . " GROUP BY m.id ORDER BY m.name ASC LIMIT 300", $params)->fetchAll();

layout_header('Medicines');
?>
<div class="toolbar">
  <form class="search" method="get">
    <input name="q" value="<?= e($search) ?>" placeholder="Search medicine, salt, barcode or manufacturer…" autofocus>
    <select name="cat"><option value="">All Categories</option><?php foreach (MED_CATEGORIES as $c): ?><option <?= $catF === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
    <button class="btn">Filter</button>
  </form>
  <div style="display: flex; gap: 8px;">
    <?php if ($me['role'] === 'admin'): ?>
      <button class="btn btn-outline" onclick="document.getElementById('importCsvModal').showModal()">📥 Import CSV</button>
    <?php endif; ?>
    <a class="btn btn-primary" href="medicines.php?edit=0">+ Add Medicine</a>
  </div>
</div>

<div class="card flush">
  <?php if (!$rows): ?><p class="empty">No medicines found.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead>
      <tr>
        <th>Medicine / Brand</th>
        <th>Generic / Salt</th>
        <th>Category</th>
        <th>Rack</th>
        <th class="num">Pack Size</th>
        <th class="num">In Stock</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $m): ?>
        <tr class="<?= !$m['active'] ? 'row-void' : '' ?>">
          <td>
            <a href="medicines.php?view=<?= (int)$m['id'] ?>"><strong><?= e($m['name']) ?></strong></a>
            <?php if ($m['schedule'] !== 'OTC'): ?><span class="rx-flag">Rx <?= e($m['schedule']) ?></span><?php endif; ?>
            <?php if (!empty($m['barcode'])): ?><small class="block muted">Barcode: <?= e($m['barcode']) ?></small><?php endif; ?>
          </td>
          <td><?= e($m['generic_name']) ?><small class="block muted"><?= e($m['manufacturer']) ?></small></td>
          <td><?= e($m['category']) ?></td>
          <td><?= e($m['rack'] ?: '—') ?></td>
          <td class="num"><?= (int)$m['pack_size'] ?> <?= e($m['unit_label']) ?></td>
          <td class="num"><span class="tag <?= $m['stock'] == 0 ? 'tag-danger' : ($m['stock'] <= $m['reorder_level'] ? 'tag-warn' : 'tag-ok') ?>"><?= e(units_label((int)$m['stock'], (int)$m['pack_size'], $m['unit_label'])) ?></span></td>
          <td class="num"><a class="btn btn-sm" href="medicines.php?view=<?= (int)$m['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<!-- Bulk CSV Import Modal -->
<dialog id="importCsvModal" class="modal-dialog">
  <form method="post" enctype="multipart/form-data" class="card" style="margin: 0; min-width: 320px; max-width: 500px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import_csv">
    <div class="card-head">
      <h2>Bulk Import Medicines (CSV)</h2>
      <button type="button" class="icon-btn" onclick="document.getElementById('importCsvModal').close()">✕</button>
    </div>
    <p class="muted">Upload your medicine inventory list in CSV format. You can download our sample CSV template below:</p>
    <p><a class="btn btn-sm btn-outline" href="medicines.php?download_template=csv">📄 Download Sample CSV Template</a></p>
    <label style="margin-top: 14px;">Select CSV File *
      <input type="file" name="csv_file" accept=".csv,text/csv" required>
    </label>
    <div class="form-actions" style="margin-top: 16px;">
      <button type="button" class="btn" onclick="document.getElementById('importCsvModal').close()">Cancel</button>
      <button class="btn btn-primary">Start Import</button>
    </div>
  </form>
</dialog>

<?php layout_footer(); ?>

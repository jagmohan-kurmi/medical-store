<?php
// JSON lookups used by billing, barcode scanning, substitutes, and customer autocomplete.
require __DIR__ . '/boot.php';

header('Content-Type: application/json; charset=utf-8');
if (!user()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Signed out']));
}

$mode = get('mode');

// 1. Customer Autocomplete by Phone or Name
if ($mode === 'customer') {
    $q = trim((string)get('q'));
    if (mb_strlen($q) < 2) exit(json_encode([]));
    $like = '%' . $q . '%';
    $custs = q('SELECT id, name, phone, address, doctor_name, credit_limit, balance
        FROM customers WHERE phone LIKE ? OR name LIKE ? ORDER BY (phone LIKE ?) DESC, name ASC LIMIT 10',
        [$like, $like, $q . '%'])->fetchAll();
    foreach ($custs as &$c) {
        $c['id'] = (int)$c['id'];
        $c['credit_limit'] = (float)$c['credit_limit'];
        $c['balance'] = (float)$c['balance'];
    }
    exit(json_encode($custs));
}

// 2. Same-Salt / Substitute Medicine Finder
if ($mode === 'substitutes') {
    $medId = (int)get('med_id');
    $salt = trim((string)get('salt'));
    if ($medId > 0 && $salt === '') {
        $salt = (string)q('SELECT generic_name FROM medicines WHERE id = ?', [$medId])->fetchColumn();
    }
    if ($salt === '') exit(json_encode([]));

    // Find other medicines with same generic name that have positive non-expired stock
    $alts = q("SELECT m.id, m.name, m.generic_name, m.manufacturer, m.pack_size, m.unit_label, m.gst_rate, m.schedule, m.rack,
            COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.qty_available END), 0) stock
        FROM medicines m
        JOIN batches b ON b.medicine_id = m.id
        WHERE m.active = 1 AND m.id <> ? AND (m.generic_name = ? OR (LENGTH(?) > 4 AND m.generic_name LIKE ?))
        GROUP BY m.id
        HAVING stock > 0
        ORDER BY stock DESC, m.name ASC LIMIT 10",
        [$medId, $salt, $salt, '%' . $salt . '%'])->fetchAll();

    foreach ($alts as &$a) {
        $a['id'] = (int)$a['id'];
        $a['pack_size'] = (int)$a['pack_size'];
        $a['gst_rate'] = (float)$a['gst_rate'];
        $a['stock'] = (int)$a['stock'];
        $a['batches'] = array_map(fn($b) => [
            'id' => (int)$b['id'],
            'batch_no' => $b['batch_no'],
            'expiry' => fmt_expiry($b['expiry_date']),
            'mrp' => (float)$b['mrp'],
            'qty' => (int)$b['qty_available'],
        ], q('SELECT id, batch_no, expiry_date, mrp, qty_available FROM batches
            WHERE medicine_id = ? AND qty_available > 0 AND expiry_date >= CURDATE() ORDER BY expiry_date, id', [$a['id']])->fetchAll());
    }
    exit(json_encode($alts));
}

// 3. Medicine Search (Sale / Purchase / Barcode)
$term = trim((string)get('q'));
$searchMode = $mode === 'purchase' ? 'purchase' : 'sale';

if (mb_strlen($term) < 1) {
    exit(json_encode([]));
}

// Check if exact barcode match first
$barcodeMatch = q("SELECT m.id, m.name, m.generic_name, m.manufacturer, m.pack_size, m.unit_label, m.gst_rate, m.schedule, m.rack, m.barcode,
        COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.qty_available END), 0) stock
    FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
    WHERE m.active = 1 AND (m.barcode = ? OR b.batch_no = ?)
    GROUP BY m.id LIMIT 1", [$term, $term])->fetchAll();

if ($barcodeMatch) {
    $meds = $barcodeMatch;
} else {
    if (mb_strlen($term) < 2) exit(json_encode([]));
    $like = '%' . $term . '%';
    $meds = q("SELECT m.id, m.name, m.generic_name, m.manufacturer, m.pack_size, m.unit_label, m.gst_rate, m.schedule, m.rack, m.barcode,
            COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.qty_available END), 0) stock
        FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
        WHERE m.active = 1 AND (m.name LIKE ? OR m.generic_name LIKE ? OR m.barcode LIKE ?)
        GROUP BY m.id ORDER BY (m.name LIKE ?) DESC, (m.barcode = ?) DESC, m.name LIMIT 15",
        [$like, $like, $like, $term . '%', $term])->fetchAll();
}

foreach ($meds as &$m) {
    $m['id'] = (int)$m['id'];
    $m['pack_size'] = (int)$m['pack_size'];
    $m['gst_rate'] = (float)$m['gst_rate'];
    $m['stock'] = (int)$m['stock'];

    // Check count of in-stock substitute brands
    if ($searchMode === 'sale' && $m['generic_name']) {
        $m['substitute_count'] = (int)q("SELECT COUNT(DISTINCT m2.id)
            FROM medicines m2 JOIN batches b2 ON b2.medicine_id = m2.id
            WHERE m2.id <> ? AND m2.generic_name = ? AND m2.active = 1 AND b2.qty_available > 0 AND b2.expiry_date >= CURDATE()",
            [$m['id'], $m['generic_name']])->fetchColumn();
    } else {
        $m['substitute_count'] = 0;
    }

    if ($searchMode === 'sale') {
        $m['batches'] = array_map(fn($b) => [
            'id' => (int)$b['id'], 'batch_no' => $b['batch_no'], 'expiry' => fmt_expiry($b['expiry_date']),
            'mrp' => (float)$b['mrp'], 'qty' => (int)$b['qty_available'],
        ], q('SELECT id, batch_no, expiry_date, mrp, qty_available FROM batches
            WHERE medicine_id = ? AND qty_available > 0 AND expiry_date >= CURDATE() ORDER BY expiry_date, id', [$m['id']])->fetchAll());
    } else {
        $last = q('SELECT mrp, purchase_rate, disc_pct FROM batches WHERE medicine_id = ? ORDER BY id DESC LIMIT 1', [$m['id']])->fetch();
        $m['last_mrp'] = $last ? (float)$last['mrp'] : null;
        $m['last_rate'] = $last ? (float)$last['purchase_rate'] : null;
        $m['last_disc'] = $last ? (float)$last['disc_pct'] : null;
    }
}
echo json_encode($meds);

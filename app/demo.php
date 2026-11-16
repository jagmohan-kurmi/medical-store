<?php
declare(strict_types=1);

// Demo store data: schema, sample settings, users, suppliers, medicines, a purchase and ~2 weeks of bills.

function apply_schema(): void
{
    $schema = file_get_contents(APP_ROOT . '/database/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $schema)))) as $stmt) {
        try { db()->exec($stmt); } catch (Exception $e) {}
    }
    if (is_file(APP_ROOT . '/database/migration_v2.sql')) {
        $mig = file_get_contents(APP_ROOT . '/database/migration_v2.sql');
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $mig)))) as $stmt) {
            try { db()->exec($stmt); } catch (Exception $e) {}
        }
    }
}

/** Seeds an empty store. Refuses (and says so) when bills already exist. */
function demo_seed(): string
{
    apply_schema();
    if ((int)q('SELECT COUNT(*) FROM sales')->fetchColumn() > 0) {
        return "Sales already exist. Demo seed is only for an empty database.";
    }

    $settings = [
        'store_name' => 'City Care Medical Store', 'address' => "Shop 4, Main Market Road\nNear Bus Stand, Indore (M.P.) 452001",
        'phone' => '98270 00000', 'gstin' => '23ABCDE1234F1Z5', 'dl_no' => '20B/MP/IND/0001, 21B/MP/IND/0001',
        'store_upi_id' => 'citycare@upi', 'default_invoice_format' => 'a4',
        'expiry_alert_days' => '90', 'max_discount_pct' => '20', 'bill_prefix' => 'CC',
        'invoice_footer' => 'Goods once sold will not be taken back without bill. Please check expiry before use. Get well soon!',
    ];
    foreach ($settings as $k => $v) {
        q('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$k, $v]);
    }
    q("INSERT IGNORE INTO users (name, username, password_hash, role) VALUES ('Store Owner', 'admin', ?, 'admin')", [password_hash('admin12345', PASSWORD_DEFAULT)]);
    q("INSERT IGNORE INTO users (name, username, password_hash, role) VALUES ('Counter Staff', 'staff', ?, 'staff')", [password_hash('staff12345', PASSWORD_DEFAULT)]);
    $adminId = (int)q("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();

    q("INSERT INTO suppliers (name, contact_person, phone, gstin, dl_no, address) VALUES
      ('Shree Pharma Distributors', 'Rakesh Jain', '9826100001', '23AAAFS1111A1Z1', '20B/MP/101', 'Siyaganj, Indore'),
      ('Medilink Agencies', 'Sunil Verma', '9826100002', '23AAAFM2222B1Z2', '20B/MP/202', 'Kothari Market, Indore')");
    $sup = q('SELECT id FROM suppliers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    q("INSERT INTO customers (name, phone, address, doctor_name, credit_limit, balance) VALUES
      ('Ramesh Patel', '9827011223', 'MG Road, Indore', 'Dr. S. K. Mehta', 5000, 450.00),
      ('Sunita Sharma', '9827022334', 'Palasia, Indore', '', 3000, 0.00),
      ('Amit Joshi', '9827033445', 'Vijay Nagar, Indore', 'Dr. Alok Verma', 5000, 1200.00)");

    q("INSERT INTO shortage_book (medicine_name, generic_name, qty_requested, customer_name, customer_phone, notes, created_by) VALUES
      ('Augmentin 625 Duo', 'Amoxicillin 500mg + Clavulanic Acid 125mg', 10, 'Deepak Soni', '9827044556', 'Patient needs urgently for full course', ?),
      ('Thyronorm 50mcg', 'Thyroxine Sodium', 5, 'Kavita Rathore', '9827055667', '', ?)", [$adminId, $adminId]);

    // name, generic, maker, category, schedule, gst, pack, unit, rack, reorder, mrp/pack, rate/pack, packs
    $meds = [
        ['Dolo 650', 'Paracetamol 650mg', 'Micro Labs', 'Tablet', 'OTC', 12, 15, 'Tab', 'A-1', 60, 33.60, 24.10, 40],
        ['Crocin Advance', 'Paracetamol 500mg', 'GSK', 'Tablet', 'OTC', 12, 15, 'Tab', 'A-1', 45, 30.00, 21.50, 20],
        ['Azithral 500', 'Azithromycin 500mg', 'Alembic', 'Tablet', 'H', 12, 5, 'Tab', 'B-2', 15, 119.50, 85.00, 12],
        ['Augmentin 625 Duo', 'Amoxicillin 500mg + Clavulanic Acid 125mg', 'GSK', 'Tablet', 'H', 12, 10, 'Tab', 'B-2', 20, 223.00, 160.00, 8],
        ['Pan 40', 'Pantoprazole 40mg', 'Alkem', 'Tablet', 'H', 12, 15, 'Tab', 'B-1', 45, 155.00, 98.00, 15],
        ['Cetirizine 10', 'Cetirizine 10mg', 'Cipla', 'Tablet', 'OTC', 12, 10, 'Tab', 'A-3', 30, 18.50, 9.20, 30],
        ['Benadryl Cough Syrup', 'Diphenhydramine', 'J&J', 'Syrup', 'OTC', 12, 1, 'Btl', 'C-1', 5, 138.00, 101.00, 10],
        ['Glycomet GP 1', 'Metformin 500mg + Glimepiride 1mg', 'USV', 'Tablet', 'H', 12, 15, 'Tab', 'B-4', 45, 98.00, 68.00, 18],
        ['Telma 40', 'Telmisartan 40mg', 'Glenmark', 'Tablet', 'H', 12, 15, 'Tab', 'B-4', 45, 221.00, 150.00, 10],
        ['ORS Electral', 'Oral Rehydration Salts', 'FDC', 'Powder', 'OTC', 5, 1, 'Sachet', 'C-3', 20, 21.00, 15.50, 50],
        ['Volini Gel 30g', 'Diclofenac Gel', 'Sun Pharma', 'Gel', 'OTC', 18, 1, 'Tube', 'C-2', 5, 145.00, 104.00, 8],
        ['Shelcal 500', 'Calcium + Vitamin D3', 'Torrent', 'Tablet', 'OTC', 12, 15, 'Tab', 'A-4', 30, 122.00, 80.00, 3],
        ['Digene Gel 200ml', 'Antacid', 'Abbott', 'Suspension', 'OTC', 12, 1, 'Btl', 'C-1', 4, 185.00, 133.00, 6],
        ['Montair LC', 'Montelukast 10mg + Levocetirizine 5mg', 'Cipla', 'Tablet', 'H', 12, 10, 'Tab', 'B-3', 20, 245.00, 170.00, 1],
        ['Accu-Chek Active Strips 50', 'Glucometer strips', 'Roche', 'Device', 'OTC', 12, 1, 'Box', 'D-1', 2, 1120.00, 870.00, 4],
    ];

    $pdo = db();
    $pdo->beginTransaction();
    q('INSERT INTO purchases (supplier_id, invoice_no, invoice_date, total, created_by) VALUES (?, ?, CURDATE() - INTERVAL 20 DAY, 0, ?)', [$sup[0], 'SPD/2026/4412', $adminId]);
    $pid = (int)$pdo->lastInsertId();
    $total = $gstTotal = 0;
    foreach ($meds as $k => $m) {
        [$name, $gen, $mk, $cat, $sch, $gst, $pack, $unit, $rack, $reorder, $mrp, $rate, $packs] = $m;
        q('INSERT INTO medicines (name, generic_name, manufacturer, category, schedule, hsn, gst_rate, pack_size, unit_label, rack, reorder_level)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$name, $gen, $mk, $cat, $sch, $cat === 'Device' ? '9027' : '3004', $gst, $pack, $unit, $rack, $reorder]);
        $mid = (int)$pdo->lastInsertId();
        // Mix of expiry dates so the expiry alerts have something to show.
        $exp = match (true) {
            $k === 6 => date('Y-m-t', strtotime('-1 month')),
            $k % 5 === 2 => date('Y-m-t', strtotime('+2 months')),
            default => date('Y-m-t', strtotime('+' . (10 + $k) . ' months')),
        };
        $units = $packs * $pack;
        q('INSERT INTO batches (medicine_id, purchase_id, batch_no, expiry_date, mrp, purchase_rate, disc_pct, gst_rate, cost_rate, packs, free_packs, qty_in, qty_available)
           VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, ?, ?)', [$mid, $pid, strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3)) . (2400 + $k * 7), $exp, $mrp, $rate, $gst, $rate, $packs, $units, $units]);
        $total += $packs * $rate;
        $gstTotal += round($packs * $rate * $gst / 100, 2);
    }
    $grand = round($total + $gstTotal);
    q('UPDATE purchases SET taxable = ?, gst_amount = ?, round_off = ?, total = ? WHERE id = ?',
        [round($total, 2), round($gstTotal, 2), round($grand - $total - $gstTotal, 2), $grand, $pid]);
    q("INSERT INTO supplier_payments (supplier_id, pay_date, amount, mode, reference, created_by) VALUES (?, CURDATE() - INTERVAL 10 DAY, 5000, 'UPI', 'UTR 4521889012', ?)", [$sup[0], $adminId]);
    q("UPDATE suppliers SET opening_balance = 2350 WHERE id = ?", [$sup[1]]);
    $pdo->commit();

    // Demo bills over the last 14 days.
    $names = ['Ramesh Patel', 'Sunita Sharma', '', 'Amit Joshi', '', 'Pooja Yadav', 'Mohd. Arif', '', 'Kavita Rathore', 'Deepak Soni'];
    $modes = ['Cash', 'Cash', 'UPI', 'UPI', 'Card', 'Cash', 'Credit'];
    mt_srand(42);
    for ($day = 13; $day >= 0; $day--) {
        for ($b = 0, $count = mt_rand(2, 6); $b < $count; $b++) {
            $pdo->beginTransaction();
            $lines = [];
            $rx = false;
            for ($j = 0, $ni = mt_rand(1, 3); $j < $ni; $j++) {
                $row = q('SELECT b.*, m.pack_size, m.gst_rate, m.schedule FROM batches b JOIN medicines m ON m.id = b.medicine_id
                    WHERE b.qty_available > 30 AND b.expiry_date >= CURDATE() ORDER BY RAND() LIMIT 1')->fetch();
                if (!$row || isset($lines[$row['id']])) continue;
                $qty = $row['pack_size'] > 1 ? mt_rand(1, 2) * (mt_rand(0, 1) ? (int)$row['pack_size'] : 5) : mt_rand(1, 2);
                $unit = $row['mrp'] / $row['pack_size'];
                $gross = round($unit * $qty, 2);
                $lines[$row['id']] = [$row, $qty, $unit, $gross];
                $rx = $rx || $row['schedule'] !== 'OTC';
            }
            if (!$lines) { $pdo->rollBack(); continue; }
            $disc = [0, 0, 5, 10][mt_rand(0, 3)];
            $mode = $modes[mt_rand(0, count($modes) - 1)];
            $cust = $names[mt_rand(0, count($names) - 1)];
            if ($mode === 'Credit' && $cust === '') $cust = 'Ramesh Patel';
            $grossT = $netT = $taxT = $gstT = 0;
            $calc = [];
            foreach ($lines as [$row, $qty, $unit, $gross]) {
                $amt = round($gross * (1 - $disc / 100), 2);
                $tax = round($amt / (1 + $row['gst_rate'] / 100), 2);
                $calc[] = [$row, $qty, $unit, $gross, $amt, $tax];
                $grossT += $gross; $netT += $amt; $taxT += $tax; $gstT += $amt - $tax;
            }
            $total = round($netT);
            $when = date('Y-m-d H:i:s', strtotime("-$day day") - ($day === 0 ? mt_rand(0, 3) * 3600 : 0));
            if ($day > 0) $when = date('Y-m-d', strtotime("-$day day")) . sprintf(' %02d:%02d:00', mt_rand(9, 21), mt_rand(0, 59));
            q('INSERT INTO sales (bill_date, customer_name, customer_phone, doctor_name, gross, discount_pct, discount, taxable, gst_amount, round_off, total, payment_mode, created_by)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$when, $cust, $cust ? '98' . mt_rand(10000000, 99999999) : '', $rx ? 'Dr. S. K. Mehta' : '',
                round($grossT, 2), $disc, round($grossT - $netT, 2), round($taxT, 2), round($gstT, 2), round($total - $netT, 2), $total, $mode, $adminId]);
            $sid = (int)$pdo->lastInsertId();
            q('UPDATE sales SET bill_no = ? WHERE id = ?', ['CC-' . str_pad((string)$sid, 6, '0', STR_PAD_LEFT), $sid]);
            foreach ($calc as [$row, $qty, $unit, $gross, $amt, $tax]) {
                q('INSERT INTO sale_items (sale_id, medicine_id, batch_id, qty, unit_price, unit_cost, gross, amount, gst_rate, taxable, gst_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$sid, $row['medicine_id'], $row['id'], $qty, round($unit, 4), round($row['purchase_rate'] / $row['pack_size'], 4), $gross, $amt, $row['gst_rate'], $tax, round($amt - $tax, 2)]);
                q('UPDATE batches SET qty_available = qty_available - ? WHERE id = ?', [$qty, $row['id']]);
            }
            $pdo->commit();
        }
    }
    return "Demo data loaded. Sign in with admin / admin12345 (or staff / staff12345).";
}

/**
 * Wipes every table and seeds fresh demo data. Only allowed in demo_mode.
 * A Gemini key saved in Settings survives the reset so AI scanning keeps working.
 */
function demo_reset(): string
{
    if (config('demo_mode') !== true) {
        throw new RuntimeException('Demo reset is only allowed when demo_mode is true.');
    }
    apply_schema();
    $keep = q("SELECT skey, svalue FROM settings WHERE skey = 'gemini_api_key_enc'")->fetchAll();
    $files = q("SELECT bill_file FROM purchases WHERE bill_file <> ''")->fetchAll(PDO::FETCH_COLUMN);
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['sale_return_items', 'sale_returns', 'sale_items', 'sales', 'customer_payments', 'customers', 'shortage_book', 'supplier_return_items', 'supplier_returns', 'stock_adjustments', 'supplier_payments', 'batches', 'purchases', 'medicines', 'suppliers', 'settings', 'login_attempts', 'users'] as $t) {
        db()->exec("TRUNCATE TABLE $t");
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach ($keep as $k) {
        q('INSERT INTO settings (skey, svalue) VALUES (?, ?)', [$k['skey'], $k['svalue']]);
    }
    foreach ($files as $f) {
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $f) && is_file(APP_ROOT . '/storage/bills/' . $f)) {
            unlink(APP_ROOT . '/storage/bills/' . $f);
        }
    }
    return demo_seed();
}
<?php
declare(strict_types=1);

// Supplier-bill scanning with Google Gemini, plus matching the result to our suppliers and medicines.

const BILL_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
const BILL_MAX_BYTES = 10 * 1024 * 1024;

function bills_dir(): string
{
    $dir = APP_ROOT . '/storage/bills';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

/** Stored bill files are named <32 hex>.<ext>; anything else is rejected. */
function bill_file_path(string $name): ?string
{
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $name)) {
        return null;
    }
    $path = bills_dir() . '/' . $name;
    return is_file($path) ? $path : null;
}

function secret_key(): string
{
    return hash('sha256', (string)config('app_key') . '|settings-secret', true);
}

function secret_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

function secret_decrypt(string $stored): string
{
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

/** Key from config (preferred on servers) or the encrypted one saved in Settings. */
function gemini_api_key(): string
{
    $fromConfig = (string)config('gemini_api_key', '');
    if ($fromConfig !== '') {
        return $fromConfig;
    }
    $enc = setting('gemini_api_key_enc');
    return $enc === '' ? '' : secret_decrypt($enc);
}

const GEMINI_DEFAULT_MODEL = 'gemini-3.5-flash';
// Used when the main model is busy (503/429) or not available to the key (404).
const GEMINI_FALLBACK_MODEL = 'gemini-3.5-flash-lite';

function gemini_model(): string
{
    $m = setting('gemini_model', GEMINI_DEFAULT_MODEL);
    // gemini-2.5-flash no longer serves new keys; move old saved settings to the current default.
    if ($m === 'gemini-2.5-flash') $m = GEMINI_DEFAULT_MODEL;
    return preg_match('/^[a-z0-9.\-]{3,60}$/', $m) ? $m : GEMINI_DEFAULT_MODEL;
}

/** POSTs JSON to a Gemini model. Returns [http status, decoded body or null, curl error]. */
function gemini_post(string $model, array $payload, string $key): array
{
    $base = rtrim((string)config('gemini_api_base', 'https://generativelanguage.googleapis.com'), '/');
    $ch = curl_init($base . '/v1beta/models/' . rawurlencode($model) . ':generateContent');
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
    ];
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA; // local Windows PHP has no CA bundle
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$status, $resp === false ? null : json_decode($resp, true), $err];
}

function gemini_generation_config(string $model, ?array $schema): array
{
    $cfg = ['temperature' => 0];
    if ($schema) {
        $cfg['responseMimeType'] = 'application/json';
        $cfg['responseSchema'] = $schema;
    }
    // Low thinking keeps a bill scan around 10-20 s instead of a minute; reading needs little reasoning.
    if (preg_match('/^gemini-[3-9]/', $model) && !str_contains($model, 'lite')) {
        $cfg['thinkingConfig'] = ['thinkingLevel' => 'low'];
    }
    return $cfg;
}

function gemini_bill_schema(): array
{
    $str = ['type' => 'STRING', 'nullable' => true];
    $num = ['type' => 'NUMBER', 'nullable' => true];
    return [
        'type' => 'OBJECT',
        'properties' => [
            'supplier' => ['type' => 'OBJECT', 'properties' => [
                'name' => $str, 'gstin' => $str, 'dl_no' => $str, 'phone' => $str, 'address' => $str,
            ]],
            'invoice_no' => $str,
            'invoice_date' => $str,
            'items' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                'name' => $str, 'pack' => $str, 'hsn' => $str, 'batch_no' => $str, 'expiry' => $str,
                'qty' => $num, 'free_qty' => $num, 'mrp' => $num, 'rate' => $num,
                'discount_pct' => $num, 'gst_rate' => $num, 'amount' => $num,
            ], 'required' => ['name']]],
            'taxable_total' => $num,
            'gst_total' => $num,
            'grand_total' => $num,
        ],
        'required' => ['items'],
    ];
}

const GEMINI_BILL_PROMPT = <<<'TXT'
You are reading an Indian pharmaceutical wholesale (distributor) purchase invoice / bill.
Extract the values EXACTLY as printed. Do not guess or calculate values that are not printed; use null instead.
- supplier: the SELLER / distributor who issued the bill (not the buyer/retailer). gstin is the seller's 15-character GSTIN. dl_no is the seller's drug licence number(s).
- invoice_no: the bill / invoice number. invoice_date: the bill date as YYYY-MM-DD.
- items: one entry per product line, in the printed order. Skip subtotal/tax/summary rows.
  - name: product name as printed, including strength (e.g. "DOLO 650 TAB").
  - pack: pack/packing as printed (e.g. "15'S", "1x10", "10 TAB", "100ML").
  - batch_no: batch number. expiry: expiry as MM/YY.
  - qty: billed quantity (number of packs/strips). free_qty: free / scheme quantity, 0 if none.
  - mrp: MRP per pack. rate: purchase rate per pack BEFORE discount and BEFORE GST (the "Rate"/"PTR" column).
  - discount_pct: line discount percent, 0 if none. gst_rate: total GST percent for the line (CGST% + SGST%, or IGST%).
  - amount: the line amount as printed.
- taxable_total, gst_total, grand_total: invoice totals as printed (grand_total = final net amount payable).
Numbers must be plain numbers without currency symbols or commas.
TXT;

/**
 * Sends the bill to Gemini and returns the decoded extraction.
 * @throws RuntimeException with a message safe to show the user.
 */
function gemini_read_bill(string $path, string $mime): array
{
    $key = gemini_api_key();
    if ($key === '') {
        throw new RuntimeException('AI scanning is not set up. The admin must add a Gemini API key in Settings.');
    }
    $contents = [['parts' => [
        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode(file_get_contents($path))]],
        ['text' => GEMINI_BILL_PROMPT],
    ]]];
    $models = array_values(array_unique([gemini_model(), GEMINI_FALLBACK_MODEL]));
    foreach ($models as $model) {
        [$status, $json, $err] = gemini_post($model, ['contents' => $contents, 'generationConfig' => gemini_generation_config($model, gemini_bill_schema())], $key);
        if ($status === 200 || !in_array($status, [404, 429, 500, 503], true)) break;
    }
    if ($status === 0) {
        throw new RuntimeException('Could not reach Google Gemini: ' . $err);
    }
    if ($status !== 200) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $status);
        if (in_array($status, [400, 401, 403], true) && stripos($msg, 'key') !== false) $msg = 'The Gemini API key is not valid. Check it in Settings.';
        if ($status === 429) $msg = 'Gemini usage limit reached. Wait a minute and try again.';
        if ($status === 503) $msg = 'Google Gemini is busy right now. Please try again in a minute.';
        throw new RuntimeException('AI scan failed: ' . $msg);
    }
    $text = '';
    foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (empty($part['thought'])) $text .= $part['text'] ?? '';
    }
    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException('The AI could not read this bill. Try a clearer, straight photo of the full bill.');
    }
    return $data;
}

function norm_name(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/\b(tab|tabs|tablet|tablets|cap|caps|capsule|capsules|syp|syrup|susp|inj|injection|oint|cream|gel|drops?)\b/', ' ', $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

/** "15'S" / "1x10" / "10 TAB" -> 15 / 10 / 10. Liquids, tubes and unknowns -> 1. */
function parse_pack_size(?string $pack): int
{
    $p = strtolower((string)$pack);
    if ($p === '' || preg_match('/\d\s*(ml|gm|g|kg|ltr|l)\b/', $p)) return 1;
    if (preg_match('/(\d+)\s*[x\*]\s*(\d+)/', $p, $m)) return max(1, (int)$m[1] * (int)$m[2]);
    if (preg_match('/(\d+)/', $p, $m)) return max(1, min(1000, (int)$m[1]));
    return 1;
}

/** "08/27", "8/2027", "AUG-27", "2027-08" -> "2027-08" (or '' when unreadable). */
function parse_expiry(?string $exp): string
{
    $e = strtoupper(trim((string)$exp));
    $months = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
    if (preg_match('/^(\d{4})-(\d{1,2})/', $e, $m)) { $y = (int)$m[1]; $mo = (int)$m[2]; }
    elseif (preg_match('/^(\d{1,2})\s*[\/\-.]\s*(\d{2,4})$/', $e, $m)) { $mo = (int)$m[1]; $y = (int)$m[2]; }
    elseif (preg_match('/^([A-Z]{3})[A-Z]*\s*[\/\-.\' ]?\s*(\d{2,4})$/', $e, $m) && isset($months[$m[1]])) { $mo = $months[$m[1]]; $y = (int)$m[2]; }
    else return '';
    if ($y < 100) $y += 2000;
    return ($mo >= 1 && $mo <= 12 && $y >= 2000 && $y <= 2100) ? sprintf('%04d-%02d', $y, $mo) : '';
}

function guess_category(string $name, int $pack): array
{
    $n = strtoupper($name);
    foreach ([['SYP|SYRUP', 'Syrup', 'Btl'], ['SUSP', 'Suspension', 'Btl'], ['INJ', 'Injection', 'Vial'], ['DROP', 'Drops', 'Btl'],
                 ['OINT', 'Ointment', 'Tube'], ['CREAM', 'Cream', 'Tube'], ['GEL', 'Gel', 'Tube'], ['CAP', 'Capsule', 'Cap'], ['POWDER|SACHET', 'Powder', 'Sachet']] as [$re, $cat, $unit]) {
        if (preg_match('/\b(' . $re . ')/', $n)) return [$cat, $unit];
    }
    return $pack > 1 ? ['Tablet', 'Tab'] : ['Other', 'Unit'];
}

function match_supplier(array $s): ?array
{
    $gstin = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($s['gstin'] ?? '')));
    if (strlen($gstin) === 15) {
        $row = q("SELECT id, name FROM suppliers WHERE REPLACE(UPPER(gstin), ' ', '') = ?", [$gstin])->fetch();
        if ($row) return $row;
    }
    // Names like "X Pharma Distributors" share most letters, so compare only the distinctive part.
    $core = fn(string $n) => preg_replace('/[^a-z0-9]/', '', preg_replace(
        '/\b(m\/?s|the|pharma|pharmaceuticals?|pharmacy|medicos?|medical|medicines?|drugs?|distributors?|distribution|agency|agencies|enterprises?|traders?|trading|healthcare|health|care|pvt|private|ltd|limited|llp|co|company|and|&)\b/',
        ' ', strtolower($n)));
    $name = $core((string)($s['name'] ?? ''));
    if (strlen($name) < 3) return null;
    $best = null;
    $bestPct = 0;
    foreach (q('SELECT id, name, gstin FROM suppliers')->fetchAll() as $row) {
        $rowGstin = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$row['gstin']));
        // Both GSTINs known and different: a different firm, however similar the name.
        if (strlen($gstin) === 15 && strlen($rowGstin) === 15 && $rowGstin !== $gstin) continue;
        similar_text($name, $core($row['name']), $pct);
        if ($pct > $bestPct) { $bestPct = $pct; $best = $row; }
    }
    return $bestPct >= 85 ? ['id' => $best['id'], 'name' => $best['name']] : null;
}

function match_medicine(string $name, array $all): ?array
{
    $n = norm_name($name);
    if ($n === '') return null;
    $best = null;
    $bestPct = 0;
    foreach ($all as $m) {
        $mn = $m['_norm'];
        if ($mn === $n) return $m;
        similar_text($n, $mn, $pct);
        // Brand names on bills often carry extra pack text; reward a full containment match.
        if (strlen($mn) >= 4 && (str_starts_with($n, $mn) || str_starts_with($mn, $n))) $pct = max($pct, 90);
        if ($pct > $bestPct) { $bestPct = $pct; $best = $m; }
    }
    return $bestPct >= 78 ? $best : null;
}

/** Turns Gemini's extraction into rows the purchase form can load directly. */
function build_purchase_prefill(array $data): array
{
    $meds = q('SELECT id, name, pack_size, unit_label, gst_rate FROM medicines WHERE active = 1')->fetchAll();
    foreach ($meds as &$m) {
        $m['_norm'] = norm_name($m['name']);
    }
    unset($m);
    $num = fn($v) => is_numeric($v) ? (float)$v : 0.0;

    $sup = is_array($data['supplier'] ?? null) ? $data['supplier'] : [];
    $supMatch = match_supplier($sup);
    $date = (string)($data['invoice_date'] ?? '');
    $items = [];
    $warnings = [];
    foreach ($data['items'] as $n => $it) {
        if (!is_array($it) || trim((string)($it['name'] ?? '')) === '') continue;
        $name = trim((string)$it['name']);
        $match = match_medicine($name, $meds);
        $pack = parse_pack_size($it['pack'] ?? null);
        [$cat, $unit] = guess_category($name, $pack);
        $row = [
            'scanned_name' => $name,
            'medicine' => $match ? ['id' => (int)$match['id'], 'name' => $match['name'], 'pack_size' => (int)$match['pack_size'], 'unit_label' => $match['unit_label']] : null,
            'new_medicine' => $match ? null : ['name' => mb_substr($name, 0, 150), 'pack_size' => $pack, 'unit_label' => $unit, 'category' => $cat,
                'hsn' => preg_replace('/\D/', '', (string)($it['hsn'] ?? '')) ?: '3004', 'gst_rate' => $num($it['gst_rate'] ?? 12)],
            'batch_no' => mb_substr(trim((string)($it['batch_no'] ?? '')), 0, 40),
            'expiry' => parse_expiry($it['expiry'] ?? null),
            'packs' => (int)round($num($it['qty'] ?? 0)),
            'free_packs' => (int)round($num($it['free_qty'] ?? 0)),
            'rate' => round($num($it['rate'] ?? 0), 2),
            'mrp' => round($num($it['mrp'] ?? 0), 2),
            'disc_pct' => round($num($it['discount_pct'] ?? 0), 2),
            'gst_rate' => in_array((float)$num($it['gst_rate'] ?? -1), array_map('floatval', GST_RATES), true) ? $num($it['gst_rate']) : ($match ? (float)$match['gst_rate'] : 12),
            'scanned_amount' => $num($it['amount'] ?? 0),
        ];
        $label = 'Row ' . (count($items) + 1) . " ($name)";
        if ($row['batch_no'] === '') $warnings[] = "$label: batch number not readable.";
        if ($row['expiry'] === '') $warnings[] = "$label: expiry not readable.";
        if ($row['mrp'] <= 0) $warnings[] = "$label: MRP not readable.";
        if ($row['rate'] > $row['mrp'] && $row['mrp'] > 0) $warnings[] = "$label: rate is higher than MRP, please check.";
        $items[] = $row;
    }
    if (!$items) {
        throw new RuntimeException('No medicine lines were found on this bill.');
    }
    return [
        'supplier' => [
            'id' => $supMatch ? (int)$supMatch['id'] : null,
            'matched_name' => $supMatch['name'] ?? null,
            'name' => mb_substr(trim((string)($sup['name'] ?? '')), 0, 150),
            'gstin' => mb_substr(strtoupper(trim((string)($sup['gstin'] ?? ''))), 0, 20),
            'dl_no' => mb_substr(trim((string)($sup['dl_no'] ?? '')), 0, 60),
            'phone' => mb_substr(trim((string)($sup['phone'] ?? '')), 0, 20),
            'address' => mb_substr(trim((string)($sup['address'] ?? '')), 0, 255),
        ],
        'invoice_no' => mb_substr(trim((string)($data['invoice_no'] ?? '')), 0, 50),
        'invoice_date' => valid_date($date) ? $date : '',
        'items' => $items,
        'scanned_total' => is_numeric($data['grand_total'] ?? null) ? round((float)$data['grand_total'], 2) : null,
        'warnings' => $warnings,
    ];
}

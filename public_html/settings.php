<?php
require __DIR__ . '/boot.php';
require APP_ROOT . '/app/ai_ocr.php';
require_admin();

$keys = [
    'store_name' => ['Store name', 100], 'phone' => ['Phone', 40], 'gstin' => ['GSTIN', 20], 'dl_no' => ['Drug licence no(s).', 120],
    'address' => ['Address (shown on bill)', 255], 'bill_prefix' => ['Bill number prefix', 8],
    'store_upi_id' => ['Store UPI ID (VPA for QR payment on bills)', 50],
    'default_invoice_format' => ['Default Print Format', 10],
    'expiry_alert_days' => ['Expiry alert (days before)', 4], 'max_discount_pct' => ['Maximum discount % on a bill', 5],
    'invoice_footer' => ['Bill footer note', 255],
];
$save = fn(string $k, string $v) => q('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$k, $v]);

if (is_post() && post('action') === 'reset_demo' && config('demo_mode') === true) {
    csrf_check();
    require APP_ROOT . '/app/demo.php';
    demo_reset();
    $_SESSION = [];
    session_regenerate_id(true);
    redirect('login.php?demo=admin');
}

if (is_post() && post('action') === 'ai') {
    csrf_check();
    $key = post('gemini_api_key');
    if (post('remove_key') === '1') {
        $save('gemini_api_key_enc', '');
        flash('Gemini API key removed. AI bill scanning is off.');
    } elseif ($key !== '') {
        if (!preg_match('/^[A-Za-z0-9_.\-]{20,200}$/', $key)) {
            flash('That does not look like a Gemini API key.', 'err');
            redirect('settings.php#ai');
        }
        $save('gemini_api_key_enc', secret_encrypt($key));
    }
    $model = post('gemini_model');
    if (preg_match('/^[a-z0-9.\-]{3,60}$/', $model)) $save('gemini_model', $model);

    if (post('remove_key') !== '1') {
        // A tiny real request, so a typo in the key or model is caught here and not at the counter.
        [$code] = gemini_api_key() === '' ? [0] : gemini_post(gemini_model(), ['contents' => [['parts' => [['text' => 'Reply with: OK']]]],
            'generationConfig' => gemini_generation_config(gemini_model(), null)], gemini_api_key());
        if (gemini_api_key() === '') flash('Add a Gemini API key to turn on bill scanning.', 'err');
        elseif ($code === 200) flash('AI settings saved. Gemini key works — bill scanning is ON.');
        elseif ($code === 404) flash('Key saved, but this model is not available to the key. Try ' . GEMINI_DEFAULT_MODEL . '.', 'err');
        elseif ($code === 503 || $code === 429) flash('Key saved. Gemini is busy right now (' . $code . '); scanning will retry with a lighter model.', 'err');
        else flash('Key saved, but Gemini rejected it (' . ($code ?: 'no connection') . '). Check the key.', 'err');
    }
    redirect('settings.php#ai');
}

if (is_post()) {
    csrf_check();
    foreach ($keys as $k => [, $max]) {
        $v = mb_substr(post($k), 0, $max);
        if ($k === 'expiry_alert_days') $v = (string)max(1, min(365, (int)$v));
        if ($k === 'max_discount_pct') $v = (string)max(0, min(100, (float)$v));
        if ($k === 'bill_prefix') $v = preg_replace('/[^A-Za-z0-9]/', '', $v) ?: 'INV';
        if ($k === 'default_invoice_format' && !in_array($v, ['a4', 'thermal'], true)) $v = 'a4';
        $save($k, $v);
    }
    flash('Settings saved.');
    redirect('settings.php');
}

$defaults = ['bill_prefix' => 'INV', 'expiry_alert_days' => '90', 'max_discount_pct' => '20', 'default_invoice_format' => 'a4', 'store_upi_id' => ''];
$currentKey = gemini_api_key();
$fromConfig = (string)config('gemini_api_key', '') !== '';
layout_header('Settings');
?>
<form method="post" class="card form-grid">
  <h2 class="span-4">Store details</h2>
  <?= csrf_field() ?>
  <?php foreach ($keys as $k => [$label, $max]): $wide = in_array($k, ['address', 'invoice_footer', 'store_name', 'dl_no'], true); ?>
    <label class="<?= $wide ? 'span-2' : '' ?>"><?= e($label) ?>
      <?php if ($k === 'address'): ?>
        <textarea name="address" rows="2" maxlength="255"><?= e(setting($k)) ?></textarea>
      <?php elseif ($k === 'default_invoice_format'): ?>
        <select name="default_invoice_format">
          <option value="a4" <?= setting($k, 'a4') === 'a4' ? 'selected' : '' ?>>A4 Full Tax Invoice</option>
          <option value="thermal" <?= setting($k, 'a4') === 'thermal' ? 'selected' : '' ?>>80mm POS Thermal Slip</option>
        </select>
      <?php else: ?>
        <input name="<?= $k ?>" maxlength="<?= $max ?>" value="<?= e(setting($k, $defaults[$k] ?? '')) ?>" <?= $k === 'store_upi_id' ? 'placeholder="e.g. mobile@upi or store@bank"' : '' ?>>
      <?php endif; ?>
    </label>
  <?php endforeach; ?>
  <div class="span-4 form-actions"><button class="btn btn-primary">Save settings</button></div>
</form>

<form method="post" class="card form-grid" id="ai">
  <div class="span-4">
    <h2>AI bill scanning (Google Gemini)</h2>
    <p class="muted">Reads supplier bills on the Purchases screen. Get a free key at <strong>aistudio.google.com → Get API key</strong>. Bill photos are sent to Google to be read.</p>
    <p>Status: <?= $currentKey !== '' ? '<span class="tag tag-ok">ON</span> key ending <code>…' . e(substr($currentKey, -4)) . '</code>' . ($fromConfig ? ' (from config file)' : '') : '<span class="tag tag-danger">OFF</span> no key added' ?></p>
  </div>
  <?= csrf_field() ?><input type="hidden" name="action" value="ai">
  <label class="span-2">Gemini API key<input type="password" name="gemini_api_key" autocomplete="off" placeholder="<?= $currentKey !== '' ? 'Leave blank to keep the current key' : 'Paste key (starts with AIza…)' ?>" <?= $fromConfig ? 'disabled' : '' ?>></label>
  <label>Model<input name="gemini_model" value="<?= e(gemini_model()) ?>"><small>Default: <?= GEMINI_DEFAULT_MODEL ?> (falls back to <?= GEMINI_FALLBACK_MODEL ?> when busy)</small></label>
  <label class="check"><input type="checkbox" name="remove_key" value="1" <?= $currentKey === '' || $fromConfig ? 'disabled' : '' ?>> Remove saved key</label>
  <div class="span-4 form-actions"><button class="btn btn-primary">Save &amp; test key</button></div>
</form>

<?php if (config('demo_mode') === true): ?>
<form method="post" class="card" data-confirm="Delete all bills, stock, medicines and suppliers and reload fresh sample data?">
  <?= csrf_field() ?><input type="hidden" name="action" value="reset_demo">
  <h2>Demo data</h2>
  <p class="muted">This is a public demo. Reset brings back the original sample store (it also resets every night). The Gemini key is kept.</p>
  <button class="btn btn-danger">Reset demo data</button>
</form>
<?php endif; ?>

<?php layout_footer(); ?>

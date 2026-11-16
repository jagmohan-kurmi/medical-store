<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
        static $checkedSchema = false;
        if (!$checkedSchema) {
            $checkedSchema = true;
            try {
                $has = $pdo->query("SHOW TABLES LIKE 'customers'")->fetchColumn();
                if (!$has) {
                    require_once APP_ROOT . '/app/demo.php';
                    apply_schema();
                }
            } catch (Throwable $t) {
                // Ignore schema check error
            }
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($v): string
{
    return '₹' . number_format((float)$v, 2);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null, string $type = 'ok')
{
    if ($msg !== null) {
        $_SESSION['flash'][] = [$type, $msg];
        return null;
    }
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Form expired. Please go back, refresh the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = q('SELECT id, name, username, role FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']])->fetch() ?: null;
        }
    }
    return $u;
}

function require_login(): array
{
    $u = user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        layout_header('Owner access only');
        echo '<div class="card"><p>This page is only for the store owner (admin). Counter staff can make bills, add medicines and enter purchases.</p>';
        echo config('demo_mode') === true
            ? '<a class="btn btn-primary" href="login.php?demo=admin">Switch to Owner</a>'
            : '<a class="btn" href="index.php">Back to dashboard</a>';
        echo '</div>';
        layout_footer();
        exit;
    }
    return $u;
}

function setting(string $key, string $default = ''): string
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (q('SELECT skey, svalue FROM settings')->fetchAll() as $r) {
            $all[$r['skey']] = $r['svalue'];
        }
    }
    return $all[$key] ?? $default;
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function fmt_date(?string $d): string
{
    return $d ? date('d-m-Y', strtotime($d)) : '';
}

function fmt_expiry(string $d): string
{
    return date('m/y', strtotime($d));
}

/** Converts stock units to a "3 strips + 4 tab" style label. */
function units_label(int $units, int $packSize, string $unit): string
{
    if ($packSize <= 1) {
        return $units . ' ' . $unit;
    }
    $packs = intdiv($units, $packSize);
    $loose = $units % $packSize;
    $s = $packs . ' pk';
    if ($loose) {
        $s .= ' + ' . $loose . ' ' . $unit;
    }
    return $s;
}

function amount_in_words(float $amount): string
{
    $rupees = (int)floor($amount);
    $paise = (int)round(($amount - $rupees) * 100);
    $w = $rupees === 0 ? 'Zero' : number_words_in($rupees);
    $out = 'Rupees ' . $w;
    if ($paise > 0) {
        $out .= ' and ' . number_words_in($paise) . ' Paise';
    }
    return $out . ' Only';
}

function number_words_in(int $n): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $x) use ($ones, $tens): string {
        if ($x < 20) {
            return $ones[$x];
        }
        return trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
    };
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$div, $label]) {
        if ($n >= $div) {
            $chunk = intdiv($n, $div);
            $parts[] = ($chunk >= 100 ? number_words_in($chunk) : $two($chunk)) . ' ' . $label;
            $n %= $div;
        }
    }
    if ($n > 0) {
        $parts[] = $two($n);
    }
    return implode(' ', $parts);
}

function client_ip_hash(): string
{
    return hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', (string)config('app_key'));
}

const MED_CATEGORIES = ['Tablet', 'Capsule', 'Syrup', 'Suspension', 'Injection', 'Drops', 'Ointment', 'Cream', 'Gel',
    'Powder', 'Inhaler', 'Spray', 'Soap', 'Device', 'Surgical', 'Ayurvedic', 'FMCG', 'Other'];
const GST_RATES = [0, 5, 12, 18, 28];
const PAYMENT_MODES = ['Cash', 'UPI', 'Card', 'Credit'];

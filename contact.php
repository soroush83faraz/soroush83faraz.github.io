<?php
/**
 * Self-hosted contact endpoint for the static FMG-Tech site.
 *
 * Receives the contact form POST (FormData from src/components/ContactForm.tsx), stores the
 * submission in MySQL, and emails the team via the host's mail server. No framework, no Django.
 *
 * This file lives in public/ and is copied into the static export (out/contact.php); Apache/PHP
 * on Plesk executes it — Next.js does not. Configure DB credentials and the recipient either via
 * environment variables (preferred) or a contact.config.php placed ONE LEVEL ABOVE the web root
 * (so a rebuild of out/ never overwrites it and it never lands in git). See
 * contact.config.example.php and db/schema.sql.
 */

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, string $status, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Config: env vars first, then an out-of-webroot contact.config.php fallback ----
$config = [
    'db_host'  => getenv('CONTACT_DB_HOST') ?: 'localhost',
    'db_name'  => getenv('CONTACT_DB_NAME') ?: '',
    'db_user'  => getenv('CONTACT_DB_USER') ?: '',
    'db_pass'  => getenv('CONTACT_DB_PASS') ?: '',
    'to_email' => getenv('CONTACT_TO_EMAIL') ?: 'info@fmg-tech.ir',
    'from_email' => getenv('CONTACT_FROM_EMAIL') ?: '',
];
$configFile = __DIR__ . '/../contact.config.php';
if (is_readable($configFile)) {
    $fileConfig = require $configFile;
    if (is_array($fileConfig)) {
        $config = array_merge($config, array_filter($fileConfig, fn($v) => $v !== null && $v !== ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, 'error', 'متد نامعتبر است.');
}

// Honeypot: real users never fill this hidden field; bots do. Pretend success to not tip them off.
if (!empty($_POST['botcheck'])) {
    respond(200, 'success', 'پیام شما با موفقیت ارسال شد.');
}

// ---- Collect + validate (mirrors the Django ContactSubmission rules) ----
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');
$product = trim($_POST['related_product_slug'] ?? '');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 10) {
    respond(422, 'error', 'لطفاً نام، ایمیل و پیام (حداقل ۱۰ کاراکتر) را کامل کنید.');
}
if (mb_strlen($message) > 5000) {
    respond(422, 'error', 'پیام بیش از حد طولانی است.');
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);

// ---- Store in MySQL (prepared statement) ----
$stored = false;
try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Rate limit: max 5 submissions per IP per hour (mirrors the old CONTACT_RATE_LIMIT).
    $rl = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM contact_submissions WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
    );
    $rl->execute([$ip]);
    if ((int) $rl->fetch()['c'] >= 5) {
        respond(429, 'error', 'تعداد درخواست‌ها زیاد است. لطفاً کمی بعد دوباره تلاش کنید.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO contact_submissions
            (name, email, phone, subject, message, related_product_slug, ip_address, user_agent, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$name, $email, $phone, $subject, $message, $product, $ip, $ua, 'new']);
    $stored = true;
} catch (Throwable $e) {
    // Don't leak DB internals; the email below is the primary delivery path.
    error_log('contact.php DB error: ' . $e->getMessage());
}

// ---- Email the team via the host mail server ----
$to = $config['to_email'];
$mailSubject = '=?UTF-8?B?' . base64_encode('پیام جدید از سایت' . ($subject ? " — {$subject}" : '')) . '?=';
$bodyLines = [
    "نام: {$name}",
    "ایمیل: {$email}",
    $phone   ? "تلفن: {$phone}" : null,
    $subject ? "موضوع: {$subject}" : null,
    $product ? "محصول مرتبط: {$product}" : null,
    '',
    'پیام:',
    $message,
];
$body = implode("\n", array_filter($bodyLines, fn($l) => $l !== null));

$fromEmail = $config['from_email'] ?: ('no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'fmg-tech.ir'));
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    "From: FMG-Tech <{$fromEmail}>",
    "Reply-To: {$name} <{$email}>",
];
$sent = @mail($to, $mailSubject, $body, implode("\r\n", $headers));

if ($stored || $sent) {
    respond(200, 'success', 'پیام شما با موفقیت ارسال شد.');
}
respond(500, 'error', 'ارسال پیام ناموفق بود. لطفاً با شماره‌های تماس در ارتباط باشید.');

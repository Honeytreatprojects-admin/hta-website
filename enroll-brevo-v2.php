<?php
/**
 * enroll-brevo.php — receives a course enrolment from courses.html and:
 *
 *  1. Creates / updates the contact in Brevo (name, phone, course, centre) and, if BREVO_LIST_ID is set,
 *     adds them to that list  -> starts a "Contact added to list" automation.
 *  2. Sends a custom event (BREVO_EVENT_NAME)  -> starts a "Custom event" automation.
 *  3. Emails the selected centre (Brevo transactional email)  -> replaces the old EmailJS notification.
 *
 * Optional extra constants for brevo-config.php (defaults are used if you leave them out):
 *   BREVO_SENDER_EMAIL  – must be a verified sender / authenticated domain in Brevo
 *   BREVO_SENDER_NAME
 *   HTA_FALLBACK_EMAIL  – receives the notification if the centre is not recognised
 */

define('HTA_BREVO', true);
$cfg = __DIR__ . '/secrets/brevo-config-v2.php';
if (!is_file($cfg)) { hta_out(500, ['ok' => false, 'error' => 'config_missing']); }
require $cfg;

if (!defined('BREVO_SENDER_EMAIL')) define('BREVO_SENDER_EMAIL', 'contact@honeytreatacademy.com');
if (!defined('BREVO_SENDER_NAME'))  define('BREVO_SENDER_NAME', 'HTA Website');
if (!defined('HTA_FALLBACK_EMAIL')) define('HTA_FALLBACK_EMAIL', 'contact@honeytreatacademy.com');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function hta_out($code, $data) {
    http_response_code($code);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function hta_log($msg) { error_log('[HTA Brevo] ' . $msg); } // no personal data is logged

/* ---------- 1. Basic request checks ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { hta_out(405, ['ok' => false, 'error' => 'method']); }

$allowed = array_filter(array_map('trim', explode(',', strtolower(HTA_ALLOWED_HOSTS))));
$allowed = array_merge($allowed, ['localhost', '127.0.0.1']);
$src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$srcHost = strtolower((string) parse_url($src, PHP_URL_HOST));
if ($srcHost === '' || !in_array($srcHost, $allowed, true)) { hta_out(403, ['ok' => false, 'error' => 'origin']); }

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '', true);
if (!is_array($in)) { $in = $_POST; }

if (!empty($in['company'])) { hta_out(200, ['ok' => true]); } // honeypot: silently drop bots

$clean = function ($v, $max) {
    $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
    return mb_substr($v, 0, $max);
};
$first   = $clean($in['firstName'] ?? '', 60);
$last    = $clean($in['lastName'] ?? '', 60);
$email   = strtolower($clean($in['email'] ?? '', 120));
$phone   = $clean($in['phone'] ?? '', 30);
$track   = $clean($in['track'] ?? '', 80);
$course  = $clean($in['course'] ?? '', 80);
$centre  = $clean($in['centre'] ?? '', 80);
$message = $clean($in['message'] ?? '', 500);
$page    = $clean($in['page'] ?? '', 200);

if ($first === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    hta_out(422, ['ok' => false, 'error' => 'invalid']);
}

/* ---------- 2. Rate limit (per IP) and duplicate guard ---------- */
$tmp = sys_get_temp_dir();
$ip  = $_SERVER['REMOTE_ADDR'] ?? '0';
$rl  = $tmp . '/hta_brevo_rl_' . md5($ip);
$now = time();
$hits = is_file($rl) ? (json_decode((string) @file_get_contents($rl), true) ?: []) : [];
$hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 600; }));
if (count($hits) >= 8) { hta_out(429, ['ok' => false, 'error' => 'rate']); }
$hits[] = $now;
@file_put_contents($rl, json_encode($hits), LOCK_EX);

$dupe = $tmp . '/hta_brevo_dup_' . md5($email . '|' . $course . '|' . $centre);
if (is_file($dupe) && filemtime($dupe) > $now - 600) { hta_out(200, ['ok' => true, 'duplicate' => true]); }

/* ---------- 3. Brevo calls ---------- */
function brevo($method, $path, $body) {
    $base = getenv('HTA_BREVO_API_BASE') ?: 'https://api.brevo.com/v3';
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $res === false ? null : json_decode((string) $res, true), $err];
}

function hta_phone($p) {
    $d = preg_replace('/\D/', '', $p);
    if ($d === '') return null;
    if (strpos($d, '234') === 0 && strlen($d) === 13) return '+' . $d;       // 2348031234567
    if ($d[0] === '0' && strlen($d) === 11) return '+234' . substr($d, 1);   // 08031234567
    if (strlen($d) === 10 && in_array($d[0], ['7', '8', '9'], true)) return '+234' . $d; // 8031234567
    if (strpos(trim($p), '+') === 0 && strlen($d) >= 8 && strlen($d) <= 15) return '+' . $d; // other countries
    return null;
}
$sms = hta_phone($phone);

// Contact: try with everything, then fall back if Brevo rejects an attribute (missing COURSE/CENTRE
// attribute, or the phone number already belongs to another contact).
$attrSets = [];
$base = ['FIRSTNAME' => $first, 'LASTNAME' => $last];
$full = $base;
if ($sms) $full['SMS'] = $sms;
if (BREVO_SAVE_COURSE_ATTRIBUTES) { $full['COURSE'] = $course; $full['CENTRE'] = $centre; }
$attrSets[] = $full;
if (BREVO_SAVE_COURSE_ATTRIBUTES) { $noCourse = $base; if ($sms) $noCourse['SMS'] = $sms; $attrSets[] = $noCourse; }
if ($sms) $attrSets[] = $base;

$contactOk = false;
$lastErr = '';
foreach ($attrSets as $attrs) {
    $payload = ['email' => $email, 'attributes' => $attrs, 'updateEnabled' => true];
    if ((int) BREVO_LIST_ID > 0) $payload['listIds'] = [(int) BREVO_LIST_ID];
    [$code, $body, $cerr] = brevo('POST', '/contacts', $payload);
    if ($code === 201 || $code === 204) { $contactOk = true; break; }
    $lastErr = $code . ' ' . ($body['code'] ?? '') . ' ' . ($body['message'] ?? $cerr);
    if ($code !== 400) break; // auth / network problems won't be fixed by dropping attributes
}
if (!$contactOk) hta_log('contact failed: ' . $lastErr);

$eventOk = null; // null = not configured
if (BREVO_EVENT_NAME !== '') {
    $ev = [
        'event_name'         => BREVO_EVENT_NAME,
        'identifiers'        => ['email_id' => $email],
        'contact_properties' => ['FIRSTNAME' => $first, 'LASTNAME' => $last],
        'event_properties'   => [
            'track'   => $track,
            'course'  => $course,
            'centre'  => $centre,
            'phone'   => $sms ?: $phone,
            'message' => $message,
            'page'    => $page,
        ],
    ];
    [$code, $body, $cerr] = brevo('POST', '/events', $ev);
    $eventOk = ($code >= 200 && $code < 300);
    if (!$eventOk) hta_log('event failed: ' . $code . ' ' . ($body['code'] ?? '') . ' ' . ($body['message'] ?? $cerr));
}

if ((int) BREVO_LIST_ID <= 0 && BREVO_EVENT_NAME === '') {
    hta_log('neither BREVO_LIST_ID nor BREVO_EVENT_NAME is set — no automation can start');
}

/* ---------- 4. Notify the centre (replaces EmailJS) ---------- */
// Centre addresses now live on the server only, so they no longer appear in the page source.
$centreEmails = [
    'Akilo Centre (Lagos)'        => 'hta.akilocentre@gmail.com',
    'Abule Egba Centre (Lagos)'   => 'hta.abuleegbacentre@gmail.com',
    'Osogbo Centre (Osun)'        => 'hta.osogbocentre@gmail.com',
    'Monatan Centre (Ibadan)'     => 'hta.monatancentre@gmail.com',
    'Tijani Bello Centre (Lagos)' => 'hta.tijanibellocentre@gmail.com',
    'Magodo Centre (Lagos)'       => 'hta.magodocentre@gmail.com',
    'Abuja Centre (Abuja)'        => 'hta.abujacentre@gmail.com',
];
$to = $centreEmails[$centre] ?? HTA_FALLBACK_EMAIL;

$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$row = function ($label, $value) use ($h) {
    return '<tr><td style="padding:6px 12px 6px 0;color:#666;vertical-align:top;"><b>' . $h($label) . '</b></td>'
         . '<td style="padding:6px 0;">' . $h($value) . '</td></tr>';
};
$html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;">'
      . '<h3 style="margin:0 0 12px;">New course enrolment</h3>'
      . '<table cellpadding="0" cellspacing="0">'
      . $row('Name', trim($first . ' ' . $last))
      . $row('Email', $email)
      . $row('Phone', $phone)
      . $row('Track', $track)
      . $row('Course', $course)
      . $row('Centre', $centre)
      . $row('Message', $message !== '' ? $message : 'No additional message.')
      . '</table></div>';

[$code, $body, $cerr] = brevo('POST', '/smtp/email', [
    'sender'      => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
    'to'          => [['email' => $to]],
    'replyTo'     => ['email' => $email, 'name' => trim($first . ' ' . $last)],
    'subject'     => 'New enrolment: ' . $course . ' (' . $centre . ')',
    'htmlContent' => $html,
]);
$notifyOk = ($code >= 200 && $code < 300);
if (!$notifyOk) hta_log('centre email failed: ' . $code . ' ' . ($body['code'] ?? '') . ' ' . ($body['message'] ?? $cerr));

/* ---------- 4b. Forward to the HTA CRM ---------- */
$crmOk = null; // null = not configured
if (defined('HTA_CRM_URL') && HTA_CRM_URL !== '') {
    $crmPayload = [
        'external_id'  => md5($email . '|' . $course . '|' . $centre . '|' . gmdate('Y-m-d')),
        'submitted_at' => gmdate('c'),
        'first_name'   => $first,
        'last_name'    => $last,
        'email'        => $email,
        'phone'        => $sms ?: $phone,
        'track'        => $track,
        'course'       => $course,
        'centre'       => $centre,
        'message'      => $message,
        'source'       => 'honeytreatacademy.com' . $page,
    ];

    $ch = curl_init(HTA_CRM_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . (defined('HTA_CRM_TOKEN') ? HTA_CRM_TOKEN : ''),
        ],
        CURLOPT_POSTFIELDS     => json_encode($crmPayload, JSON_UNESCAPED_UNICODE),
    ]);
    $crmRes  = curl_exec($ch);
    $crmCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $crmErr  = curl_error($ch);
    curl_close($ch);

    $crmOk = ($crmCode >= 200 && $crmCode < 300);
    if (!$crmOk) hta_log('crm failed: ' . $crmCode . ' ' . $crmErr);
}

/* ---------- 5. Result ---------- */
$ok = $notifyOk || $contactOk || ($crmOk === true);
if ($notifyOk && $contactOk && $eventOk !== false && $crmOk !== false) @touch($dupe);
hta_out($ok ? 200 : 502, [
    'ok' => $ok, 'contact' => $contactOk, 'event' => $eventOk,
    'notify' => $notifyOk, 'crm' => $crmOk,
]);

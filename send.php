<?php
// Big Rock Arms: receives the request ticket and emails it to the shop.
// Mail goes out through Hostinger's authenticated SMTP as sales@, because PHP mail()
// on the host reported success but the message never arrived (tested 2026-11-21).
// The SMTP login lives in smtp-config.php one level ABOVE the web root, never in this repo:
//   <?php return ['user' => 'sales@bigrockarms.com', 'pass' => 'the mailbox password'];
// If it is missing or SMTP fails, the visitor gets an error and the page falls back to
// their email app, so a request is never lost silently.
// Nothing is stored. The only state is a short-lived per-visitor counter for rate limiting,
// keyed by a hash of the IP so no address is written to disk.

const TO = 'bigrockarms@gmail.com';
const FROM = 'sales@bigrockarms.com';
const KINDS = ['Special order', 'FFL transfer', 'NFA transfer', 'Optics & accessories', 'Something else'];
const LIMIT = 5;          // requests per visitor
const WINDOW = 3600;      // per hour

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply($code, $ok, $msg = '') {
  http_response_code($code);
  echo json_encode(['ok' => $ok, 'message' => $msg]);
  exit;
}

// one line, no control characters, capped length: safe for headers and the body
function field($key, $max) {
  $v = isset($_POST[$key]) ? (string) $_POST[$key] : '';
  $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
  $v = trim(preg_replace('/\s+/u', ' ', $v));
  return mb_substr($v, 0, $max);
}

const SMTP_HOST = 'ssl://smtp.hostinger.com';
const SMTP_PORT = 465;

// Minimal SMTP client (AUTH LOGIN over implicit TLS). Returns true only when the
// server accepts the message for delivery.
function smtp_send($to, $subject, $body, $headers) {
  $cfgFile = dirname(__DIR__) . '/smtp-config.php';
  if (!is_file($cfgFile)) { error_log('send.php: missing ' . $cfgFile); return false; }
  $cfg = include $cfgFile;
  if (empty($cfg['user']) || empty($cfg['pass'])) { error_log('send.php: smtp-config.php incomplete'); return false; }

  $fp = @stream_socket_client(SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 15);
  if (!$fp) { error_log('send.php: connect failed: ' . $errstr); return false; }
  stream_set_timeout($fp, 15);

  $cmd = function ($line, $expect) use ($fp) {
    if ($line !== null) fwrite($fp, $line . "\r\n");
    $resp = '';
    while (($l = fgets($fp, 515)) !== false) {
      $resp .= $l;
      if (strlen($l) < 4 || $l[3] === ' ') break;
    }
    if ((int) substr($resp, 0, 3) !== $expect) {
      $what = $line === null ? 'greeting' : strtok($line, ' :');
      throw new RuntimeException('SMTP ' . $what . ' got: ' . trim($resp));
    }
  };

  $data = implode("\r\n", array_merge(
    [
      'Date: ' . date('r'),
      'Message-ID: <' . bin2hex(random_bytes(12)) . '@bigrockarms.com>',
      'To: ' . $to,
      'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
    ],
    $headers
  )) . "\r\n\r\n" . preg_replace('/^\./m', '..', $body);

  try {
    $cmd(null, 220);
    $cmd('EHLO bigrockarms.com', 250);
    $cmd('AUTH LOGIN', 334);
    $cmd(base64_encode($cfg['user']), 334);
    $cmd(base64_encode($cfg['pass']), 235);
    $cmd('MAIL FROM:<' . $cfg['user'] . '>', 250);
    $cmd('RCPT TO:<' . $to . '>', 250);
    $cmd('DATA', 354);
    $cmd($data . "\r\n.", 250);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
  } catch (RuntimeException $e) {
    error_log('send.php: ' . $e->getMessage());
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return false;
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, false, 'POST only.');

// bots fill the hidden field; pretend it worked so they move on
if (!empty($_POST['website'])) reply(200, true);

$item = field('item', 400);
$kind = field('kind', 40);
$name = field('name', 80);
$budget = field('budget', 40);
$email = field('email', 120);

if ($item === '') reply(422, false, 'Tell us what you are after, even roughly.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) reply(422, false, 'We need an email to send your quote to.');
if (!in_array($kind, KINDS, true)) $kind = 'Something else';

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$bucket = sys_get_temp_dir() . '/bra-' . hash('sha256', 'bigrockarms|' . $ip);
$hits = array_filter(
  is_file($bucket) ? (array) json_decode((string) file_get_contents($bucket), true) : [],
  function ($t) { return $t > time() - WINDOW; }
);
if (count($hits) >= LIMIT) reply(429, false, 'Too many requests. Please email us directly.');
$hits[] = time();
file_put_contents($bucket, json_encode(array_values($hits)), LOCK_EX);

$fees = ['FFL transfer' => '$20 (single firearm)', 'NFA transfer' => '$50 (per NFA item)'];
$transfer = isset($fees[$kind]);
$lines = [
  'New request from bigrockarms.com',
  '',
  'Order type: ' . $kind,
  'Looking for: ' . $item,
  $transfer ? 'Transfer fee: ' . $fees[$kind] : 'Budget: ' . ($budget !== '' ? $budget : 'Not specified'),
  '',
  'Name: ' . ($name !== '' ? $name : 'Not given'),
  'Email: ' . $email,
  '',
  'Reply to this email to answer them directly.',
];

$subject = $kind . ': ' . mb_substr($item, 0, 60);
$fromName = $name !== '' ? str_replace(['"', '\\'], '', $name) . ' via Big Rock Arms' : 'Big Rock Arms website';
$headers = [
  'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . FROM . '>',
  'Reply-To: ' . $email,
  'MIME-Version: 1.0',
  'Content-Type: text/plain; charset=UTF-8',
  'Content-Transfer-Encoding: 8bit',
];

$sent = smtp_send(TO, $subject, implode("\r\n", $lines), $headers);

if (!$sent) reply(502, false, 'We could not send that just now.');
reply(200, true);

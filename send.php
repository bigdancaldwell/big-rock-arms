<?php
// Big Rock Arms: receives the request ticket and emails it to the shop.
// Nothing is stored. The only state is a short-lived per-visitor counter for rate limiting,
// keyed by a hash of the IP so no address is written to disk.

const TO = 'bigrockarms@gmail.com';
const FROM = 'forms@bigrockarms.com';
const KINDS = ['Special order', 'FFL transfer', 'Optics & accessories', 'Something else'];
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

$transfer = $kind === 'FFL transfer';
$lines = [
  'New request from bigrockarms.com',
  '',
  'Order type: ' . $kind,
  'Looking for: ' . $item,
  $transfer ? 'Transfer fee: $20 (single firearm)' : 'Budget: ' . ($budget !== '' ? $budget : 'Not specified'),
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

$sent = mail(
  TO,
  '=?UTF-8?B?' . base64_encode($subject) . '?=',
  implode("\r\n", $lines),
  implode("\r\n", $headers),
  '-f' . FROM
);

if (!$sent) reply(502, false, 'We could not send that just now.');
reply(200, true);

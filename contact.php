<?php
/**
 * Pearl Paros — sends the contact form to the office mailbox.
 *
 * Needs hosting that runs PHP and can send mail.
 * Nothing is stored on the server; the message is only emailed.
 * Written for PHP 7.2 and newer.
 */
declare(strict_types=1);

const MAIL_TO   = 'info@pearl-paros.gr';
// The sender must be an address on this same domain, otherwise mail providers may treat it as spam.
const MAIL_FROM = 'website@pearl-paros.gr';
const SITE_NAME = 'Pearl Paros';
// How many messages one visitor may send per hour.
const PER_HOUR  = 5;
// How many messages the form sends per hour from everybody together (keeps a flood out of the mailbox).
const ALL_PER_HOUR = 40;

function wants_json(): bool
{
    return strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
}

/** Answer the browser and stop. */
function finish(bool $ok, string $code, int $status = 200): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'code' => $code]);
        exit;
    }
    // The form was posted without JavaScript: show a small plain page.
    $el = ($_POST['lang'] ?? '') === 'el';
    $home = $el ? '/el/' : '/';
    $title = $ok
        ? ($el ? 'Σας ευχαριστούμε' : 'Thank you')
        : ($el ? 'Το μήνυμα δεν εστάλη' : 'The message was not sent');
    $text = $ok
        ? ($el ? 'Το μήνυμά σας εστάλη. Θα επικοινωνήσουμε σύντομα μαζί σας.' : 'Your message is on its way. We will get back to you soon.')
        : ($el ? 'Παρακαλούμε δοκιμάστε ξανά ή γράψτε μας στο ' . MAIL_TO . '.' : 'Please try again, or write to us at ' . MAIL_TO . '.');
    $back = $el ? 'Επιστροφή στην ιστοσελίδα' : 'Back to the site';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="' . ($el ? 'el' : 'en') . '"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . htmlspecialchars($title) . ' | ' . SITE_NAME . '</title>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#fbf8f2;color:#0b1b33;font:17px/1.6 Georgia,serif;text-align:center;padding:2rem">'
        . '<main><h1 style="font-weight:400;font-size:2.2rem;margin:0 0 .6em">' . htmlspecialchars($title) . '</h1>'
        . '<p style="margin:0 0 1.5em">' . htmlspecialchars($text) . '</p>'
        . '<p><a style="color:#1558d6" href="' . $home . '#contact">' . htmlspecialchars($back) . '</a></p></main></body></html>';
    exit;
}

/** Number of characters (not bytes), also where the multibyte extension is missing. */
function chars(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/** One line of text: trimmed, no line breaks or control characters, cut to a length. */
function one_line(string $value, int $max): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

/** A subject line that mail programs read correctly in any alphabet. */
function mail_subject(string $subject): string
{
    if (function_exists('mb_encode_mimeheader')) {
        mb_internal_encoding('UTF-8');
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

/**
 * The visitor's address, used only to count messages per visitor.
 * When the web server sits behind its own front server on the same machine, every visitor arrives as a
 * local address and the front server passes the real one along; only then is that passed-along value used.
 */
function visitor_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    if (!$public) {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
            $parts = explode(',', (string) ($_SERVER[$header] ?? ''));
            $last = trim((string) end($parts)); // the last entry is the one the nearest server added
            if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
                return $last;
            }
        }
    }
    return $ip !== '' ? $ip : 'unknown';
}

/** The send times of the last hour kept in a small temporary file. */
function recent_sends(string $file, int $now): array
{
    $recent = [];
    if (is_file($file)) {
        $saved = json_decode((string) @file_get_contents($file), true);
        if (is_array($saved)) {
            foreach ($saved as $t) {
                if (is_int($t) && $t > $now - 3600 && $t <= $now) {
                    $recent[] = $t;
                }
            }
        }
    }
    return $recent;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    finish(false, 'method', 405);
}

// Spam traps: a field people never see, and forms sent faster than a person can type.
// Robots are told "sent" so they do not try other tricks; nothing is mailed.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    finish(true, 'ok');
}
if (isset($_POST['elapsed']) && is_numeric($_POST['elapsed']) && (int) $_POST['elapsed'] < 1200) {
    finish(true, 'ok');
}

$name    = one_line((string) ($_POST['name'] ?? ''), 100);
$email   = one_line((string) ($_POST['email'] ?? ''), 160);
$topic   = one_line((string) ($_POST['topic'] ?? ''), 80);
$lang    = ($_POST['lang'] ?? '') === 'el' ? 'Greek' : 'English';
$message = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['message'] ?? '')));
$message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message) ?? '';

if ($name === '' || $message === '' || chars($message) > 5000 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    finish(false, 'invalid', 400);
}

// Limits, kept in small temporary files (only a scrambled form of the visitor's address is used as a file name).
$tmp = rtrim(sys_get_temp_dir(), '/\\');
$now = time();
$visitorFile = $tmp . '/pearl-contact-' . sha1(visitor_ip()) . '.json';
$allFile     = $tmp . '/pearl-contact-all-' . sha1(__FILE__) . '.json';
$recent    = recent_sends($visitorFile, $now);
$recentAll = recent_sends($allFile, $now);
if (count($recent) >= PER_HOUR || count($recentAll) >= ALL_PER_HOUR) {
    finish(false, 'slow-down', 429);
}

$subject = SITE_NAME . ' website: ' . ($topic !== '' ? $topic . ' — ' : '') . $name;
$body = "Name:     {$name}\n"
    . "Email:    {$email}\n"
    . ($topic !== '' ? "Topic:    {$topic}\n" : '')
    . "Language: {$lang}\n"
    . "\n{$message}\n"
    . "\n-- \nSent from the contact form on pearl-paros.gr. Reply to this email to answer {$name}.\n";
// Encoded so that long paragraphs and Greek letters arrive intact whatever the mail server does with long lines.
$body = quoted_printable_encode(str_replace("\n", "\r\n", $body));

$headers = implode("\r\n", [
    'From: ' . SITE_NAME . ' <' . MAIL_FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: quoted-printable',
]);

$sent = @mail(MAIL_TO, mail_subject($subject), $body, $headers, '-f' . MAIL_FROM);
if (!$sent) {
    // Some hosting does not let a script choose the return address: send without it.
    $sent = @mail(MAIL_TO, mail_subject($subject), $body, $headers);
}
if (!$sent) {
    finish(false, 'mail', 500);
}

$recent[] = $now;
$recentAll[] = $now;
@file_put_contents($visitorFile, json_encode($recent), LOCK_EX);
@file_put_contents($allFile, json_encode($recentAll), LOCK_EX);
finish(true, 'ok');

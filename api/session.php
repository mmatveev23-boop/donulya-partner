<?php
/**
 * Session API for partner auth via messengers (TG / VK / MAX).
 *
 * Endpoints:
 *   POST /partners/api/session.php          — create / update session (frontend)
 *   GET  /partners/api/session.php?session_id=XXX — get session status (frontend polls + Salebot reads)
 *   POST /partners/api/session.php?action=confirm — Salebot confirms auth (sets status=ok)
 */

header('Content-Type: application/json; charset=utf-8');

// CORS — allow GitHub Pages dev + donula.online prod
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://mmatveev23-boop.github.io',
    'https://donula.online',
    'http://localhost',
];
foreach ($allowedOrigins as $ao) {
    if (str_starts_with($origin, $ao)) {
        header("Access-Control-Allow-Origin: $origin");
        break;
    }
}
// Salebot server-side calls have no Origin — allow them through
if ($origin === '') {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

const SESSION_ID_PATTERN = '/^[a-z0-9]{8}$/';
const SESSION_TTL = 259200; // 72 hours
const RATE_LIMIT_WINDOW = 60;
const RATE_LIMIT_MAX = 30;
const MAX_BODY = 8192;

$dataDir      = __DIR__ . '/data/sessions';
$logFile      = __DIR__ . '/data/session.log';
$rateLimitDir = __DIR__ . '/data/ratelimit';

// ── Helpers ──────────────────────────────────────────────────────

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function logMsg(string $msg): void {
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $ip   = clientIp();
    $hash = substr(hash('sha256', $ip), 0, 12);
    $line = sprintf("[%s] [ip:%s] %s\n", date('Y-m-d H:i:s'), $hash, $msg);
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function clientIp(): string {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    }
    return '0.0.0.0';
}

function ensureDir(string $dir): void {
    if (!is_dir($dir)) mkdir($dir, 0750, true);
}

function cleanupSessions(): void {
    global $dataDir;
    if (!is_dir($dataDir) || mt_rand(1, 20) !== 1) return;
    $threshold = time() - SESSION_TTL;
    foreach (glob($dataDir . '/*.json') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < $threshold) @unlink($f);
    }
}

function rateLimitOk(): bool {
    global $rateLimitDir;
    ensureDir($rateLimitDir);
    $file = $rateLimitDir . '/' . hash('sha256', clientIp()) . '.json';
    $now  = time();
    $ts   = [];
    if (is_file($file)) {
        $ts = json_decode(@file_get_contents($file), true) ?: [];
        $ts = array_filter($ts, fn($t) => $t >= $now - RATE_LIMIT_WINDOW);
    }
    if (count($ts) >= RATE_LIMIT_MAX) return false;
    $ts[] = $now;
    @file_put_contents($file, json_encode(array_values($ts)), LOCK_EX);
    return true;
}

function sanitize($v): string {
    if (!is_scalar($v)) return '';
    $t = trim((string)$v);
    return $t === '' ? '' : substr($t, 0, 500);
}

function sessionPath(string $sid): string {
    global $dataDir;
    return $dataDir . '/' . $sid . '.json';
}

function loadSession(string $sid): ?array {
    $f = sessionPath($sid);
    if (!is_file($f)) return null;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function saveSession(string $sid, array $data): void {
    $data['updated_at'] = date('c');
    file_put_contents(
        sessionPath($sid),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

// ── OPTIONS (CORS preflight) ─────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ensureDir($dataDir);
cleanupSessions();

// ── Allowed fields for session create/update from frontend ──────

$allowedFields = [
    'provider',     // telegram | vk | max
    'messenger',
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
    'referrer', 'page_url',
];

// ── POST ─────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!rateLimitOk()) {
        logMsg('rate limit');
        respond(429, ['ok' => false, 'error' => 'rate_limit']);
    }

    $raw = file_get_contents('php://input');
    if (strlen($raw) > MAX_BODY) respond(413, ['ok' => false, 'error' => 'body_too_large']);

    $input = json_decode($raw, true);
    if (!is_array($input)) respond(400, ['ok' => false, 'error' => 'invalid_json']);

    $action = $_GET['action'] ?? ($input['action'] ?? '');

    // ─── action=confirm (Salebot calls this) ────────────────────

    if ($action === 'confirm') {
        $sid = sanitize($input['session_id'] ?? '');
        if (!preg_match(SESSION_ID_PATTERN, $sid)) {
            logMsg("confirm: invalid sid=$sid");
            respond(400, ['ok' => false, 'error' => 'invalid_session_id']);
        }

        $session = loadSession($sid);
        if (!$session) {
            logMsg("confirm: not found sid=$sid");
            respond(404, ['ok' => false, 'error' => 'session_not_found']);
        }

        // Update with Salebot data
        $session['status']      = 'ok';
        $session['phone']       = sanitize($input['phone'] ?? '');
        $session['name']        = sanitize($input['name'] ?? '');
        $session['platform_id'] = sanitize($input['platform_id'] ?? '');
        $session['client_type'] = sanitize($input['client_type'] ?? '');
        $session['confirmed_at'] = date('c');

        saveSession($sid, $session);
        logMsg("confirm: ok sid=$sid phone={$session['phone']}");
        respond(200, ['ok' => true, 'session_id' => $sid]);
    }

    // ─── Default POST: create/update session (frontend) ─────────

    $sid = sanitize($input['session_id'] ?? '');
    if (!preg_match(SESSION_ID_PATTERN, $sid)) {
        logMsg("create: invalid sid");
        respond(400, ['ok' => false, 'error' => 'invalid_session_id']);
    }

    $existing = loadSession($sid) ?: [];

    $session = [
        'session_id' => $sid,
        'status'     => $existing['status'] ?? 'waiting',
        'created_at' => $existing['created_at'] ?? date('c'),
        'ip_hash'    => substr(hash('sha256', clientIp()), 0, 12),
    ];

    // Merge allowed fields (existing + new input)
    foreach ($allowedFields as $f) {
        if (isset($existing[$f]) && $existing[$f] !== '') $session[$f] = $existing[$f];
        $v = sanitize($input[$f] ?? '');
        if ($v !== '') $session[$f] = $v;
    }

    // Preserve confirm data if already confirmed
    foreach (['phone', 'name', 'platform_id', 'client_type', 'confirmed_at'] as $k) {
        if (isset($existing[$k])) $session[$k] = $existing[$k];
    }

    saveSession($sid, $session);
    logMsg("create: sid=$sid provider=" . ($session['provider'] ?? '-'));
    respond(200, ['ok' => true, 'session_id' => $sid]);
}

// ── GET ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sid = sanitize($_GET['session_id'] ?? $_GET['message'] ?? '');

    // Support Salebot /start token parsing
    if (preg_match('/^\/start(?:@\w+)?\s+([a-z0-9]{8})$/i', $sid, $m)) {
        $sid = strtolower($m[1]);
    } else {
        $sid = strtolower(preg_replace('/[^a-z0-9]/', '', $sid));
    }

    if (!preg_match(SESSION_ID_PATTERN, $sid)) {
        respond(400, ['ok' => false, 'error' => 'invalid_session_id']);
    }

    $session = loadSession($sid);
    if (!$session) {
        respond(404, ['ok' => false, 'error' => 'session_not_found']);
    }

    // For frontend polling: return minimal data
    respond(200, [
        'ok'          => true,
        'session_id'  => $sid,
        'status'      => $session['status'] ?? 'waiting',
        'phone'       => $session['phone'] ?? '',
        'name'        => $session['name'] ?? '',
        'platform_id' => $session['platform_id'] ?? '',
        'provider'    => $session['provider'] ?? '',
    ]);
}

respond(405, ['ok' => false, 'error' => 'method_not_allowed']);

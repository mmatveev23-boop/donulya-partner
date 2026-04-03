<?php
/**
 * Salebot webhook handler for partner auth.
 *
 * Salebot forwards all incoming messages here.
 * This script:
 *  1. Detects session_id from /start parameter (TG tag) or ref-link (session_id)
 *  2. Sends greeting + phone request via Salebot API (TG: contact button)
 *  3. When phone is received — confirms the session via session.php
 *  4. Sends confirmation message with link back to site
 *
 * Webhook URL: https://donula.online/partners/api/webhook.php
 */

// ── Config ────────────────────────────────────────────────────────

const SALEBOT_API_KEY = '15934d777a5f183b3b0389f48b8829d8';
const SALEBOT_API_BASE = 'https://chatter.salebot.pro/api/' . SALEBOT_API_KEY;

const SESSION_API_URL = 'https://donula.online/partners/api/session.php';
const SITE_URL = 'https://donula.online/partners/';
const SITE_URL_AFTER_AUTH = 'https://donula.online/partners/?step=5';

const SESSION_ID_PATTERN = '/^[a-z0-9]{8}$/';
const PHONE_PATTERN = '/(?:\+?7|8)[\s\-]?\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}/';

const SYSTEM_EVENTS = [
    '', 'client_started', 'client_returned', 'client_left',
    'client_silent_1h', 'chat_opened', 'new_chat_member',
    'client_unsubscribed', 'client_subscribed',
];

// TG bot token for sending keyboard (Salebot API doesn't support request_contact)
// We send via Telegram Bot API directly for the contact button
const TG_BOT_TOKEN = ''; // Will use Salebot for now, TG keyboard via buttons param

$logFile = __DIR__ . '/data/webhook.log';

// ── Helpers ───────────────────────────────────────────────────────

function logMsg(string $msg): void {
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $msg);
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function salebotSend(int|string $clientId, string $message, ?array $buttons = null): bool {
    $url = SALEBOT_API_BASE . '/message';
    $payload = [
        'client_id' => (string)$clientId,
        'message'   => $message,
    ];
    if ($buttons) {
        $payload['buttons'] = $buttons;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logMsg("salebotSend client=$clientId code=$code resp=$resp");
    return $code >= 200 && $code < 300;
}

function salebotSaveVars(int|string $clientId, array $vars): void {
    $url = SALEBOT_API_BASE . '/save_variables';
    $json = json_encode([
        'client_id' => (string)$clientId,
        'variables' => $vars,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function sessionConfirm(string $sid, string $phone, string $name, string $platformId, string $clientType): bool {
    $json = json_encode([
        'session_id'  => $sid,
        'phone'       => $phone,
        'name'        => $name,
        'platform_id' => $platformId,
        'client_type' => $clientType,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(SESSION_API_URL . '?action=confirm');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logMsg("sessionConfirm sid=$sid code=$code resp=$resp");
    return $code === 200;
}

/**
 * Create partner in amoCRM via Maksim's Vercel API.
 * Returns ['ref_code' => '...', 'ref_link' => '...'] on success, or null on failure.
 */
function vercelCreatePartner(string $name, string $phone, string $platform, string $platformId): ?array {
    $url = 'https://donulya-partner-project.vercel.app/api/partner';
    $payload = json_encode([
        'name'        => $name,
        'phone'       => $phone,
        'platform'    => $platform,   // 'tg', 'vk', 'max'
        'platform_id' => $platformId,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logMsg("vercelCreatePartner code=$code resp=$resp");

    if ($code >= 200 && $code < 300) {
        $data = json_decode($resp, true);
        if (is_array($data) && !empty($data['ref_link'])) {
            return $data;
        }
    }
    return null;
}

/**
 * Map client_type to platform name for Vercel API.
 */
function clientTypeToPlatform(string $clientType): string {
    return match ($clientType) {
        '1'  => 'tg',
        '0'  => 'vk',
        '20' => 'max',
        default => 'unknown',
    };
}

function extractPhone(string $text): string {
    if (preg_match(PHONE_PATTERN, $text, $m)) {
        return preg_replace('/[^\d+]/', '', $m[0]);
    }
    $digits = preg_replace('/\D/', '', $text);
    if (strlen($digits) >= 10 && strlen($digits) <= 12) {
        return '+' . ltrim($digits, '+');
    }
    return '';
}

function isSessionToken(string $text): bool {
    return (bool)preg_match(SESSION_ID_PATTERN, strtolower(trim($text)));
}

/**
 * Send TG message with request_contact keyboard via Telegram Bot API directly.
 * Salebot API doesn't support request_contact buttons.
 */
function tgSendWithContactButton(string $tgBotToken, string $chatId, string $text): bool {
    $url = "https://api.telegram.org/bot$tgBotToken/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text'    => $text,
        'reply_markup' => json_encode([
            'keyboard' => [[
                ['text' => '📱 Отправить номер телефона', 'request_contact' => true],
            ]],
            'resize_keyboard'   => true,
            'one_time_keyboard' => true,
        ]),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logMsg("tgSend chatId=$chatId code=$code resp=$resp");
    return $code === 200;
}

function tgRemoveKeyboard(string $tgBotToken, string $chatId, string $text): bool {
    $url = "https://api.telegram.org/bot$tgBotToken/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text'    => $text,
        'reply_markup' => json_encode(['remove_keyboard' => true]),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return true;
}

// ── Main webhook handler ──────────────────────────────────────────

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
logMsg("INCOMING: $raw");

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['ok' => true]);
    exit;
}

// Only process incoming messages (is_input = 1)
if ((int)($data['is_input'] ?? 0) !== 1) {
    echo json_encode(['ok' => true]);
    exit;
}

$client     = $data['client'] ?? [];
$clientId   = $client['id'] ?? '';
$clientType = (string)($client['client_type'] ?? '');
$clientName = $client['name'] ?? '';
$platformId = $client['recepient'] ?? '';  // TG user_id for direct TG API
$tag        = $client['tag'] ?? '';
$group      = $client['group'] ?? '';
$message    = trim($data['message'] ?? '');

// Read stored variables directly from webhook payload (no extra API call)
$orderVars   = $client['order_variables'] ?? [];
$storedSid    = $orderVars['partner_sid'] ?? '';
$storedStatus = $orderVars['partner_status'] ?? '';

// Extract phone from TG contact (attachments)
$contactPhone = '';
$attachments = $data['attachments'] ?? [];
if (is_array($attachments)) {
    foreach ($attachments as $att) {
        if (isset($att['phone_number'])) {
            $contactPhone = preg_replace('/\D/', '', $att['phone_number']);
            if (strlen($contactPhone) >= 10) {
                $contactPhone = '+' . ltrim($contactPhone, '+');
            }
        }
    }
}

logMsg("Processing: client=$clientId type=$clientType tag=$tag msg=$message stored_sid=$storedSid stored_status=$storedStatus contact_phone=$contactPhone");

// Skip system events
if (in_array($message, SYSTEM_EVENTS, true) && !$contactPhone) {
    logMsg("Skipping system event: $message");
    echo json_encode(['ok' => true]);
    exit;
}

// Also skip if message is the same session token that's already stored (TG resends tag as message)
if ($message === $storedSid && $storedStatus) {
    logMsg("Skipping duplicate session token message");
    echo json_encode(['ok' => true]);
    exit;
}

// ── Get TG bot token from Salebot for direct TG API ──────────────
// We need it for request_contact keyboard. Read from Salebot bot settings.
// For now, use the token from the Salebot integration.
// The TG bot token is available via Salebot's "Показать токены" on integrations page.
// TODO: set real TG bot token here
$tgBotToken = file_get_contents(__DIR__ . '/data/.tg_bot_token');
$tgBotToken = trim($tgBotToken ?: '');

$isTelegram = ($clientType === '1');

// ── Already registered ───────────────────────────────────────────

if ($storedStatus === 'ok') {
    salebotSend($clientId,
        "Вы уже зарегистрированы! 🎉\n\n" .
        "Продолжайте обучение:\n" .
        "🔗 " . SITE_URL
    );
    echo json_encode(['ok' => true]);
    exit;
}

// ── Waiting for phone — check if this is a phone ─────────────────

if ($storedSid && $storedStatus === 'waiting_phone') {
    // Phone from TG contact button
    $phone = $contactPhone ?: extractPhone($message);

    if ($phone) {
        $confirmed = sessionConfirm($storedSid, $phone, $clientName, $platformId, $clientType);

        if ($confirmed) {
            // Create partner in amoCRM via Vercel API → get ref_link
            $platform = clientTypeToPlatform($clientType);
            $vercelResult = vercelCreatePartner($clientName, $phone, $platform, $platformId);

            $saveVars = [
                'partner_status' => 'ok',
                'partner_phone'  => $phone,
            ];

            if ($vercelResult && !empty($vercelResult['ref_link'])) {
                $refLink = $vercelResult['ref_link'];
                $refCode = $vercelResult['ref_code'] ?? '';
                $saveVars['partner_ref_link'] = $refLink;
                $saveVars['partner_ref_code'] = $refCode;

                $confirmMsg = "🎉 Регистрация завершена!\n\n" .
                    "Ваша реферальная ссылка:\n" .
                    $refLink . "\n\n" .
                    "Делитесь ей в чатах и соцсетях — за каждый договор вы получаете 10 000 ₽\n\n" .
                    "Продолжайте обучение на сайте:\n" .
                    "🔗 " . SITE_URL_AFTER_AUTH;
            } else {
                // Vercel API failed — fallback message without ref_link
                logMsg("WARNING: Vercel API failed for phone=$phone, proceeding without ref_link");
                $confirmMsg = "✅ Регистрация пройдена!\n\n" .
                    "Продолжайте проходить обучение на сайте.\n\n" .
                    "Перейдите по ссылке:\n" .
                    "🔗 " . SITE_URL_AFTER_AUTH;
            }

            salebotSaveVars($clientId, $saveVars);

            if ($isTelegram && $tgBotToken) {
                tgRemoveKeyboard($tgBotToken, $platformId, $confirmMsg);
            } else {
                salebotSend($clientId, $confirmMsg);
            }
        } else {
            salebotSend($clientId,
                "Произошла ошибка. Попробуйте открыть бота заново с сайта:\n🔗 " . SITE_URL
            );
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // Not a phone — friendly reminder
    salebotSend($clientId,
        "Добро пожаловать в партнёрскую программу ДОНУЛЯ! 🤝\n\n" .
        "Для завершения регистрации отправьте ваш номер телефона.\n\n" .
        "Например: +79991234567"
    );
    echo json_encode(['ok' => true]);
    exit;
}

// ── New user or first visit — detect session_id ──────────────────

// Session_id can come from:
// - TG: client.tag (from /start parameter)
// - VK/MAX: client.variables.session_id (from ref-link ?session_id=XXX)
// - Message text itself (if user pastes the token)
$clientVars = $client['variables'] ?? [];
$refSessionId = $clientVars['session_id'] ?? '';

$sessionId = '';
if ($tag && isSessionToken($tag)) {
    $sessionId = strtolower($tag);
}
if (!$sessionId && $refSessionId && isSessionToken($refSessionId)) {
    $sessionId = strtolower($refSessionId);
}
if (!$sessionId && isSessionToken($message)) {
    $sessionId = strtolower($message);
}

if ($sessionId) {
    salebotSaveVars($clientId, [
        'partner_sid'    => $sessionId,
        'partner_status' => 'waiting_phone',
    ]);

    $greeting = "Добро пожаловать в партнёрскую программу ДОНУЛЯ! 🤝\n\n" .
        "Для регистрации отправьте ваш номер телефона.";

    if ($isTelegram && $tgBotToken) {
        // Send with TG contact button
        tgSendWithContactButton($tgBotToken, $platformId, $greeting);
    } else {
        // VK/MAX: ask to type phone
        salebotSend($clientId, $greeting . "\n\nПример: +79991234567");
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── No session, not registered → prompt to go to site ────────────

salebotSend($clientId,
    "Привет! 👋\n\n" .
    "Чтобы стать партнёром, перейдите на сайт и нажмите кнопку регистрации:\n" .
    "🔗 " . SITE_URL
);

echo json_encode(['ok' => true]);

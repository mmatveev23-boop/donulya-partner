<?php
// Relay bot: Настя отвечает партнёрам из Telegram
// TG webhook → определяет кому ответить → Salebot → партнёру
//
// Настройка: зарегистрировать webhook:
// curl "https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://donula.online/partners/api/nastya-relay.php"

require_once __DIR__ . '/amo-helper.php';

const RELAY_TG_TOKEN = '8667064623:AAEx0s5NtrWEfVryJC8isPevd8IO24LDdDQ';
const NASTYA_USERNAME = 'divenskaya';
const NASTYA_CHAT_FILE = __DIR__ . '/data/.nastya_chat_id';
const RELAY_MAP_DIR = __DIR__ . '/data/relay/';

// Ensure relay dir exists
if (!is_dir(RELAY_MAP_DIR)) mkdir(RELAY_MAP_DIR, 0770, true);

// ── Get Nastya's chat_id ──
function getNastyaChatId(): ?string {
    if (file_exists(NASTYA_CHAT_FILE)) return trim(file_get_contents(NASTYA_CHAT_FILE));
    return null;
}

function saveNastyaChatId(string $chatId): void {
    file_put_contents(NASTYA_CHAT_FILE, $chatId);
}

// ── Save mapping: TG message_id → Salebot client_id + platform ──
function saveRelayMapping(int $messageId, string $clientId, string $platform, string $partnerName): void {
    file_put_contents(RELAY_MAP_DIR . $messageId . '.json', json_encode([
        'client_id' => $clientId,
        'platform' => $platform,
        'partner_name' => $partnerName,
        'ts' => date('c')
    ]));
}

function getRelayMapping(int $messageId): ?array {
    $file = RELAY_MAP_DIR . $messageId . '.json';
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

// ── Send message via TG Bot API ──
function tgSendRelay(string $chatId, string $text, ?int $replyTo = null): ?int {
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($replyTo) $payload['reply_to_message_id'] = $replyTo;

    $ch = curl_init("https://api.telegram.org/bot" . RELAY_TG_TOKEN . "/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $resp['result']['message_id'] ?? null;
}

// ── Forward partner message to Nastya ──
function forwardToNastya(string $partnerName, string $platform, string $message, string $clientId): void {
    $nastyaChatId = getNastyaChatId();
    if (!$nastyaChatId) return;

    $emoji = ['tg' => '✈️', 'vk' => '💙', 'max' => '💜'];
    $icon = $emoji[$platform] ?? '💬';

    $text = "$icon <b>$partnerName</b> ($platform):\n$message";

    $messageId = tgSendRelay($nastyaChatId, $text);
    if ($messageId) {
        saveRelayMapping($messageId, $clientId, $platform, $partnerName);
    }
}

// ── Process incoming TG update (Nastya's reply) ──
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo 'ok'; exit; }

$msg = $input['message'] ?? null;
if (!$msg) { echo 'ok'; exit; }

$chatId = (string)($msg['chat']['id'] ?? '');
$username = $msg['from']['username'] ?? '';
$text = $msg['text'] ?? '';
$replyTo = $msg['reply_to_message']['message_id'] ?? null;

// Save Nastya's chat_id on first message
if (strtolower($username) === strtolower(NASTYA_USERNAME)) {
    saveNastyaChatId($chatId);
}

// Check if it's Nastya
$nastyaChatId = getNastyaChatId();
if ($chatId !== $nastyaChatId) {
    echo 'ok';
    exit;
}

// If Nastya replies to a forwarded message — send to partner
if ($replyTo && $text) {
    $mapping = getRelayMapping($replyTo);
    if ($mapping) {
        $clientId = $mapping['client_id'];
        $partnerName = $mapping['partner_name'];

        // Send via Salebot
        $ch = curl_init(SALEBOT_API_BASE . '/send_message');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'client_id' => $clientId,
                'message' => $text
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Confirm to Nastya
        tgSendRelay($chatId, "✅ Отправлено → $partnerName");

        // Log to amoCRM
        // TODO: add note to partner's contact

        echo 'ok';
        exit;
    } else {
        tgSendRelay($chatId, "⚠️ Не могу определить получателя. Ответьте reply на конкретное сообщение.");
        echo 'ok';
        exit;
    }
}

// Nastya writes without reply — show help
if ($text === '/start') {
    tgSendRelay($chatId, "👋 Привет, Настя!\n\nЯ буду пересылать вам сообщения от партнёров из TG/VK/MAX.\n\nЧтобы ответить — нажмите reply на сообщение партнёра и напишите ответ.");
} elseif ($text) {
    tgSendRelay($chatId, "Чтобы ответить партнёру — нажмите reply на его сообщение.");
}

echo 'ok';

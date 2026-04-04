<?php
/**
 * amoCRM helper — shared by all API endpoints.
 * Handles token storage, auto-refresh, and API calls.
 * Tokens stored in data/.amo_tokens.json (auto-refreshed).
 */

define('AMO_DOMAIN', 'donulya2.amocrm.ru');
define('AMO_CLIENT_ID', 'f0e074e7-7685-48bd-bac9-306c674ca450');
define('AMO_CLIENT_SECRET', '4WxXbQidSKHPGKDz6zuwmekAwuCi6tPwCgiVus9kcgSwy1zi4hVnkkG1ihEgJaGm');
define('AMO_REDIRECT', 'https://mmatveev23-boop.github.io/donulya-partner/');
define('AMO_TOKENS_FILE', __DIR__ . '/data/.amo_tokens.json');

// Salebot
define('SALEBOT_API_KEY', '15934d777a5f183b3b0389f48b8829d8');
define('SALEBOT_API_BASE', 'https://chatter.salebot.pro/api/' . SALEBOT_API_KEY);

// Pipeline IDs
define('PARTNER_PIPELINE', 10776994);
define('LEAD_PIPELINE', 10777002);

// Partner statuses
define('PS_NEW', 84857050);
define('PS_TRAINING', 84857054);
define('PS_TRAINED', 84857058);
define('PS_ACTIVE', 84857062);
define('PS_PRO', 84857066);
define('PS_TOP', 84857070);
define('PS_INACTIVE', 84857074);

// Lead statuses
define('LS_NEW', 84857102);
define('LS_QUALIFICATION', 84857106);
define('LS_CONSULT_SET', 84857110);
define('LS_CONSULT_DONE', 84857114);
define('LS_DEAL_PENDING', 84857118);
define('LS_DEAL_CLOSED', 84857122);
define('LS_PAYOUT', 84857126);
define('LS_REFUSED', 84857130);

// Custom field IDs — leads
define('CF_LEAD_REF_CODE', 1700947);
define('CF_LEAD_CHANNEL', 1700949);
define('CF_LEAD_DEBT', 1700951);
define('CF_LEAD_MESSENGER', 1700953);
define('CF_LEAD_PAYOUT_STATUS', 1700955);
define('CF_LEAD_PAYOUT_SUM', 1700957);

// Custom field IDs — contacts
define('CF_PHONE', 1596487);
define('CF_REF_CODE', 1700959);
define('CF_REF_LINK', 1700961);
define('CF_MESSENGER', 1700963);
define('CF_MESSENGER_ID', 1700965);
define('CF_LEVEL', 1700967);
define('CF_TOTAL_LEADS', 1700969);
define('CF_TOTAL_DEALS', 1700971);
define('CF_BALANCE', 1700973);

// Enum IDs
define('CHANNELS', ['link' => 1651115, 'direct' => 1651117]);
define('DEBTS', ['250-500' => 1651119, '500-1000' => 1651121, '1000-3000' => 1651123, '3000+' => 1651125]);
define('MESSENGERS_ENUM', ['telegram' => 1651127, 'tg' => 1651127, 'vk' => 1651129, 'max' => 1651131, 'phone' => 1651133]);
define('LEVELS', ['novice' => 1651145, 'pro' => 1651147, 'top' => 1651149, 'legend' => 1651151]);
define('MESSENGER_CONTACT', ['telegram' => 1651139, 'tg' => 1651139, 'vk' => 1651141, 'max' => 1651143]);

define('REWARD_PER_DEAL', 10000);
define('MANAGER_ID', 1702497); // Лесников Евгений

// ── Token management ──

function amo_load_tokens() {
    if (!file_exists(AMO_TOKENS_FILE)) return null;
    $data = json_decode(file_get_contents(AMO_TOKENS_FILE), true);
    return $data ?: null;
}

function amo_save_tokens($access, $refresh) {
    file_put_contents(AMO_TOKENS_FILE, json_encode([
        'access_token' => $access,
        'refresh_token' => $refresh,
        'updated_at' => date('c')
    ], JSON_PRETTY_PRINT));
}

function amo_refresh_token($refresh) {
    $resp = amo_request_raw('POST', 'https://' . AMO_DOMAIN . '/oauth2/access_token', [
        'client_id' => AMO_CLIENT_ID,
        'client_secret' => AMO_CLIENT_SECRET,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'redirect_uri' => AMO_REDIRECT
    ]);
    $data = json_decode($resp, true);
    if (!empty($data['access_token'])) {
        amo_save_tokens($data['access_token'], $data['refresh_token']);
        return $data['access_token'];
    }
    return null;
}

function amo_get_token() {
    $tokens = amo_load_tokens();
    if (!$tokens) return null;

    // Try existing token
    $test = amo_api('GET', '/api/v4/account', null, $tokens['access_token']);
    if ($test !== null) return $tokens['access_token'];

    // Refresh
    return amo_refresh_token($tokens['refresh_token']);
}

// ── HTTP helpers ──

function amo_request_raw($method, $url, $data = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp;
}

function amo_api($method, $endpoint, $data = null, $token = null) {
    if (!$token) $token = amo_get_token();
    if (!$token) return null;

    $url = 'https://' . AMO_DOMAIN . $endpoint;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 204) return []; // No content
    if ($code >= 400) return null;
    return json_decode($resp, true);
}

// ── CORS ──

function cors_headers() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
}

// ── JSON response ──

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Helpers ──

function get_json_input() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function generate_ref_code($name) {
    $translit = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sh','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
    $latin = '';
    $nameLower = function_exists('mb_strtolower') ? mb_strtolower($name ?: 'user') : strtolower($name ?: 'user');
    foreach (preg_split('//u', $nameLower, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $latin .= $translit[$ch] ?? $ch;
    }
    $latin = strtoupper(substr(preg_replace('/[^a-z]/', '', $latin), 0, 4));
    if (strlen($latin) < 2) $latin = 'USER';
    return $latin . rand(100, 999);
}

function find_partner_by_ref($token, $refCode) {
    $data = amo_api('GET', '/api/v4/contacts?query=' . urlencode($refCode), null, $token);
    if (!$data || empty($data['_embedded']['contacts'])) return null;
    foreach ($data['_embedded']['contacts'] as $c) {
        foreach ($c['custom_fields_values'] ?? [] as $f) {
            if ($f['field_id'] == CF_REF_CODE && ($f['values'][0]['value'] ?? '') === $refCode) {
                return $c;
            }
        }
    }
    return null;
}

function find_partner_lead($token, $refCode) {
    $data = amo_api('GET', '/api/v4/leads?filter[pipeline_id]=' . PARTNER_PIPELINE . '&with=contacts&limit=250', null, $token);
    if (!$data || empty($data['_embedded']['leads'])) return null;
    foreach ($data['_embedded']['leads'] as $lead) {
        $contactId = $lead['_embedded']['contacts'][0]['id'] ?? null;
        if (!$contactId) continue;
        $contact = amo_api('GET', "/api/v4/contacts/$contactId", null, $token);
        if (!$contact) continue;
        foreach ($contact['custom_fields_values'] ?? [] as $f) {
            if ($f['field_id'] == CF_REF_CODE && ($f['values'][0]['value'] ?? '') === $refCode) {
                return ['lead' => $lead, 'contact' => $contact];
            }
        }
    }
    return null;
}

function get_contact_field($contact, $fieldId) {
    foreach ($contact['custom_fields_values'] ?? [] as $f) {
        if ($f['field_id'] == $fieldId) return $f['values'][0]['value'] ?? '';
    }
    return '';
}

function salebot_notify($platformId, $message) {
    if (!$platformId) return;
    $ch = curl_init(SALEBOT_API_BASE . '/message');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['client_id' => $platformId, 'message' => $message]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    curl_exec($ch);
    curl_close($ch);
}

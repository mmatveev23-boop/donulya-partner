<?php
/** POST /api/partner-create.php — create partner in amoCRM + generate ref code */
require_once __DIR__ . '/amo-helper.php';
cors_headers();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'POST only'], 405);

$data = get_json_input();
$phone = preg_replace('/[^\d+]/', '', $data['phone'] ?? '');
$name = $data['name'] ?? '';
$platform = strtolower($data['platform'] ?? '');
$platformId = $data['platform_id'] ?? '';

if (!$phone || !$name) json_response(['error' => 'name and phone required'], 400);

$token = amo_get_token();
if (!$token) json_response(['error' => 'amoCRM auth failed'], 500);

// Generate unique ref code
$refCode = generate_ref_code($name);
for ($i = 0; $i < 5; $i++) {
    $check = amo_api('GET', '/api/v4/contacts?query=' . urlencode($refCode), null, $token);
    if (!$check || empty($check['_embedded']['contacts'])) break;
    $exists = false;
    foreach ($check['_embedded']['contacts'] as $c) {
        foreach ($c['custom_fields_values'] ?? [] as $f) {
            if ($f['field_id'] == CF_REF_CODE && ($f['values'][0]['value'] ?? '') === $refCode) {
                $exists = true; break 2;
            }
        }
    }
    if (!$exists) break;
    $refCode = generate_ref_code($name);
}

$refLink = 'https://donula.online/partners/ref.html?ref=' . $refCode;

// Contact fields
$cf = [
    ['field_id' => CF_PHONE, 'values' => [['value' => $phone, 'enum_code' => 'MOB']]],
    ['field_id' => CF_REF_CODE, 'values' => [['value' => $refCode]]],
    ['field_id' => CF_REF_LINK, 'values' => [['value' => $refLink]]],
    ['field_id' => CF_MESSENGER_ID, 'values' => [['value' => $platformId]]],
    ['field_id' => CF_LEVEL, 'values' => [['enum_id' => LEVELS['novice']]]],
    ['field_id' => CF_TOTAL_LEADS, 'values' => [['value' => 0]]],
    ['field_id' => CF_TOTAL_DEALS, 'values' => [['value' => 0]]],
    ['field_id' => CF_BALANCE, 'values' => [['value' => 0]]],
];
if ($platform && isset(MESSENGER_CONTACT[$platform])) {
    $cf[] = ['field_id' => CF_MESSENGER, 'values' => [['enum_id' => MESSENGER_CONTACT[$platform]]]];
}

// Create contact
$contactResult = amo_api('POST', '/api/v4/contacts', [['first_name' => $name, 'custom_fields_values' => $cf]], $token);
$contactId = $contactResult['_embedded']['contacts'][0]['id'] ?? '';

// Create lead in Partners pipeline
if ($contactId) {
    amo_api('POST', '/api/v4/leads', [[
        'name' => $name . ' (партнёр)',
        'pipeline_id' => PARTNER_PIPELINE,
        'status_id' => PS_NEW,
        '_embedded' => ['contacts' => [['id' => (int)$contactId]]]
    ]], $token);
}

json_response(['status' => 'ok', 'contact_id' => $contactId, 'ref_code' => $refCode, 'ref_link' => $refLink]);

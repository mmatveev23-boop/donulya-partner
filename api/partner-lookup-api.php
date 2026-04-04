<?php
/** GET /api/partner-lookup-api.php?phone=79991234567 — find partner by phone */
require_once __DIR__ . '/amo-helper.php';
cors_headers();

$phone = preg_replace('/[^\d]/', '', $_GET['phone'] ?? '');
if (!$phone) json_response(['error' => 'phone required'], 400);

$token = amo_get_token();
if (!$token) json_response(['error' => 'amoCRM auth failed'], 500);

$data = amo_api('GET', '/api/v4/contacts?query=' . urlencode($phone), null, $token);
if (!$data || empty($data['_embedded']['contacts'])) json_response(['error' => 'not found'], 404);

foreach ($data['_embedded']['contacts'] as $c) {
    $refCode = get_contact_field($c, CF_REF_CODE);
    if ($refCode) {
        json_response(['status' => 'ok', 'ref_code' => $refCode, 'contact_id' => $c['id'], 'name' => $c['name'] ?? '']);
    }
}
json_response(['error' => 'not found'], 404);

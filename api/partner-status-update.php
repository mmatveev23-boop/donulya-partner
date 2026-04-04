<?php
/** POST /api/partner-status-update.php — update partner status in amoCRM */
require_once __DIR__ . '/amo-helper.php';
cors_headers();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'POST only'], 405);

$data = get_json_input();
$refCode = $data['ref_code'] ?? '';
$status = $data['status'] ?? '';

if (!$refCode || !$status) json_response(['error' => 'ref_code and status required'], 400);

$statusMap = [
    'new' => PS_NEW, 'training' => PS_TRAINING, 'trained' => PS_TRAINED,
    'active' => PS_ACTIVE, 'pro' => PS_PRO, 'top' => PS_TOP, 'inactive' => PS_INACTIVE
];
if (!isset($statusMap[$status])) json_response(['error' => 'Invalid status'], 400);

$token = amo_get_token();
if (!$token) json_response(['error' => 'amoCRM auth failed'], 500);

$partner = find_partner_lead($token, $refCode);
if (!$partner) json_response(['error' => 'not found'], 404);

amo_api('PATCH', '/api/v4/leads/' . $partner['lead']['id'], ['status_id' => $statusMap[$status]], $token);

json_response(['status' => 'ok', 'lead_id' => $partner['lead']['id'], 'new_status' => $status]);

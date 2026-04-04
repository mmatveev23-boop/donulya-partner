<?php
/** POST /api/lead.php — create lead from ref.html → amoCRM */
require_once __DIR__ . '/amo-helper.php';
cors_headers();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'POST only'], 405);

$data = get_json_input();
$phone = preg_replace('/[^\d+]/', '', $data['phone'] ?? '');
$name = $data['name'] ?? '';
$ref = $data['ref'] ?? '';

$token = amo_get_token();
if (!$token) json_response(['error' => 'amoCRM auth failed'], 500);

// Custom fields
$cf = [
    ['field_id' => CF_LEAD_REF_CODE, 'values' => [['value' => $ref]]],
    ['field_id' => CF_LEAD_CHANNEL, 'values' => [['enum_id' => CHANNELS['link']]]],
    ['field_id' => CF_LEAD_PAYOUT_STATUS, 'values' => [['enum_id' => 1651135]]], // Не выплачено
];
if (!empty($data['debt']) && isset(DEBTS[$data['debt']])) {
    $cf[] = ['field_id' => CF_LEAD_DEBT, 'values' => [['enum_id' => DEBTS[$data['debt']]]]];
}
if (!empty($data['messenger']) && isset(MESSENGERS_ENUM[$data['messenger']])) {
    $cf[] = ['field_id' => CF_LEAD_MESSENGER, 'values' => [['enum_id' => MESSENGERS_ENUM[$data['messenger']]]]];
}

// Create lead + contact
$payload = [[
    'name' => ($name ?: 'Лид') . ' (партнёрка)',
    'pipeline_id' => LEAD_PIPELINE,
    'status_id' => LS_NEW,
    'custom_fields_values' => $cf,
    '_embedded' => ['contacts' => [[
        'first_name' => $name,
        'custom_fields_values' => [
            ['field_id' => CF_PHONE, 'values' => [['value' => $phone, 'enum_code' => 'MOB']]]
        ]
    ]]]
]];

$result = amo_api('POST', '/api/v4/leads/complex', $payload, $token);
$leadId = $result[0]['id'] ?? '';

// Task for manager
if ($leadId) {
    amo_api('POST', '/api/v4/tasks', [[
        'text' => "Позвонить клиенту (партнёрский лид от $ref). Имя: $name, тел: $phone",
        'complete_till' => time() + 1800,
        'entity_id' => (int)$leadId,
        'entity_type' => 'leads',
        'task_type_id' => 1,
        'responsible_user_id' => MANAGER_ID
    ]], $token);
}

// Update partner: leads +1, move to Active, notify
if ($leadId && $ref) {
    $partner = find_partner_by_ref($token, $ref);
    if ($partner) {
        $currentLeads = (int)get_contact_field($partner, CF_TOTAL_LEADS) + 1;
        amo_api('PATCH', '/api/v4/contacts/' . $partner['id'], [
            'custom_fields_values' => [
                ['field_id' => CF_TOTAL_LEADS, 'values' => [['value' => $currentLeads]]]
            ]
        ], $token);

        // Move to Active
        $partnerLead = find_partner_lead($token, $ref);
        if ($partnerLead) {
            $activeStatuses = [PS_ACTIVE, PS_PRO, PS_TOP];
            if (!in_array($partnerLead['lead']['status_id'], $activeStatuses)) {
                amo_api('PATCH', '/api/v4/leads/' . $partnerLead['lead']['id'], ['status_id' => PS_ACTIVE], $token);
            }
        }

        // Notify
        $messengerId = get_contact_field($partner, CF_MESSENGER_ID);
        salebot_notify($messengerId, "🔔 Новый лид по вашей ссылке!\n\nИмя: $name\nСтатус: Юрист начал работу\n\nВсего лидов: $currentLeads");
    }
}

json_response(['status' => $leadId ? 'ok' : 'error', 'lead_id' => $leadId]);

<?php
/** POST /api/amo-webhook-handler.php — amoCRM calls this when lead status changes */
require_once __DIR__ . '/amo-helper.php';

// amoCRM sends form-encoded data
$statusUpdates = $_POST['leads']['status'] ?? [];
if (empty($statusUpdates)) { echo json_encode(['status' => 'ok', 'message' => 'no updates']); exit; }

$token = amo_get_token();
if (!$token) { http_response_code(500); echo 'auth failed'; exit; }

$results = [];

foreach ($statusUpdates as $update) {
    $leadId = $update['id'] ?? '';
    $newStatus = (int)($update['status_id'] ?? 0);
    $pipelineId = (int)($update['pipeline_id'] ?? 0);

    if ($pipelineId !== LEAD_PIPELINE) continue;

    // Get lead details
    $lead = amo_api('GET', "/api/v4/leads/$leadId", null, $token);
    if (!$lead) continue;

    $refCode = '';
    foreach ($lead['custom_fields_values'] ?? [] as $f) {
        if ($f['field_id'] == CF_LEAD_REF_CODE) { $refCode = $f['values'][0]['value'] ?? ''; break; }
    }
    if (!$refCode) continue;

    $leadName = $lead['name'] ?? 'Клиент';

    // === DEAL CLOSED ===
    if ($newStatus === LS_DEAL_CLOSED) {
        $partner = find_partner_by_ref($token, $refCode);
        if (!$partner) continue;

        $totalDeals = (int)get_contact_field($partner, CF_TOTAL_DEALS) + 1;
        $balance = (int)get_contact_field($partner, CF_BALANCE) + REWARD_PER_DEAL;
        $messengerId = get_contact_field($partner, CF_MESSENGER_ID);

        // Level
        $levelId = LEVELS['novice'];
        if ($totalDeals >= 25) $levelId = LEVELS['legend'];
        elseif ($totalDeals >= 10) $levelId = LEVELS['top'];
        elseif ($totalDeals >= 3) $levelId = LEVELS['pro'];

        // Update contact
        amo_api('PATCH', '/api/v4/contacts/' . $partner['id'], [
            'custom_fields_values' => [
                ['field_id' => CF_TOTAL_DEALS, 'values' => [['value' => $totalDeals]]],
                ['field_id' => CF_BALANCE, 'values' => [['value' => $balance]]],
                ['field_id' => CF_LEVEL, 'values' => [['enum_id' => $levelId]]],
            ]
        ], $token);

        // Move partner lead
        $partnerData = find_partner_lead($token, $refCode);
        if ($partnerData) {
            $newPartnerStatus = PS_ACTIVE;
            if ($totalDeals >= 10) $newPartnerStatus = PS_TOP;
            elseif ($totalDeals >= 3) $newPartnerStatus = PS_PRO;
            amo_api('PATCH', '/api/v4/leads/' . $partnerData['lead']['id'], ['status_id' => $newPartnerStatus], $token);
        }

        // Notify
        salebot_notify($messengerId, "🎉 Договор заключён!\n\nКлиент: $leadName\nВознаграждение: +" . number_format(REWARD_PER_DEAL, 0, '', ' ') . " ₽\n\nВаш баланс: " . number_format($balance, 0, '', ' ') . " ₽\nДоговоров: $totalDeals");
        $results[] = ['lead_id' => $leadId, 'action' => 'deal_closed', 'deals' => $totalDeals];
    }

    // === REFUSED ===
    if ($newStatus === LS_REFUSED) {
        $partner = find_partner_by_ref($token, $refCode);
        if (!$partner) continue;
        $messengerId = get_contact_field($partner, CF_MESSENGER_ID);
        salebot_notify($messengerId, "ℹ️ Клиент $leadName — не подошёл.\n\nНе расстраивайтесь — продолжайте рекомендовать!\nЗа каждый договор вы получаете " . number_format(REWARD_PER_DEAL, 0, '', ' ') . " ₽");
        $results[] = ['lead_id' => $leadId, 'action' => 'refused'];
    }
}

echo json_encode(['status' => 'ok', 'processed' => count($results), 'results' => $results]);

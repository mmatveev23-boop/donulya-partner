// Vercel Serverless Function: ref.html → amoCRM API
// POST /api/lead — creates lead in amoCRM pipeline "Партнёрские лиды"

const AMO_DOMAIN = 'donulya2.amocrm.ru';
const AMO_CLIENT_ID = 'f0e074e7-7685-48bd-bac9-306c674ca450';
const AMO_CLIENT_SECRET = process.env.AMO_CLIENT_SECRET;
const AMO_REDIRECT = 'https://mmatveev23-boop.github.io/donulya-partner/';
const PIPELINE_ID = 10777002;
const STATUS_NEW = 84857102;

const SALEBOT_API_KEY = process.env.SALEBOT_API_KEY || '15934d777a5f183b3b0389f48b8829d8';
const SALEBOT_API_BASE = 'https://chatter.salebot.pro/api/' + SALEBOT_API_KEY;
const CF_MESSENGER_ID = 1700965;
const CF_PARTNER_REF = 1700959;

// Custom field IDs
const CF = {
  refCode: 1700947,
  channel: 1700949,
  debt: 1700951,
  messenger: 1700953,
  payoutStatus: 1700955
};

const CHANNELS = { 'ссылка': 1651115, 'прямой': 1651117 };
const DEBTS = { '250-500': 1651119, '500-1000': 1651121, '1000-3000': 1651123, '3000+': 1651125 };
const MESSENGERS = { 'telegram': 1651127, 'vk': 1651129, 'max': 1651131, 'phone': 1651133 };

// Token management via Vercel KV (env variables as fallback)
let cachedToken = null;
let cachedRefresh = null;

async function getTokens() {
  if (cachedToken) return { access: cachedToken, refresh: cachedRefresh };
  return {
    access: process.env.AMO_ACCESS_TOKEN || '',
    refresh: process.env.AMO_REFRESH_TOKEN || ''
  };
}

async function refreshToken(refresh) {
  const resp = await fetch(`https://${AMO_DOMAIN}/oauth2/access_token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      client_id: AMO_CLIENT_ID,
      client_secret: AMO_CLIENT_SECRET,
      grant_type: 'refresh_token',
      refresh_token: refresh,
      redirect_uri: AMO_REDIRECT
    })
  });
  const data = await resp.json();
  if (data.access_token) {
    cachedToken = data.access_token;
    cachedRefresh = data.refresh_token;
    return data.access_token;
  }
  return null;
}

async function getAccessToken() {
  const tokens = await getTokens();
  // Try existing token
  const test = await fetch(`https://${AMO_DOMAIN}/api/v4/account`, {
    headers: { 'Authorization': 'Bearer ' + tokens.access }
  });
  if (test.ok) {
    cachedToken = tokens.access;
    cachedRefresh = tokens.refresh;
    return tokens.access;
  }
  // Refresh
  return await refreshToken(tokens.refresh);
}

module.exports = async function handler(req, res) {
  // CORS
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Method not allowed' });

  try {
    const data = req.body;
    const phone = (data.phone || '').replace(/[^\d+]/g, '');

    const token = await getAccessToken();
    if (!token) return res.status(500).json({ error: 'amoCRM auth failed' });

    // Build custom fields
    const customFields = [
      { field_id: CF.refCode, values: [{ value: data.ref || '' }] },
      { field_id: CF.channel, values: [{ enum_id: CHANNELS['ссылка'] }] },
      { field_id: CF.payoutStatus, values: [{ enum_id: 1651135 }] } // Не выплачено
    ];

    if (data.debt && DEBTS[data.debt]) {
      customFields.push({ field_id: CF.debt, values: [{ enum_id: DEBTS[data.debt] }] });
    }
    if (data.messenger && MESSENGERS[data.messenger]) {
      customFields.push({ field_id: CF.messenger, values: [{ enum_id: MESSENGERS[data.messenger] }] });
    }

    // Create lead + contact
    const payload = [{
      name: (data.name || 'Лид') + ' (партнёрка)',
      pipeline_id: PIPELINE_ID,
      status_id: STATUS_NEW,
      custom_fields_values: customFields,
      _embedded: {
        contacts: [{
          first_name: data.name || '',
          custom_fields_values: [
            { field_id: 1596487, values: [{ value: phone, enum_code: 'WORK' }] }
          ]
        }]
      }
    }];

    const amoResp = await fetch(`https://${AMO_DOMAIN}/api/v4/leads/complex`, {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    const resultText = await amoResp.text();
    let leadId = '';
    try {
      const result = JSON.parse(resultText);
      leadId = result[0]?.id || '';
    } catch(e) {}

    // Create task for manager: "Позвонить клиенту за 30 минут"
    if (leadId) {
      try {
        const taskDue = Math.floor(Date.now() / 1000) + 1800; // +30 min
        await fetch(`https://${AMO_DOMAIN}/api/v4/tasks`, {
          method: 'POST',
          headers: {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'},
          body: JSON.stringify([{
            text: 'Позвонить клиенту (партнёрский лид от ' + (data.ref || 'прямой') + '). Имя: ' + (data.name || '') + ', тел: ' + phone,
            complete_till: taskDue,
            entity_id: leadId,
            entity_type: 'leads',
            task_type_id: 1, // Звонок
            responsible_user_id: 1702497 // Лесников Евгений
          }])
        });
      } catch(taskErr) {
        console.log('Task creation error:', taskErr.message);
      }
    }

    // Update partner: move to Active + increment lead counter
    if (leadId && data.ref) {
      try {
        await updatePartnerOnNewLead(token, data.ref, data.name);
      } catch(partnerErr) {
        console.log('Partner update error:', partnerErr.message);
      }
    }

    return res.status(200).json({ status: leadId ? 'ok' : 'amo_error', lead_id: leadId });

  } catch (err) {
    return res.status(500).json({ status: 'error', message: err.message });
  }
}

// Find partner contact by ref_code and update counters + status
async function updatePartnerOnNewLead(token, refCode, leadName) {
  const PARTNER_PIPELINE = 10776994;
  const STATUS_ACTIVE = 84857062;
  const CF_REF_CODE = 1700959;
  const CF_TOTAL_LEADS = 1700969;

  // Search all leads in partner pipeline
  const resp = await fetch(`https://${AMO_DOMAIN}/api/v4/leads?filter[pipeline_id]=${PARTNER_PIPELINE}&with=contacts&limit=250`, {
    headers: {'Authorization': 'Bearer ' + token}
  });
  if (!resp.ok) return;
  const data = await resp.json();
  const leads = data?._embedded?.leads || [];

  for (const lead of leads) {
    const contactId = lead._embedded?.contacts?.[0]?.id;
    if (!contactId) continue;

    const cResp = await fetch(`https://${AMO_DOMAIN}/api/v4/contacts/${contactId}`, {
      headers: {'Authorization': 'Bearer ' + token}
    });
    if (!cResp.ok) continue;
    const contact = await cResp.json();
    const refField = (contact.custom_fields_values || []).find(f => f.field_id === CF_REF_CODE);
    if (!refField || refField.values[0]?.value !== refCode) continue;

    // Found partner! Update lead counter
    const leadsField = (contact.custom_fields_values || []).find(f => f.field_id === CF_TOTAL_LEADS);
    const currentLeads = parseInt(leadsField?.values?.[0]?.value || '0') + 1;

    // Update contact: increment leads
    await fetch(`https://${AMO_DOMAIN}/api/v4/contacts/${contactId}`, {
      method: 'PATCH',
      headers: {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'},
      body: JSON.stringify({ custom_fields_values: [
        { field_id: CF_TOTAL_LEADS, values: [{ value: currentLeads }] }
      ]})
    });

    // Move partner to Active (if not already Active/Pro/Top)
    const activeStatuses = [84857062, 84857066, 84857070]; // Active, Pro, Top
    if (!activeStatuses.includes(lead.status_id)) {
      await fetch(`https://${AMO_DOMAIN}/api/v4/leads/${lead.id}`, {
        method: 'PATCH',
        headers: {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'},
        body: JSON.stringify({ status_id: STATUS_ACTIVE })
      });
    }

    // Notify partner via Salebot
    const messengerIdField = (contact.custom_fields_values || []).find(f => f.field_id === CF_MESSENGER_ID);
    const messengerId = messengerIdField?.values?.[0]?.value || '';
    if (messengerId) {
      try {
        await fetch(SALEBOT_API_BASE + '/message', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            client_id: messengerId,
            message: '🔔 Новый лид по вашей ссылке!\n\nИмя: ' + (leadName || 'Не указано') + '\nСтатус: Юрист начал работу\n\nВсего лидов: ' + currentLeads
          })
        });
      } catch(e) {}
    }
    break;
  }
}

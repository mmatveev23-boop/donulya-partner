// Vercel Serverless: update partner status in amoCRM pipeline
// POST /api/partner-status
// Body: { ref_code, status: "training" | "trained" | "active" }

const AMO_DOMAIN = 'donulya2.amocrm.ru';
const AMO_CLIENT_ID = 'f0e074e7-7685-48bd-bac9-306c674ca450';
const AMO_CLIENT_SECRET = process.env.AMO_CLIENT_SECRET;
const AMO_REDIRECT = 'https://mmatveev23-boop.github.io/donulya-partner/';

// Partner pipeline statuses
const PARTNER_PIPELINE = 10776994;
const PARTNER_STATUSES = {
  'new':      84857050,  // Новый партнёр
  'training': 84857054,  // На обучении
  'trained':  84857058,  // Обучение пройдено
  'active':   84857062,  // Активный
  'pro':      84857066,  // Профи (3+)
  'top':      84857070,  // Топ (10+)
  'inactive': 84857074,  // Неактивный
};

// Contact custom fields
const CF_REF_CODE = 1700959;
const CF_TOTAL_LEADS = 1700969;
const CF_TOTAL_DEALS = 1700971;
const CF_BALANCE = 1700973;
const CF_LEVEL = 1700967;
const LEVELS = { novice: 1651145, pro: 1651147, top: 1651149, legend: 1651151 };

// Token management
let cachedToken = null, cachedRefresh = null;

async function refreshToken(refresh) {
  const resp = await fetch(`https://${AMO_DOMAIN}/oauth2/access_token`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ client_id: AMO_CLIENT_ID, client_secret: AMO_CLIENT_SECRET,
      grant_type: 'refresh_token', refresh_token: refresh, redirect_uri: AMO_REDIRECT })
  });
  const data = await resp.json();
  if (data.access_token) { cachedToken = data.access_token; cachedRefresh = data.refresh_token; return data.access_token; }
  return null;
}

async function getAccessToken() {
  const tokens = { access: cachedToken || process.env.AMO_ACCESS_TOKEN || '', refresh: cachedRefresh || process.env.AMO_REFRESH_TOKEN || '' };
  const test = await fetch(`https://${AMO_DOMAIN}/api/v4/account`, { headers: {'Authorization': 'Bearer ' + tokens.access} });
  if (test.ok) { cachedToken = tokens.access; cachedRefresh = tokens.refresh; return tokens.access; }
  return await refreshToken(tokens.refresh);
}

// Find partner lead by ref_code
async function findPartnerLead(token, refCode) {
  // Search leads in partner pipeline by ref_code custom field
  const resp = await fetch(`https://${AMO_DOMAIN}/api/v4/leads?filter[pipeline_id]=${PARTNER_PIPELINE}&with=contacts`, {
    headers: {'Authorization': 'Bearer ' + token}
  });
  if (!resp.ok) return null;
  const data = await resp.json();
  const leads = data?._embedded?.leads || [];

  // Need to check each lead's contact for ref_code
  for (const lead of leads) {
    const contactId = lead._embedded?.contacts?.[0]?.id;
    if (!contactId) continue;
    const cResp = await fetch(`https://${AMO_DOMAIN}/api/v4/contacts/${contactId}`, {
      headers: {'Authorization': 'Bearer ' + token}
    });
    if (!cResp.ok) continue;
    const contact = await cResp.json();
    const refField = (contact.custom_fields_values || []).find(f => f.field_id === CF_REF_CODE);
    if (refField && refField.values[0]?.value === refCode) {
      return { lead_id: lead.id, contact_id: contactId, contact };
    }
  }
  return null;
}

// Update lead status
async function updateLeadStatus(token, leadId, statusId) {
  await fetch(`https://${AMO_DOMAIN}/api/v4/leads/${leadId}`, {
    method: 'PATCH',
    headers: {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'},
    body: JSON.stringify({ status_id: statusId })
  });
}

// Update contact fields
async function updateContactFields(token, contactId, fields) {
  await fetch(`https://${AMO_DOMAIN}/api/v4/contacts/${contactId}`, {
    method: 'PATCH',
    headers: {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'},
    body: JSON.stringify({ custom_fields_values: fields })
  });
}

module.exports = async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Method not allowed' });

  try {
    const { ref_code, status } = req.body;
    if (!ref_code || !status) return res.status(400).json({ error: 'ref_code and status required' });

    const statusId = PARTNER_STATUSES[status];
    if (!statusId) return res.status(400).json({ error: 'Invalid status: ' + status });

    const token = await getAccessToken();
    if (!token) return res.status(500).json({ error: 'amoCRM auth failed' });

    const partner = await findPartnerLead(token, ref_code);
    if (!partner) return res.status(404).json({ error: 'Partner not found: ' + ref_code });

    // Update lead status in pipeline
    await updateLeadStatus(token, partner.lead_id, statusId);

    return res.status(200).json({ status: 'ok', lead_id: partner.lead_id, new_status: status });

  } catch (err) {
    return res.status(500).json({ status: 'error', message: err.message });
  }
};

// Exported for use by other API functions
module.exports.findPartnerLead = findPartnerLead;
module.exports.updateLeadStatus = updateLeadStatus;
module.exports.updateContactFields = updateContactFields;
module.exports.getAccessToken = getAccessToken;
module.exports.PARTNER_STATUSES = PARTNER_STATUSES;
module.exports.CF_TOTAL_LEADS = CF_TOTAL_LEADS;
module.exports.CF_TOTAL_DEALS = CF_TOTAL_DEALS;
module.exports.CF_BALANCE = CF_BALANCE;
module.exports.CF_LEVEL = CF_LEVEL;
module.exports.LEVELS = LEVELS;

// Vercel Serverless: amoCRM webhook handler
// Receives webhooks when lead status changes in "Партнёрские лиды"
// Triggers: deal closed → update partner counters + notify via Salebot

const AMO_DOMAIN = 'donulya2.amocrm.ru';
const AMO_CLIENT_ID = 'f0e074e7-7685-48bd-bac9-306c674ca450';
const AMO_CLIENT_SECRET = process.env.AMO_CLIENT_SECRET;
const AMO_REDIRECT = 'https://mmatveev23-boop.github.io/donulya-partner/';

const SALEBOT_API_KEY = process.env.SALEBOT_API_KEY || '15934d777a5f183b3b0389f48b8829d8';
const SALEBOT_API_BASE = 'https://chatter.salebot.pro/api/' + SALEBOT_API_KEY;

// Pipeline & status IDs
const LEAD_PIPELINE = 10777002;
const STATUS_DEAL_CLOSED = 84857122;   // Договор заключён
const STATUS_PAYOUT = 84857126;         // Выплата партнёру
const STATUS_REFUSED = 84857130;        // Отказ

const PARTNER_PIPELINE = 10776994;
const PARTNER_STATUS_ACTIVE = 84857062;
const PARTNER_STATUS_PRO = 84857066;
const PARTNER_STATUS_TOP = 84857070;

// Contact fields
const CF_REF_CODE = 1700947;       // lead field: ref-код партнёра
const CF_PARTNER_REF = 1700959;    // contact field: ref-код
const CF_TOTAL_LEADS = 1700969;
const CF_TOTAL_DEALS = 1700971;
const CF_BALANCE = 1700973;
const CF_LEVEL = 1700967;
const CF_MESSENGER_ID = 1700965;
const LEVELS = { novice: 1651145, pro: 1651147, top: 1651149, legend: 1651151 };

const REWARD_PER_DEAL = 10000;

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

// Send message to partner via Salebot
async function notifyPartner(platformId, message) {
  if (!platformId) return;
  try {
    await fetch(SALEBOT_API_BASE + '/message', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        client_id: platformId,
        message: message
      })
    });
  } catch(e) { console.log('Salebot notify error:', e.message); }
}

// Find partner by ref_code, return contact data
async function findPartnerByRefCode(token, refCode) {
  const resp = await fetch(`https://${AMO_DOMAIN}/api/v4/contacts?query=${encodeURIComponent(refCode)}`, {
    headers: {'Authorization': 'Bearer ' + token}
  });
  if (!resp.ok) return null;
  const data = await resp.json();
  const contacts = data?._embedded?.contacts || [];
  for (const c of contacts) {
    const refField = (c.custom_fields_values || []).find(f => f.field_id === CF_PARTNER_REF);
    if (refField && refField.values[0]?.value === refCode) return c;
  }
  return null;
}

// Find partner lead in pipeline
async function findPartnerLead(token, refCode) {
  const resp = await fetch(`https://${AMO_DOMAIN}/api/v4/leads?filter[pipeline_id]=${PARTNER_PIPELINE}&with=contacts&limit=250`, {
    headers: {'Authorization': 'Bearer ' + token}
  });
  if (!resp.ok) return null;
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
    const refField = (contact.custom_fields_values || []).find(f => f.field_id === CF_PARTNER_REF);
    if (refField && refField.values[0]?.value === refCode) return { lead, contact };
  }
  return null;
}

module.exports = async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'POST only' });

  try {
    // amoCRM sends form-encoded webhook data
    const body = req.body;

    // Parse amoCRM webhook format
    // leads[status][0][id], leads[status][0][status_id], leads[status][0][pipeline_id]
    const statusUpdates = body?.leads?.status || [];
    if (!statusUpdates.length) return res.status(200).json({ status: 'ok', message: 'no status updates' });

    const token = await getAccessToken();
    if (!token) return res.status(500).json({ error: 'amoCRM auth failed' });

    const results = [];

    for (const update of statusUpdates) {
      const leadId = update.id;
      const newStatusId = parseInt(update.status_id);
      const pipelineId = parseInt(update.pipeline_id);

      // Only process leads from "Партнёрские лиды" pipeline
      if (pipelineId !== LEAD_PIPELINE) continue;

      // Get lead details to find ref_code
      const leadResp = await fetch(`https://${AMO_DOMAIN}/api/v4/leads/${leadId}`, {
        headers: {'Authorization': 'Bearer ' + token}
      });
      if (!leadResp.ok) continue;
      const lead = await leadResp.json();

      const refCodeField = (lead.custom_fields_values || []).find(f => f.field_id === CF_REF_CODE);
      const refCode = refCodeField?.values?.[0]?.value || '';
      if (!refCode) continue;

      const leadName = lead.name || 'Клиент';

      // === DEAL CLOSED ===
      if (newStatusId === STATUS_DEAL_CLOSED) {
        const partner = await findPartnerByRefCode(token, refCode);
        if (!partner) continue;

        // Get current counters
        const getField = (id) => (partner.custom_fields_values || []).find(f => f.field_id === id);
        const totalDeals = parseInt(getField(CF_TOTAL_DEALS)?.values?.[0]?.value || '0') + 1;
        const balance = parseInt(getField(CF_BALANCE)?.values?.[0]?.value || '0') + REWARD_PER_DEAL;
        const messengerId = getField(CF_MESSENGER_ID)?.values?.[0]?.value || '';

        // Determine level
        let levelId = LEVELS.novice;
        if (totalDeals >= 25) levelId = LEVELS.legend;
        else if (totalDeals >= 10) levelId = LEVELS.top;
        else if (totalDeals >= 3) levelId = LEVELS.pro;

        // Update contact fields
        await fetch(`https://${AMO_DOMAIN}/api/v4/contacts/${partner.id}`, {
          method: 'PATCH',
          headers: {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'},
          body: JSON.stringify({ custom_fields_values: [
            { field_id: CF_TOTAL_DEALS, values: [{ value: totalDeals }] },
            { field_id: CF_BALANCE, values: [{ value: balance }] },
            { field_id: CF_LEVEL, values: [{ enum_id: levelId }] }
          ]})
        });

        // Update partner lead status (Pro/Top if threshold reached)
        const partnerData = await findPartnerLead(token, refCode);
        if (partnerData) {
          let newPartnerStatus = PARTNER_STATUS_ACTIVE;
          if (totalDeals >= 10) newPartnerStatus = PARTNER_STATUS_TOP;
          else if (totalDeals >= 3) newPartnerStatus = PARTNER_STATUS_PRO;

          await fetch(`https://${AMO_DOMAIN}/api/v4/leads/${partnerData.lead.id}`, {
            method: 'PATCH',
            headers: {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'},
            body: JSON.stringify({ status_id: newPartnerStatus })
          });
        }

        // Notify partner via Salebot
        await notifyPartner(messengerId,
          `🎉 Договор заключён!\n\nКлиент: ${leadName}\nВознаграждение: +${REWARD_PER_DEAL.toLocaleString()} ₽\n\nВаш баланс: ${balance.toLocaleString()} ₽\nДоговоров: ${totalDeals}`
        );

        results.push({ lead_id: leadId, action: 'deal_closed', deals: totalDeals, balance });
      }

      // === REFUSED ===
      if (newStatusId === STATUS_REFUSED) {
        const partner = await findPartnerByRefCode(token, refCode);
        if (!partner) continue;
        const messengerId = (partner.custom_fields_values || []).find(f => f.field_id === CF_MESSENGER_ID)?.values?.[0]?.value || '';

        await notifyPartner(messengerId,
          `ℹ️ Клиент ${leadName} — не подошёл.\n\nНе расстраивайтесь — продолжайте рекомендовать!\nЗа каждый договор вы получаете 10 000 ₽`
        );

        results.push({ lead_id: leadId, action: 'refused' });
      }
    }

    return res.status(200).json({ status: 'ok', processed: results.length, results });

  } catch (err) {
    return res.status(500).json({ status: 'error', message: err.message });
  }
};

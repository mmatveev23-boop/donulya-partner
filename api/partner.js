// Vercel Serverless: Salebot → create partner in amoCRM
// POST /api/partner — called by Salebot webhook after phone confirmation
//
// Body: { name, phone, platform (tg/vk/max), platform_id }
// Returns: { status, ref_code, ref_link, contact_id }

const AMO_DOMAIN = 'donulya2.amocrm.ru';
const AMO_CLIENT_ID = 'f0e074e7-7685-48bd-bac9-306c674ca450';
const AMO_CLIENT_SECRET = process.env.AMO_CLIENT_SECRET;
const AMO_REDIRECT = 'https://mmatveev23-boop.github.io/donulya-partner/';
const PIPELINE_ID = 10776994;         // Партнёры
const STATUS_NEW = 84857050;          // Новый партнёр

// Contact custom field IDs
const CF = {
  phone: 1596487,
  refCode: 1700959,
  refLink: 1700961,
  messenger: 1700963,
  messengerId: 1700965,
  level: 1700967,
  totalLeads: 1700969,
  totalDeals: 1700971,
  balance: 1700973
};

const MESSENGERS = { 'tg': 1651139, 'telegram': 1651139, 'vk': 1651141, 'max': 1651143 };
const LEVEL_NOVICE = 1651145;

// Generate ref code: first 4 letters of name (transliterated) + 3 random digits
function generateRefCode(name) {
  const translit = {
    'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'e','ж':'zh','з':'z','и':'i',
    'й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t',
    'у':'u','ф':'f','х':'h','ц':'c','ч':'ch','ш':'sh','щ':'sh','ъ':'','ы':'y','ь':'',
    'э':'e','ю':'yu','я':'ya'
  };
  let latin = '';
  for (const ch of (name || 'user').toLowerCase()) {
    latin += translit[ch] || ch;
  }
  latin = latin.replace(/[^a-z]/g, '').substring(0, 4).toUpperCase();
  if (latin.length < 2) latin = 'USER';
  const digits = String(Math.floor(Math.random() * 900) + 100);
  return latin + digits;
}

// Token management (same as lead.js)
let cachedToken = null;
let cachedRefresh = null;

async function getTokens() {
  if (cachedToken) return { access: cachedToken, refresh: cachedRefresh };
  return { access: process.env.AMO_ACCESS_TOKEN || '', refresh: process.env.AMO_REFRESH_TOKEN || '' };
}

async function refreshToken(refresh) {
  const resp = await fetch(`https://${AMO_DOMAIN}/oauth2/access_token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      client_id: AMO_CLIENT_ID, client_secret: AMO_CLIENT_SECRET,
      grant_type: 'refresh_token', refresh_token: refresh, redirect_uri: AMO_REDIRECT
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
  const test = await fetch(`https://${AMO_DOMAIN}/api/v4/account`, {
    headers: { 'Authorization': 'Bearer ' + tokens.access }
  });
  if (test.ok) { cachedToken = tokens.access; cachedRefresh = tokens.refresh; return tokens.access; }
  return await refreshToken(tokens.refresh);
}

module.exports = async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Method not allowed' });

  try {
    const data = req.body;
    const phone = (data.phone || '').replace(/[^\d+]/g, '');
    const name = data.name || '';
    const platform = (data.platform || '').toLowerCase();
    const platformId = data.platform_id || '';

    if (!phone || !name) {
      return res.status(400).json({ error: 'name and phone required' });
    }

    const token = await getAccessToken();
    if (!token) return res.status(500).json({ error: 'amoCRM auth failed' });

    // Generate unique ref code (check amoCRM for duplicates)
    let refCode = generateRefCode(name);
    for (let attempt = 0; attempt < 5; attempt++) {
      try {
        const checkResp = await fetch(`https://${AMO_DOMAIN}/api/v4/contacts?query=${refCode}`, {
          headers: {'Authorization': 'Bearer ' + token}
        });
        if (checkResp.status === 204 || !checkResp.ok) break; // no results = unique
        const checkText = await checkResp.text();
        if (!checkText) break;
        const checkData = JSON.parse(checkText);
        const exists = (checkData?._embedded?.contacts || []).some(c =>
          (c.custom_fields_values || []).some(f => f.field_id === CF.refCode && f.values[0]?.value === refCode)
        );
        if (!exists) break;
      } catch(e) { break; }
      refCode = generateRefCode(name);
    }
    const refLink = 'https://mmatveev23-boop.github.io/donulya-partner/ref.html?ref=' + refCode;

    // Build contact custom fields
    const contactFields = [
      { field_id: CF.phone, values: [{ value: phone, enum_code: 'MOB' }] },
      { field_id: CF.refCode, values: [{ value: refCode }] },
      { field_id: CF.refLink, values: [{ value: refLink }] },
      { field_id: CF.messengerId, values: [{ value: platformId }] },
      { field_id: CF.level, values: [{ enum_id: LEVEL_NOVICE }] },
      { field_id: CF.totalLeads, values: [{ value: 0 }] },
      { field_id: CF.totalDeals, values: [{ value: 0 }] },
      { field_id: CF.balance, values: [{ value: 0 }] }
    ];

    if (platform && MESSENGERS[platform]) {
      contactFields.push({ field_id: CF.messenger, values: [{ enum_id: MESSENGERS[platform] }] });
    }

    // Create contact
    const contactResp = await fetch(`https://${AMO_DOMAIN}/api/v4/contacts`, {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
      body: JSON.stringify([{ first_name: name, custom_fields_values: contactFields }])
    });
    const contactResult = await contactResp.json();
    const contactId = contactResult?._embedded?.contacts?.[0]?.id || '';

    // Create lead in Partners pipeline
    if (contactId) {
      await fetch(`https://${AMO_DOMAIN}/api/v4/leads`, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
        body: JSON.stringify([{
          name: name + ' (партнёр)',
          pipeline_id: PIPELINE_ID,
          status_id: STATUS_NEW,
          _embedded: { contacts: [{ id: contactId }] }
        }])
      });
    }

    return res.status(200).json({
      status: 'ok',
      contact_id: contactId,
      ref_code: refCode,
      ref_link: refLink
    });

  } catch (err) {
    return res.status(500).json({ status: 'error', message: err.message });
  }
};

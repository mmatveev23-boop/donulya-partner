// Vercel Serverless: find partner by phone, return ref_code
// GET /api/partner-lookup?phone=79991234567

const AMO_DOMAIN = 'donulya2.amocrm.ru';
const AMO_CLIENT_ID = 'f0e074e7-7685-48bd-bac9-306c674ca450';
const AMO_CLIENT_SECRET = process.env.AMO_CLIENT_SECRET;
const AMO_REDIRECT = 'https://mmatveev23-boop.github.io/donulya-partner/';
const CF_REF_CODE = 1700959;

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

module.exports = async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();

  try {
    const phone = (req.query.phone || '').replace(/[^\d]/g, '');
    if (!phone) return res.status(400).json({ error: 'phone required' });

    const token = await getAccessToken();
    if (!token) return res.status(500).json({ error: 'amoCRM auth failed' });

    // Search contact by phone
    const resp = await fetch(`https://${AMO_DOMAIN}/api/v4/contacts?query=${phone}`, {
      headers: {'Authorization': 'Bearer ' + token}
    });
    if (!resp.ok) return res.status(404).json({ error: 'not found' });

    const data = await resp.json();
    const contacts = data?._embedded?.contacts || [];

    for (const contact of contacts) {
      const refField = (contact.custom_fields_values || []).find(f => f.field_id === CF_REF_CODE);
      if (refField && refField.values[0]?.value) {
        return res.status(200).json({
          status: 'ok',
          ref_code: refField.values[0].value,
          contact_id: contact.id,
          name: contact.name
        });
      }
    }

    return res.status(404).json({ error: 'partner not found for phone ' + phone });

  } catch (err) {
    return res.status(500).json({ status: 'error', message: err.message });
  }
};

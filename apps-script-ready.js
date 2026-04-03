// === ДОНУЛЯ ПАРТНЁРКА — Google Apps Script ===
// Webhook: ref.html → Google Sheet + amoCRM
//
// После вставки кода:
// 1. Сохрани (Cmd+S)
// 2. Начать развёртывание → Управление → Редактировать → Новая версия → Развернуть

// === amoCRM CONFIG ===
var AMO_DOMAIN = 'donulya2.amocrm.ru';
var AMO_CLIENT_ID = 'f0e074e7-7685-48bd-bac9-306c674ca450';
var AMO_CLIENT_SECRET = '4WxXbQidSKHPGKDz6zuwmekAwuCi6tPwCgiVus9kcgSwy1zi4hVnkkG1ihEgJaGm';
var AMO_REDIRECT = 'https://mmatveev23-boop.github.io/donulya-partner/';
var AMO_PIPELINE_ID = 10777002;       // Партнёрские лиды
var AMO_STATUS_NEW = 84857102;        // Новый лид

// Custom field IDs
var CF_REF_CODE = 1700947;            // Ref-код партнёра (text)
var CF_CHANNEL = 1700949;             // Канал привлечения (select)
var CF_DEBT = 1700951;                // Сумма долга (select)
var CF_MESSENGER = 1700953;           // Мессенджер клиента (select)
var CF_PAYOUT_STATUS = 1700955;       // Статус выплаты (select)

// Enum IDs
var CHANNELS = {'ссылка': 1651115, 'прямой': 1651117};
var DEBTS = {'250-500': 1651119, '500-1000': 1651121, '1000-3000': 1651123, '3000+': 1651125};
var MESSENGERS = {'telegram': 1651127, 'vk': 1651129, 'max': 1651131, 'phone': 1651133};

// === TOKEN MANAGEMENT ===
function getTokens() {
  var props = PropertiesService.getScriptProperties();
  return {
    access: props.getProperty('amo_access_token'),
    refresh: props.getProperty('amo_refresh_token')
  };
}

function saveTokens(access, refresh) {
  var props = PropertiesService.getScriptProperties();
  props.setProperty('amo_access_token', access);
  props.setProperty('amo_refresh_token', refresh);
}

function refreshToken() {
  var tokens = getTokens();
  if (!tokens.refresh) return null;

  var resp = UrlFetchApp.fetch('https://' + AMO_DOMAIN + '/oauth2/access_token', {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify({
      client_id: AMO_CLIENT_ID,
      client_secret: AMO_CLIENT_SECRET,
      grant_type: 'refresh_token',
      refresh_token: tokens.refresh,
      redirect_uri: AMO_REDIRECT
    }),
    muteHttpExceptions: true
  });

  var data = JSON.parse(resp.getContentText());
  if (data.access_token) {
    saveTokens(data.access_token, data.refresh_token);
    return data.access_token;
  }
  return null;
}

function getAccessToken() {
  var tokens = getTokens();
  if (tokens.access) {
    // Try existing token
    var test = UrlFetchApp.fetch('https://' + AMO_DOMAIN + '/api/v4/account', {
      headers: {'Authorization': 'Bearer ' + tokens.access},
      muteHttpExceptions: true
    });
    if (test.getResponseCode() === 200) return tokens.access;
  }
  // Refresh
  return refreshToken();
}

// Run once to set initial tokens
function initTokens() {
  var props = PropertiesService.getScriptProperties();
  // Paste current tokens here and run once
  props.setProperty('amo_access_token', 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6IjgyMTlkZGM5ZWFmOTQ2MDU3ZTk5ZmI5ODJiNTRmNmI3NjJiOWQ1NGU2OTgwNzM4N2UwZDA1MzQ1MTk2ZGM0MjA0YjA1YTA1NzYzZjhkMGIxIn0.eyJhdWQiOiJmMGUwNzRlNy03Njg1LTQ4YmQtYmFjOS0zMDZjNjc0Y2E0NTAiLCJqdGkiOiI4MjE5ZGRjOWVhZjk0NjA1N2U5OWZiOTgyYjU0ZjZiNzYyYjlkNTRlNjk4MDczODdlMGQwNTM0NTE5NmRjNDIwNGIwNWEwNTc2M2Y4ZDBiMSIsImlhdCI6MTc3NTI0NjgxNCwibmJmIjoxNzc1MjQ2ODE0LCJleHAiOjE3NzUzMzMyMTQsInN1YiI6IjE3MDI0OTciLCJncmFudF90eXBlIjoiIiwiYWNjb3VudF9pZCI6MzI5MjQ5MjIsImJhc2VfZG9tYWluIjoiYW1vY3JtLnJ1IiwidmVyc2lvbiI6Miwic2NvcGVzIjpbInB1c2hfbm90aWZpY2F0aW9ucyIsImZpbGVzIiwiY3JtIiwiZmlsZXNfZGVsZXRlIiwibm90aWZpY2F0aW9ucyJdLCJoYXNoX3V1aWQiOiI0MjI2NzMzZi0xNDFiLTRlZWYtYmNiYy04MDIyNGVhOTg5ZWMiLCJhcGlfZG9tYWluIjoiYXBpLWIuYW1vY3JtLnJ1In0.kbbKD74muHVqnsuaQkj9UpwZuefVWBb00P-XicvMkTKi8vZwxl1EMiALKCROWF6Ot8OfAkWy3Hj7oLjr5ANymKrQEfAR4_tlUPof5hIioJK8Z2FOpH1REmKgHmmdKeItKdDl4xeiyeA8-zcMXDuLfojI3SoAVXNBpdFunJXahOWLmIGq2UFn83upffMgQD6-p_solAskxfJBI7SokeGlKEODf2gPDkl4k1jbe8gMk3Q0k76YBYWkH6gTe_Rop_QfjmW8oBPDn3NaEx1k_rzh2-tUxz2x8Tu4XTTYslTSjiVf5HRtEwMV5GWN7pGtMy6kdQhTOXKIg7D466CKIr-u-A');
  props.setProperty('amo_refresh_token', 'def502007956a67647557c8cc2c5046eb8011c291cecaac6d4724b03c01e37f772eb20117109be072dab993c53d12745d0cd1e0dab6586fed5af4e90f32263705a9564a6ad70208327b3cfa6f496343262c7fa40442868d6edd83175f5a7258579098e437d9b99433349cd829e693cd083fee6b15a935e74a094210b82a65001510e80891a5496bed96c82b3cf2bf32fdefab7c021d10629e600abed9a1a8fd1b424d0a31e6e2daac005432519788bc7b8c2e49348c40b35d62dbc5243e8d36f91349ce531a127e29f63da63bdd3397ac1c9949e842376d21592b6bc45047dfdbcbe7d6e5a53f0d005772b35a65b245640d9750aee582e680428de1bf24b3063b048d1291979b365b875683f4f0eeb12a958b8ba1269f0445cd1be3a03e54e64f4b3e75f516f62819af4df74ee83d30b1038abf759a291be182ce80c2fb38164c7a5ed75d42497391268e1eb9e81f13a4b00c64d19577c49a8c95eef7faef240502e62da99ac3dede1c651c3c36bd79e90b92799e121d4e681463ed03ba9c0528abb71953cb0eba1b38f7fbcc1fbab3874cc3facda0bdf6e38b2108afcb9d3b9335db37fd6df6602201db6331541a43d43d75eda69a03d429c7a3980ec34a8ea8ef15f79650850bc54aa0aae41c3291d27275f84c820ceaad059354f02d4bad587bad279b05572563f67fd86ce');
}

// === SETUP ===
function setupSheets() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  ss.rename('ДОНУЛЯ Партнёрка');

  var partners = ss.getSheetByName('Партнёры') || ss.getSheets()[0];
  if (partners.getName() !== 'Партнёры') partners.setName('Партнёры');
  partners.getRange('A1:H1').setValues([['ID','Имя','Телефон','Ref-код','Мессенджер','ID мессенджера','Дата регистрации','Статус']]);
  partners.getRange('A1:H1').setFontWeight('bold').setBackground('#1A2B22').setFontColor('#00E676');
  partners.setFrozenRows(1);

  var leads = ss.getSheetByName('Лиды') || ss.insertSheet('Лиды');
  leads.getRange('A1:K1').setValues([['ID','Имя клиента','Телефон','Сумма долга','Мессенджер','Ref-код партнёра','Канал','Статус','Дата','Юрист','amoCRM ID']]);
  leads.getRange('A1:K1').setFontWeight('bold').setBackground('#1A2B22').setFontColor('#00E676');
  leads.setFrozenRows(1);
}

// === WEBHOOK ===
function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var leads = ss.getSheetByName('Лиды');

    var lastRow = leads.getLastRow();
    var nextId = lastRow > 0 ? lastRow : 1;
    var phone = (data.phone || '').replace(/[^\d+]/g, '');

    // 1. Write to Google Sheet (backup)
    leads.appendRow([
      nextId,
      data.name || '',
      "'" + phone,
      data.debt || '',
      data.messenger || '',
      data.ref || '',
      'ссылка',
      'Новый',
      new Date(),
      '',
      ''  // amoCRM ID - will be filled
    ]);

    // 2. Create lead in amoCRM
    var amoId = '';
    try {
      amoId = createAmoLead(data, phone);
      // Update sheet with amoCRM ID
      if (amoId) {
        leads.getRange(leads.getLastRow(), 11).setValue(amoId);
      }
    } catch(amoErr) {
      Logger.log('amoCRM error: ' + amoErr);
    }

    // 3. Email notification
    try {
      MailApp.sendEmail({
        to: Session.getActiveUser().getEmail(),
        subject: 'Новый лид ДОНУЛЯ от ' + (data.ref || 'прямой'),
        body: 'Имя: ' + data.name + '\nТелефон: ' + phone + '\nДолг: ' + data.debt + '\nМессенджер: ' + data.messenger + '\nПартнёр: ' + (data.ref || 'прямой') + '\namoCRM: ' + (amoId ? 'https://' + AMO_DOMAIN + '/leads/detail/' + amoId : 'не создан')
      });
    } catch(mailErr) {}

    return ContentService
      .createTextOutput(JSON.stringify({status: 'ok', id: nextId, amo_id: amoId}))
      .setMimeType(ContentService.MimeType.JSON);

  } catch(err) {
    return ContentService
      .createTextOutput(JSON.stringify({status: 'error', message: err.toString()}))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

function createAmoLead(data, phone) {
  var token = getAccessToken();
  if (!token) return '';

  // Build custom fields
  var customFields = [
    {field_id: CF_REF_CODE, values: [{value: data.ref || ''}]},
    {field_id: CF_CHANNEL, values: [{enum_id: CHANNELS['ссылка']}]},
    {field_id: CF_PAYOUT_STATUS, values: [{enum_id: 1651135}]} // Не выплачено
  ];

  // Debt
  if (data.debt && DEBTS[data.debt]) {
    customFields.push({field_id: CF_DEBT, values: [{enum_id: DEBTS[data.debt]}]});
  }

  // Messenger
  if (data.messenger && MESSENGERS[data.messenger]) {
    customFields.push({field_id: CF_MESSENGER, values: [{enum_id: MESSENGERS[data.messenger]}]});
  }

  // Create lead
  var leadPayload = [{
    name: (data.name || 'Лид') + ' (партнёрка)',
    pipeline_id: AMO_PIPELINE_ID,
    status_id: AMO_STATUS_NEW,
    custom_fields_values: customFields,
    _embedded: {
      contacts: [{
        first_name: data.name || '',
        custom_fields_values: [
          {field_id: 287279, values: [{value: phone, enum_code: 'WORK'}]} // Phone field
        ]
      }]
    }
  }];

  var resp = UrlFetchApp.fetch('https://' + AMO_DOMAIN + '/api/v4/leads/complex', {
    method: 'post',
    headers: {'Authorization': 'Bearer ' + token},
    contentType: 'application/json',
    payload: JSON.stringify(leadPayload),
    muteHttpExceptions: true
  });

  var result = JSON.parse(resp.getContentText());
  if (result[0] && result[0].id) {
    return result[0].id.toString();
  }
  Logger.log('amoCRM response: ' + resp.getContentText());
  return '';
}

function doGet(e) {
  return ContentService
    .createTextOutput(JSON.stringify({status: 'ok', message: 'ДОНУЛЯ Партнёрка webhook active'}))
    .setMimeType(ContentService.MimeType.JSON);
}

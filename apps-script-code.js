// === ДОНУЛЯ ПАРТНЁРКА — Google Apps Script ===
// Скопируй этот код в Apps Script (Расширения → Apps Script)
// Затем: 1) выбери setupSheets() → Выполнить
//        2) Начать развёртывание → Веб-приложение → Все → Развернуть

function setupSheets() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  ss.rename('ДОНУЛЯ Партнёрка');

  var partners = ss.getSheetByName('Лист1') || ss.getSheets()[0];
  partners.setName('Партнёры');
  partners.getRange('A1:H1').setValues([['ID','Имя','Телефон','Ref-код','Мессенджер','ID мессенджера','Дата регистрации','Статус']]);
  partners.getRange('A1:H1').setFontWeight('bold').setBackground('#1A2B22').setFontColor('#00E676');
  partners.setFrozenRows(1);

  var leads = ss.insertSheet('Лиды');
  leads.getRange('A1:J1').setValues([['ID','Имя клиента','Телефон','Сумма долга','Мессенджер','Ref-код партнёра','Канал','Статус','Дата','Юрист']]);
  leads.getRange('A1:J1').setFontWeight('bold').setBackground('#1A2B22').setFontColor('#00E676');
  leads.setFrozenRows(1);

  var dash = ss.insertSheet('Дашборд');
  dash.getRange('A1:F1').setValues([['Партнёр','Ref-код','Лидов','Договоров','Заработано','К выплате']]);
  dash.getRange('A1:F1').setFontWeight('bold').setBackground('#1A2B22').setFontColor('#00E676');
  dash.setFrozenRows(1);

  partners.getRange('A2:H2').setValues([[1,'Тест','','TEST123','','','=NOW()','Активный']]);
}

function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var leads = ss.getSheetByName('Лиды');
    var partners = ss.getSheetByName('Партнёры');

    var lastRow = leads.getLastRow();
    var nextId = lastRow > 0 ? lastRow : 1;

    var partnerName = '';
    if (data.ref) {
      var partnerData = partners.getDataRange().getValues();
      for (var i = 1; i < partnerData.length; i++) {
        if (partnerData[i][3] === data.ref) {
          partnerName = partnerData[i][1];
          break;
        }
      }
    }

    leads.appendRow([
      nextId,
      data.name || '',
      data.phone || '',
      data.debt || '',
      data.messenger || '',
      data.ref || '',
      'ссылка',
      'Новый',
      new Date(),
      ''
    ]);

    try {
      MailApp.sendEmail({
        to: Session.getActiveUser().getEmail(),
        subject: 'Новый лид ДОНУЛЯ от партнёра ' + (data.ref || 'прямой'),
        body: 'Имя: ' + data.name + '\nТелефон: ' + data.phone + '\nДолг: ' + data.debt + '\nМессенджер: ' + data.messenger + '\nПартнёр: ' + (partnerName || data.ref || 'прямой') + '\nВремя: ' + new Date()
      });
    } catch(mailErr) {}

    return ContentService
      .createTextOutput(JSON.stringify({status: 'ok', id: nextId}))
      .setMimeType(ContentService.MimeType.JSON);

  } catch(err) {
    return ContentService
      .createTextOutput(JSON.stringify({status: 'error', message: err.toString()}))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

function doGet(e) {
  return ContentService
    .createTextOutput(JSON.stringify({status: 'ok', message: 'ДОНУЛЯ Партнёрка webhook active'}))
    .setMimeType(ContentService.MimeType.JSON);
}

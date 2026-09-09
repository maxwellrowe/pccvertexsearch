// Bound Apps Script: set WEBHOOK_TOKEN in Project Settings > Script properties.
// Use the same value as the proxy's existing webhook token. Do not paste it here.
const QUESTION_SHEET_NAME = 'question_answer_log_v2';
const EVENT_SHEET_NAME = 'interaction_events';
const QUESTION_HEADERS = [
  'query_timestamp', 'answer_timestamp', 'completed_timestamp', 'session_id',
  'conversation_id', 'query_id', 'answer_id', 'previous_query_id', 'turn_number',
  'query_kind', 'rephrase_status', 'rephrase_reason', 'retry_of_query_id',
  'question', 'answer', 'reference_urls', 'status', 'http_status', 'error',
  'cache', 'page_url', 'is_test', 'bot_suspected', 'bot_reason', 'schema_version'
];
const EVENT_HEADERS = [
  'timestamp', 'event_id', 'event_type', 'session_id', 'conversation_id',
  'query_id', 'answer_id', 'parent_event_id', 'rating', 'feedback',
  'escalation_action', 'destination_url', 'page_url', 'is_test',
  'bot_suspected', 'bot_reason', 'schema_version'
];

function doPost(e) {
  const lock = LockService.getScriptLock();
  let locked = false;
  try {
    const expected = PropertiesService.getScriptProperties().getProperty('WEBHOOK_TOKEN');
    if (!expected || !e || !e.parameter || e.parameter.token !== expected) {
      return json_({ ok: false, error: 'Unauthorized' });
    }
    if (!e.postData || !e.postData.contents) return json_({ ok: false, error: 'Missing JSON body' });
    const body = JSON.parse(e.postData.contents);
    if (!body || typeof body !== 'object' || Array.isArray(body)) throw new Error('Invalid payload');
    locked = lock.tryLock(1500);
    if (!locked) return json_({ ok: false, error: 'Busy; retry delivery' });
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    // Allow deployment of this receiver before the PHP/client update.
    // Legacy rows remain in the historical tabs, with the IP cell left blank.
    if (body.schema_version !== 2) {
      const feedback = body.event_type === 'feedback' || body.sheet === 'feedback';
      const name = feedback ? 'feedback' : 'question_answer_log';
      const headers = feedback ? ['timestamp', 'question', 'answer', 'rating', 'feedback'] :
        ['timestamp', 'question', 'answer', 'reference_urls', 'cache', 'sid', 'session', 'ip', 'page_url'];
      body.ip = '';
      append_(ss, name, headers, body, '');
    } else {
      const question = body.event_type === 'question_answer';
      if (!question && !['feedback', 'feedback_comment', 'escalation'].includes(body.event_type)) {
        throw new Error('Invalid event type');
      }
      const id = question ? 'query_id' : 'event_id';
      if (!/^[A-Za-z0-9_-]{16,64}$/.test(body[id] || '')) throw new Error('Missing or invalid ID');
      append_(ss, question ? QUESTION_SHEET_NAME : EVENT_SHEET_NAME,
        question ? QUESTION_HEADERS : EVENT_HEADERS, body, id);
    }
    return json_({ ok: true });
  } catch (err) {
    return json_({ ok: false, error: String(err) });
  } finally {
    if (locked) lock.releaseLock();
  }
}

function append_(ss, name, headers, body, id) {
  const sheet = ss.getSheetByName(name) || ss.insertSheet(name);
  if (!sheet.getLastRow()) {
    sheet.appendRow(headers);
    sheet.setFrozenRows(1);
    sheet.getRange(1, 1, 1, headers.length).setFontWeight('bold').setBackground('#e8eef5');
  }
  const actual = sheet.getRange(1, 1, 1, headers.length).getValues()[0];
  if (actual.join('\t') !== headers.join('\t')) throw new Error('Unexpected headers in ' + name);
  if (id && sheet.getLastRow() > 1) {
    const existing = sheet.getRange(2, headers.indexOf(id) + 1, sheet.getLastRow() - 1, 1)
      .createTextFinder(body[id]).matchEntireCell(true).useRegularExpression(false).findNext();
    if (existing) return;
  }
  sheet.appendRow(headers.map(header => cell_(body[header])));
}

function cell_(value) {
  if (value === undefined || value === null) return '';
  if (typeof value === 'boolean' || typeof value === 'number') return value;
  const text = String(value).slice(0, 45000);
  // Questions and answers must be stored as text, never executed as formulas.
  return /^[\s]*[=+@-]/.test(text) ? "'" + text : text;
}

function json_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

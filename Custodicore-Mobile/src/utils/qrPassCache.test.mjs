// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  QR_PASS_CACHE_VERSION,
  buildQrPassCacheEntry,
  formatLastUpdated,
  isDefinitiveNoPass,
  isServerUnreachable,
  parseQrPassCacheEntry,
  shouldKeepQrPassCache,
} from './qrPassCache.js';

/** 2026-10-09 09:12 in Manila (UTC+8). */
const NOW = new Date('2026-10-09T01:12:00Z');

/** A normalized pass as QRCodeScreen holds it. */
function pass(overrides = {}) {
  return {
    qrToken: 'a'.repeat(64),
    expiresAt: '2026-10-09T16:30:00+08:00',
    checkedIn: false,
    scheduledAt: '2026-10-09T09:00:00+08:00',
    pdlName: 'Juan D. Rivera',
    dateDisplay: 'Oct 9, 2026',
    timeLabel: '09:00 - 16:30 · Whole day',
    sessionsText: 'Morning 09:00 - 11:30 · Afternoon 13:00 - 16:30',
    ...overrides,
  };
}

function entry(overrides = {}, passOverrides = {}) {
  return buildQrPassCacheEntry({ accountId: 7, visitId: 42, pass: pass(passOverrides), now: NOW, ...overrides });
}

test('buildQrPassCacheEntry saves the display fields, the visit and the Manila visit date', () => {
  const saved = entry();
  assert.equal(saved.v, QR_PASS_CACHE_VERSION);
  assert.equal(saved.accountId, '7');
  assert.equal(saved.visitId, '42');
  assert.equal(saved.visitDate, '2026-10-09');
  assert.equal(saved.checkedIn, false);
  assert.equal(saved.savedAt, NOW.toISOString());
  assert.deepEqual(saved.pass, pass());
});

test('buildQrPassCacheEntry keeps only the known pass fields', () => {
  const saved = buildQrPassCacheEntry({
    accountId: 7,
    visitId: 42,
    pass: { ...pass(), password: 'nope', authToken: 'nope' },
    now: NOW,
  });
  assert.equal('password' in saved.pass, false);
  assert.equal('authToken' in saved.pass, false);
});

test('buildQrPassCacheEntry saves nothing without an account, visit, token or date', () => {
  assert.equal(entry({ accountId: null }), null);
  assert.equal(entry({ accountId: '  ' }), null);
  assert.equal(entry({ visitId: undefined }), null);
  assert.equal(entry({}, { qrToken: '' }), null);
  assert.equal(entry({}, { scheduledAt: null }), null);
});

test('parseQrPassCacheEntry reads back only this account\'s valid entry', () => {
  const json = JSON.stringify(entry());
  assert.equal(parseQrPassCacheEntry(json, 7).visitId, '42');
  assert.equal(parseQrPassCacheEntry(json, '7').visitId, '42');
  assert.equal(parseQrPassCacheEntry(json, 8), null, 'another account never sees it');
  assert.equal(parseQrPassCacheEntry(json, null), null, 'signed out');
  assert.equal(parseQrPassCacheEntry('not json', 7), null);
  assert.equal(parseQrPassCacheEntry(null, 7), null);
  assert.equal(parseQrPassCacheEntry(JSON.stringify({ ...entry(), v: 99 }), 7), null);
  assert.equal(parseQrPassCacheEntry(JSON.stringify({ ...entry(), pass: { qrToken: '' } }), 7), null);
});

test('a pass is kept through its visit day and deleted after it when not inside', () => {
  const saved = entry();
  assert.equal(shouldKeepQrPassCache(saved, NOW), true);
  // 23:59 Manila on the visit day.
  assert.equal(shouldKeepQrPassCache(saved, new Date('2026-10-09T15:59:00Z')), true);
  // 00:00 Manila the next day.
  assert.equal(shouldKeepQrPassCache(saved, new Date('2026-10-09T16:00:00Z')), false);
  assert.equal(shouldKeepQrPassCache(null, NOW), false);
});

test('a pass is kept while the visitor is inside, even overnight', () => {
  const inside = entry({}, { checkedIn: true });
  assert.equal(inside.checkedIn, true);
  assert.equal(shouldKeepQrPassCache(inside, new Date('2026-10-10T02:00:00Z')), true);
  assert.equal(shouldKeepQrPassCache(inside, new Date('2026-10-12T02:00:00Z')), true);
});

test('only a server answer of "no pass" deletes the saved pass', () => {
  assert.equal(isDefinitiveNoPass({ status: 409 }), true);
  assert.equal(isDefinitiveNoPass({ status: 404 }), true);
  assert.equal(isDefinitiveNoPass({ status: 401 }), false);
  assert.equal(isDefinitiveNoPass({ status: 500 }), false);
  assert.equal(isDefinitiveNoPass({}), false);
  assert.equal(isDefinitiveNoPass(null), false);
});

test('no response, a timeout or a server error shows the saved pass offline', () => {
  assert.equal(isServerUnreachable({}), true, 'no response');
  assert.equal(isServerUnreachable({ timedOut: true }), true);
  assert.equal(isServerUnreachable({ status: 503 }), true);
  assert.equal(isServerUnreachable({ status: 500 }), true);
  assert.equal(isServerUnreachable({ status: 409 }), false);
  assert.equal(isServerUnreachable({ status: 401 }), false);
});

test('formatLastUpdated shows Manila time, with the date when not today', () => {
  assert.equal(formatLastUpdated(NOW.toISOString(), NOW), '09:12');
  assert.equal(formatLastUpdated('2026-10-08T14:05:00Z', NOW), 'Oct 8, 22:05');
  // Saved 23:30 Manila, read just after midnight.
  assert.equal(formatLastUpdated('2026-10-09T15:30:00Z', new Date('2026-10-09T16:10:00Z')), 'Oct 9, 23:30');
  assert.equal(formatLastUpdated('garbage', NOW), null);
  assert.equal(formatLastUpdated(null, NOW), null);
});

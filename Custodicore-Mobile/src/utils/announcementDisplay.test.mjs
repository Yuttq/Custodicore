// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { announcementCategoryLabel, formatAnnouncementDate } from './announcementDisplay.js';

test('announcement dates read as the facility (Manila) date', () => {
  // GET /api/announcements sends published_at as Manila midnight.
  assert.equal(formatAnnouncementDate('2026-10-07T00:00:00+08:00'), 'Oct 7, 2026');
  // The same instant in UTC is still Oct 7 in Manila.
  assert.equal(formatAnnouncementDate('2026-10-06T16:00:00Z'), 'Oct 7, 2026');
  assert.equal(formatAnnouncementDate('2026-10-06T15:59:00Z'), 'Oct 6, 2026');
});

test('fractional seconds and surrounding spaces are accepted', () => {
  assert.equal(formatAnnouncementDate('2026-10-07T00:00:00.000+08:00'), 'Oct 7, 2026');
  assert.equal(formatAnnouncementDate(' 2026-10-07T00:00:00+08:00 '), 'Oct 7, 2026');
});

test('missing, invalid or non-ISO announcement dates show nothing', () => {
  for (const value of [
    null,
    undefined,
    '',
    '   ',
    'not a date',
    42,
    // Date.parse would accept these in V8 (engine-specific); the API never sends them.
    'October 7',
    '2026',
    '2026-10-07',
    '2026-10-07T00:00:00',
  ]) {
    assert.equal(formatAnnouncementDate(value), null, String(value));
  }
});

test('categories become readable labels', () => {
  assert.equal(announcementCategoryLabel('schedule'), 'Schedule');
  assert.equal(announcementCategoryLabel('general'), 'General');
  assert.equal(announcementCategoryLabel('visit_rules'), 'Visit Rules');
  assert.equal(announcementCategoryLabel('FACILITY'), 'Facility');
  for (const value of ['', '  ', null, undefined, 3]) {
    assert.equal(announcementCategoryLabel(value), null, String(value));
  }
});

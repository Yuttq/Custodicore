import test from 'node:test';
import assert from 'node:assert/strict';

import { noAvailabilityMessage } from './noAvailabilityMessage.js';

const day = (date, ...reasons) => ({
  date,
  available: false,
  slots: reasons.map((reason) => ({ available: false, reason })),
});

const rel = (days, extra = {}) => ({ pdlName: 'Angel Low', pdlAvailable: true, days, ...extra });

test('unpublished sessions say the dates are not opened yet', () => {
  assert.match(
    noAvailabilityMessage(rel([day('2026-11-26', 'not_published', 'not_published'), day('2026-12-03', 'not_published')])),
    /Visit dates for Angel Low haven't been opened yet/,
  );
});

test('the most common reason wins over a few blocked days', () => {
  const days = [
    day('2026-11-12', 'daily_limit'),
    day('2026-11-19', 'daily_limit'),
    day('2026-11-26', 'not_published'),
    day('2026-12-03', 'not_published'),
    day('2026-12-10', 'not_published'),
  ];
  assert.match(noAvailabilityMessage(rel(days)), /haven't been opened yet/);
});

test('past days are ignored', () => {
  assert.match(noAvailabilityMessage(rel([day('2026-11-05', 'past'), day('2026-11-12', 'full')])), /are full/);
});

test('each reason has its own message', () => {
  assert.match(noAvailabilityMessage(rel([day('d', 'weekly_limit')])), /weekly visit limit/);
  assert.match(noAvailabilityMessage(rel([day('d', 'daily_limit')])), /Only one PDL can be visited per day/);
  assert.match(noAvailabilityMessage(rel([day('d', 'already_scheduled')])), /already have a visit with Angel Low/);
  assert.match(noAvailabilityMessage(rel([day('d', 'closed')])), /Visiting is closed/);
  assert.match(noAvailabilityMessage(rel([day('d', 'not_eligible')])), /Angel Low has no visiting days/);
});

test('an unavailable PDL is said first, whatever the days say', () => {
  assert.match(
    noAvailabilityMessage(rel([day('d', 'not_published')], { pdlAvailable: false })),
    /Visits with Angel Low aren't available right now/,
  );
});

test('a tie goes to the reason the visitor can do least about', () => {
  assert.match(noAvailabilityMessage(rel([day('a', 'daily_limit'), day('b', 'not_published')])), /haven't been opened yet/);
});

test('no reason to go on keeps the general message', () => {
  assert.equal(
    noAvailabilityMessage(rel([])),
    'No visit dates are available in the coming weeks. Please check again later.',
  );
  assert.match(noAvailabilityMessage({ days: [day('d', 'full')] }), /for this PDL are full/);
  assert.equal(noAvailabilityMessage(null), 'No visit dates are available in the coming weeks. Please check again later.');
});

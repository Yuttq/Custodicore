import test from 'node:test';
import assert from 'node:assert/strict';

import { noAvailabilityMessage, noAvailabilityReason } from './noAvailabilityMessage.js';

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

test('non-visiting days never outvote the real reason on visiting days', () => {
  // A Thursday-only PDL over a month: many not_eligible days, few real ones.
  const weeklyLimit = { limit: 2, weeks: { '2026-11-09': 2 } };
  const days = [
    ...['2026-11-11', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-20']
      .map((d) => day(d, 'not_eligible')),
    day('2026-11-12', 'weekly_limit'),
    day('2026-11-19', 'not_published'),
    day('2026-11-26', 'not_published'),
  ];
  assert.match(noAvailabilityMessage(rel(days), weeklyLimit), /haven't been opened yet/);
  assert.match(
    noAvailabilityMessage(rel([day('a', 'not_eligible'), day('b', 'not_eligible'), day('c', 'weekly_limit')]), weeklyLimit),
    /weekly visit limit/,
  );
  assert.match(
    noAvailabilityMessage(rel([day('a', 'not_eligible'), day('b', 'not_in_effect')])),
    /Angel Low has no visiting days/,
  );
});

test('the main reason is exposed so screens can skip a repeated message', () => {
  assert.equal(noAvailabilityReason(rel([day('a', 'not_eligible'), day('b', 'weekly_limit')])), 'weekly_limit');
  assert.equal(noAvailabilityReason(rel([day('a', 'not_published')], { pdlAvailable: false })), 'pdl_unavailable');
  assert.equal(noAvailabilityReason(rel([])), null);
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

test('the weekly limit message names the full weeks and covers all linked PDLs', () => {
  const weeklyLimit = { limit: 2, weeks: { '2026-11-09': 2, '2026-11-16': 2, '2026-11-23': 1 } };
  const days = [
    day('2026-11-15', 'weekly_limit'), // Sunday: week of Nov 9–15
    day('2026-11-19', 'weekly_limit'), // Thursday: week of Nov 16–22
    day('2026-11-22', 'weekly_limit'),
  ];
  const message = noAvailabilityMessage(rel(days), weeklyLimit);
  assert.match(message, /weekly visit limit of 2 visits for November 9–15 and November 16–22 \(Monday–Sunday\)/);
  assert.match(message, /all your linked PDLs together/);
});

test('without weekly usage data the weekly limit message still explains the rule', () => {
  const message = noAvailabilityMessage(rel([day('2026-11-19', 'weekly_limit')]));
  assert.match(message, /weekly visit limit\. The limit counts visits with all your linked PDLs together/);
});

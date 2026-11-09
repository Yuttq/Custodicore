// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { AUTO_REFRESH_MS, cameToForeground, shouldPoll } from './autoRefresh.js';

test('refreshes every 30 seconds', () => {
  assert.equal(AUTO_REFRESH_MS, 30_000);
});

test('coming back to the app counts as returning to the foreground', () => {
  assert.equal(cameToForeground('background', 'active'), true);
  assert.equal(cameToForeground('inactive', 'active'), true);
});

test('staying active or going to the background does not', () => {
  assert.equal(cameToForeground('active', 'active'), false);
  assert.equal(cameToForeground('active', 'background'), false);
  assert.equal(cameToForeground('background', 'inactive'), false);
  assert.equal(cameToForeground(null, 'active'), false);
});

test('polls only while focused, in front and enabled', () => {
  assert.equal(shouldPoll(true, 'active'), true);
  assert.equal(shouldPoll(false, 'active'), false);
  assert.equal(shouldPoll(true, 'background'), false);
  assert.equal(shouldPoll(true, 'inactive'), false);
  assert.equal(shouldPoll(true, 'active', false), false);
});

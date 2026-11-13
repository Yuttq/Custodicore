// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { LEGACY_LOCAL_PROFILE_KEY, localProfileKey } from './localProfileKey.js';

test('each account gets its own profile key', () => {
  assert.equal(localProfileKey('12'), '@custodicore/visitor_profile_local:12');
  assert.equal(localProfileKey(12), '@custodicore/visitor_profile_local:12');
  assert.notEqual(localProfileKey('12'), localProfileKey('13'));
});

test('no account, no key: nothing is read or saved', () => {
  assert.equal(localProfileKey(null), null);
  assert.equal(localProfileKey(undefined), null);
  assert.equal(localProfileKey(''), null);
  assert.equal(localProfileKey('  '), null);
});

test('an account key never equals the old shared key', () => {
  assert.notEqual(localProfileKey('12'), LEGACY_LOCAL_PROFILE_KEY);
  assert.equal(LEGACY_LOCAL_PROFILE_KEY, '@custodicore/visitor_profile_local');
});

// Run with: npm test  (node --test, no extra dependencies)
//
// These exercise the pure URL / client ID checks. They do not load api.js, so
// the "Server not configured" message on a real request still needs checking
// in a build.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { normalizeGoogleClientId, resolveApiBaseUrl } from './apiBaseUrl.js';

const release = (nativeUrl, extra = {}) =>
  resolveApiBaseUrl({ nativeUrl, platform: 'android', isRelease: true, ...extra });
const dev = (nativeUrl, extra = {}) =>
  resolveApiBaseUrl({ nativeUrl, platform: 'android', isRelease: false, ...extra });

test('release build accepts an https URL and drops the trailing slash', () => {
  assert.deepEqual(release('https://custodicore.example.com/api/'), {
    baseUrl: 'https://custodicore.example.com/api',
    problem: null,
  });
});

test('release build accepts an https URL with a port, trimming spaces', () => {
  assert.deepEqual(release('  https://custodicore.example.com:8443/api  '), {
    baseUrl: 'https://custodicore.example.com:8443/api',
    problem: null,
  });
});

test('release build with no URL is not configured', () => {
  for (const value of [undefined, null, '', '   ', 'undefined', 'null']) {
    assert.deepEqual(release(value), { baseUrl: null, problem: 'missing' }, String(value));
  }
});

test('release build refuses http', () => {
  assert.deepEqual(release('http://custodicore.example.com/api'), {
    baseUrl: null,
    problem: 'insecure',
  });
});

test('release build refuses malformed URLs', () => {
  for (const value of [
    'custodicore.example.com/api',
    'ftp://custodicore.example.com/api',
    'https://',
    'https://custodicore.example.com/api?x=1',
    'https://exa mple.com/api',
  ]) {
    assert.deepEqual(release(value), { baseUrl: null, problem: 'invalid' }, value);
  }
});

test('the old placeholder address is never used', () => {
  assert.deepEqual(release('https://api.custodicore.placeholder'), {
    baseUrl: null,
    problem: 'invalid',
  });
  assert.deepEqual(dev('https://api.custodicore.placeholder'), {
    baseUrl: null,
    problem: 'invalid',
  });
});

test('development build keeps http LAN and emulator addresses', () => {
  assert.deepEqual(dev('http://192.168.1.10:8000/api'), {
    baseUrl: 'http://192.168.1.10:8000/api',
    problem: null,
  });
  assert.deepEqual(dev('http://10.0.2.2:8000/api/'), {
    baseUrl: 'http://10.0.2.2:8000/api',
    problem: null,
  });
});

test('development build with no URL is not configured either', () => {
  assert.deepEqual(dev(''), { baseUrl: null, problem: 'missing' });
});

test('web uses EXPO_PUBLIC_WEB_API_URL when set', () => {
  assert.deepEqual(
    dev('http://10.0.2.2:8000/api', { platform: 'web', webUrl: 'http://localhost:8000/api' }),
    { baseUrl: 'http://localhost:8000/api', problem: null },
  );
});

test('web otherwise swaps the emulator host for 127.0.0.1', () => {
  assert.deepEqual(dev('http://10.0.2.2:8000/api', { platform: 'web', webUrl: '' }), {
    baseUrl: 'http://127.0.0.1:8000/api',
    problem: null,
  });
});

test('Google client ID is trimmed', () => {
  assert.equal(
    normalizeGoogleClientId('  123-abc.apps.googleusercontent.com \n'),
    '123-abc.apps.googleusercontent.com',
  );
});

test('missing Google client ID is empty, which hides the Google button', () => {
  for (const value of [undefined, null, '', '   ', 'undefined', 'null', 42]) {
    assert.equal(normalizeGoogleClientId(value), '', String(value));
  }
});

import test from 'node:test';
import assert from 'node:assert/strict';

import { normalizeFacilityContact, telLink } from './facilityContact.js';

test('no phone number means no contact', () => {
  assert.equal(normalizeFacilityContact({ contact: null }), null);
  assert.equal(normalizeFacilityContact({ contact: { name: 'Desk', phone: '  ' } }), null);
  assert.equal(normalizeFacilityContact(null), null);
  assert.equal(normalizeFacilityContact('oops'), null);
});

test('a contact keeps its name and hours, trimmed, or null when empty', () => {
  assert.deepEqual(
    normalizeFacilityContact({ contact: { name: ' Visiting Area ', phone: '(032) 123-4567 ', hours: '' } }),
    { name: 'Visiting Area', phone: '(032) 123-4567', hours: null },
  );
});

test('tel links keep only digits and a leading plus', () => {
  assert.equal(telLink('(032) 123-4567'), 'tel:0321234567');
  assert.equal(telLink('+63 32 123 4567'), 'tel:+63321234567');
});

test('anything that is not a plain phone number gets no link', () => {
  for (const bad of ['javascript:alert(1)', 'tel:123', '12345', '0917abc4567', '', null, undefined]) {
    assert.equal(telLink(bad), null, String(bad));
  }
});

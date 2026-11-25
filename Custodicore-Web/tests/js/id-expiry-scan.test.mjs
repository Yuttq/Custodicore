// Date finder of the "Scan ID" button (public/js/id-expiry-scan.js).
// Run: node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Run the browser script as a browser would (a plain script, not a module)
// and take the finder it exposes as IdExpiryScan.
const sandbox = {};
sandbox.globalThis = sandbox;
vm.runInNewContext(readFileSync(new URL('../../public/js/id-expiry-scan.js', import.meta.url), 'utf8'), sandbox);
const { findExpiryDate } = sandbox.IdExpiryScan;

const TODAY = '2026-11-25';
// Copied into this realm, so deepEqual compares plain objects.
const find = (text) => {
  const found = findExpiryDate(text, TODAY);
  return found ? { ...found } : found;
};

test('LTO driver\'s license: year-first date after "Expiration Date"', () => {
  const text = `REPUBLIC OF THE PHILIPPINES
DEPARTMENT OF TRANSPORTATION
LAND TRANSPORTATION OFFICE
DRIVER'S LICENSE
DELA CRUZ, JUAN SANTOS
Nationality Sex Date of Birth Weight (kg) Height(m)
PHL M 1990/05/10 70 1.70
License No. Expiration Date Agency Code
N01-12-345678 2028/03/04 N01`;
  assert.deepEqual(find(text), { date: '2028-03-04', how: 'label' });
});

test('label on one line, date on the next', () => {
  assert.deepEqual(find('VALID UNTIL\n04 MAR 2029'), { date: '2029-03-04', how: 'label' });
  assert.deepEqual(find('Date of Expiry\nMarch 4, 2029'), { date: '2029-03-04', how: 'label' });
});

test('month-name and numeric formats', () => {
  assert.equal(find('Expiry: 4 March 2030').date, '2030-03-04');
  assert.equal(find('EXP DATE MAR 04 2030').date, '2030-03-04');
  assert.equal(find('Valid thru 03/04/2030').date, '2030-03-04', 'month first');
  assert.equal(find('Valid thru 25/03/2030').date, '2030-03-25', 'day first when it cannot be a month');
});

test('common OCR slips in digits are fixed', () => {
  assert.equal(find('EXPIRATION DATE 2O28/O3/O4').date, '2028-03-04');
});

test('passport machine-readable lines give the expiry', () => {
  const text = `P<PHLDELA<CRUZ<<JUAN<SANTOS<<<<<<<<<<<<<<<<<<
P1234567A2PHL9005101M3103045<<<<<<<<<<<<<<04`;
  assert.deepEqual(find(text), { date: '2031-03-04', how: 'mrz' });
});

test('without a label, the only future date is offered as a weaker guess', () => {
  const text = `UNIFIED MULTI-PURPOSE ID
DATE OF BIRTH 1990/05/10
2029/08/15`;
  assert.deepEqual(find(text), { date: '2029-08-15', how: 'latest' });
});

test('birth and issue dates are never taken as the expiry', () => {
  assert.equal(find('Date of Birth 2030/01/01'), null);
  assert.equal(find('Date Issued\n2030/01/01'), null);
});

test('nothing found: null (the officer types the date)', () => {
  assert.equal(find('PHILIPPINE IDENTIFICATION CARD\nPCN 1234-5678-9012-3456'), null);
  assert.equal(find(''), null);
  assert.equal(find('Expiry: 2028/02/30'), null, 'an impossible date is ignored');
});

test('two possible future dates without a label: no guess', () => {
  assert.equal(find('2029/01/01\n2030/01/01'), null);
});

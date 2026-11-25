// Relationship check of PSA documents (public/js/relationship-docs-scan.js).
// Run: node --test tests/js/relationship-docs-scan.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const sandbox = {};
sandbox.globalThis = sandbox;
vm.runInNewContext(readFileSync(new URL('../../public/js/relationship-docs-scan.js', import.meta.url), 'utf8'), sandbox);
const scan = sandbox.RelationshipDocsScan;
const analyse = (docs, visitor, pdl) => JSON.parse(JSON.stringify(scan.analyseDocuments(docs, visitor, pdl)));

// The PDL: Juan Santos Dela Cruz. Mother Maria Santos (married: Maria Santos Dela Cruz),
// father Pedro Reyes Dela Cruz.
const PDL = { full: 'Juan Santos Dela Cruz', first: 'Juan', last: 'Dela Cruz' };
const MOTHER = { full: 'Maria Santos Dela Cruz', first: 'Maria', last: 'Dela Cruz' };
const FATHER = { full: 'Pedro Reyes Dela Cruz', first: 'Pedro', last: 'Dela Cruz' };
const SISTER = { full: 'Ana Santos Dela Cruz', first: 'Ana', last: 'Dela Cruz' };
const WIFE = { full: 'Liza Garcia Dela Cruz', first: 'Liza', last: 'Dela Cruz' };
const STRANGER = { full: 'Rosa Mendoza Lim', first: 'Rosa', last: 'Lim' };

const birthCertificate = (child, mother, father) => `
REPUBLIC OF THE PHILIPPINES
PHILIPPINE STATISTICS AUTHORITY
CERTIFICATE OF LIVE BIRTH
1. NAME (First) (Middle) (Last)
${child}
2. SEX MALE
MOTHER
7. MAIDEN NAME (First) (Middle) (Last)
${mother}
8. CITIZENSHIP FILIPINO
FATHER
13. NAME (First) (Middle) (Last)
${father}
PLACE OF BIRTH QUEZON CITY`;

const pdlBirth = birthCertificate('JUAN SANTOS DELA CRUZ', 'MARIA GOMEZ SANTOS', 'PEDRO REYES DELA CRUZ');
const sisterBirth = birthCertificate('ANA SANTOS DELA CRUZ', 'MARIA GOMEZ SANTOS', 'PEDRO REYES DELA CRUZ');

test("the PDL's birth certificate names the visitor: parent (the father)", () => {
  const r = analyse({ pdl_birth_certificate: pdlBirth }, FATHER, PDL);
  assert.equal(r.relationship, 'parent');
  assert.equal(r.documents.pdl_birth_certificate, 'match');
});

test("a married mother matches by her maiden surname (her middle name)", () => {
  // Maria Santos Dela Cruz is on the certificate as MARIA GOMEZ SANTOS.
  assert.equal(analyse({ pdl_birth_certificate: pdlBirth }, MOTHER, PDL).relationship, 'parent');
});

test("the visitor's birth certificate names the PDL: child", () => {
  const visitorBirth = birthCertificate('ROBERTO GARCIA DELA CRUZ', 'LIZA MENDOZA GARCIA', 'JUAN SANTOS DELA CRUZ');
  const r = analyse({ visitor_birth_certificate: visitorBirth }, { full: 'Roberto Garcia Dela Cruz', first: 'Roberto', last: 'Dela Cruz' }, PDL);
  assert.equal(r.relationship, 'child');
  assert.equal(r.documents.visitor_birth_certificate, 'match');
});

test('both birth certificates name the same parents: sibling', () => {
  const r = analyse({ pdl_birth_certificate: pdlBirth, visitor_birth_certificate: sisterBirth }, SISTER, PDL);
  assert.equal(r.relationship, 'sibling');
  assert.equal(r.documents.pdl_birth_certificate, 'match');
  assert.equal(r.documents.visitor_birth_certificate, 'match');
});

test('the marriage certificate names both: spouse', () => {
  const marriage = `CERTIFICATE OF MARRIAGE
HUSBAND  WIFE
1. NAME OF CONTRACTING PARTIES (First) (Middle) (Last)
JUAN SANTOS DELA CRUZ    LIZA MENDOZA GARCIA
PLACE OF MARRIAGE MANILA CITY`;
  // Liza Garcia Dela Cruz married: the certificate has her maiden name LIZA MENDOZA GARCIA.
  const r = analyse({ marriage_certificate: marriage }, WIFE, PDL);
  assert.equal(r.relationship, 'spouse');
  assert.equal(r.documents.marriage_certificate, 'match');
});

test("a child's birth certificate naming both: live-in partner", () => {
  const childBirth = birthCertificate('MIKO GARCIA DELA CRUZ', 'LIZA MENDOZA GARCIA', 'JUAN SANTOS DELA CRUZ');
  const r = analyse({ child_birth_certificate: childBirth }, { full: 'Liza Mendoza Garcia', first: 'Liza', last: 'Garcia' }, PDL);
  assert.equal(r.relationship, 'live_in_partner');
});

test('OCR typos in the names still match', () => {
  const blurry = pdlBirth.replace('PEDRO', 'PEDR0').replace('REYES DELA CRUZ', 'REYES DELA CRUS');
  assert.equal(analyse({ pdl_birth_certificate: blurry.replace('PEDR0', 'PEDRD') }, FATHER, PDL).relationship, 'parent');
});

test('a stranger is not matched: no suggestion, check manually', () => {
  const r = analyse({ pdl_birth_certificate: pdlBirth }, STRANGER, PDL);
  assert.equal(r.relationship, null);
  assert.match(r.reason, /Check them manually/);
  assert.equal(r.documents.pdl_birth_certificate, 'match', "It is the PDL's certificate, it just does not name the visitor.");
});

test("someone else's certificate does not match", () => {
  const other = birthCertificate('CARLO DIAZ TAN', 'NORA DIAZ', 'RAMON TAN');
  const r = analyse({ pdl_birth_certificate: other }, FATHER, PDL);
  assert.equal(r.relationship, null);
  assert.equal(r.documents.pdl_birth_certificate, 'no_match');
});

test('nothing readable: unreadable, no suggestion', () => {
  const r = analyse({ marriage_certificate: '', pdl_birth_certificate: '~~ ## ..' }, WIFE, PDL);
  assert.equal(r.relationship, null);
  assert.equal(r.documents.marriage_certificate, 'unreadable');
  assert.equal(r.documents.pdl_birth_certificate, 'unreadable');
});

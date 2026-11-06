// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  DUPLICATE_WINDOW_MS,
  collapseDuplicateNotifications,
  extractNotificationReason,
  extractRejectedDocumentLabel,
  notificationIconName,
  resolveNotificationAction,
} from './notificationActions.js';

// Bodies exactly as the backend writes them.
const DOC_BODY =
  'Your Passport was not accepted. Reason: Photo page is blurry. Please upload a new one in the CustodiCore app so staff can review it again.';
const PDL_BODY =
  'Facility staff could not approve your request to add Juan Dela Cruz. Reason: No PDL with this name. You can send a new request with the correct details.';
const ACCOUNT_BODY =
  'Your account was returned so you can correct your information or documents. What to fix: Retake your photo without glasses.';

test('the staff reason is read from rejection bodies', () => {
  assert.equal(extractNotificationReason('document_rejected', DOC_BODY), 'Photo page is blurry.');
  assert.equal(extractNotificationReason('pdl_request_rejected', PDL_BODY), 'No PDL with this name.');
  assert.equal(
    extractNotificationReason('account_rejected', ACCOUNT_BODY),
    'Retake your photo without glasses.',
  );
});

test('no reason is invented', () => {
  assert.equal(extractNotificationReason('visit_confirmed', 'Reason: none'), null);
  assert.equal(extractNotificationReason('document_rejected', 'Your ID was not accepted.'), null);
  assert.equal(
    extractNotificationReason(
      'account_rejected',
      'Your account was returned so you can correct your information or documents. Please contact facility staff for details.',
    ),
    null,
  );
  assert.equal(extractNotificationReason(null, DOC_BODY), null);
  assert.equal(extractNotificationReason('document_rejected', undefined), null);
});

test('the rejected document is named in the body', () => {
  assert.equal(extractRejectedDocumentLabel(DOC_BODY), 'Passport');
  assert.equal(
    extractRejectedDocumentLabel('Your Birth Certificate was not accepted. Reason: x'),
    'Birth Certificate',
  );
  assert.equal(extractRejectedDocumentLabel('Something else'), null);
});

const approved = { backendStatus: 'verified', pdlRequest: null, documents: [] };

test('Request Again only while a new PDL request is allowed', () => {
  const n = { category: 'pdl_request_rejected', body: PDL_BODY };
  assert.deepEqual(resolveNotificationAction(n, approved), {
    label: 'Request Again',
    route: 'AddPdlRequest',
  });
  assert.deepEqual(
    resolveNotificationAction(n, { ...approved, pdlRequest: { status: 'rejected' } }),
    { label: 'Request Again', route: 'AddPdlRequest' },
  );
  // A newer request is already waiting for staff.
  assert.equal(resolveNotificationAction(n, { ...approved, pdlRequest: { status: 'open' } }), null);
  assert.equal(resolveNotificationAction(n, { ...approved, backendStatus: 'pending' }), null);
  // State not loaded yet: no shortcut rather than a wrong one.
  assert.equal(resolveNotificationAction(n, null), null);
});

const rejectedRequest = {
  id: '7',
  status: 'rejected',
  pdlName: 'Juan Dela Cruz',
  relationshipType: 'parent',
  hasChildrenTogether: null,
  rejectionReason: 'No PDL with this name.',
};

test('Request Again carries the rejected request it is about', () => {
  const n = { category: 'pdl_request_rejected', body: PDL_BODY };
  assert.deepEqual(resolveNotificationAction(n, { ...approved, pdlRequest: rejectedRequest }), {
    label: 'Request Again',
    route: 'AddPdlRequest',
    params: {
      prefill: {
        pdlName: 'Juan Dela Cruz',
        relationship: 'parent',
        hasChildrenTogether: null,
        rejectionReason: 'No PDL with this name.',
      },
    },
  });
  // An older notice about another PDL: an empty form, not the wrong details.
  const older = { category: 'pdl_request_rejected', body: PDL_BODY.replace('Juan Dela Cruz', 'Pedro Santos') };
  assert.deepEqual(resolveNotificationAction(older, { ...approved, pdlRequest: rejectedRequest }), {
    label: 'Request Again',
    route: 'AddPdlRequest',
  });
});

const passport = {
  key: 'government_id',
  label: 'Government ID',
  detail: 'Passport',
  uploadStatus: 'rejected',
  canUpload: true,
};
const birthCert = {
  key: 'rel-1:birth_certificate',
  label: 'Birth Certificate',
  detail: 'Juan Dela Cruz',
  uploadStatus: 'rejected',
  canUpload: true,
};

test('Replace Document opens the upload screen for the document it names', () => {
  const n = { category: 'document_rejected', body: DOC_BODY };
  assert.deepEqual(resolveNotificationAction(n, { ...approved, documents: [passport, birthCert] }), {
    label: 'Replace Document',
    route: 'UploadID',
    params: { documentKey: 'government_id' },
  });
  const b = { category: 'document_rejected', body: 'Your Birth Certificate was not accepted. Reason: x' };
  assert.deepEqual(resolveNotificationAction(b, { ...approved, documents: [passport, birthCert] }), {
    label: 'Replace Document',
    route: 'UploadID',
    params: { documentKey: 'rel-1:birth_certificate' },
  });
});

test('Replace Document falls back to the documents list when unsure', () => {
  const other = { ...birthCert, key: 'rel-2:birth_certificate', detail: 'Pedro Santos' };
  const b = { category: 'document_rejected', body: 'Your Birth Certificate was not accepted. Reason: x' };
  // Two PDLs with a rejected birth certificate each.
  assert.deepEqual(resolveNotificationAction(b, { ...approved, documents: [birthCert, other] }), {
    label: 'Replace Document',
    route: 'VisitorVerificationDocuments',
  });
});

test('no Replace Document once nothing replaceable is rejected', () => {
  const n = { category: 'document_rejected', body: DOC_BODY };
  assert.equal(
    resolveNotificationAction(n, {
      ...approved,
      documents: [{ ...passport, uploadStatus: 'under_review' }],
    }),
    null,
  );
  assert.equal(
    resolveNotificationAction(n, { ...approved, documents: [{ ...passport, canUpload: false }] }),
    null,
  );
});

test('other notifications have no shortcut', () => {
  for (const category of ['visit_confirmed', 'account_approved', 'account_rejected', 'visits', null]) {
    assert.equal(resolveNotificationAction({ category, body: '' }, approved), null, String(category));
  }
});

test('type icons', () => {
  assert.equal(notificationIconName('document_rejected'), 'document-text');
  assert.equal(notificationIconName('pdl_request_rejected'), 'person-add');
  assert.equal(notificationIconName('visit_confirmed'), 'calendar');
  assert.equal(notificationIconName('unknown_type'), null);
});

const row = (id, createdAt, extra = {}) => ({
  id,
  title: 'Document Not Accepted',
  body: DOC_BODY,
  rawCategory: 'document_rejected',
  read: true,
  createdAt,
  ...extra,
});

test('copies of one event collapse into the newest row', () => {
  const out = collapseDuplicateNotifications([
    row('2', '2026-11-05T10:01:00Z', { read: false }),
    row('1', '2026-11-05T10:00:00Z'),
  ]);
  assert.equal(out.length, 1);
  assert.equal(out[0].id, '2');
  assert.deepEqual(out[0].ids, ['2', '1']);
  assert.equal(out[0].read, false);
});

test('an unread older copy keeps the collapsed row unread', () => {
  const out = collapseDuplicateNotifications([
    row('2', '2026-11-05T10:01:00Z'),
    row('1', '2026-11-05T10:00:00Z', { read: false }),
  ]);
  assert.equal(out[0].read, false);
});

test('separate events stay separate', () => {
  const later = new Date(Date.parse('2026-11-05T10:00:00Z') + DUPLICATE_WINDOW_MS + 1000).toISOString();
  const out = collapseDuplicateNotifications([
    row('3', later),
    row('2', '2026-11-05T10:00:00Z', { body: 'Your National ID was not accepted. Reason: x' }),
    row('1', '2026-11-05T10:00:00Z'),
  ]);
  assert.deepEqual(
    out.map((n) => n.id),
    ['3', '2', '1'],
  );
  // Without dates nothing is collapsed.
  assert.equal(collapseDuplicateNotifications([row('b', null), row('a', null)]).length, 2);
});

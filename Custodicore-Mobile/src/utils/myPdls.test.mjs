// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { relationshipsNeedingDocuments, summarizeMyPdls } from './myPdls.js';

const pdl = (status) => ({ status });

test('summary counts relationships and the current request', () => {
  const s = summarizeMyPdls([pdl('approved'), pdl('approved'), pdl('pending')], { status: 'open' });
  assert.equal(s.approved, 2);
  assert.equal(s.pending, 2);
  assert.equal(s.rejected, 0);
  assert.equal(s.full, '2 approved · 2 pending');
  assert.equal(s.short, '2 pending');
});

test('the short summary shows what needs the visitor first', () => {
  assert.equal(summarizeMyPdls([pdl('approved'), pdl('pending')], { status: 'rejected' }).short, '1 not accepted');
  assert.equal(summarizeMyPdls([pdl('approved')], null).short, '1 approved');
  assert.equal(
    summarizeMyPdls([pdl('approved'), pdl('rejected')], { status: 'rejected' }).full,
    '1 approved · 2 not accepted',
  );
});

test('nothing to summarize', () => {
  const s = summarizeMyPdls([], null);
  assert.equal(s.full, '');
  assert.equal(s.short, '');
  assert.equal(summarizeMyPdls(undefined, undefined).full, '');
});

test('relationships with a document still to upload or replace', () => {
  const ids = relationshipsNeedingDocuments([
    { relationshipRecordId: '1', uploadStatus: 'pending', canUpload: true },
    { relationshipRecordId: '2', uploadStatus: 'rejected', canUpload: true },
    { relationshipRecordId: '3', uploadStatus: 'under_review', canUpload: true },
    { relationshipRecordId: '4', uploadStatus: 'rejected', canUpload: false },
    { relationshipRecordId: null, uploadStatus: 'rejected', canUpload: true },
  ]);
  assert.deepEqual([...ids].sort(), ['1', '2']);
  assert.equal(relationshipsNeedingDocuments(null).size, 0);
});

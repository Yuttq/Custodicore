/**
 * My PDLs summaries for Profile → My PDLs and the link row in Verification
 * Documents. Pure — no React Native imports — so it runs under `node --test`.
 * Built from what GET /api/documents already returns (pdlRelationships and
 * pdlRequests after normalizeVerification).
 */

/**
 * @typedef {object} MyPdlsSummary
 * @property {number} approved
 * @property {number} pending — pending relationships plus open requests
 * @property {number} rejected — rejected relationships plus rejected requests
 * @property {string} full — "2 approved · 1 pending · 1 not accepted" ('' when empty)
 * @property {string} short — the one count that most needs attention ('' when empty)
 */

/**
 * @param {{ status: 'approved' | 'pending' | 'rejected' }[] | null | undefined} pdls
 * @param {{ status: 'open' | 'rejected' }[] | null | undefined} pdlRequests
 * @returns {MyPdlsSummary}
 */
export function summarizeMyPdls(pdls, pdlRequests) {
  const list = Array.isArray(pdls) ? pdls : [];
  const requests = Array.isArray(pdlRequests) ? pdlRequests : [];
  const count = (status) => list.filter((p) => p?.status === status).length;
  const countRequests = (status) => requests.filter((r) => r?.status === status).length;
  const approved = count('approved');
  const pending = count('pending') + countRequests('open');
  const rejected = count('rejected') + countRequests('rejected');

  const parts = [
    approved ? `${approved} approved` : '',
    pending ? `${pending} pending` : '',
    rejected ? `${rejected} not accepted` : '',
  ].filter(Boolean);

  // Profile shows one figure: what needs the visitor first.
  const short = rejected
    ? `${rejected} not accepted`
    : pending
      ? `${pending} pending`
      : approved
        ? `${approved} approved`
        : '';

  return { approved, pending, rejected, full: parts.join(' · '), short };
}

/**
 * Ids of relationships that still have a document for the visitor to upload
 * or replace (missing or rejected, and uploadable), so My PDLs can point a
 * pending PDL to Verification Documents.
 * @param {{ relationshipRecordId?: string | null; uploadStatus?: string; canUpload?: boolean }[] | null | undefined} documents
 * @returns {Set<string>}
 */
export function relationshipsNeedingDocuments(documents) {
  const ids = new Set();
  (Array.isArray(documents) ? documents : []).forEach((doc) => {
    if (
      doc?.relationshipRecordId &&
      doc.canUpload === true &&
      (doc.uploadStatus === 'pending' || doc.uploadStatus === 'rejected')
    ) {
      ids.add(String(doc.relationshipRecordId));
    }
  });
  return ids;
}

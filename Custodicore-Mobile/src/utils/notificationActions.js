/**
 * Phase 3 notifications: the staff reason, the inline shortcut and the icon
 * for a notification row, plus duplicate collapsing.
 *
 * GET /api/notifications sends id, title, body, category (the backend
 * notification_type), read, createdAt and the record it is about
 * (relatedType / relatedId, e.g. `visitor_pdl_relationships` / "12"). The
 * shortcut is chosen from the type and that record, checked against the
 * visitor's current verification state (GET /api/documents), so it only
 * appears while the item still needs action. Older notifications without a
 * record fall back to the body text. There is no separate reason field, so
 * the reason is read from the body the backend writes ("… Reason: <staff
 * reason> …", "… What to fix: <staff reason>"). Nothing here invents a
 * reason: no marker in the body → no reason.
 */

import { canAddAnotherPdl, pdlRequestPrefill, relationshipRequestPrefill } from './pdlLinkRequest.js';

/** Backend notification_type values with a staff reason in the body. */
const REASON_PATTERNS = {
  // DocumentRejectionNotice: "Your {label} was not accepted. Reason: {reason} Please upload a new one …"
  document_rejected: /Reason:\s*([\s\S]+?)\s*(?:Please upload a new one\b[\s\S]*)?$/,
  // PdlLinkReviewController::reject: "… Reason: {reason}. You can send a new request …"
  pdl_request_rejected: /Reason:\s*([\s\S]+?)\s*(?:You can send a new request\b[\s\S]*)?$/,
  // VisitorController::rejectRelationship: "… Reason: {reason}. To visit this PDL, send a new …"
  relationship_rejected: /Reason:\s*([\s\S]+?)\s*(?:To visit this PDL\b[\s\S]*)?$/,
  // VisitorController::reject: "… What to fix: {reason}"
  account_rejected: /What to fix:\s*([\s\S]+?)\s*$/,
};

/** The notification's record id when it is of `type`, else null. */
function relatedIdOf(notification, type) {
  const id = notification?.relatedId;
  return notification?.relatedType === type && id != null && String(id) !== '' ? String(id) : null;
}

/**
 * @param {unknown} category
 * @returns {string}
 */
function typeOf(category) {
  return typeof category === 'string' ? category.trim().toLowerCase() : '';
}

/**
 * The staff reason written into a rejection notification's body, or null.
 * @param {unknown} category — backend notification_type
 * @param {unknown} body
 * @returns {string | null}
 */
export function extractNotificationReason(category, body) {
  const pattern = REASON_PATTERNS[typeOf(category)];
  if (!pattern || typeof body !== 'string') return null;
  const match = body.match(pattern);
  const reason = match?.[1]?.trim();
  return reason ? reason : null;
}

/**
 * The document named in a "Document Not Accepted" body ("Your Passport was
 * not accepted."), or null.
 * @param {unknown} body
 * @returns {string | null}
 */
export function extractRejectedDocumentLabel(body) {
  if (typeof body !== 'string') return null;
  const match = body.match(/^\s*Your\s+(.+?)\s+was not accepted\./);
  return match?.[1]?.trim() || null;
}

/**
 * @typedef {object} NotificationAction
 * @property {string} label — button text
 * @property {string} route — existing stack screen
 * @property {Record<string, unknown>} [params]
 */

/**
 * The inline shortcut for a notification, or null when there is nothing to
 * do (already replaced, a newer request is waiting, state not loaded yet…).
 *
 * - `account_rejected` → "Fix My Account" opens Verification Documents (the
 *   reason and the documents to correct), only while the account is still
 *   returned.
 * - `pdl_request_rejected` → "Request Again" opens the Add Another PDL form,
 *   only while a new request is allowed (approved account, none open). It is
 *   prefilled from the latest rejected request when that is the request this
 *   notification is about (same record, or — for older notifications — its
 *   PDL name appears in the body); otherwise the form starts empty rather
 *   than with another request's details.
 * - `relationship_rejected` → "Request Again", same rule: a rejected
 *   relationship takes no new documents, so the visitor sends a new request,
 *   prefilled from that relationship while it is still rejected.
 * - `relationship_approved` → "Book a Visit" opens Home (Visitation
 *   Calendar), while that relationship is still approved.
 * - `document_rejected` → "Replace Document" opens the upload screen for
 *   that document (it shows the staff reason), or the Verification
 *   Documents list when the document cannot be pinned down. Only while a
 *   replaceable document is still rejected.
 *
 * @param {{ category?: unknown; body?: unknown; relatedType?: string | null; relatedId?: string | null }} notification
 * @param {{ backendStatus?: string | null; pdlRequest?: any; pdlRelationships?: any[]; documents?: any[] } | null | undefined} verification
 * @returns {NotificationAction | null}
 */
export function resolveNotificationAction(notification, verification) {
  if (!verification) return null;
  const type = typeOf(notification?.category);
  const body = typeof notification?.body === 'string' ? notification.body : '';
  const pdls = Array.isArray(verification.pdlRelationships) ? verification.pdlRelationships : [];

  if (type === 'account_rejected') {
    return verification.backendStatus === 'rejected'
      ? { label: 'Fix My Account', route: 'VisitorVerificationDocuments' }
      : null;
  }

  if (type === 'pdl_request_rejected') {
    if (!canAddAnotherPdl(verification.backendStatus, verification.pdlRequest)) return null;
    const prefill = pdlRequestPrefill(verification.pdlRequest);
    const requestId = relatedIdOf(notification, 'pdl_link_requests');
    const sameRequest = requestId
      ? String(verification.pdlRequest?.id ?? '') === requestId
      : Boolean(prefill?.pdlName && body.includes(prefill.pdlName));
    return prefill && sameRequest
      ? { label: 'Request Again', route: 'AddPdlRequest', params: { prefill } }
      : { label: 'Request Again', route: 'AddPdlRequest' };
  }

  if (type === 'relationship_rejected') {
    if (!canAddAnotherPdl(verification.backendStatus, verification.pdlRequest)) return null;
    const relationshipId = relatedIdOf(notification, 'visitor_pdl_relationships');
    const pdl = relationshipId ? pdls.find((p) => String(p?.id) === relationshipId) : null;
    // Reopened or approved since: nothing left to do for this notification.
    if (pdl && pdl.status !== 'rejected') return null;
    const prefill = relationshipRequestPrefill(pdl, extractNotificationReason(type, body));
    return prefill
      ? { label: 'Request Again', route: 'AddPdlRequest', params: { prefill } }
      : { label: 'Request Again', route: 'AddPdlRequest' };
  }

  if (type === 'relationship_approved') {
    if (verification.backendStatus !== 'verified') return null;
    const relationshipId = relatedIdOf(notification, 'visitor_pdl_relationships');
    const stillApproved = relationshipId
      ? pdls.some((p) => String(p?.id) === relationshipId && p.status === 'approved')
      : pdls.some((p) => p?.status === 'approved');
    return stillApproved ? { label: 'Book a Visit', route: 'Dashboard' } : null;
  }

  if (type === 'document_rejected') {
    const replaceable = (Array.isArray(verification.documents) ? verification.documents : []).filter(
      (doc) => doc && doc.uploadStatus === 'rejected' && doc.canUpload === true,
    );
    if (replaceable.length === 0) return null;

    const label = extractRejectedDocumentLabel(notification?.body)?.toLowerCase();
    // A government ID is listed as "Government ID" with its type ("Passport")
    // in `detail`; the notice names the type.
    const matches = label
      ? replaceable.filter(
          (doc) =>
            String(doc.label ?? '').toLowerCase() === label ||
            String(doc.detail ?? '').toLowerCase() === label,
        )
      : [];

    return matches.length === 1
      ? {
          label: 'Replace Document',
          route: 'UploadID',
          params: { documentKey: matches[0].key },
        }
      : { label: 'Replace Document', route: 'VisitorVerificationDocuments' };
  }

  return null;
}

/** Small row icon per notification type; null keeps the tone icon. */
export function notificationIconName(category) {
  const type = typeOf(category);
  if (type === 'document_rejected') return 'document-text';
  if (type === 'pdl_request_rejected') return 'person-add';
  if (type === 'account_rejected') return 'alert-circle';
  if (type === 'account_approved' || type === 'relationship_approved') return 'shield-checkmark';
  if (type === 'relationship_linked') return 'people';
  if (type === 'relationship_rejected') return 'close-circle';
  if (type === 'visit_confirmed') return 'calendar';
  if (type.startsWith('visit')) return 'calendar-outline';
  return null;
}

/** Same type, title and text sent this close together = the same event. */
export const DUPLICATE_WINDOW_MS = 5 * 60 * 1000;

/**
 * Collapses repeated copies of one event (same category, title and body sent
 * within DUPLICATE_WINDOW_MS, e.g. a double-submitted staff action) into the
 * newest row. The kept row lists every id in `ids` so marking it read marks
 * all copies, and stays unread while any copy is unread. Order is kept.
 * @template {{ id: string; title: string; body: string; read: boolean; createdAt: string | null; rawCategory?: string | null }} T
 * @param {T[]} items
 * @returns {(T & { ids: string[] })[]}
 */
export function collapseDuplicateNotifications(items) {
  /** @type {(T & { ids: string[] })[]} */
  const out = [];
  /** @type {Map<string, (T & { ids: string[] })[]>} */
  const byKey = new Map();

  const sorted = [...items].sort(
    (a, b) => timeOf(b.createdAt) - timeOf(a.createdAt),
  );

  for (const item of sorted) {
    const key = `${item.rawCategory ?? ''}\u0000${item.title}\u0000${item.body}`;
    const kept = byKey.get(key) ?? [];
    const t = timeOf(item.createdAt);
    const twin = kept.find(
      (k) => Number.isFinite(t) && Number.isFinite(timeOf(k.createdAt)) &&
        Math.abs(timeOf(k.createdAt) - t) <= DUPLICATE_WINDOW_MS,
    );
    if (twin) {
      twin.ids.push(item.id);
      if (!item.read) twin.read = false;
      continue;
    }
    const row = { ...item, ids: [item.id] };
    kept.push(row);
    byKey.set(key, kept);
    out.push(row);
  }

  // Back to the incoming order.
  const order = new Map(items.map((it, i) => [it.id, i]));
  return out.sort((a, b) => (order.get(a.id) ?? 0) - (order.get(b.id) ?? 0));
}

/** @param {string | null | undefined} iso */
function timeOf(iso) {
  if (!iso) return NaN;
  const t = new Date(iso).getTime();
  return Number.isNaN(t) ? NaN : t;
}

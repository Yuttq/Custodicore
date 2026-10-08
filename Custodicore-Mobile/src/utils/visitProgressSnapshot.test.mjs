// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { buildCompactVisitStepsFromTimeline } from './visitProgressSnapshot.js';

/** Mirrors GET /api/schedules/{id}/timeline: steps up to `lastDone` completed, the next current. */
function timeline(lastDone, extra = []) {
  const ids = [
    'visitor_eligible',
    'schedule_assigned',
    'attendance_confirmed',
    'qr_generated',
    'checked_in',
    'visit_completed',
  ];
  const doneIndex = ids.indexOf(lastDone);
  return [
    ...ids.map((id, i) => ({
      id,
      title: id,
      description: `${id} description`,
      occurredAt: i <= doneIndex ? '2026-10-08T09:00:00+08:00' : null,
      officerNote: null,
      stepState: i <= doneIndex ? 'completed' : i === doneIndex + 1 ? 'current' : 'pending',
    })),
    ...extra,
  ];
}

const summary = (steps) => steps.map((s) => `${s.id}:${s.stepState}`);

test('assigned request shows Awaiting Staff Review as the current step', () => {
  const steps = buildCompactVisitStepsFromTimeline(timeline('schedule_assigned'), 'assigned');
  assert.deepEqual(summary(steps), [
    'visitor_eligible:completed',
    'awaiting_review:current',
    'attendance_confirmed:pending',
    'qr_generated:pending',
    'checked_in:pending',
    'visit_completed:pending',
  ]);
  const review = steps[1];
  assert.equal(review.label, 'Awaiting Staff Review');
  assert.ok(!steps.some((s) => s.label === 'Schedule Assigned'));
});

test('pending_confirmation keeps Schedule Assigned done and Attendance Confirmed current', () => {
  const steps = buildCompactVisitStepsFromTimeline(
    timeline('schedule_assigned'),
    'pending_confirmation',
  );
  assert.deepEqual(summary(steps).slice(0, 3), [
    'visitor_eligible:completed',
    'schedule_assigned:completed',
    'attendance_confirmed:current',
  ]);
  assert.equal(steps[1].label, 'Schedule Assigned');
});

test('confirmed and completed visits are unchanged', () => {
  const confirmed = buildCompactVisitStepsFromTimeline(timeline('attendance_confirmed'), 'confirmed');
  assert.deepEqual(summary(confirmed).slice(1, 4), [
    'schedule_assigned:completed',
    'attendance_confirmed:completed',
    'qr_generated:current',
  ]);
  const completed = buildCompactVisitStepsFromTimeline(timeline('visit_completed'), 'completed');
  assert.ok(completed.every((s) => s.stepState === 'completed'));
  assert.equal(completed.length, 6);
});

test('terminal statuses still end with the backend closing event', () => {
  const closing = {
    id: 'visit_cancelled',
    title: 'Visit Cancelled',
    description: 'This visit was cancelled.',
    occurredAt: '2026-10-08T10:00:00+08:00',
    officerNote: null,
    stepState: 'completed',
  };
  const steps = buildCompactVisitStepsFromTimeline(
    timeline('schedule_assigned', [closing]),
    'cancelled',
  );
  assert.deepEqual(summary(steps), [
    'visitor_eligible:completed',
    'schedule_assigned:completed',
    'visit_cancelled:completed',
  ]);
});

test('without a status the backend steps are used as-is', () => {
  const steps = buildCompactVisitStepsFromTimeline(timeline('schedule_assigned'));
  assert.equal(steps[1].id, 'schedule_assigned');
  assert.equal(steps[2].stepState, 'current');
});

/** Visitor-submitted request: the backend's own step ids and titles replace the staff ones. */
const REQUEST_STEPS = {
  schedule_assigned: { id: 'request_submitted', title: 'Request Submitted' },
  attendance_confirmed: { id: 'request_approved', title: 'Request Approved' },
};
function requestTimeline(lastDone, extra = []) {
  return timeline(lastDone, extra).map((step) => ({ ...step, ...REQUEST_STEPS[step.id] }));
}

test('visitor-submitted assigned request still shows Awaiting Staff Review', () => {
  const steps = buildCompactVisitStepsFromTimeline(requestTimeline('schedule_assigned'), 'assigned');
  assert.deepEqual(summary(steps).slice(0, 3), [
    'visitor_eligible:completed',
    'awaiting_review:current',
    'request_approved:pending',
  ]);
  assert.equal(steps[1].label, 'Awaiting Staff Review');
});

test('visitor-submitted confirmed request uses the backend Request Submitted / Approved steps', () => {
  const steps = buildCompactVisitStepsFromTimeline(requestTimeline('attendance_confirmed'), 'confirmed');
  assert.deepEqual(summary(steps).slice(1, 4), [
    'request_submitted:completed',
    'request_approved:completed',
    'qr_generated:current',
  ]);
  assert.equal(steps[1].label, 'Request Submitted');
  assert.equal(steps[2].label, 'Request Approved');
  assert.ok(!steps.some((s) => s.label === 'Schedule Assigned' || s.label === 'Attendance Confirmed'));
});

test('visitor-submitted cancelled request ends with Request Rejected', () => {
  const closing = {
    id: 'request_rejected',
    title: 'Request Rejected',
    description: 'Your visit request was rejected by facility staff.',
    occurredAt: '2026-10-08T10:00:00+08:00',
    officerNote: null,
    stepState: 'completed',
  };
  const steps = buildCompactVisitStepsFromTimeline(
    requestTimeline('schedule_assigned', [closing]),
    'cancelled',
  );
  assert.deepEqual(summary(steps), [
    'visitor_eligible:completed',
    'request_submitted:completed',
    'request_rejected:completed',
  ]);
  assert.equal(steps[2].label, 'Request Rejected');
});

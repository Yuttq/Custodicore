// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { colors } from './colors.js';

/** WCAG 2.x relative luminance of a #RRGGBB color. */
function luminance(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a, b) {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

const AA_TEXT = 4.5;

test('filled controls keep white labels at WCAG AA', () => {
  for (const fill of ['primaryTealDark', 'successStrong', 'dangerStrong']) {
    assert.ok(contrast(colors.white, colors[fill]) >= AA_TEXT, fill);
  }
});

test('accessible text tokens meet WCAG AA on white and the app background', () => {
  for (const token of [
    'primaryTealDark',
    'successStrong',
    'warningText',
    'dangerStrong',
    'textSecondary',
    'textMuted',
  ]) {
    for (const bg of ['white', 'background']) {
      assert.ok(contrast(colors[token], colors[bg]) >= AA_TEXT, `${token} on ${bg}`);
    }
  }
});

test('warning chips keep dark text on the bright warning fill', () => {
  assert.ok(contrast(colors.textPrimary, colors.warning) >= AA_TEXT);
});

test('bright accents are preserved for decorative use', () => {
  assert.equal(colors.primaryTeal, '#0DA58A');
  assert.equal(colors.success, '#16A34A');
  assert.equal(colors.warning, '#F59E0B');
  assert.equal(colors.danger, '#EF4444');
});

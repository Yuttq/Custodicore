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

/** A hex color laid over white at `alpha`, like rgba() status tints. */
function tint(hex, alpha) {
  const ch = [1, 3, 5].map((i) =>
    Math.round(parseInt(hex.slice(i, i + 2), 16) * alpha + 255 * (1 - alpha)),
  );
  return `#${ch.map((c) => c.toString(16).padStart(2, '0')).join('')}`;
}

test('QR pass status text stays AA on its tinted banners', () => {
  const banners = [
    ['successText', tint(colors.success, 0.12)],
    ['primaryTealDark', tint(colors.primaryTeal, 0.12)],
    ['warningText', tint(colors.warning, 0.14)],
  ];
  for (const [token, bg] of banners) {
    assert.ok(contrast(colors[token], bg) >= AA_TEXT, `${token} on ${bg}`);
    assert.ok(contrast(colors.textPrimary, bg) >= AA_TEXT, `hint on ${bg}`);
  }
});

test('text on navy surfaces meets WCAG AA', () => {
  assert.equal(colors.navySurface, colors.primaryNavy);
  for (const token of ['textOnNavy', 'textOnNavyMuted']) {
    assert.ok(contrast(colors[token], colors.navySurface) >= AA_TEXT, token);
  }
});

test('bright teal and green are not used as text on navy or as white-label fills', () => {
  // Documents why Login uses successStrong (not success) for its green button,
  // and why links/labels on navy use textOnNavy* instead of teal.
  assert.ok(contrast(colors.white, colors.success) < AA_TEXT);
  assert.ok(contrast(colors.primaryTeal, colors.navySurface) < AA_TEXT);
});

test('bright accents are preserved for decorative use', () => {
  assert.equal(colors.primaryTeal, '#0DA58A');
  assert.equal(colors.success, '#16A34A');
  assert.equal(colors.warning, '#F59E0B');
  assert.equal(colors.danger, '#EF4444');
});

import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';
import { renderGoogleButton } from '../services/googleAuthService';

/** Google Identity Services accepts button widths of 200–400 px. */
const MIN_WIDTH = 200;
const MAX_WIDTH = 400;

/** Plain DOM style for the element GIS renders into (not a react-native style). */
const GIS_HOST_STYLE = { display: 'flex', justifyContent: 'center' };

/**
 * Web variant of `./GoogleSignInButton.js`: renders Google's official
 * "Continue with Google" button (Google Identity Services). Same props as the
 * native button. `onPress` runs after the visitor picks an account, so the
 * caller's existing handler continues the sign-in unchanged.
 *
 * @param {object} props
 * @param {() => void} props.onPress
 * @param {boolean} [props.loading]
 * @param {boolean} [props.disabled]
 */
export default function GoogleSignInButton({ onPress, loading = false, disabled = false }) {
  const containerRef = useRef(null);
  const onPressRef = useRef(onPress);
  const [width, setWidth] = useState(0);
  const [unavailable, setUnavailable] = useState(false);
  const isDisabled = disabled || loading;

  useEffect(() => {
    onPressRef.current = onPress;
  }, [onPress]);

  useEffect(() => {
    const element = containerRef.current;
    if (!element || !width) return undefined;

    let active = true;
    let detach = null;
    const buttonWidth = Math.round(Math.min(Math.max(width, MIN_WIDTH), MAX_WIDTH));
    renderGoogleButton(element, { width: buttonWidth }, () => onPressRef.current?.())
      .then((cleanup) => {
        if (active) {
          detach = cleanup;
          setUnavailable(false);
        } else {
          cleanup();
        }
      })
      .catch(() => {
        if (active) setUnavailable(true);
      });

    return () => {
      active = false;
      detach?.();
    };
  }, [width]);

  if (unavailable) {
    return (
      <Text style={styles.unavailable} accessibilityRole="alert">
        Google Sign-In is unavailable right now. Please sign in with your email and password.
      </Text>
    );
  }

  return (
    <View
      style={[styles.wrap, isDisabled && styles.disabled]}
      onLayout={(e) => setWidth(e.nativeEvent.layout.width)}
      aria-busy={loading}
    >
      <div ref={containerRef} style={GIS_HOST_STYLE} />
      {loading ? (
        <View style={styles.loadingOverlay}>
          <ActivityIndicator color={colors.primaryNavy} />
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    alignSelf: 'stretch',
    width: '100%',
    minHeight: layout.buttonHeight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  disabled: {
    opacity: 0.55,
    pointerEvents: 'none',
  },
  loadingOverlay: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.white,
    borderRadius: layout.buttonRadius,
  },
  unavailable: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    paddingHorizontal: spacing.md,
  },
});

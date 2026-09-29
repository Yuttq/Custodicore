import Ionicons from '@expo/vector-icons/Ionicons';
import { CameraView } from 'expo-camera';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Modal,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, layout, spacing, typography } from '../designSystem';
import { ensureScannerCameraPermission } from '../services/mediaPermissions';

/**
 * Full-screen QR scanner — requests camera permission only when opened (user-initiated).
 * @param {object} props
 * @param {boolean} props.visible
 * @param {() => void} props.onClose
 * @param {(data: string) => void} [props.onScan] — called once per successful scan
 */
export default function QrScannerModal({ visible, onClose, onScan }) {
  const insets = useSafeAreaInsets();
  const [phase, setPhase] = useState('idle');
  const scannedRef = useRef(false);

  const requestAccess = useCallback(async () => {
    setPhase('loading');
    const result = await ensureScannerCameraPermission();
    setPhase(result.granted ? 'ready' : 'denied');
  }, []);

  useEffect(() => {
    if (!visible) {
      setPhase('idle');
      scannedRef.current = false;
      return;
    }
    requestAccess();
  }, [visible, requestAccess]);

  const handleBarcode = useCallback(
    ({ data }) => {
      if (!data || scannedRef.current) return;
      scannedRef.current = true;
      onScan?.(data);
      onClose();
    },
    [onScan, onClose],
  );

  return (
    <Modal
      visible={visible}
      animationType="slide"
      onRequestClose={onClose}
      presentationStyle="fullScreen"
    >
      <View style={[styles.root, { paddingTop: insets.top, paddingBottom: insets.bottom }]}>
        <View style={styles.header}>
          <Text style={styles.title}>Scan QR Code</Text>
          <Pressable
            onPress={onClose}
            style={styles.closeBtn}
            accessibilityRole="button"
            accessibilityLabel="Close scanner"
          >
            <Ionicons name="close" size={24} color={colors.textPrimary} />
          </Pressable>
        </View>

        {phase === 'loading' || phase === 'idle' ? (
          <View style={styles.centered}>
            <ActivityIndicator size="large" color={colors.primaryTeal} />
            <Text style={styles.hint}>Preparing camera…</Text>
          </View>
        ) : null}

        {phase === 'denied' ? (
          <View style={styles.centered}>
            <Ionicons name="camera-outline" size={48} color={colors.textSecondary} />
            <Text style={styles.deniedTitle}>Camera access required</Text>
            <Text style={styles.deniedMessage}>
              Allow camera access to scan QR codes, or open Settings to enable permission.
            </Text>
            <Pressable
              style={styles.retryBtn}
              onPress={requestAccess}
              accessibilityRole="button"
              accessibilityLabel="Try again"
            >
              <Text style={styles.retryBtnText}>Try Again</Text>
            </Pressable>
          </View>
        ) : null}

        {phase === 'ready' ? (
          <View style={styles.cameraWrap}>
            <CameraView
              style={styles.camera}
              facing="back"
              barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
              onBarcodeScanned={handleBarcode}
            />
            <View style={styles.frame} pointerEvents="none" />
            <Text style={styles.scanHint}>Align the QR code within the frame</Text>
          </View>
        ) : null}
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: colors.background,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: layout.screenPadding,
    paddingVertical: spacing.sm,
  },
  title: {
    ...typography.cardTitle,
    color: colors.textPrimary,
  },
  closeBtn: {
    width: 44,
    height: 44,
    alignItems: 'center',
    justifyContent: 'center',
  },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: layout.screenPadding,
    gap: spacing.md,
  },
  hint: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  deniedTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    textAlign: 'center',
  },
  deniedMessage: {
    ...typography.body,
    color: colors.textSecondary,
    textAlign: 'center',
    lineHeight: 22,
  },
  retryBtn: {
    marginTop: spacing.sm,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    borderRadius: layout.buttonRadius,
    backgroundColor: colors.primaryTeal,
  },
  retryBtnText: {
    ...typography.body,
    fontWeight: '600',
    color: colors.white,
  },
  cameraWrap: {
    flex: 1,
    marginHorizontal: layout.screenPadding,
    marginBottom: spacing.lg,
    borderRadius: layout.cardRadius,
    overflow: 'hidden',
    backgroundColor: colors.primaryNavy,
  },
  camera: {
    flex: 1,
  },
  frame: {
    ...StyleSheet.absoluteFillObject,
    margin: spacing.xl,
    borderWidth: 2,
    borderColor: colors.primaryTeal,
    borderRadius: layout.buttonRadius,
  },
  scanHint: {
    ...typography.metadata,
    color: colors.white,
    textAlign: 'center',
    paddingVertical: spacing.sm,
    backgroundColor: 'rgba(15, 61, 122, 0.85)',
  },
});

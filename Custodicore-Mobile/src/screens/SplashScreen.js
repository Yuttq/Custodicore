import React, { useEffect, useMemo } from 'react';
import {
  Image,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from 'react-native';
import * as ExpoSplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import Animated, {
  useAnimatedStyle,
  useSharedValue,
  withTiming,
} from 'react-native-reanimated';
import { SafeAreaView } from 'react-native-safe-area-context';
import { colors, spacing, typography } from '../designSystem';

const SPLASH_DURATION_MS = 2200;
const FADE_IN_MS = 700;

/**
 * Branded launch screen — BJMP + CustodiCore partnership (capstone / production).
 * Layout scales with screen width for Android phones and tablets.
 */
export default function SplashScreen({ navigation }) {
  const { width, height } = useWindowDimensions();
  const contentOpacity = useSharedValue(0);
  const contentTranslateY = useSharedValue(12);

  const layout = useMemo(() => {
    const contentMaxWidth = Math.min(width * 0.9, 420);
    const bjmpSize = Math.min(width * 0.26, height * 0.12, 120);
    const custodiWidth = Math.min(contentMaxWidth * 0.88, width * 0.68, 300);
    const custodiHeight = custodiWidth * (1024 / 819);
    return { contentMaxWidth, bjmpSize, custodiWidth, custodiHeight };
  }, [width, height]);

  useEffect(() => {
    ExpoSplashScreen.hideAsync().catch(() => {});
  }, []);

  useEffect(() => {
    contentOpacity.value = withTiming(1, { duration: FADE_IN_MS });
    contentTranslateY.value = withTiming(0, { duration: FADE_IN_MS });
  }, [contentOpacity, contentTranslateY]);

  useEffect(() => {
    const timer = setTimeout(() => {
      navigation.replace('Login');
    }, SPLASH_DURATION_MS);
    return () => clearTimeout(timer);
  }, [navigation]);

  const contentAnimatedStyle = useAnimatedStyle(() => ({
    opacity: contentOpacity.value,
    transform: [{ translateY: contentTranslateY.value }],
  }));

  return (
    <View style={styles.root}>
      <View style={styles.backgroundBase} />
      <View style={styles.backgroundGradientBottom} />
      <View style={styles.accentBar} />

      <SafeAreaView style={styles.safe} edges={['top', 'bottom', 'left', 'right']}>
        <Animated.View
          style={[
            styles.content,
            { maxWidth: layout.contentMaxWidth },
            contentAnimatedStyle,
          ]}
        >
          <Image
            source={require('../../assets/bjmp-logo.png')}
            style={[
              styles.bjmpLogo,
              { width: layout.bjmpSize, height: layout.bjmpSize },
            ]}
            resizeMode="contain"
            accessibilityLabel="Bureau of Jail Management and Penology"
          />

          <View style={styles.divider} accessibilityElementsHidden />

          <Image
            source={require('../../assets/custodicore-logo.png')}
            style={[
              styles.custodiLogo,
              {
                width: layout.custodiWidth,
                height: layout.custodiHeight,
              },
            ]}
            resizeMode="contain"
            accessibilityLabel="CustodiCore"
          />

          <Text style={styles.subtitle}>Visitor Management System</Text>
        </Animated.View>
      </SafeAreaView>

      <StatusBar style="dark" />
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: colors.background,
  },
  backgroundBase: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: colors.background,
  },
  backgroundGradientBottom: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: 'rgba(15, 61, 122, 0.04)',
    top: '45%',
  },
  accentBar: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 4,
    backgroundColor: colors.primaryTeal,
  },
  safe: {
    flex: 1,
    backgroundColor: 'transparent',
  },
  content: {
    flex: 1,
    width: '100%',
    alignSelf: 'center',
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.xl,
  },
  bjmpLogo: {
    marginBottom: spacing.md,
  },
  divider: {
    width: 56,
    height: 3,
    borderRadius: 2,
    backgroundColor: colors.primaryTeal,
    marginVertical: spacing.lg,
  },
  custodiLogo: {
    marginBottom: spacing.sm,
  },
  subtitle: {
    ...typography.sectionLabel,
    color: colors.primaryTeal,
    textAlign: 'center',
    marginTop: spacing.lg,
    letterSpacing: 1.2,
    textTransform: 'none',
    fontSize: 15,
    fontWeight: '600',
  },
});

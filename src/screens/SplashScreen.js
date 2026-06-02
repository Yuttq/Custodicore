import React, { useEffect } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { Image as ExpoImage } from 'expo-image';
import { StatusBar } from 'expo-status-bar';
import Animated, {
  useAnimatedStyle,
  useSharedValue,
  withTiming,
} from 'react-native-reanimated';
import { SafeAreaView } from 'react-native-safe-area-context';
import { colors, spacing, typography } from '../designSystem';

const SPLASH_DURATION_MS = 1750;
const LOGO_ANIMATION_MS = 800;

export default function SplashScreen({ navigation }) {
  const logoOpacity = useSharedValue(0);
  const logoScale = useSharedValue(0.95);

  useEffect(() => {
    logoOpacity.value = withTiming(1, { duration: LOGO_ANIMATION_MS });
    logoScale.value = withTiming(1, { duration: LOGO_ANIMATION_MS });
  }, [logoOpacity, logoScale]);

  useEffect(() => {
    const timer = setTimeout(() => {
      navigation.replace('Login');
    }, SPLASH_DURATION_MS);
    return () => clearTimeout(timer);
  }, [navigation]);

  const logoAnimatedStyle = useAnimatedStyle(() => ({
    opacity: logoOpacity.value,
    transform: [{ scale: logoScale.value }],
  }));

  return (
    <View style={styles.root}>
      <ExpoImage
        source={require('../../assets/splash-corridor.jpg')}
        style={styles.backgroundImage}
        contentFit="cover"
        blurRadius={42}
        accessibilityIgnoresInvertColors
      />
      <View style={styles.backgroundTint} />
      <View style={styles.backgroundVeil} />

      <SafeAreaView style={styles.safe} edges={['top', 'bottom', 'left', 'right']}>
        <View style={styles.content}>
          <Animated.View style={[styles.logoWrap, logoAnimatedStyle]}>
            <Image
              source={require('../../assets/custodicore-logo.png')}
              style={styles.logo}
              resizeMode="contain"
              accessibilityLabel="CustodiCore Visitor Portal"
            />
          </Animated.View>

          <Text style={styles.tagline}>
            Secure Visits.{'\n'}Transparent Process.
          </Text>
        </View>
      </SafeAreaView>

      <StatusBar style="dark" />
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: '#E8EEF4',
  },
  backgroundImage: {
    ...StyleSheet.absoluteFillObject,
    transform: [{ scale: 1.06 }],
  },
  backgroundTint: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: 'rgba(214, 224, 235, 0.55)',
  },
  backgroundVeil: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: 'rgba(248, 250, 252, 0.72)',
  },
  safe: {
    flex: 1,
    backgroundColor: 'transparent',
  },
  content: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.xl,
  },
  logoWrap: {
    alignItems: 'center',
    justifyContent: 'center',
    maxWidth: 300,
  },
  logo: {
    width: 248,
    height: 248,
  },
  tagline: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    marginTop: spacing.md,
  },
});

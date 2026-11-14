import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { Pressable, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import FocusAwareStatusBar from '../../components/FocusAwareStatusBar';
import { colors } from '../tokens/colors';
import { layout, spacing } from '../tokens/spacing';
import { typography } from '../tokens/typography';

/** Window heights (dp) below this get the compact band. */
const COMPACT_HEIGHT = 700;

/**
 * Navy header band for top-level tab screens (Home, My Visits), matching the
 * CustodiCore Web header. Draws under the status bar (Android edge-to-edge),
 * pads itself by the top inset, and switches status bar icons to light while
 * the screen is focused. Place it OUTSIDE the screen's top safe-area edge.
 *
 * @param {object} props
 * @param {string} props.title
 * @param {string} [props.subtitle]
 * @param {React.ReactNode} [props.right]
 * @param {() => void} [props.onBack] — shows a white back arrow on the left
 */
export function HeaderBand({ title, subtitle, right, onBack }) {
  const insets = useSafeAreaInsets();
  const { height } = useWindowDimensions();
  // Short phones (e.g. 360×640): trim the band's padding so forms keep more
  // room above the keyboard. The text and touch targets stay the same size.
  const compact = height < COMPACT_HEIGHT;
  return (
    <View
      style={[
        styles.band,
        { paddingTop: insets.top + (compact ? spacing.xs : spacing.sm) },
        compact && styles.bandCompact,
      ]}
    >
      <FocusAwareStatusBar style="light" />
      <View style={styles.row}>
        {onBack ? (
          <Pressable
            onPress={onBack}
            accessibilityRole="button"
            accessibilityLabel="Go back"
            hitSlop={spacing.sm}
            style={styles.back}
          >
            <Ionicons name="chevron-back" size={24} color={colors.textOnNavy} />
          </Pressable>
        ) : null}
        <View style={styles.text}>
          <Text style={styles.title} accessibilityRole="header" numberOfLines={2}>
            {title}
          </Text>
          {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
        </View>
        {right ? <View style={styles.right}>{right}</View> : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  band: {
    backgroundColor: colors.navySurface,
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.md,
    borderBottomLeftRadius: layout.cardRadius,
    borderBottomRightRadius: layout.cardRadius,
  },
  bandCompact: {
    paddingBottom: spacing.sm,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    minHeight: layout.buttonHeight,
  },
  back: {
    width: layout.iconButtonSize,
    height: layout.iconButtonSize,
    marginLeft: -spacing.sm - spacing.xs,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: {
    flex: 1,
    paddingRight: spacing.sm,
  },
  title: {
    ...typography.screenHeader,
    color: colors.textOnNavy,
  },
  subtitle: {
    ...typography.metadata,
    color: colors.textOnNavyMuted,
    marginTop: spacing.xs,
  },
  right: {
    flexShrink: 0,
  },
});

export default HeaderBand;

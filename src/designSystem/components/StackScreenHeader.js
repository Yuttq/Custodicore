import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback } from 'react';
import { Pressable, Text, View } from 'react-native';
import { goBackOr } from '../../utils/safeNavigation';
import { colors } from '../tokens/colors';
import { commonStyles } from '../styles/common';

/**
 * Standard stack screen header — back control + centered title.
 * @param {object} props
 * @param {string} props.title
 * @param {() => void} [props.onBack] — custom back handler (overrides navigation fallback)
 * @param {import('@react-navigation/native').NavigationProp<Record<string, object | undefined>>} [props.navigation]
 * @param {{ name: string; params?: object }} [props.backFallback] — used when stack cannot go back
 * @param {string} [props.eyebrow] — small label above title (e.g. "Upload")
 * @param {import('react-native').ViewStyle} [props.style]
 */
export function StackScreenHeader({
  title,
  onBack,
  navigation,
  backFallback,
  eyebrow,
  style,
}) {
  const handleBack = useCallback(() => {
    if (onBack) {
      onBack();
      return;
    }
    if (navigation) {
      goBackOr(navigation, backFallback);
    }
  }, [onBack, navigation, backFallback]);

  return (
    <View style={[commonStyles.stackHeader, style]}>
      <Pressable
        onPress={handleBack}
        style={commonStyles.backButton}
        accessibilityRole="button"
        accessibilityLabel="Go back"
      >
        <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
      </Pressable>
      <View style={commonStyles.stackTitleWrap}>
        {eyebrow ? (
          <Text style={commonStyles.stackEyebrow} numberOfLines={1}>
            {eyebrow}
          </Text>
        ) : null}
        <Text
          style={commonStyles.stackTitle}
          numberOfLines={1}
          accessibilityRole="header"
        >
          {title}
        </Text>
      </View>
      <View style={commonStyles.backPlaceholder} />
    </View>
  );
}

export default StackScreenHeader;

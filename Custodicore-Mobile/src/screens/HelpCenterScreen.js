import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Card,
  StackScreenHeader,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { getFacilityContact } from '../services/api';
import { normalizeFacilityContact, telLink } from '../utils/facilityContact';

/**
 * Short answers describing how the app works today — no new policies.
 */
const FAQS = [
  {
    question: 'How does verification work?',
    answer:
      'First open the link we email you, then sign in. Facility staff review your details and government ID and approve your account; you will get a notification. Your relationship to each PDL is reviewed separately.',
  },
  {
    question: 'What if a document is rejected?',
    answer:
      'Open Profile → Verification Documents to see the staff reason. Upload a new, clear photo (JPG, PNG or WEBP, up to 10 MB) and staff will review it again.',
  },
  {
    question: 'How do I schedule a visit?',
    answer:
      'Once your account and PDL relationship are approved, tap Request a Visit on Home, pick an available date and submit. You will be notified when staff approve it. Visits staff assign to you are confirmed in My Visits.',
  },
  {
    question: 'How do I use the QR pass?',
    answer:
      'Your pass appears in the QR Pass tab on the day of your confirmed visit. Show it together with your valid ID at the facility.',
  },
];

/**
 * One FAQ row: the question is a heading holding a toggle button; the answer
 * is shown only while expanded.
 */
function FaqItem({ question, answer, expanded, onToggle, last }) {
  return (
    <View style={[styles.faqItem, !last && styles.faqDivider]}>
      <View accessibilityRole="header">
        <Pressable
          onPress={onToggle}
          accessibilityRole="button"
          accessibilityLabel={question}
          accessibilityState={{ expanded }}
          accessibilityHint={expanded ? 'Hides the answer' : 'Shows the answer'}
          style={({ pressed }) => [styles.questionRow, pressed && styles.pressed]}
        >
          <Text style={styles.question}>{question}</Text>
          <Ionicons
            name={expanded ? 'chevron-up' : 'chevron-down'}
            size={18}
            color={colors.textSecondary}
          />
        </Pressable>
      </View>
      {expanded ? <Text style={styles.answer}>{answer}</Text> : null}
    </View>
  );
}

/**
 * Profile → Help Center: the facility contact the Admin set on the web
 * Settings page (hidden until there is a phone number) and FAQs.
 */
export default function HelpCenterScreen({ navigation }) {
  const [contact, setContact] = useState(
    /** @type {{ name: string | null; phone: string; hours: string | null } | null} */ (null),
  );
  // Questions whose answers are open; all start closed, several may be open.
  const [openQuestions, setOpenQuestions] = useState(() => new Set());

  useEffect(() => {
    let active = true;
    getFacilityContact()
      .then((data) => {
        if (active) setContact(normalizeFacilityContact(data));
      })
      .catch(() => {
        // No contact card rather than an error: the FAQs still help.
      });
    return () => {
      active = false;
    };
  }, []);

  const toggleQuestion = useCallback((question) => {
    setOpenQuestions((prev) => {
      const next = new Set(prev);
      if (next.has(question)) next.delete(question);
      else next.add(question);
      return next;
    });
  }, []);

  const callHref = contact ? telLink(contact.phone) : null;

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Help Center" navigation={navigation} />
      <ScrollView contentContainerStyle={commonStyles.scrollContent} showsVerticalScrollIndicator={false}>
        {contact ? (
          <Card style={styles.card}>
            <Text style={styles.sectionTitle} accessibilityRole="header">
              Contact the Facility
            </Text>
            <View style={styles.contactRow}>
              <View style={styles.contactIcon}>
                <Ionicons name="call-outline" size={18} color={colors.primaryTealDark} />
              </View>
              <View style={styles.contactText}>
                <Text style={styles.phone} selectable>
                  {contact.phone}
                </Text>
                {contact.name || contact.hours ? (
                  <Text style={styles.meta}>
                    {[contact.name, contact.hours].filter(Boolean).join(' · ')}
                  </Text>
                ) : null}
              </View>
              {callHref ? (
                <Pressable
                  onPress={() => Linking.openURL(callHref).catch(() => {})}
                  accessibilityRole="button"
                  accessibilityLabel={`Call ${contact.phone}`}
                  hitSlop={8}
                  style={({ pressed }) => [styles.callButton, pressed && styles.pressed]}
                >
                  <Text style={styles.callText}>Call</Text>
                </Pressable>
              ) : null}
            </View>
          </Card>
        ) : null}

        <Card style={[styles.card, contact ? styles.cardGap : null]}>
          <Text style={styles.sectionTitle} accessibilityRole="header">
            FAQs
          </Text>
          {FAQS.map((faq, i) => (
            <FaqItem
              key={faq.question}
              question={faq.question}
              answer={faq.answer}
              expanded={openQuestions.has(faq.question)}
              onToggle={() => toggleQuestion(faq.question)}
              last={i === FAQS.length - 1}
            />
          ))}
        </Card>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: layout.cardRadius,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.sm,
    paddingBottom: spacing.xs,
  },
  cardGap: {
    marginTop: spacing.sm,
  },
  sectionTitle: {
    ...typography.statusLabel,
    textTransform: 'uppercase',
    letterSpacing: 0.6,
    color: colors.textSecondary,
    paddingVertical: spacing.xs,
  },
  contactRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.sm,
  },
  contactIcon: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.background,
  },
  contactText: {
    flex: 1,
  },
  phone: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  meta: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  callButton: {
    minHeight: 36,
    paddingHorizontal: spacing.md,
    borderRadius: layout.buttonRadius,
    backgroundColor: colors.primaryTealDark,
    alignItems: 'center',
    justifyContent: 'center',
  },
  callText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.white,
  },
  faqItem: {
    paddingVertical: 2,
  },
  faqDivider: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  questionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    minHeight: 44,
  },
  pressed: {
    opacity: 0.7,
  },
  question: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  answer: {
    ...typography.metadata,
    color: colors.textSecondary,
    paddingBottom: spacing.sm,
  },
});

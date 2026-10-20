import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
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
import { getMockFacilityContact } from '../mock/assignedVisits.mock';

/** Shown until the facility confirms its official contact information. */
const SAMPLE_CONTACT_NOTICE =
  'Sample contact details. These will be replaced once the facility confirms its official contact information.';

const CHECK_IN_REMINDER = 'Bring your valid ID. Present both QR code and ID during check-in.';

/**
 * Answers describe how the app works today (email verification, staff
 * review, Verification Documents, My Visits, QR Pass) — no new policies.
 */
const FAQS = [
  {
    question: 'How does account verification work?',
    answer:
      'Verifying your email and getting your account approved are separate steps. First, open the link sent to your email to verify your address, then sign in. Facility staff then review your information and approve your account after they verify your government ID; you will get a notification once it is approved. As a separate step, staff review your relationship documents and connect your account to the PDL you want to visit.',
  },
  {
    question: 'What if a document is rejected?',
    answer:
      'Go to Profile → Verification Documents and open the document. If staff gave a reason for the rejection, it is shown there. If the app lets you upload a replacement, upload a new, clear photo (JPG, PNG or WEBP, up to 10 MB) and staff will review it again. Verified documents cannot be replaced. If your whole account was not approved, check your documents or contact facility staff.',
  },
  {
    question: 'How do I schedule a visit?',
    answer:
      'You can request a visit after staff approve your account and verify your relationship to the PDL. Open My Visits, or tap Request a Visit on Home, choose an available date, and submit. Staff review the request, and you will be notified when it is approved or not approved. Staff may also assign you a visit, which you confirm or decline in My Visits. Some dates may be full or unavailable because of the weekly visit limit.',
  },
  {
    question: 'How do I use the QR pass at check-in?',
    answer:
      'Your QR pass appears in the QR Pass tab once you have a confirmed visit. At the facility, show the QR code together with your valid ID. The pass is valid only for your visit window; once it is no longer valid, the app says so.',
  },
];

/**
 * One FAQ: the question is a heading holding a toggle button; the answer is
 * shown only while expanded.
 */
function FaqItem({ question, answer, expanded, onToggle }) {
  return (
    <Card style={styles.card}>
      <View accessibilityRole="header">
        <Pressable
          onPress={onToggle}
          accessibilityRole="button"
          accessibilityLabel={question}
          accessibilityState={{ expanded }}
          accessibilityHint={expanded ? 'Hides the answer' : 'Shows the answer'}
          style={({ pressed }) => [styles.questionRow, pressed && styles.questionRowPressed]}
        >
          <Text style={styles.question}>{question}</Text>
          <Ionicons
            name={expanded ? 'chevron-up' : 'chevron-down'}
            size={20}
            color={colors.textSecondary}
          />
        </Pressable>
      </View>
      {expanded ? <Text style={[styles.body, styles.answer]}>{answer}</Text> : null}
    </Card>
  );
}

/** Profile → Help Center: facility contact, check-in reminder and FAQs (static). */
export default function HelpCenterScreen({ navigation }) {
  const contact = getMockFacilityContact();
  // Questions whose answers are open; all start closed, several may be open.
  const [openQuestions, setOpenQuestions] = useState(() => new Set());

  const toggleQuestion = useCallback((question) => {
    setOpenQuestions((prev) => {
      const next = new Set(prev);
      if (next.has(question)) next.delete(question);
      else next.add(question);
      return next;
    });
  }, []);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Help Center" navigation={navigation} />
      <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
        <Card style={styles.card}>
          <Text style={styles.sectionTitle} accessibilityRole="header">
            Facility Contact
          </Text>
          <View style={styles.noticeBox}>
            <Ionicons name="information-circle-outline" size={18} color={colors.primaryNavy} />
            <Text style={styles.noticeText}>{SAMPLE_CONTACT_NOTICE}</Text>
          </View>
          <Text style={styles.body}>
            For visit scheduling, verification, or facility questions, contact:
          </Text>
          <Text style={styles.contactName}>{contact.facilityName}</Text>
          <Text style={styles.body}>{contact.contactNumber}</Text>
          <Text style={styles.metadata}>{contact.officeAvailability}</Text>
        </Card>

        <Card style={styles.card}>
          <Text style={styles.sectionTitle} accessibilityRole="header">
            Check-in Reminder
          </Text>
          <Text style={styles.body}>{CHECK_IN_REMINDER}</Text>
        </Card>

        <Text style={styles.groupTitle} accessibilityRole="header">
          Frequently Asked Questions
        </Text>
        {FAQS.map((faq) => (
          <FaqItem
            key={faq.question}
            question={faq.question}
            answer={faq.answer}
            expanded={openQuestions.has(faq.question)}
            onToggle={() => toggleQuestion(faq.question)}
          />
        ))}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  content: {
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
  },
  card: {
    marginBottom: layout.cardGap,
  },
  sectionTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  groupTitle: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
    marginTop: spacing.sm,
    marginBottom: spacing.sm,
  },
  questionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    minHeight: 44,
  },
  questionRowPressed: {
    opacity: 0.7,
  },
  question: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    flex: 1,
  },
  answer: {
    marginTop: spacing.xs,
  },
  body: {
    ...typography.body,
    color: colors.textPrimary,
  },
  metadata: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  contactName: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    marginTop: spacing.sm,
  },
  noticeBox: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    marginBottom: spacing.sm,
    padding: spacing.sm,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
  },
  noticeText: {
    ...typography.metadata,
    color: colors.textPrimary,
    flex: 1,
  },
});

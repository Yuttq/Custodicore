import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createStackNavigator } from '@react-navigation/stack';
import React, { useEffect } from 'react';
import { StyleSheet, View } from 'react-native';
import * as ExpoSplashScreen from 'expo-splash-screen';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import BottomNav from '../components/BottomNav';
import Header from '../components/Header';
import LoadingSpinner from '../components/LoadingSpinner';
import { colors as dsColors } from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { MainTabBarIcon } from './mainTabBarIcons';
import CheckEmailScreen from '../screens/CheckEmailScreen';
import DashboardScreen from '../screens/DashboardScreen';
import ForgotPasswordScreen from '../screens/ForgotPasswordScreen';
import ForgotPasswordSuccessScreen from '../screens/ForgotPasswordSuccessScreen';
import LinkGoogleAccountScreen from '../screens/LinkGoogleAccountScreen';
import LoginScreen from '../screens/LoginScreen';
import NotificationsScreen from '../screens/NotificationsScreen';
import PersonalInformationScreen from '../screens/PersonalInformationScreen';
import ProfileScreen from '../screens/ProfileScreen';
import QRCodeScreen from '../screens/QRCodeScreen';
import RegisterScreen from '../screens/RegisterScreen';
import MyAssignedVisitsScreen from '../screens/MyAssignedVisitsScreen';
import SplashScreen from '../screens/SplashScreen';
import TimelineScreen from '../screens/TimelineScreen';
import UploadIDScreen from '../screens/UploadIDScreen';
import VerificationDocumentDetailScreen from '../screens/VerificationDocumentDetailScreen';
import VisitorVerificationDocumentsScreen from '../screens/VisitorVerificationDocumentsScreen';
import VerificationReviewScreen from '../screens/VerificationReviewScreen';
import UnableToAttendScreen from '../screens/UnableToAttendScreen';
import VisitDetailsScreen from '../screens/VisitDetailsScreen';
import VisitHistoryDetailScreen from '../screens/VisitHistoryDetailScreen';
import VisitHistoryScreen from '../screens/VisitHistoryScreen';
import VisitTrackingScreen from '../screens/VisitTrackingScreen';

const RootStack = createStackNavigator();
const AuthStack = createStackNavigator();
const AppStack = createStackNavigator();
const ReviewStackNav = createStackNavigator();
const Tab = createBottomTabNavigator();

/** Stack header for Login / Register — uses shared Header + top safe inset. */
function AuthStackHeader({ route, navigation }) {
  const insets = useSafeAreaInsets();
  const title =
    route.name === 'Register' ? 'Create account' : route.name === 'Login' ? '' : '';
  const showBack = route.name === 'Register' || route.name === 'Login';
  const backTarget = route.name === 'Register' ? 'Login' : 'Splash';

  return (
    <View style={[styles.authHeaderWrap, { paddingTop: insets.top }]}>
      <Header
        title={title}
        showBackButton={showBack}
        onBackPress={() => navigation.navigate(backTarget)}
      />
    </View>
  );
}

/** Visitor auth flow: sign in and registration (separate from officer/admin systems). */
function AuthNavigator() {
  return (
    <AuthStack.Navigator
      screenOptions={{
        header: (props) => <AuthStackHeader {...props} />,
        cardStyle: { backgroundColor: dsColors.white },
        headerShadowVisible: false,
      }}
    >
      <AuthStack.Screen name="Splash" component={SplashScreen} options={{ headerShown: false }} />
      <AuthStack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
      <AuthStack.Screen
        name="LinkGoogleAccount"
        component={LinkGoogleAccountScreen}
        options={{ headerShown: false }}
      />
      <AuthStack.Screen
        name="ForgotPassword"
        component={ForgotPasswordScreen}
        options={{ headerShown: false }}
      />
      <AuthStack.Screen
        name="ForgotPasswordSuccess"
        component={ForgotPasswordSuccessScreen}
        options={{ headerShown: false }}
      />
      <AuthStack.Screen
        name="Register"
        component={RegisterScreen}
        options={{ headerShown: false }}
      />
      <AuthStack.Screen
        name="CheckEmail"
        component={CheckEmailScreen}
        options={{ headerShown: false }}
      />
    </AuthStack.Navigator>
  );
}

/** Main app tabs — Dashboard first (visitor home). */
function MainTabs() {
  return (
    <Tab.Navigator
      tabBar={(props) => <BottomNav {...props} />}
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: dsColors.primaryTeal,
        tabBarInactiveTintColor: dsColors.textSecondary,
        tabBarShowLabel: true,
      }}
    >
      <Tab.Screen
        name="Dashboard"
        component={DashboardScreen}
        options={{
          title: 'Home',
          tabBarAccessibilityLabel: 'Home tab',
          tabBarIcon: (props) => <MainTabBarIcon routeName="Dashboard" {...props} />,
        }}
      />
      <Tab.Screen
        name="Schedule"
        component={MyAssignedVisitsScreen}
        options={{
          title: 'My Visits',
          tabBarAccessibilityLabel: 'My visits tab',
          tabBarIcon: (props) => <MainTabBarIcon routeName="Schedule" {...props} />,
        }}
      />
      <Tab.Screen
        name="QR"
        component={QRCodeScreen}
        options={{
          title: 'QR Pass',
          tabBarAccessibilityLabel: 'QR pass tab',
        }}
      />
      <Tab.Screen
        name="Notifications"
        component={NotificationsScreen}
        options={{
          title: 'Notifications',
          tabBarAccessibilityLabel: 'Notifications tab',
          tabBarIcon: (props) => <MainTabBarIcon routeName="Notifications" {...props} />,
        }}
      />
      <Tab.Screen
        name="Profile"
        component={ProfileScreen}
        options={{
          title: 'Profile',
          tabBarAccessibilityLabel: 'Profile tab',
          tabBarIcon: (props) => <MainTabBarIcon routeName="Profile" {...props} />,
        }}
      />
    </Tab.Navigator>
  );
}

/**
 * Signed in, email verified, but staff have not approved the visitor's
 * information/documents (pending or rejected): the review screen plus the
 * screens needed to fix documents/profile — no visits, schedules or QR.
 * The backend enforces the same restriction (EnsureVisitorApproved).
 */
function ReviewStack() {
  return (
    <ReviewStackNav.Navigator
      screenOptions={{
        headerShown: false,
        cardStyle: { backgroundColor: dsColors.background },
      }}
    >
      <ReviewStackNav.Screen name="VerificationReview" component={VerificationReviewScreen} />
      <ReviewStackNav.Screen
        name="VisitorVerificationDocuments"
        component={VisitorVerificationDocumentsScreen}
      />
      <ReviewStackNav.Screen
        name="VerificationDocumentDetail"
        component={VerificationDocumentDetailScreen}
      />
      <ReviewStackNav.Screen name="UploadID" component={UploadIDScreen} />
      <ReviewStackNav.Screen name="PersonalInformation" component={PersonalInformationScreen} />
    </ReviewStackNav.Navigator>
  );
}

/** Approved visitor: tabs plus auxiliary visitor screens (ID upload, history, timeline). */
function AuthenticatedStack() {
  return (
    <AppStack.Navigator
      initialRouteName="MainTabs"
      screenOptions={{
        headerShown: false,
        cardStyle: { backgroundColor: dsColors.background },
      }}
    >
      <AppStack.Screen name="MainTabs" component={MainTabs} />
      <AppStack.Screen name="UploadID" component={UploadIDScreen} />
      <AppStack.Screen
        name="VisitorVerificationDocuments"
        component={VisitorVerificationDocumentsScreen}
      />
      <AppStack.Screen
        name="VerificationDocumentDetail"
        component={VerificationDocumentDetailScreen}
      />
      <AppStack.Screen name="VisitHistory" component={VisitHistoryScreen} />
      <AppStack.Screen name="VisitHistoryDetail" component={VisitHistoryDetailScreen} />
      <AppStack.Screen name="PersonalInformation" component={PersonalInformationScreen} />
      <AppStack.Screen name="VisitDetails" component={VisitDetailsScreen} />
      <AppStack.Screen name="UnableToAttend" component={UnableToAttendScreen} />
      <AppStack.Screen name="VisitTracking" component={VisitTrackingScreen} />
      <AppStack.Screen name="Timeline" component={TimelineScreen} />
    </AppStack.Navigator>
  );
}

export default function AppNavigator() {
  const { token, initializing, isApprovedVisitor } = useAuth();

  useEffect(() => {
    if (initializing) return;
    if (__DEV__) console.log('AUTH INITIALIZED');
    ExpoSplashScreen.hideAsync()
      .then(() => {
        if (__DEV__) console.log('SPLASH HIDDEN');
      })
      .catch(() => {});
  }, [initializing]);

  if (initializing) {
    return <LoadingSpinner message="Starting CustodiCore…" />;
  }

  return (
    <RootStack.Navigator
      screenOptions={{
        headerShown: false,
        cardStyle: { backgroundColor: dsColors.background },
      }}
    >
      {/* Backend truth (/me verificationStatus) decides full vs. restricted access. */}
      {token && isApprovedVisitor ? (
        <RootStack.Screen name="App" component={AuthenticatedStack} />
      ) : token ? (
        <RootStack.Screen name="Review" component={ReviewStack} />
      ) : (
        <RootStack.Screen name="Auth" component={AuthNavigator} />
      )}
    </RootStack.Navigator>
  );
}

const styles = StyleSheet.create({
  authHeaderWrap: {
    backgroundColor: dsColors.white,
  },
});

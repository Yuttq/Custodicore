import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createStackNavigator } from '@react-navigation/stack';
import React from 'react';
import { StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import BottomNav from '../components/BottomNav';
import Header from '../components/Header';
import LoadingSpinner from '../components/LoadingSpinner';
import { colors as legacyColors } from '../constants';
import { colors as dsColors } from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { MainTabBarIcon } from './mainTabBarIcons';
import DashboardScreen from '../screens/DashboardScreen';
import ForgotPasswordScreen from '../screens/ForgotPasswordScreen';
import ForgotPasswordSuccessScreen from '../screens/ForgotPasswordSuccessScreen';
import LoginScreen from '../screens/LoginScreen';
import NotificationsScreen from '../screens/NotificationsScreen';
import ProfileScreen from '../screens/ProfileScreen';
import QRCodeScreen from '../screens/QRCodeScreen';
import RegisterScreen from '../screens/RegisterScreen';
import MyAssignedVisitsScreen from '../screens/MyAssignedVisitsScreen';
import SplashScreen from '../screens/SplashScreen';
import TimelineScreen from '../screens/TimelineScreen';
import UploadIDScreen from '../screens/UploadIDScreen';
import VisitorVerificationDocumentsScreen from '../screens/VisitorVerificationDocumentsScreen';
import VerificationReviewScreen from '../screens/VerificationReviewScreen';
import UnableToAttendScreen from '../screens/UnableToAttendScreen';
import VisitDetailsScreen from '../screens/VisitDetailsScreen';
import VisitHistoryScreen from '../screens/VisitHistoryScreen';
import VisitTrackingScreen from '../screens/VisitTrackingScreen';

const RootStack = createStackNavigator();
const AuthStack = createStackNavigator();
const AppStack = createStackNavigator();
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
        cardStyle: { backgroundColor: legacyColors.white },
        headerShadowVisible: false,
      }}
    >
      <AuthStack.Screen name="Splash" component={SplashScreen} options={{ headerShown: false }} />
      <AuthStack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
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

/** Authenticated stack: tabs plus auxiliary visitor screens (ID upload, history, timeline). */
function AuthenticatedStack() {
  const { pendingVerification } = useAuth();

  return (
    <AppStack.Navigator
      initialRouteName={pendingVerification ? 'VerificationReview' : 'MainTabs'}
      screenOptions={{
        headerShown: false,
        cardStyle: { backgroundColor: legacyColors.lightGray },
      }}
    >
      <AppStack.Screen
        name="VerificationReview"
        component={VerificationReviewScreen}
      />
      <AppStack.Screen name="MainTabs" component={MainTabs} />
      <AppStack.Screen name="UploadID" component={UploadIDScreen} />
      <AppStack.Screen
        name="VisitorVerificationDocuments"
        component={VisitorVerificationDocumentsScreen}
      />
      <AppStack.Screen name="VisitHistory" component={VisitHistoryScreen} />
      <AppStack.Screen name="VisitDetails" component={VisitDetailsScreen} />
      <AppStack.Screen name="UnableToAttend" component={UnableToAttendScreen} />
      <AppStack.Screen name="VisitTracking" component={VisitTrackingScreen} />
      <AppStack.Screen name="Timeline" component={TimelineScreen} />
    </AppStack.Navigator>
  );
}

export default function AppNavigator() {
  const { token, initializing } = useAuth();

  if (initializing) {
    return <LoadingSpinner message="Starting CustodiCore…" />;
  }

  return (
    <RootStack.Navigator
      screenOptions={{
        headerShown: false,
        cardStyle: { backgroundColor: legacyColors.lightGray },
      }}
    >
      {token ? (
        <RootStack.Screen name="App" component={AuthenticatedStack} />
      ) : (
        <RootStack.Screen name="Auth" component={AuthNavigator} />
      )}
    </RootStack.Navigator>
  );
}

const styles = StyleSheet.create({
  authHeaderWrap: {
    backgroundColor: legacyColors.white,
  },
});

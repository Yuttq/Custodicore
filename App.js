import 'react-native-gesture-handler';
import { NavigationContainer } from '@react-navigation/native';
import * as ExpoSplashScreen from 'expo-splash-screen';
import React from 'react';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import AppNavigator from './src/navigation/AppNavigator';
import { NotificationBadgeProvider } from './src/context/NotificationBadgeContext';
import { VisitsProvider } from './src/context/VisitsContext';
import { AuthProvider } from './src/hooks/useAuth';

ExpoSplashScreen.preventAutoHideAsync().catch(() => {});

if (__DEV__) {
  console.log('APP STARTED');
}

export default function App() {
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        <AuthProvider>
          <VisitsProvider>
            <NotificationBadgeProvider>
              <NavigationContainer
                onReady={() => {
                  if (__DEV__) console.log('NAVIGATION READY');
                }}
              >
                <AppNavigator />
              </NavigationContainer>
            </NotificationBadgeProvider>
          </VisitsProvider>
        </AuthProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}

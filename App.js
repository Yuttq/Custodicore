import 'react-native-gesture-handler';
import { NavigationContainer } from '@react-navigation/native';
import React from 'react';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import AppNavigator from './src/navigation/AppNavigator';
import { NotificationBadgeProvider } from './src/context/NotificationBadgeContext';
import { VisitsProvider } from './src/context/VisitsContext';
import { AuthProvider } from './src/hooks/useAuth';

export default function App() {
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        <AuthProvider>
          <VisitsProvider>
            <NotificationBadgeProvider>
              <NavigationContainer>
                <AppNavigator />
              </NavigationContainer>
            </NotificationBadgeProvider>
          </VisitsProvider>
        </AuthProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}


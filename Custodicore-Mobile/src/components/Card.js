import React from 'react';
import { Card as DSCard } from '../designSystem';

/**
 * @deprecated Prefer `Card` from `src/designSystem`.
 */
export default function Card(props) {
  return <DSCard {...props} />;
}

/** @typedef {'all' | 'visits' | 'updates' | 'system'} NotificationFilterKey */
/** @typedef {'visits' | 'updates' | 'system'} NotificationCategory */
/** @typedef {'today' | 'yesterday' | 'thisWeek' | 'earlier'} NotificationDateGroupKey */

export const NOTIFICATION_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'visits', label: 'Visits' },
  { key: 'updates', label: 'Updates' },
  { key: 'system', label: 'System' },
];

export const NOTIFICATION_GROUP_ORDER = ['today', 'yesterday', 'thisWeek', 'earlier'];

export const NOTIFICATION_GROUP_LABELS = {
  today: 'Today',
  yesterday: 'Yesterday',
  thisWeek: 'This Week',
  earlier: 'Earlier',
};

/**
 * @param {Date} date
 */
function startOfDay(date) {
  const next = new Date(date);
  next.setHours(0, 0, 0, 0);
  return next;
}

/**
 * @param {string | null | undefined} isoDate
 * @returns {NotificationDateGroupKey}
 */
export function getNotificationDateGroup(isoDate) {
  if (!isoDate) return 'earlier';
  const parsed = new Date(isoDate);
  if (Number.isNaN(parsed.getTime())) return 'earlier';

  const today = startOfDay(new Date());
  const itemDay = startOfDay(parsed);
  const diffDays = Math.floor((today.getTime() - itemDay.getTime()) / 86_400_000);

  if (diffDays === 0) return 'today';
  if (diffDays === 1) return 'yesterday';

  const dayOfWeek = today.getDay();
  const mondayOffset = dayOfWeek === 0 ? 6 : dayOfWeek - 1;
  const weekStart = new Date(today);
  weekStart.setDate(today.getDate() - mondayOffset);

  if (itemDay >= weekStart) return 'thisWeek';
  return 'earlier';
}

/**
 * @param {Record<string, unknown>} raw
 * @returns {NotificationCategory}
 */
export function inferNotificationCategory(raw) {
  if (typeof raw.category === 'string') {
    const category = raw.category.toLowerCase();
    if (category === 'visits' || category === 'updates' || category === 'system') {
      return category;
    }
  }
  if (typeof raw.type === 'string') {
    const type = raw.type.toLowerCase();
    if (type === 'visits' || type === 'updates' || type === 'system') {
      return type;
    }
  }

  const title = String(raw.title ?? raw.subject ?? '').toLowerCase();
  if (title.includes('visit')) return 'visits';
  if (
    title.includes('schedule') ||
    title.includes('announcement') ||
    title.includes('update') ||
    title.includes('reminder')
  ) {
    return 'updates';
  }
  return 'system';
}

/**
 * @template {{ category: NotificationCategory; createdAt: string | null }} T
 * @param {T[]} items
 * @param {NotificationFilterKey} filter
 * @returns {T[]}
 */
export function filterNotificationsByCategory(items, filter) {
  if (filter === 'all') return items;
  return items.filter((item) => item.category === filter);
}

/**
 * @template {{ createdAt: string | null }} T
 * @param {T[]} items
 * @returns {{ title: string; data: T[]; isFirst: boolean }[]}
 */
export function groupNotificationsByDate(items) {
  /** @type {Record<NotificationDateGroupKey, T[]>} */
  const buckets = {
    today: [],
    yesterday: [],
    thisWeek: [],
    earlier: [],
  };

  items.forEach((item) => {
    const group = getNotificationDateGroup(item.createdAt);
    buckets[group].push(item);
  });

  NOTIFICATION_GROUP_ORDER.forEach((key) => {
    buckets[key].sort(
      (a, b) => new Date(b.createdAt ?? 0).getTime() - new Date(a.createdAt ?? 0).getTime(),
    );
  });

  return NOTIFICATION_GROUP_ORDER.filter((key) => buckets[key].length > 0).map((key, index) => ({
    title: NOTIFICATION_GROUP_LABELS[key],
    data: buckets[key],
    isFirst: index === 0,
  }));
}

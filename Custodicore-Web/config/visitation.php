<?php

/*
|--------------------------------------------------------------------------
| Visitation schedule settings and visitor announcements
|--------------------------------------------------------------------------
| The actual visiting days, time slots and capacity are DATA in the
| facility_visitation_rules table (see DatabaseSeeder) — that table is the
| authority used by App\Services\ScheduleAvailabilityService. This file only
| holds:
|
|   - how far ahead the mobile app may look at availability, and
|   - the announcement text shown on the visitor Home screen
|     (GET /api/announcements).
|
| Announcements are plain config for now (no database table, no staff page).
| To change one, edit its text below. To add one, copy an entry and give it
| a new unique `id`. `published_at` is a date (Y-m-d) used for ordering and
| shown as the announcement date; newest first. `pinned` entries are listed
| before the others.
*/

return [
    'timezone' => 'Asia/Manila',

    // Default look-ahead when the app does not send `to`.
    'availability_days' => 30,

    // Largest from..to range a single availability request may ask for.
    'max_range_days' => 62,

    'announcements' => [
        [
            'id' => 'visiting-days',
            'title' => 'Visiting Days',
            'body' => 'Visits for drug-related PDLs are on Thursdays and Saturdays. Visits for non-drug-related PDLs are on Fridays and Sundays. Your available days in the app follow the classification of the PDL you are verified to visit.',
            'category' => 'schedule',
            'published_at' => '2026-10-07',
            'pinned' => true,
        ],
        [
            'id' => 'visiting-hours',
            'title' => 'Visiting Hours',
            'body' => 'Morning visits are from 9:00 AM to 11:30 AM. Afternoon visits are from 1:00 PM to 4:30 PM. Each time slot has a limited number of visitors.',
            'category' => 'schedule',
            'published_at' => '2026-10-07',
            'pinned' => true,
        ],
        [
            'id' => 'visiting-areas',
            'title' => 'Visiting Areas',
            'body' => 'Visits are held at the Main Building. Visitors of PDLs housed in the secondary (DOB) area will be directed by the Front Desk officer on arrival.',
            'category' => 'facility',
            'published_at' => '2026-10-07',
            'pinned' => false,
        ],
        [
            'id' => 'arrival-reminder',
            'title' => 'Before You Visit',
            'body' => 'Arrive at least 15 minutes before your time slot and bring the valid government ID you registered with. Have your QR pass ready at the Front Desk.',
            'category' => 'reminder',
            'published_at' => '2026-10-07',
            'pinned' => false,
        ],
    ],
];

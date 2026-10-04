<?php

/*
|--------------------------------------------------------------------------
| Terms & Conditions and Privacy Policy
|--------------------------------------------------------------------------
| Single source of truth for BOTH clients: the web pages (/terms, /privacy)
| and the mobile app (GET /api/legal) render exactly this text, so the two
| can never drift apart.
|
| `version` is stored on every account that accepts (accounts.consent_version).
| Bump it whenever the wording changes in a way people must re-accept.
|
| `draft` => true shows a "pending legal review" notice on the web pages and
| in the app. This text is a working draft based on the Data Privacy Act of
| 2012 (RA 10173); the facility's legal counsel / Data Protection Officer
| must review and approve it, then set `draft` to false.
|
| Optional .env:  LEGAL_DPO_CONTACT="Name / office / email / phone of the DPO"
*/

$dpo = env('LEGAL_DPO_CONTACT');

return [
    'version' => '2026-10-04',
    'effective_date' => 'October 4, 2026',
    'draft' => true,

    'terms' => [
        'title' => 'Terms and Conditions',
        'intro' => 'These Terms govern your use of CustodiCore, the visitation management system of the Bureau of Jail Management and Penology (BJMP) facility, through its website and mobile app. By creating an account you agree to them.',
        'sections' => [
            [
                'heading' => '1. Who may register',
                'body' => [
                    'Visitors register through the mobile app with their own details. BJMP officers are registered by a System Administrator. Records of persons deprived of liberty (PDLs) are created by authorized Records Officers.',
                    'You must give true, complete and current information. Giving false information, or using another person\'s identity or documents, may lead to refusal or cancellation of visits and may be referred to the proper authorities.',
                ],
            ],
            [
                'heading' => '2. Your account',
                'body' => [
                    'Your account is personal. Keep your password secret and do not let anyone else use it. You are responsible for activity under your account until you tell facility staff it has been compromised.',
                    'Staff must re-enter their own password to confirm any change to someone else\'s details. This is a safeguard and is recorded in the audit trail.',
                ],
            ],
            [
                'heading' => '3. Verification and visits',
                'body' => [
                    'Registering does not by itself allow you to visit. Your identity and your relationship to the PDL must first be verified by facility staff using the documents you submit.',
                    'Visit requests, schedules, visiting days and times follow the facility\'s rules and may be changed, restricted or cancelled for security, health, legal or operational reasons. Confirmation of a schedule is not a guarantee that a visit will take place.',
                ],
            ],
            [
                'heading' => '4. Acceptable use',
                'body' => [
                    'You agree not to misuse the system: do not upload false, altered or unlawful documents, attempt to access other people\'s data, interfere with the system\'s security, or use it for any purpose other than lawful visitation with the facility.',
                ],
            ],
            [
                'heading' => '5. Suspension and changes',
                'body' => [
                    'The facility may suspend or deactivate an account, flag a visitor, or refuse a visit where these Terms or facility rules are breached or where required by law or a court order.',
                    'We may update these Terms. When the wording changes materially you will be asked to review and accept the new version.',
                ],
            ],
            [
                'heading' => '6. Privacy',
                'body' => [
                    'How your personal information is collected, used, shared, kept and protected is explained in the Privacy Policy, which forms part of these Terms.',
                ],
            ],
        ],
    ],

    'privacy' => [
        'title' => 'Privacy Policy',
        'intro' => 'This policy explains how CustodiCore and the BJMP facility handle personal information in line with the Data Privacy Act of 2012 (Republic Act No. 10173) and its implementing rules.',
        'sections' => [
            [
                'heading' => '1. What we collect',
                'body' => [
                    'Visitors: full name, date of birth, gender, address, contact number, email address, relationship to the PDL, photos of government-issued ID and supporting documents, and your visit requests, QR codes, check-in and check-out records.',
                    'Staff: full name, email address, role and employee number, sign-in activity, and actions taken in the system.',
                    'PDLs: identity and custody details, legal and disciplinary records, and restrictions, recorded by authorized Records Officers.',
                ],
            ],
            [
                'heading' => '2. Why we collect it',
                'body' => [
                    'To verify identities and relationships, assess visitation eligibility, schedule and manage visits, control entry and exit at the facility, keep the facility and its people safe, keep an audit trail, and comply with law and lawful orders.',
                    'We do not use your information for advertising, and we do not sell it.',
                ],
            ],
            [
                'heading' => '3. Who can see it',
                'body' => [
                    'Only authorized BJMP personnel who need it for their duties (Administrators, Records Officers and Front Desk Officers), within the access their role allows.',
                    'We may disclose information to courts, law enforcement and other government agencies where the law requires or allows it.',
                ],
            ],
            [
                'heading' => '4. How long we keep it',
                'body' => [
                    'Only as long as needed for the purposes above and as required by applicable records-retention rules, after which it is securely disposed of or anonymized.',
                ],
            ],
            [
                'heading' => '5. How we protect it',
                'body' => [
                    'Passwords are stored hashed, access is limited by role, staff re-confirm their password before changing records, important actions are written to an audit trail, and uploaded documents are not publicly accessible. No system is perfectly secure; we will handle any breach as required by law.',
                ],
            ],
            [
                'heading' => '6. Your rights',
                'body' => [
                    'You have the right to be informed, to access and to correct your personal data, to object to or withdraw consent for processing that depends on it, to request blocking or removal where the law allows, to be indemnified for damages from unlawful processing, and to lodge a complaint with the National Privacy Commission (privacy.gov.ph).',
                    'Some records, such as audit entries and information we are required by law to keep, cannot be deleted on request.',
                ],
            ],
            [
                'heading' => '7. Consent',
                'body' => [
                    'By ticking the boxes at registration you confirm that you have read this policy and the Terms and Conditions and agree to the processing described. We record the date and version of your acceptance. You may withdraw consent by contacting the facility, but we may then be unable to provide visitation services.',
                ],
            ],
            [
                'heading' => '8. Contact',
                'body' => [
                    $dpo
                        ? 'Questions, requests or complaints about your personal data: ' . $dpo
                        : 'Questions, requests or complaints about your personal data: please contact the facility\'s Data Protection Officer through the facility front desk.',
                ],
            ],
        ],
    ],
];

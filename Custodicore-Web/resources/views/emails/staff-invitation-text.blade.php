Hello {{ $name }},

A CustodiCore staff account{{ $roleName ? ' (' . $roleName . ')' : '' }} has been created for you. You can sign in with this email address ({{ $loginEmail }}) or with a username you choose in the next step.

For your security no password has been set for you. Open this link to choose your own password and confirm your details:

{{ $setupUrl }}

This link can be used once and expires in {{ $expiresInHours }} {{ $expiresInHours === 1 ? 'hour' : 'hours' }}. If it has expired, ask the Warden to send you a new one.

If you were not expecting this, ignore this message and tell the Warden. Never share this link with anyone.

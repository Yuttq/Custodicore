Hello {{ $name }},

Your CustodiCore visitor account has been created. Please confirm that this is your email address before signing in to the app:

{{ $verificationUrl }}

This link can be used once and expires in {{ $expiresInHours }} {{ $expiresInHours === 1 ? 'hour' : 'hours' }}. If it has expired, request a new one from the CustodiCore app.

If you did not create a CustodiCore account, you can ignore this email.

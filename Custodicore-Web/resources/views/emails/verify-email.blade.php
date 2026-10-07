<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your email</title>
</head>
<body style="margin:0;padding:0;background:#F8FAFC;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;padding:24px 12px;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border:1px solid #E5E7EB;border-radius:16px;">
                <tr><td style="background:#0F3D7A;color:#FFFFFF;padding:16px 24px;font-weight:700;border-radius:16px 16px 0 0;">CustodiCore</td></tr>
                <tr><td style="padding:28px 24px;line-height:1.6;">
                    <h1 style="margin:0 0 12px;font-size:22px;color:#0F3D7A;">Verify your email address</h1>
                    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
                    <p style="margin:0 0 20px;">Your CustodiCore visitor account has been created. Please confirm that this is your email address before signing in to the app.</p>
                    <p style="margin:0 0 24px;">
                        <a href="{{ $verificationUrl }}" style="display:inline-block;background:#0DA58A;color:#FFFFFF;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:10px;">Verify email address</a>
                    </p>
                    <p style="margin:0 0 12px;font-size:13px;color:#6B7280;">This link can be used once and expires in {{ $expiresInHours }} {{ $expiresInHours === 1 ? 'hour' : 'hours' }}. If it has expired, request a new one from the CustodiCore app.</p>
                    <p style="margin:0;font-size:13px;color:#6B7280;">If you did not create a CustodiCore account, you can ignore this email.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>

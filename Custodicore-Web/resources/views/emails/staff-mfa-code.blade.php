<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your sign-in code</title>
</head>
<body style="margin:0;padding:0;background:#F8FAFC;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;padding:24px 12px;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border:1px solid #E5E7EB;border-radius:16px;">
                <tr><td style="background:#0F3D7A;color:#FFFFFF;padding:16px 24px;font-weight:700;border-radius:16px 16px 0 0;">CustodiCore</td></tr>
                <tr><td style="padding:28px 24px;line-height:1.6;">
                    <h1 style="margin:0 0 12px;font-size:22px;color:#0F3D7A;">Your sign-in code</h1>
                    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
                    <p style="margin:0 0 16px;">Use this code to finish signing in to CustodiCore:</p>
                    <p style="margin:0 0 20px;font-size:32px;font-weight:700;letter-spacing:8px;color:#0F3D7A;">{{ $code }}</p>
                    <p style="margin:0 0 12px;font-size:13px;color:#6B7280;">The code can be used once and expires in {{ $minutes }} {{ $minutes === 1 ? 'minute' : 'minutes' }}.</p>
                    <p style="margin:0;font-size:13px;color:#6B7280;">If you did not just try to sign in, someone may know your password: change it and tell the Admin. Never share this code with anyone.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>

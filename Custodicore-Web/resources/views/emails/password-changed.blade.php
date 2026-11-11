<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Password changed</title>
</head>
<body style="margin:0;padding:0;background:#F8FAFC;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;padding:24px 12px;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border:1px solid #E5E7EB;border-radius:16px;">
                <tr><td style="background:#0F3D7A;color:#FFFFFF;padding:16px 24px;font-weight:700;border-radius:16px 16px 0 0;">CustodiCore</td></tr>
                <tr><td style="padding:28px 24px;line-height:1.6;">
                    <h1 style="margin:0 0 12px;font-size:22px;color:#0F3D7A;">Your password was changed</h1>
                    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
                    <p style="margin:0 0 12px;">The password for your CustodiCore account was changed on {{ $changedAt }}. Other devices signed in to your account have been signed out.</p>
                    <p style="margin:0 0 12px;">If you made this change, you don't need to do anything.</p>
                    <p style="margin:0;font-size:13px;color:#6B7280;">If you did not change your password, open the CustodiCore app, tap <strong>Forgot password</strong> on the sign-in screen to set a new one, and tell facility staff.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>

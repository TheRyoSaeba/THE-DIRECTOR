<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background-color:#020617;width:100%;-webkit-font-smoothing:antialiased;">

    {{-- Preheader: shows as preview text in the inbox list. --}}
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;color:transparent;">
        {{ $subjectLine }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#020617" style="background-color:#020617;">
        <tr>
            <td align="center" style="padding:32px 16px;">

                {{-- Container: 600px max width, dark card. --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#0f172a" style="max-width:600px;width:100%;background-color:#0f172a;border:1px solid #1e293b;border-radius:12px;">

                    {{-- Header: logo + brand wordmark, centered. --}}
                    <tr>
                        <td align="center" style="padding:40px 32px 28px;">
                            <img
                                src="https://images.thedirector.app/Careers/promo.png"
                                width="84"
                                height="84"
                                alt=""
                                style="display:block;width:84px;height:84px;border:0;outline:0;"
                            />
                            <p style="margin:18px 0 0;font-size:13px;font-weight:900;letter-spacing:0.32em;color:#22d3ee;text-transform:uppercase;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
                                The Director
                            </p>
                        </td>
                    </tr>

                    {{-- Cyan accent bar — matches the in-game announcements page. --}}
                    <tr>
                        <td style="padding:0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td height="3" bgcolor="#22d3ee" style="height:3px;line-height:3px;font-size:3px;background-color:#22d3ee;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body: title, greeting, message. --}}
                    <tr>
                        <td style="padding:36px 40px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
                            <h1 style="margin:0 0 24px;font-size:28px;line-height:1.25;font-weight:300;color:#ffffff;letter-spacing:-0.01em;">
                                {{ $subjectLine }}
                            </h1>
                            <p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#94a3b8;">
                                {{ $displayName }},
                            </p>
                            <div style="margin:0;font-size:15px;line-height:1.7;color:#e2e8f0;white-space:pre-line;">{{ $bodyText }}</div>
                        </td>
                    </tr>

                    {{-- CTA button: drives the user back to the game. --}}
                    <tr>
                        <td align="center" style="padding:8px 40px 36px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td bgcolor="#22d3ee" style="border-radius:6px;">
                                        <a
                                            href="https://thedirector.app/dashboard"
                                            style="display:inline-block;padding:12px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:11px;font-weight:900;letter-spacing:0.22em;text-transform:uppercase;color:#0f172a;text-decoration:none;border-radius:6px;"
                                        >
                                            Open The Director
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer: separator, why, unsubscribe. --}}
                    <tr>
                        <td style="padding:0 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td height="1" bgcolor="#1e293b" style="height:1px;line-height:1px;font-size:1px;background-color:#1e293b;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 40px 32px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
                            <p style="margin:0 0 8px;font-size:11px;line-height:1.6;color:#64748b;">
                                You're receiving this because you have an account on
                                <a href="https://thedirector.app" style="color:#22d3ee;text-decoration:none;">thedirector.app</a>.
                                We only email about major updates, betas, and game-wide events.
                            </p>
                            <p style="margin:0;font-size:11px;line-height:1.6;">
                                <a href="{{ $unsubscribeUrl }}" style="color:#94a3b8;text-decoration:underline;">Unsubscribe</a>
                                <span style="color:#475569;">&nbsp;·&nbsp;</span>
                                <a href="https://thedirector.app/settings" style="color:#94a3b8;text-decoration:underline;">Email preferences</a>
                            </p>
                        </td>
                    </tr>
                </table>

                {{-- Below-card footer: copyright. --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">
                    <tr>
                        <td align="center" style="padding:20px 32px 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:10px;letter-spacing:0.12em;color:#475569;text-transform:uppercase;">
                            &copy; {{ date('Y') }} The Director &middot; Greed is Good.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

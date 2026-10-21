<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<body style="margin:0;background:#f4f6f8;font-family:'IBM Plex Sans Arabic',Tahoma,Arial,sans-serif;color:#1c2430;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" style="max-width:480px;background:#ffffff;border:1px solid #e4e8ee;">
                    <tr>
                        <td style="padding:28px 28px 8px;font-size:18px;font-weight:700;text-align:{{ app()->getLocale() === 'ar' ? 'right' : 'left' }};">
                            {{ __('mail.password_reset.heading') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 20px;font-size:15px;line-height:1.7;color:#3d4754;text-align:{{ app()->getLocale() === 'ar' ? 'right' : 'left' }};">
                            {{ __('mail.password_reset.intro', ['name' => $recipientName]) }}
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 28px 20px;">
                            <div dir="ltr" style="display:inline-block;letter-spacing:8px;font-size:28px;font-weight:700;color:#1c2430;">{{ $code }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 28px 28px;font-size:13px;line-height:1.7;color:#5c6773;text-align:{{ app()->getLocale() === 'ar' ? 'right' : 'left' }};">
                            {{ __('mail.password_reset.expires', ['minutes' => $expiresMinutes]) }}<br>
                            {{ __('mail.password_reset.ignore') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

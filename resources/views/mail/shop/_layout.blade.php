{{-- Shared frame of the order emails (App\Mail\Shop\*): inline styles only, the way mail clients need them. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $siteName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 32px 0 32px;font-size:13px;color:#6b7280;">{{ $siteName }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 24px 32px;font-size:15px;line-height:1.6;">
                            @yield('content')
                        </td>
                    </tr>
                    @hasSection('footer')
                        <tr>
                            <td style="padding:16px 32px 24px 32px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;line-height:1.5;">
                                @yield('footer')
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

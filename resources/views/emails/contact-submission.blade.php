<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $siteName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="padding:32px 32px 8px 32px;">
                            <h1 style="margin:0 0 8px 0;font-size:20px;color:#111;">{{ __('admin.submission_email_heading', ['site' => $siteName]) }}</h1>
                            <p style="margin:0 0 16px 0;font-size:13px;color:#6b7280;">{{ $submission->created_at->format('d/m/Y H:i') }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 16px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
                                @foreach(['name' => $submission->name, 'email' => $submission->email, 'phone' => $submission->phone, 'subject' => $submission->subject] as $field => $value)
                                    @if($value)
                                        <tr>
                                            <td style="padding:10px 16px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;width:120px;border-bottom:1px solid #e5e7eb;">{{ __('admin.submission_field_'.$field) }}</td>
                                            <td style="padding:10px 16px;font-size:14px;color:#111;border-bottom:1px solid #e5e7eb;">{{ $value }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                                <tr>
                                    <td colspan="2" style="padding:12px 16px;font-size:14px;color:#111;line-height:1.6;white-space:pre-wrap;">{{ $submission->message }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 32px 32px;">
                            <a href="{{ $adminUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;font-size:13px;padding:10px 16px;border-radius:6px;">{{ __('admin.submission_email_cta') }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

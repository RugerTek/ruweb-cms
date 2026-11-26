{{-- App\Mail\Connectors\MetaNotResponding: Meta has not answered for N days; the connection is kept and recovers by itself. --}}
@extends('mail.shop._layout')

@section('content')
    <h1 style="margin:0 0 12px 0;font-size:20px;">{{ __('meta_health.unreachable_mail_heading') }}</h1>
    <p style="margin:0 0 12px 0;">{{ $catalogName ? __('meta_health.unreachable_mail_intro_catalog', ['days' => $days, 'catalog' => $catalogName]) : __('meta_health.unreachable_mail_intro', ['days' => $days]) }}</p>
    <p style="margin:0 0 12px 0;padding:12px 16px;background:#fef3c7;border-radius:6px;font-size:14px;">{{ __('meta_health.unreachable_mail_action_help') }}</p>

    @include('mail.shop._button', ['url' => $connectorUrl, 'label' => __('meta_health.unreachable_mail_action')])
    <p style="margin:16px 0 0 0;font-size:13px;color:#6b7280;">{{ __('meta_health.mail_login_help', ['login' => $loginUrl]) }}</p>
@endsection

@section('footer')
    {{ __('meta_health.unreachable_mail_footer') }}
@endsection

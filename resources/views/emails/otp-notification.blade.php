<x-mail::message>
# Hello{{ $type === 'password_reset' ? '!' : ' ' . $name . '!' }}

@if($type === 'password_reset')
You are receiving this email because we received a password reset request for your account.

Your password reset code is: <strong>{{ $otp }}</strong>

This code will expire in 15 minutes.

If you did not request a password reset, no further action is required.
@else
Thank you for registering with ChattoShop!

Your email verification code is: <strong>{{ $otp }}</strong>

This code will expire in 10 minutes.

If you did not create an account, no further action is required.
@endif

@lang('Regards'),<br>
{{ config('app.name') }}
</x-mail::message>

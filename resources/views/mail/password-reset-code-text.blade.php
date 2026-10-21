{{ __('mail.password_reset.heading') }}

{{ __('mail.password_reset.intro', ['name' => $recipientName]) }}

{{ $code }}

{{ __('mail.password_reset.expires', ['minutes' => $expiresMinutes]) }}
{{ __('mail.password_reset.ignore') }}

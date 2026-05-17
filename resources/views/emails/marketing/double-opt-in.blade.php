<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Confirm your subscription</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background: #f5f5f5; padding: 20px;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 32px;">
        <h2 style="margin-top: 0; color: #111;">Confirm your subscription</h2>

        <p>Hello{{ $subscriber->first_name ? ' ' . $subscriber->first_name : '' }},</p>

        <p>
            You're receiving this email because someone (hopefully you) signed up to receive
            <strong>{{ $list->name }}</strong>.
        </p>

        <p>Please confirm your subscription by clicking the button below:</p>

        <p style="text-align: center; margin: 32px 0;">
            <a href="{{ $confirmUrl }}"
               style="background: #0d6efd; color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 6px; display: inline-block;">
                Confirm my subscription
            </a>
        </p>

        <p style="color: #6c757d; font-size: 14px;">
            If you didn't sign up, you can safely ignore this email — no further messages will be sent.
        </p>

        <hr style="border: 0; border-top: 1px solid #eee; margin: 24px 0;">
        <p style="color: #6c757d; font-size: 12px;">
            Or copy this URL into your browser: <br>
            <code style="word-break: break-all;">{{ $confirmUrl }}</code>
        </p>
    </div>
</body>
</html>

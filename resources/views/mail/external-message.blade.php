<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $internalMessage->subject }}</title>
</head>
<body style="margin:0;padding:32px;background:#f7f3ec;color:#17324d;font-family:Arial,sans-serif;">
    <div style="max-width:720px;margin:0 auto;background:#fff;padding:32px;border-top:4px solid #d97a07;">
        <p style="margin:0 0 8px;color:#d97a07;font-size:12px;letter-spacing:2px;text-transform:uppercase;">U-Mail · UTICA Jendouba</p>
        <h1 style="margin:0 0 8px;font-size:24px;">{{ $internalMessage->subject }}</h1>
        <p style="margin:0 0 28px;color:#718096;font-size:13px;">
            From {{ $internalMessage->senderDisplayName() }} &lt;{{ $internalMessage->senderDisplayEmail() }}&gt;
        </p>
        <div style="font-size:15px;line-height:1.7;">{!! $internalMessage->body_html !!}</div>
        <p style="margin:32px 0 0;padding-top:18px;border-top:1px solid #e8e1d6;color:#718096;font-size:12px;">
            This message was sent from U-Mail. You can reply normally to continue the conversation.
        </p>
    </div>
</body>
</html>

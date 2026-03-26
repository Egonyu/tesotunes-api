<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $brand ?? 'TesoTunes' }} Security Alert</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="padding:20px 24px;background:#111827;color:#ffffff;font-size:28px;font-weight:700;letter-spacing:0.3px;text-align:center;">
        {{ $brand ?? 'TesoTunes' }}
      </td>
    </tr>
    <tr>
      <td style="padding:28px 24px 8px 24px;font-size:32px;line-height:1.2;font-weight:700;color:#111827;">
        {{ $greeting ?? 'Hi there,' }}
      </td>
    </tr>
    <tr>
      <td style="padding:0 24px 12px 24px;font-size:20px;line-height:1.5;color:#374151;">
        {{ $intro ?? 'A security event occurred on your account.' }}
      </td>
    </tr>

    @if(!empty($details) && is_array($details))
      <tr>
        <td style="padding:8px 24px 0 24px;">
          <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
            @foreach($details as $label => $value)
              <tr>
                <td style="padding:12px 16px 6px 16px;font-size:14px;color:#6b7280;font-weight:600;width:38%;vertical-align:top;">
                  {{ $label }}
                </td>
                <td style="padding:12px 16px 6px 0;font-size:14px;color:#111827;vertical-align:top;word-break:break-word;">
                  {{ $value }}
                </td>
              </tr>
            @endforeach
          </table>
        </td>
      </tr>
    @endif

    @if(!empty($body))
      <tr>
        <td style="padding:18px 24px 4px 24px;font-size:16px;line-height:1.6;color:#374151;">
          {{ $body }}
        </td>
      </tr>
    @endif

    @if(!empty($actionText) && !empty($actionUrl))
      <tr>
        <td style="padding:18px 24px 4px 24px;">
          <a href="{{ $actionUrl }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:8px;">
            {{ $actionText }}
          </a>
        </td>
      </tr>
      <tr>
        <td style="padding:14px 24px 6px 24px;font-size:14px;line-height:1.6;color:#6b7280;">
          If the button does not work, copy and paste this URL into your browser:<br>
          <a href="{{ $actionUrl }}" style="color:#0f766e;word-break:break-all;">{{ $actionUrl }}</a>
        </td>
      </tr>
    @endif

    <tr>
      <td style="padding:14px 24px 10px 24px;font-size:16px;color:#374151;">
        {{ $outro ?? 'Stay safe!' }}
      </td>
    </tr>
    <tr>
      <td style="padding:0 24px 24px 24px;font-size:16px;line-height:1.6;color:#111827;">
        Regards,<br>
        {{ $brand ?? 'TesoTunes' }}
      </td>
    </tr>
  </table>
</body>
</html>

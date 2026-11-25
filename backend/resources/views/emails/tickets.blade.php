<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                <tr>
                    <td style="padding:0 0 16px 0;font-size:20px;font-weight:bold;">Bookcat</td>
                </tr>
                <tr>
                    <td style="background:#ffffff;border-radius:8px;padding:24px;">
                        <h1 style="margin:0 0 8px 0;font-size:22px;">{{ $heading }}</h1>
                        <p style="margin:0 0 16px 0;font-size:15px;line-height:22px;">
                            Hi {{ $buyerName }}, @if($forBuyer) your order <strong>{{ $reference }}</strong> is confirmed. @else a ticket was booked for you. @endif
                        </p>

                        <p style="margin:0 0 4px 0;font-size:18px;font-weight:bold;">{{ $eventTitle }}</p>
                        @if($when)<p style="margin:0;font-size:15px;">{{ $when }}</p>@endif
                        @if($venueName)<p style="margin:0;font-size:15px;">{{ $venueName }}@if($venueAddress), {{ $venueAddress }}@endif</p>@endif
                        @if($total)<p style="margin:8px 0 0 0;font-size:14px;color:#52525b;">Total paid: {{ $total }}</p>@endif
                    </td>
                </tr>

                @foreach($rows as $i => $row)
                    <tr><td style="height:12px;"></td></tr>
                    <tr>
                        <td style="background:#ffffff;border-radius:8px;padding:20px;text-align:center;">
                            <p style="margin:0 0 2px 0;font-size:16px;font-weight:bold;">{{ $row['name'] }}</p>
                            <p style="margin:0 0 12px 0;font-size:14px;color:#52525b;">{{ $row['seat'] }}</p>
                            <img src="{{ $message->embedData($row['png'], 'ticket-'.($i + 1).'.png', 'image/png') }}"
                                 width="220" height="220" alt="Ticket QR code {{ $i + 1 }}"
                                 style="display:block;margin:0 auto;width:220px;height:220px;">
                            <p style="margin:10px 0 0 0;font-size:12px;color:#71717a;">Show this code at the entrance.</p>
                        </td>
                    </tr>
                @endforeach

                <tr>
                    <td style="padding:16px 4px 0 4px;font-size:12px;line-height:18px;color:#71717a;">
                        Each code works once. Do not share it publicly, because anyone holding the code can use the ticket.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
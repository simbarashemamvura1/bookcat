{{ $heading }}

Hi {{ $buyerName }},
@if($forBuyer)
Your order {{ $reference }} is confirmed.
@else
A ticket was booked for you.
@endif

{{ $eventTitle }}
@if($when){{ $when }}
@endif
@if($venueName){{ $venueName }}@if($venueAddress), {{ $venueAddress }}@endif

@endif
@if($total)Total paid: {{ $total }}
@endif

@foreach($rows as $i => $row)
Ticket {{ $i + 1 }}: {{ $row['name'] }}
{{ $row['seat'] }}
Ticket code: {{ $row['token'] }}

@endforeach
Open the HTML version of this email to see the QR codes to show at the entrance.
Each code works once. Do not share it publicly.
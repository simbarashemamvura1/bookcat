<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Ticket;
use App\Support\QrPng;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One email, two audiences: the buyer gets every ticket on the order, an additional
 * attendee gets only their own. The QR code encodes the ticket token and nothing else.
 */
class TicketsEmail extends Mailable
{
    /** @param Collection<int, Ticket> $tickets */
    public function __construct(
        public Order $order,
        public Collection $tickets,
        public bool $forBuyer = true,
    ) {
    }

    public function envelope(): Envelope
    {
        $title = $this->order->event->title;

        return new Envelope(
            subject: $this->forBuyer
                ? 'Your tickets for '.$title.' ('.$this->order->reference.')'
                : 'Your ticket for '.$title,
        );
    }

    public function content(): Content
    {
        $event = $this->order->event;
        $venue = $event->venue;
        $zone = $venue?->timezone ?: config('app.timezone');
        $when = $event->starts_at?->copy()->setTimezone($zone);

        $this->tickets->loadMissing('eventSeat');

        $rows = $this->tickets->map(fn (Ticket $ticket) => [
            'name' => $ticket->attendee_name,
            'seat' => $this->seatLabel($ticket),
            'token' => $ticket->token,
            'png' => QrPng::make($ticket->token),
        ])->values()->all();

        return new Content(
            view: 'emails.tickets',
            text: 'emails.tickets-text',
            with: [
                'heading' => $this->forBuyer ? 'Your tickets are ready' : 'Your ticket is ready',
                'forBuyer' => $this->forBuyer,
                'reference' => $this->order->reference,
                'buyerName' => $this->order->buyer_name,
                'eventTitle' => $event->title,
                'when' => $when?->format('l, F j, Y \a\t g:i A T'),
                'venueName' => $venue?->name,
                'venueAddress' => $venue
                    ? trim($venue->address_line.', '.$venue->city.', '.$venue->region)
                    : null,
                'rows' => $rows,
                'total' => $this->forBuyer ? $this->totalLabel() : null,
            ],
        );
    }

    private function seatLabel(Ticket $ticket): string
    {
        $seat = $ticket->eventSeat;

        if (! $seat) {
            return 'Standing admission';
        }

        return 'Section '.$seat->section.' · Row '.$seat->row_label.' · Seat '.$seat->seat_number;
    }

    private function totalLabel(): string
    {
        if ($this->order->total_cents === 0) {
            return 'Free';
        }

        return $this->order->currency.' '.number_format($this->order->total_cents / 100, 2);
    }
}
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketPurchased
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public $attendee,
        public $ticket,
        public $event,
    ) {}
}

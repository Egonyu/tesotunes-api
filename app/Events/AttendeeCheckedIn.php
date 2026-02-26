<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendeeCheckedIn
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public $attendee,
        public $event,
    ) {}
}

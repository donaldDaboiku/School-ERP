<?php

namespace App\Events;

use App\Models\Timetable;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClassScheduled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?Timetable $timetable;
    public ?array $payload;

    /**
     * Create a new event instance.
     */
    public function __construct(?Timetable $timetable = null, ?array $payload = null)
    {
        $this->timetable = $timetable;
        $this->payload = $payload;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel-name'),
        ];
    }
}

<?php

namespace App\Events;

use App\Models\Notice;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnnouncementPublished
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?Notice $notice;
    public ?int $schoolId;

    /**
     * Create a new event instance.
     */
    public function __construct(?Notice $notice = null, ?int $schoolId = null)
    {
        $this->notice = $notice;
        $this->schoolId = $schoolId;
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

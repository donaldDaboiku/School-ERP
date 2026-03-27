<?php

namespace App\Events;

use App\Models\Parents;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParentRegistered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?Parents $parent;
    public ?User $user;

    /**
     * Create a new event instance.
     */
    public function __construct(?Parents $parent = null, ?User $user = null)
    {
        $this->parent = $parent;
        $this->user = $user;
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

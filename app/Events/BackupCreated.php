<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BackupCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?string $path;
    public ?int $schoolId;
    public ?array $metadata;

    /**
     * Create a new event instance.
     */
    public function __construct(?string $path = null, ?int $schoolId = null, ?array $metadata = null)
    {
        $this->path = $path;
        $this->schoolId = $schoolId;
        $this->metadata = $metadata;
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

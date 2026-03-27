<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemMaintenance
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?string $message;
    public ?string $severity;
    public ?string $startsAt;
    public ?string $endsAt;
    public ?int $initiatedBy;
    public ?array $context;

    /**
     * Create a new event instance.
     */
    public function __construct(
        ?string $message = null,
        ?string $severity = null,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?int $initiatedBy = null,
        ?array $context = null
    )
    {
        $this->message = $message;
        $this->severity = $severity;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->initiatedBy = $initiatedBy;
        $this->context = $context;
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

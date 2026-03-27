<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReportGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?string $reportType;
    public ?int $schoolId;
    public ?array $filters;
    public ?array $result;

    /**
     * Create a new event instance.
     */
    public function __construct(?string $reportType = null, ?int $schoolId = null, ?array $filters = null, ?array $result = null)
    {
        $this->reportType = $reportType;
        $this->schoolId = $schoolId;
        $this->filters = $filters;
        $this->result = $result;
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

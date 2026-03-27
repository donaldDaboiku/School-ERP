<?php

namespace App\Events;

use App\Models\Student;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentPromoted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?Student $student;
    public ?int $fromClassId;
    public ?int $toClassId;
    public ?int $academicSessionId;
    public ?int $termId;

    /**
     * Create a new event instance.
     */
    public function __construct(
        ?Student $student = null,
        ?int $fromClassId = null,
        ?int $toClassId = null,
        ?int $academicSessionId = null,
        ?int $termId = null
    )
    {
        $this->student = $student;
        $this->fromClassId = $fromClassId;
        $this->toClassId = $toClassId;
        $this->academicSessionId = $academicSessionId;
        $this->termId = $termId;
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

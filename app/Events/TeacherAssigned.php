<?php

namespace App\Events;

use App\Models\Teacher;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeacherAssigned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?Teacher $teacher;
    public ?array $classIds;
    public ?array $subjectIds;
    public ?string $role;
    public ?int $academicYear;

    /**
     * Create a new event instance.
     */
    public function __construct(
        ?Teacher $teacher = null,
        ?array $classIds = null,
        ?array $subjectIds = null,
        ?string $role = null,
        ?int $academicYear = null
    )
    {
        $this->teacher = $teacher;
        $this->classIds = $classIds;
        $this->subjectIds = $subjectIds;
        $this->role = $role;
        $this->academicYear = $academicYear;
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

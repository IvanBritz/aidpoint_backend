<?php

namespace App\Events;

use App\Models\AuditLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuditLogRecorded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public AuditLog $log;
    public int $facilityId;

    public function __construct(AuditLog $log, int $facilityId)
    {
        $this->log = $log;
        $this->facilityId = $facilityId;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('facility.' . $this->facilityId . '.audit')];
    }

    public function broadcastAs(): string
    {
        return 'audit.log.recorded';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->log->id,
            'event_type' => $this->log->event_type,
            'event_category' => $this->log->event_category,
            'description' => $this->log->description,
            'event_data' => $this->log->event_data,
            'user' => [
                'id' => $this->log->user?->id,
                'name' => $this->log->user_name,
                'role' => $this->log->user_role,
            ],
            'entity_type' => $this->log->entity_type,
            'entity_id' => $this->log->entity_id,
            'risk_level' => $this->log->risk_level,
            'created_at' => $this->log->created_at?->toISOString(),
        ];
    }
}
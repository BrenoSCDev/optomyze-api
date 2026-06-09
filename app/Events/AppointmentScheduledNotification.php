<?php

namespace App\Events;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class AppointmentScheduledNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Appointment $appointment;
    public User $actor;

    public function __construct(Appointment $appointment, User $actor)
    {
        $this->appointment = $appointment->loadMissing([
            'lead:id,first_name,last_name,funnel_id',
            'user:id,name,profile_pic',
        ]);
        $this->actor = $actor;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->appointment->company_id);
    }

    public function broadcastAs(): string
    {
        return 'appointment.scheduled';
    }

    public function broadcastWith(): array
    {
        $appointment  = $this->appointment;
        $lead         = $appointment->lead;
        $assignedUser = $appointment->user;

        $leadName = $lead
            ? trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''))
            : null;

        return [
            'id'      => uniqid('notif_'),
            'type'    => 'appointment_scheduled',
            'message' => $leadName
                ? "Appointment scheduled for {$leadName}"
                : "New appointment: {$appointment->title}",
            'appointment' => [
                'id'           => $appointment->id,
                'title'        => $appointment->title,
                'scheduled_at' => $appointment->scheduled_at->toISOString(),
                'end_at'       => $appointment->end_at?->toISOString(),
            ],
            'lead' => $lead ? [
                'id'         => $lead->id,
                'funnel_id'  => $lead->funnel_id,
                'first_name' => $lead->first_name,
                'last_name'  => $lead->last_name,
            ] : null,
            'assigned_user' => $assignedUser ? [
                'id'   => $assignedUser->id,
                'name' => $assignedUser->name,
            ] : null,
            'actor' => [
                'id'              => $this->actor->id,
                'name'            => $this->actor->name,
                'profile_pic_url' => $this->actor->profile_pic
                    ? Storage::url($this->actor->profile_pic)
                    : null,
            ],
            'timestamp' => now()->toISOString(),
        ];
    }
}

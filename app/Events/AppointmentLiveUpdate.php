<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class AppointmentLiveUpdate implements ShouldBroadcastNow
{
    public function __construct(
        private readonly string $action,
        private readonly Appointment $appointment,
    ) {
        if ($action !== 'deleted') {
            $this->appointment->loadMissing([
                'appointmentType:id,name,color,duration_minutes',
                'lead:id,first_name,last_name,funnel_id',
                'user:id,name,email',
            ]);
        }
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('appointments.' . $this->appointment->company_id);
    }

    public function broadcastAs(): string
    {
        return 'appointment.live';
    }

    public function broadcastWith(): array
    {
        if ($this->action === 'deleted') {
            return [
                'action'      => 'deleted',
                'id'          => $this->appointment->id,
                // Sent on deletes too: a listener showing one calendar needs to
                // know whether the removed appointment was even on it.
                'calendar_id' => $this->appointment->calendar_id,
            ];
        }

        $appt = $this->appointment;

        return [
            'action'      => $this->action,
            'appointment' => [
                'id'                  => $appt->id,
                'company_id'          => $appt->company_id,
                'calendar_id'         => $appt->calendar_id,
                'lead_id'             => $appt->lead_id,
                'appointment_type_id' => $appt->appointment_type_id,
                'user_id'             => $appt->user_id,
                'title'               => $appt->title,
                'scheduled_at'        => $appt->scheduled_at?->toISOString(),
                'end_at'              => $appt->end_at?->toISOString(),
                'timezone'            => $appt->timezone,
                'notes'               => $appt->notes,
                'status'              => $appt->status,
                'created_at'          => $appt->created_at?->toISOString(),
                'updated_at'          => $appt->updated_at?->toISOString(),
                'appointment_type'    => $appt->appointmentType ? [
                    'id'               => $appt->appointmentType->id,
                    'name'             => $appt->appointmentType->name,
                    'color'            => $appt->appointmentType->color,
                    'duration_minutes' => $appt->appointmentType->duration_minutes,
                ] : null,
                'lead' => $appt->lead ? [
                    'id'         => $appt->lead->id,
                    'first_name' => $appt->lead->first_name,
                    'last_name'  => $appt->lead->last_name,
                    'funnel_id'  => $appt->lead->funnel_id,
                ] : null,
                'user' => $appt->user ? [
                    'id'    => $appt->user->id,
                    'name'  => $appt->user->name,
                    'email' => $appt->user->email,
                ] : null,
            ],
        ];
    }
}

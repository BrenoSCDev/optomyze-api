<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Appointment extends Model
{
    protected $fillable = [
        'company_id', 'calendar_id', 'lead_id', 'appointment_type_id', 'user_id',
        'title', 'scheduled_at', 'end_at', 'timezone', 'notes', 'status'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    /**
     * Take the appointment's comments with it.
     *
     * Notes are polymorphic, so there is no foreign key to cascade — without
     * this, deleting an appointment leaves its thread behind as rows nothing
     * can reach. Mirrors how deleting a lead clears its chats.
     */
    protected static function booted(): void
    {
        static::deleting(function (Appointment $appointment) {
            Observation::where('notable_type', $appointment->getMorphClass())
                ->where('notable_id', $appointment->getKey())
                ->delete();
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    /** Threaded comments, the same notes leads and deals use. */
    public function observations(): MorphMany
    {
        return $this->morphMany(Observation::class, 'notable');
    }
    public function calendar(): BelongsTo { return $this->belongsTo(Calendar::class); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function appointmentType(): BelongsTo { return $this->belongsTo(AppointmentType::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'appointment_product');
    }
}

<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function calendar(): BelongsTo { return $this->belongsTo(Calendar::class); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function appointmentType(): BelongsTo { return $this->belongsTo(AppointmentType::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'appointment_product');
    }
}

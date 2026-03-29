<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentType extends Model
{
    protected $fillable = ['company_id', 'name', 'duration_minutes', 'color', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function appointments(): HasMany { return $this->hasMany(Appointment::class); }
}

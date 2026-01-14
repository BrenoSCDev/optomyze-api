<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'name', 'token'];

    // protected $hidden = [
    //     'token', // Hide sensitive data from JSON responses
    // ];

    /**
     * Relationship: ApiToken belongs to a Company
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Generate a unique API token and save it
     */
    public static function generateToken($companyId, $name)
    {
        $token = hash_hmac('sha256', Str::random(40), config('app.key'));

        return self::create([
            'company_id' => $companyId,
            'name' => $name,
            'token' => $token,
        ]);
    }

    /**
     * Revoke token by its value
     */
    public static function revokeToken($token)
    {
        return self::where('token', $token)->delete();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'key',
        'group',
        'description',
    ];

    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class, 'permission_key', 'key');
    }
}

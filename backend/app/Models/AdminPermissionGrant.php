<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminPermissionGrant extends Model
{
    public $timestamps = false; // the table only has granted_at

    protected $fillable = ['admin_id', 'permission_id', 'granted_by', 'granted_at'];
}
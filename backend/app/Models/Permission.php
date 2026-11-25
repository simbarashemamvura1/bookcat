<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = ['key', 'label'];

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(BusinessAccount::class, 'admin_permission_grants', 'permission_id', 'admin_id')
            ->withPivot('granted_by', 'granted_at');
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class BusinessAccount extends Authenticatable
{
    use Notifiable;

    public const ROLE_ORGANIZER = 'organizer';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPER_ADMIN = 'super_admin';

    // role and status are deliberately NOT mass-assignable, so a request
    // payload can never promote an account. Set them explicitly in code.
    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'admin_permission_grants', 'admin_id', 'permission_id')
            ->withPivot('granted_by', 'granted_at');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN && $this->status === 'active';
    }

    public function hasPermission(string $key): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        if ($this->role !== self::ROLE_ADMIN) {
            return false;
        }

        return $this->permissions()->where('permissions.key', $key)->exists();
    }
}
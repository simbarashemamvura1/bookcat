<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class BusinessAccount extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Notifiable;

    public const ROLE_ORGANIZER = 'organizer';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPER_ADMIN = 'super_admin';

    // role and status are deliberately NOT mass-assignable, so a request
    // payload can never promote an account. Set them explicitly in code.
    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * The Filament panel is the staff console: only active admins and super admins may open it.
     * Organizers will use the organizer app instead, never this panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'business'
            && $this->status === 'active'
            && in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'admin_permission_grants', 'admin_id', 'permission_id')
            ->withPivot('granted_by', 'granted_at');
    }

    public function organizer(): HasOne
    {
        return $this->hasOne(Organizer::class);
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
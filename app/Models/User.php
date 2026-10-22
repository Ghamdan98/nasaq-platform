<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Support\UserPermissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = ['status' => 'active', 'role' => 'hr'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'employee_id',
        'status',
        'phone',
        'permissions_json',
        'denied_permissions_json',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => 'string',
            'permissions_json' => 'array',
            'denied_permissions_json' => 'array',
        ];
    }

    public function hasRole(string|UserRole $role): bool
    {
        $expectedRole = $role instanceof UserRole ? $role->value : $role;

        return $this->role === $expectedRole;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::ADMIN);
    }

    public function isHr(): bool
    {
        return $this->hasRole(UserRole::HR);
    }

    public function isReviewer(): bool
    {
        return $this->hasRole(UserRole::REVIEWER);
    }

    public function canAdjustTransactionValues(): bool
    {
        return $this->role !== UserRole::REVIEWER->value
            && $this->hasPermission('adjust_transaction_values');
    }

    public function hasPermission(string $permission): bool
    {
        if ($permission === 'manage-system-settings') return $this->status === 'active' && $this->role === 'developer' && $this->hasExplicitPermission($permission);
        if ($this->role === 'developer') return false;
        if ($this->role === UserRole::ADMIN->value) {
            return true;
        }

        if (in_array($permission, $this->denied_permissions_json ?? [], true)) {
            return false;
        }
        $defaults = UserPermissions::defaults($this->role);

        $permissions = array_unique(array_merge($defaults, $this->permissions_json ?? []));

        return in_array($permission, $permissions, true);
    }

    public function hasExplicitPermission(string $permission): bool
    {
        return ! in_array($permission, $this->denied_permissions_json ?? [], true) && in_array($permission, $this->permissions_json ?? [], true);
    }

    public function effectivePermissions(): array
    {
        if ($this->role === 'developer') return $this->hasPermission('manage-system-settings') ? ['manage-system-settings'] : [];
        return array_values(array_filter(UserPermissions::names(), fn ($permission) => $this->hasPermission($permission) && ($permission !== 'adjust_transaction_values' || ! $this->isReviewer())));
    }
}

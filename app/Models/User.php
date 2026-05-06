<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES = ['전체', '영업', '통관', '정산', '관리'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'permission',
        'role',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->permission === 'super';
    }

    public function isAdmin(): bool
    {
        return in_array($this->permission, ['super', 'admin'], true);
    }

    public function isUser(): bool
    {
        return $this->permission === 'user';
    }

    public function canAccessAdmin(): bool
    {
        return $this->isAdmin();
    }

    public function canAccessSales(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($this->role, ['전체', '영업'], true);
    }

    public function canAccessClearance(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($this->role, ['전체', '통관'], true);
    }

    public function canAccessSettlement(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($this->role, ['전체', '정산'], true);
    }

    public function canToggleFeatures(): bool
    {
        return $this->isSuperAdmin();
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}

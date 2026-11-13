<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBranch;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use BelongsToOrganization;
    use HasBranch;
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['admin', 'manager', 'reception', 'coach', 'accountant'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if ($m->role !== 'platform') {
                \App\Services\Subscription::assertCanAdd('staff');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'pay_rate' => 'float',
        ];
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function hasRole(string ...$roles): bool
    {
        return $this->role === 'admin' || in_array($this->role, $roles, true);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (true) {
                \App\Services\Subscription::assertCanAdd('clients');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'billing_flag' => 'boolean',
            'wallet_balance' => 'float',
            'date_of_birth' => 'date:Y-m-d',
            'phone_verified_at' => 'datetime',
        ];
    }

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function events()
    {
        return $this->hasMany(ClientEvent::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'current_period_end' => 'datetime', 'cancel_at' => 'datetime'];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscriptionInvoices()
    {
        return $this->hasMany(SubscriptionInvoice::class)->latest('id');
    }

    public function owner()
    {
        return $this->hasOne(User::class)->withoutGlobalScope('tenant')->where('role', 'admin')->oldest('id');
    }
}

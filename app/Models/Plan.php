<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    public const FEATURES = [
        'pos' => 'Point of sale & stock',
        'tournaments' => 'Tournaments',
        'payroll' => 'Payroll',
        'reports' => 'Reports & analytics',
        'branches' => 'Multiple branches',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['features' => 'array', 'price_monthly' => 'float', 'price_yearly' => 'float', 'public' => 'boolean', 'active' => 'boolean'];
    }

    public function organizations()
    {
        return $this->hasMany(Organization::class);
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    public function price(string $cycle): float
    {
        return $cycle === 'yearly' ? $this->price_yearly : $this->price_monthly;
    }
}

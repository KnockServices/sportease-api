<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchThrough;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use BelongsToOrganization;
    use BranchThrough;

    protected $branchVia = 'user';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pay_rate' => 'float',
            'days_worked' => 'float',
            'hours_worked' => 'float',
            'earned' => 'float',
            'carried_over' => 'float',
            'paid' => 'float',
        ];
    }

    protected $appends = ['total_due', 'balance'];

    public function getTotalDueAttribute(): float
    {
        return round($this->earned + $this->carried_over, 2);
    }

    public function getBalanceAttribute(): float
    {
        return round($this->earned + $this->carried_over - $this->paid, 2);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payments()
    {
        return $this->hasMany(PayrollPayment::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchThrough;
use Illuminate\Database\Eloquent\Model;

class PayrollPayment extends Model
{
    use BelongsToOrganization;
    use BranchThrough;

    protected $branchVia = 'payroll.user';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'paid_on' => 'date:Y-m-d',
        ];
    }

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }
}

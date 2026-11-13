<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchThrough;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use BelongsToOrganization;
    use BranchThrough;

    protected $branchVia = 'invoice';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchThrough;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use BelongsToOrganization;
    use BranchThrough;

    protected $branchVia = 'court';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'original_starts_at' => 'datetime',
            'price' => 'float',
            'discount_percent' => 'float',
            'discount_amount' => 'float',
            'total' => 'float',
        ];
    }

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

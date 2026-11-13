<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use BelongsToOrganization;
    use HasBranch;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total' => 'float',
        ];
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

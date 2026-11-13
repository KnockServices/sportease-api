<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'cost' => 'float',
            'show_online' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function inventory()
    {
        return $this->hasMany(InventoryItem::class);
    }
}

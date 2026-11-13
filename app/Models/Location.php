<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (($m->active ?? true)) {
                \App\Services\Subscription::assertCanAdd('branches');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** "09:00:00" → "09:00" for time inputs. */
    public function getOpensAtAttribute($v): ?string
    {
        return $v ? substr($v, 0, 5) : null;
    }

    public function getClosesAtAttribute($v): ?string
    {
        return $v ? substr($v, 0, 5) : null;
    }

    public function courts()
    {
        return $this->hasMany(Court::class);
    }

    public function inventoryItems()
    {
        return $this->hasMany(InventoryItem::class);
    }
}

<?php

namespace App\Models\Concerns;

use App\Services\Branch;
use Illuminate\Database\Eloquent\Builder;

/** Models with their own location_id column. `->inBranch()` filters to the current branch (no-op for "All branches"). */
trait HasBranch
{
    public function scopeInBranch(Builder $q, ?int $branch = null, bool $withShared = false): Builder
    {
        $branch ??= Branch::id();
        if (! $branch) {
            return $q;
        }
        $col = $this->getTable().'.location_id';

        return $q->where(fn ($q) => $q->where($col, $branch)->when($withShared, fn ($q) => $q->orWhereNull($col)));
    }

    public function branch()
    {
        return $this->belongsTo(\App\Models\Location::class, 'location_id');
    }
}

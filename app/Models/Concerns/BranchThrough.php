<?php

namespace App\Models\Concerns;

use App\Services\Branch;
use Illuminate\Database\Eloquent\Builder;

/** Models whose branch comes from a related record (a reservation's court, a payment's invoice …). Set $branchVia. */
trait BranchThrough
{
    public function scopeInBranch(Builder $q, ?int $branch = null): Builder
    {
        $branch ??= Branch::id();
        if (! $branch) {
            return $q;
        }
        $vias = (array) $this->branchVia;

        return $q->where(function ($q) use ($vias, $branch) {
            foreach ($vias as $i => $via) {
                $q->{$i ? 'orWhereHas' : 'whereHas'}($via, fn ($r) => $r->where('location_id', $branch));
            }
        });
    }
}

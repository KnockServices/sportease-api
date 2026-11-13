<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Services\Tenant;
use Illuminate\Database\Eloquent\Builder;

/** Tenant isolation: queries only ever see the current organization's rows. */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($id = Tenant::id()) {
                $q->where($q->getModel()->getTable().'.organization_id', $id);
            }
        });
        static::creating(function ($m) {
            $m->organization_id ??= Tenant::id();
        });
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}

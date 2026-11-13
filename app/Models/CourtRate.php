<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A duration price tier for a court, e.g. 60 min = $80, 90 min = $110. */
class CourtRate extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'float', 'duration_min' => 'integer'];
    }

    public function court()
    {
        return $this->belongsTo(Court::class);
    }
}

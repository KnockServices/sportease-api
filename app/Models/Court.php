<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;

class Court extends Model
{
    use BelongsToOrganization;
    use HasBranch;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'hourly_rate' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Court IDs: full courts C-01, C-02 …; sub-courts carry their full court's ID (C-01-A, C-02-Q3).
        static::creating(function (Court $court) {
            $suffix = $court->suffix ?? null; // optional hint from splitCourt: A, B, Q1 …
            unset($court->suffix);
            if ($court->code) {
                return;
            }
            if ($court->parent_id) {
                $parent = Court::find($court->parent_id);
                $n = Court::where('parent_id', $parent->id)->count() + 1;
                $court->code = $parent->code.'-'.($suffix ?: ($court->kind === 'quarter' ? "Q$n" : chr(64 + $n)));
            } else {
                $n = Court::whereNull('parent_id')->count() + 1;
                do {
                    $code = sprintf('C-%02d', $n++);
                } while (Court::where('code', $code)->exists());
                $court->code = $code;
            }
        });
    }

    /** "C-01-A · Main Court – A" */
    public function getLabelAttribute(): string
    {
        return "{$this->code} · {$this->name}";
    }

    public function rates()
    {
        return $this->hasMany(CourtRate::class)->orderBy('duration_min');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function parent()
    {
        return $this->belongsTo(Court::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Court::class, 'parent_id');
    }
}

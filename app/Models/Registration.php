<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    use BelongsToOrganization;
    use HasBranch;
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // A registration belongs to its team's branch; session packs take the coach's or the working branch.
        static::creating(function (self $r) {
            $r->location_id ??= Team::find($r->team_id)?->location_id ?? User::find($r->coach_id)?->location_id ?? \App\Services\Branch::fallback();
        });
    }

    protected $appends = ['sessions_remaining'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'sessions_total' => 'integer',
            'sessions_used' => 'integer',
        ];
    }

    /** Sessions left on a session-based package (null for team packages, which run on the team schedule). */
    public function getSessionsRemainingAttribute(): ?int
    {
        return $this->sessions_total === null ? null : max(0, $this->sessions_total - $this->sessions_used);
    }

    public function packageSessions()
    {
        return $this->hasMany(PackageSession::class)->latest('held_at');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

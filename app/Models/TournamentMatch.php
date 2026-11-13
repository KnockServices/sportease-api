<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchThrough;
use Illuminate\Database\Eloquent\Model;

class TournamentMatch extends Model
{
    use BelongsToOrganization;
    use BranchThrough;

    protected $branchVia = 'court';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'home_score' => 'integer', 'away_score' => 'integer'];
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function home()
    {
        return $this->belongsTo(TournamentTeam::class, 'home_id');
    }

    public function away()
    {
        return $this->belongsTo(TournamentTeam::class, 'away_id');
    }

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function referee()
    {
        return $this->belongsTo(User::class, 'referee_id');
    }

    public function next()
    {
        return $this->belongsTo(self::class, 'next_match_id');
    }

    public function feeders()
    {
        return $this->hasMany(self::class, 'next_match_id');
    }

    public function getTitleAttribute(): string
    {
        return ($this->home?->name ?? 'TBD').' vs '.($this->away?->name ?? 'TBD');
    }
}

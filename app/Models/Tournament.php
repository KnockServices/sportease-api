<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    use BelongsToOrganization;
    use HasBranch;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'entry_fee' => 'float'];
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function teams()
    {
        return $this->hasMany(TournamentTeam::class)->orderByRaw('coalesce(seed, 9999)')->orderBy('id');
    }

    public function matches()
    {
        return $this->hasMany(TournamentMatch::class)->orderBy('round')->orderBy('position');
    }
}

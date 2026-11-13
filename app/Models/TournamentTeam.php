<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class TournamentTeam extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_fee' => 'float'];
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

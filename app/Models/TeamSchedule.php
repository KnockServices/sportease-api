<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class TeamSchedule extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function court()
    {
        return $this->belongsTo(Court::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchThrough;
use Illuminate\Database\Eloquent\Model;

class TeamSession extends Model
{
    use BelongsToOrganization;
    use BranchThrough;

    protected $branchVia = 'court';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'original_starts_at' => 'datetime',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function substituteCoach()
    {
        return $this->belongsTo(User::class, 'substitute_coach_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use BelongsToOrganization;
    use HasBranch;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function schedules()
    {
        return $this->hasMany(TeamSchedule::class);
    }

    public function sessions()
    {
        return $this->hasMany(TeamSession::class);
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    public function waitingList()
    {
        return $this->hasMany(WaitingListEntry::class);
    }
}

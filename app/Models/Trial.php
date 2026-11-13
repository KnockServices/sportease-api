<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchThrough;
use Illuminate\Database\Eloquent\Model;

class Trial extends Model
{
    use BelongsToOrganization;
    use BranchThrough;

    protected $branchVia = ['court', 'team'];
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'day_before_reminder_sent_at' => 'datetime',
            'evaluated_at' => 'datetime',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}

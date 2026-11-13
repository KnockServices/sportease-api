<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchThrough;
use Illuminate\Database\Eloquent\Model;

/** One session consumed (or excused) on a session-based package. */
class PackageSession extends Model
{
    use BelongsToOrganization;
    use BranchThrough;

    protected $branchVia = 'registration';
    public const COUNTED = ['attended', 'no_show'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['held_at' => 'datetime', 'duration_min' => 'integer'];
    }

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function marker()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}

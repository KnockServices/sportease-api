<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchThrough;
use Illuminate\Database\Eloquent\Model;

class WaitingListEntry extends Model
{
    use BelongsToOrganization;
    use BranchThrough;

    protected $branchVia = 'team';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'offer_expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

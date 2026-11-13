<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'active' => 'boolean',
            'sessions_count' => 'integer',
            'session_minutes' => 'integer',
        ];
    }

    public function isSessionBased(): bool
    {
        return $this->type === 'sessions';
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}

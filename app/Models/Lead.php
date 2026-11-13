<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use BelongsToOrganization;
    use HasBranch;
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $l) => $l->location_id ??= \App\Services\Branch::fallback());
    }

    protected function casts(): array
    {
        return [
            'participant_dob' => 'date:Y-m-d',
        ];
    }

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function trials()
    {
        return $this->hasMany(Trial::class);
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }
}

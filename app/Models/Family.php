<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Family extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'float',
        ];
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function temporaryCredits()
    {
        return $this->hasMany(TemporaryCredit::class);
    }
}

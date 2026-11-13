<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToOrganization;
    use HasBranch;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'discount_percent' => 'float',
            'discount_amount' => 'float',
            'total' => 'float',
            'paid_amount' => 'float',
            'due_date' => 'date:Y-m-d',
        ];
    }

    protected $appends = ['balance', 'is_overdue'];

    public function getBalanceAttribute(): float
    {
        return round($this->total - $this->paid_amount, 2);
    }

    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->status, ['unpaid', 'partial']) && $this->due_date && $this->due_date->isBefore(today());
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

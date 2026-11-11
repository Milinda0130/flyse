<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use Auditable;

    protected $fillable = ['expense_date', 'category', 'description', 'amount', 'currency', 'payment_method', 'receipt_document_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    public function auditBookingId(): ?int
    {
        return null;
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'receipt_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

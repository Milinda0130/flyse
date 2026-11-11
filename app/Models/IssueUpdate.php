<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only issue timeline entry; it is itself the history, so it is not audited separately. */
class IssueUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['issue_id', 'update_text', 'status_from', 'status_to', 'created_by'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(BookingIssue::class, 'issue_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

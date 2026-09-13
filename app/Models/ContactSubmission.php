<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One submission of the code-owned contact form. See docs/content/forms.md.
 *
 * Submissions are records, not content: they are not tracked by the content
 * revision ledger and the client never edits them.
 */
class ContactSubmission extends Model
{
    protected $fillable = [
        'page_id', 'locale', 'name', 'email', 'phone', 'subject', 'message',
        'ip', 'user_agent', 'referer', 'recaptcha_score', 'read_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'recaptcha_score' => 'float',
            'read_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markRead(): void
    {
        if (! $this->isRead()) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}

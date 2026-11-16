<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Conversation extends Model
{
    protected $fillable = ['tenant_id', 'channel', 'user_ref'];

    protected function casts(): array
    {
        return ['messages' => 'array', 'cost' => 'decimal:6'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conversation) {
            $conversation->uuid ??= (string) Str::uuid();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The exchange as the people in it saw it: the user's words and the assistant's answers (tool calls left out).
     *
     * @return list<array{role: string, text: string, at: ?string}>
     */
    public function transcript(): array
    {
        $lines = [];
        foreach ((array) $this->messages as $m) {
            $content = $m['content'] ?? '';
            $texts = is_string($content)
                ? [$content]
                : array_map(fn ($b) => (string) $b['text'], array_filter((array) $content, fn ($b) => ($b['type'] ?? null) === 'text' && isset($b['text'])));
            $text = trim(implode("\n\n", $texts));
            if ($text !== '') {
                $lines[] = ['role' => $m['role'], 'text' => $text, 'at' => $m['at'] ?? null];
            }
        }

        return $lines;
    }
}

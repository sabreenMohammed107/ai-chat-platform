<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['name', 'monthly_messages', 'price', 'model', 'max_pages'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'monthly_messages' => 'integer', 'max_pages' => 'integer'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The Claude model this plan's agents answer with. */
    public function modelId(): string
    {
        return $this->model ?: (string) config('chat.default_model');
    }
}

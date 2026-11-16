<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A client of the platform: their agent, knowledge, keys, subscription and usage. */
class Tenant extends Model
{
    protected $fillable = ['name', 'website', 'status', 'assistant_name', 'instructions', 'contact_info'];

    protected function casts(): array
    {
        return ['warned_for' => 'date'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The subscription that ends last (the current one, or the one that ran out). */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->ofMany(['ends_at' => 'max', 'id' => 'max']);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(UsageDaily::class);
    }

    public function activeSubscription(): ?Subscription
    {
        $subscription = $this->subscription;

        return $subscription && $subscription->isActive() ? $subscription : null;
    }

    /**
     * Start of the current monthly period: the subscription's start day, every month (a yearly subscription still
     * gets its messages month by month).
     */
    public function periodStart(): ?CarbonImmutable
    {
        $subscription = $this->activeSubscription();
        if (! $subscription) {
            return null;
        }
        $start = CarbonImmutable::parse($subscription->starts_at);
        $months = (int) floor($start->diffInMonths(today()));
        $period = $start->addMonthsNoOverflow($months);

        return $period->gt(today()) ? $start->addMonthsNoOverflow(max(0, $months - 1)) : $period;
    }

    public function periodEnd(): ?CarbonImmutable
    {
        $start = $this->periodStart();
        if (! $start) {
            return null;
        }
        $end = $start->addMonthsNoOverflow(1)->subDay();
        $ends = CarbonImmutable::parse($this->activeSubscription()->ends_at);

        return $end->gt($ends) ? $ends : $end;
    }

    public function messagesUsed(): int
    {
        $start = $this->periodStart();

        return $start ? (int) $this->usage()->where('date', '>=', $start->toDateString())->sum('messages') : 0;
    }

    public function messagesAllowed(): int
    {
        return (int) ($this->activeSubscription()?->plan?->monthly_messages ?? 0);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** "safer.travel" or "www.safer.travel/" as typed → "https://safer.travel"; empty stays null. */
    public static function normalizeWebsite(?string $website): ?string
    {
        $website = trim((string) $website);
        if ($website === '') {
            return null;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $website)) {
            $website = 'https://'.ltrim($website, '/');
        }

        return rtrim($website, '/');
    }

    /** Lets the website widget work on this client's website: adds its domain to the widget key's allowed domains. */
    public function allowWebsiteInWidget(): void
    {
        $domains = $this->website ? ApiKey::parseDomains($this->website) : [];
        $key = $this->apiKeys()->where('type', 'public')->whereNull('revoked_at')->latest()->first();
        if (! $key) {
            ApiKey::issuePublic($this, $domains);

            return;
        }
        $merged = array_values(array_unique(array_merge((array) $key->allowed_domains, $domains)));
        if ($merged !== (array) $key->allowed_domains) {
            $key->update(['allowed_domains' => $merged]);
        }
    }

    public function displayName(): string
    {
        return $this->assistant_name ?: $this->name;
    }
}

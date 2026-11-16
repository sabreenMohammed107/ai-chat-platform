<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Secret keys (sk_live_...) are for the client's server: only their sha256 is stored, the key is shown once.
 * Public keys (pk_live_...) are for the website widget: they sit in the page anyway, so they are stored, and they only
 * work from the allowed domains.
 */
class ApiKey extends Model
{
    protected $fillable = ['tenant_id', 'type', 'name', 'prefix', 'hash', 'token', 'allowed_domains'];

    protected $hidden = ['hash'];

    protected function casts(): array
    {
        return ['allowed_domains' => 'array', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return array{0: self, 1: string} the key and its plain secret (show it once) */
    public static function issueSecret(Tenant $tenant, ?string $name = null): array
    {
        $plain = 'sk_live_'.Str::random(40);
        $key = self::create([
            'tenant_id' => $tenant->id,
            'type' => 'secret',
            'name' => $name,
            'prefix' => substr($plain, 0, 12),
            'hash' => hash('sha256', $plain),
        ]);

        return [$key, $plain];
    }

    public static function issuePublic(Tenant $tenant, array $domains = []): self
    {
        $plain = 'pk_live_'.Str::random(32);

        return self::create([
            'tenant_id' => $tenant->id,
            'type' => 'public',
            'prefix' => substr($plain, 0, 12),
            'token' => $plain,
            'allowed_domains' => array_values($domains),
        ]);
    }

    public static function findSecret(string $plain): ?self
    {
        return self::where('type', 'secret')->where('hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }

    public static function findPublic(string $plain): ?self
    {
        return self::where('type', 'public')->where('token', $plain)->whereNull('revoked_at')->first();
    }

    /** Whether a page on $origin (scheme://host[:port]) may use this public key: the host or one of its subdomains. */
    public function allowsOrigin(?string $origin): bool
    {
        $host = $origin ? strtolower((string) parse_url($origin, PHP_URL_HOST)) : '';
        if ($host === '') {
            return false;
        }
        foreach ((array) $this->allowed_domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    /** Turns what the client typed ("https://www.shop.com/", "shop.com") into bare hosts. */
    public static function parseDomains(?string $text): array
    {
        $domains = [];
        foreach (preg_split('/[\s,]+/', (string) $text) as $item) {
            $item = trim(strtolower($item));
            if ($item === '') {
                continue;
            }
            $host = parse_url(str_contains($item, '://') ? $item : 'https://'.$item, PHP_URL_HOST);
            if ($host) {
                $domains[] = preg_replace('/^www\./', '', $host);
            }
        }

        return array_values(array_unique($domains));
    }
}

<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedDomain extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRANSFER_PENDING = 'transfer_pending';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REDEMPTION = 'redemption';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'ext_absolute_hosting_domains';

    protected $fillable = [
        'service_id',
        'domain_name',
        'tld',
        'status',
        'provider_status',
        'registration_period',
        'expires_at',
        'transfer_locked',
        'nameservers',
        'contact_data',
        'provider_data',
        'last_synced_at',
    ];

    protected $casts = [
        'registration_period' => 'integer',
        'expires_at' => 'date',
        'transfer_locked' => 'boolean',
        'nameservers' => 'array',
        'contact_data' => 'encrypted:array',
        'provider_data' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function operationalSummary(): array
    {
        $providerData = is_array($this->provider_data)
            ? $this->provider_data
            : [];

        $operation = $providerData['operation'] ?? null;

        return [
            'domain' => $this->domain_name,
            'action' => match ($operation) {
                'register' => 'New registration',
                'transfer' => 'Domain transfer',
                default => 'Unknown',
            },
            'domain_status' => $this->statusLabel(),
            'provider_status' => $this->providerStatusLabel(),
            'period' => $this->registration_period === 1
                ? '1 year'
                : "{$this->registration_period} years",
            'nameservers' => array_values(
                array_filter(
                    $this->nameservers ?? [],
                    static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
                ),
            ),
            'provider_reference' => $this->providerReference(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'warning' => $this->provider_status === 'uncertain'
                ? 'The provider result is uncertain. Verify the order before any retry.'
                : null,
        ];
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_TRANSFER_PENDING => 'Transfer pending',
            self::STATUS_EXPIRED => 'Expired',
            self::STATUS_REDEMPTION => 'Redemption',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Unknown',
        };
    }

    private function providerStatusLabel(): string
    {
        return match ($this->provider_status) {
            null, '' => 'Not submitted',
            'submitting' => 'Submitting',
            'submitted' => 'Submitted',
            'uncertain' => 'Uncertain',
            default => 'Unknown',
        };
    }

    private function providerReference(): ?string
    {
        $providerData = is_array($this->provider_data)
            ? $this->provider_data
            : [];

        $response = is_array($providerData['response'] ?? null)
            ? $providerData['response']
            : [];

        foreach ([
            'order_reference',
            'orderReference',
            'order_id',
            'orderId',
            'id',
        ] as $key) {
            $value = $response[$key] ?? null;

            if (
                is_scalar($value)
                && trim((string) $value) !== ''
            ) {
                return trim((string) $value);
            }
        }

        return null;
    }

    public function setDomainNameAttribute(string $value): void
    {
        $this->attributes['domain_name'] = strtolower(
            rtrim(trim($value), '.')
        );
    }

    public function setTldAttribute(string $value): void
    {
        $normalised = strtolower(ltrim(trim($value), '.'));

        $this->attributes['tld'] = '.' . $normalised;
    }
}

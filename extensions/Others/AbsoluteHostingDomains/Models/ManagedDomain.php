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

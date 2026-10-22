@php
    $summary = $this->getOperationalSummary();
@endphp

@if ($summary)
    <div class="fi-widget-wrapper col-span-full rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                        Registrar order
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Safe operational status for this managed domain.
                    </p>
                </div>

                <span
                    @class([
                        'inline-flex w-fit items-center rounded-full px-2.5 py-1 text-xs font-medium',
                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/20 dark:text-yellow-300' => $summary['provider_status'] === 'Submitting',
                        'bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-300' => $summary['provider_status'] === 'Submitted',
                        'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300' => $summary['provider_status'] === 'Uncertain',
                        'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => !in_array(
                            $summary['provider_status'],
                            ['Submitting', 'Submitted', 'Uncertain'],
                            true,
                        ),
                    ])
                >
                    {{ $summary['provider_status'] }}
                </span>
            </div>

            @if ($summary['warning'])
                <div class="flex items-start gap-2 rounded-lg border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-900 dark:border-yellow-500/40 dark:bg-yellow-500/10 dark:text-yellow-200">
                    <x-ri-error-warning-line class="mt-0.5 size-5 shrink-0" />
                    <span>{{ $summary['warning'] }}</span>
                </div>
            @endif

            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Domain</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $summary['domain'] }}
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Operation</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $summary['action'] }}
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Domain status</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $summary['domain_status'] }}
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Registration period</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $summary['period'] }}
                    </dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-gray-500 dark:text-gray-400">Nameservers</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $summary['nameservers'] !== []
                            ? implode(', ', $summary['nameservers'])
                            : 'Not recorded' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Provider reference</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $summary['provider_reference'] ?: 'Not available' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Last synchronised</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $summary['last_synced_at']
                            ? \Illuminate\Support\Carbon::parse($summary['last_synced_at'])->translatedFormat('M j, Y H:i')
                            : 'Not yet synchronised' }}
                    </dd>
                </div>
            </dl>

            <p class="text-xs text-gray-500 dark:text-gray-400">
                Registrant contact details, transfer credentials and raw provider data are intentionally excluded.
            </p>
        </div>
    </div>
@endif

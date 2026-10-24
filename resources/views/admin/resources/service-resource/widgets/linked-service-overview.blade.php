@php
    $linkedService = $this->getLinkedService();
    $linkedServiceType = $this->getLinkedServiceType();
@endphp

@if ($linkedService)
    <div class="fi-widget-wrapper col-span-full rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <div class="rounded-lg bg-primary-50 p-2 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                    <x-ri-links-line class="size-5" />
                </div>

                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Linked service
                    </p>

                    <h2 class="mt-1 truncate text-base font-semibold text-gray-950 dark:text-white">
                        {{ $linkedService->label }}
                    </h2>

                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        <span class="rounded-full border border-gray-200 px-2.5 py-1 text-gray-600 dark:border-white/10 dark:text-gray-300">
                            {{ $linkedServiceType }}
                        </span>

                        <span @class([
                            'rounded-full px-2.5 py-1 font-medium',
                            'bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-300' =>
                                $linkedService->status === 'active',
                            'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300' =>
                                in_array(
                                    $linkedService->status,
                                    ['suspended', 'cancelled'],
                                    true,
                                ),
                            'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/20 dark:text-yellow-300' =>
                                !in_array(
                                    $linkedService->status,
                                    ['active', 'suspended', 'cancelled'],
                                    true,
                                ),
                        ])>
                            {{ ucfirst(str_replace('_', ' ', $linkedService->status)) }}
                        </span>

                        <span class="text-gray-500 dark:text-gray-400">
                            Service #{{ $linkedService->id }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        This hosting and domain service pair belongs to the same customer order.
                    </p>
                </div>
            </div>

            <a href="{{ \App\Admin\Resources\ServiceResource::getUrl('edit', ['record' => $linkedService]) }}"
                View linked service
                <x-ri-arrow-right-line class="size-4" />
            </a>
        </div>
    </div>
@endif

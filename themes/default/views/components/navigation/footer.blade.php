<footer class="w-full px-4 py-4 lg:mt-72 mt-44  bg-background-secondary border-t border-neutral">
    <div class="container my-12 mx-auto px-4 sm:px-6 md:px-8 lg:px-10">
        <div class="flex flex-col md:flex-row justify-between gap-2 items-center">
            <div class="flex flex-col gap-6 items-start">
                <div class="flex flex-row gap-2">
                    <x-logo class="h-10" />
                    @if(theme('logo_display', 'logo-and-name') != 'logo-only')
                    <span class="text-xl font-bold leading-none flex items-center">{{ config('app.name') }}</span>
                    @endif
                </div>
                <div class="text-sm text-base/70">
                    {{ __('© :year :app_name. | All rights reserved.', ['year' => date('Y'), 'app_name' => config('app.name')]) }}
                </div>
              
            </div>
            <a class="bg-background-secondary border border-neutral p-2 rounded-lg transition-colors group mt-4 mb-6 flex items-center gap-2 text-base/50 hover:text-base" href="https://paymenter.org" target="_blank">
               
<p class="text-sm text-base/50 mt-4 mb-6"> © {{ date('Y') }} vKloud. Powered by vPay. </p>
        </div>
    </div>
</footer>

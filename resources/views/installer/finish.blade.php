<div class="flex flex-col items-center justify-center text-center p-12">
    <h2 class="text-2xl font-bold mb-4">🎉 {{ __('installer.finish.title') }}</h2>
    <p class="text-gray-600 mb-8">
        {{ __('installer.finish.description') }}
    </p>

    <div class="flex gap-4 mb-10">
        <button class="px-6 py-2 bg-black text-white rounded-lg cursor-pointer active:scale-95 transition-all duration-200" wire:click="downloadSettings">
            {{ __('installer.finish.save_settings') }}
        </button>
    </div>

    <div class="w-full text-left" x-data="{ tab: 'webhook' }">
        <h3 class="text-sm font-semibold text-gray-700 mb-1">{{ __('installer.finish.cron_guide.title') }}</h3>
        <p class="text-xs text-gray-400 mb-3">{{ __('installer.finish.cron_guide.subtitle') }}</p>

        <div class="border rounded-xl overflow-hidden">
            <div class="flex border-b bg-gray-50">
                <button type="button" @click="tab = 'webhook'" class="flex-1 px-3 py-2 text-xs font-medium cursor-pointer transition-colors" :class="tab === 'webhook' ? 'text-gray-900 border-b-2 border-gray-900 bg-white' : 'text-gray-400'">
                    {{ __('installer.finish.cron_guide.tab_webhook') }}
                </button>
                <button type="button" @click="tab = 'cpanel'" class="flex-1 px-3 py-2 text-xs font-medium cursor-pointer transition-colors" :class="tab === 'cpanel' ? 'text-gray-900 border-b-2 border-gray-900 bg-white' : 'text-gray-400'">
                    {{ __('installer.finish.cron_guide.tab_cpanel') }}
                </button>
                <button type="button" @click="tab = 'plesk'" class="flex-1 px-3 py-2 text-xs font-medium cursor-pointer transition-colors" :class="tab === 'plesk' ? 'text-gray-900 border-b-2 border-gray-900 bg-white' : 'text-gray-400'">
                    {{ __('installer.finish.cron_guide.tab_plesk') }}
                </button>
            </div>

            <div class="p-4 text-xs text-gray-600 leading-relaxed">
                <div x-show="tab === 'webhook'" x-cloak class="space-y-2">
                    <p>{{ __('installer.finish.cron_guide.webhook_intro') }}</p>
                    <p><strong>1.</strong> {{ __('installer.finish.cron_guide.webhook_step1') }}</p>
                    <p><strong>2.</strong> {{ __('installer.finish.cron_guide.webhook_step2') }}</p>
                </div>

                <div x-show="tab === 'cpanel'" x-cloak class="space-y-2">
                    <p>{{ __('installer.finish.cron_guide.cpanel_intro') }}</p>
                    <p>{{ __('installer.finish.cron_guide.cpanel_step') }}</p>
                    <div class="bg-gray-900 text-emerald-400 p-2.5 rounded-lg font-mono text-[11px] overflow-x-auto">
                        {{ __('installer.finish.cron_guide.cpanel_command') }}
                    </div>
                </div>

                <div x-show="tab === 'plesk'" x-cloak class="space-y-2">
                    <p>{{ __('installer.finish.cron_guide.plesk_intro') }}</p>
                    <p>{{ __('installer.finish.cron_guide.plesk_step1') }}</p>
                    <div class="bg-gray-900 text-emerald-400 p-2.5 rounded-lg font-mono text-[11px] overflow-x-auto">
                        {{ __('installer.finish.cron_guide.plesk_env') }}
                    </div>
                    <p>{{ __('installer.finish.cron_guide.plesk_step2') }}</p>
                    <p class="font-semibold text-gray-700">{{ __('installer.finish.cron_guide.plesk_command1_label') }}</p>
                    <div class="bg-gray-900 text-emerald-400 p-2.5 rounded-lg font-mono text-[11px] overflow-x-auto">
                        {{ __('installer.finish.cron_guide.plesk_command1') }}
                    </div>
                    <p class="font-semibold text-gray-700">{{ __('installer.finish.cron_guide.plesk_command2_label') }}</p>
                    <div class="bg-gray-900 text-emerald-400 p-2.5 rounded-lg font-mono text-[11px] overflow-x-auto">
                        {{ __('installer.finish.cron_guide.plesk_command2') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

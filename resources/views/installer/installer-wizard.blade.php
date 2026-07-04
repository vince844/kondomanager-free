<div x-data="{ showWaitScreen: @entangle('showWaitScreen') }">
    <div class="w-full max-w-screen-lg min-w-5xl mx-auto">
        @if (session('installer.error'))
            <div class="error my-2"> {{ session('installer.error') }}</div>
        @endif

        <div x-show="showWaitScreen" x-cloak>
            <div class="fixed w-full h-full inset-0 bg-gray-950/70 backdrop-blur-sm z-50 flex justify-center items-center">
                <div class="bg-white rounded-2xl px-8 py-6 shadow-2xl flex flex-col items-center gap-3 max-w-sm text-center">
                    <svg class="animate-spin h-8 w-8 text-gray-800" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span class="font-medium text-gray-700">
                        {{ $stepKey === 'environment' ? __('installer.wizard.wait_environment') : __('installer.wizard.wait_default') }}
                    </span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-8 flex gap-8">
            <!-- Stepper -->
            <div class="shrink-0 max-w-xs border-r pe-4">
                <div class="flex items-center gap-3 bg-gray-50 rounded-xl px-3 py-3 mb-8">
                    <span class="shrink-0 flex items-center justify-center w-10 h-10 rounded-full bg-gray-950 text-white text-sm font-bold">Km</span>
                    <div class="leading-tight">
                        <p class="font-semibold text-gray-800">Kondomanager</p>
                        <p class="text-xs text-gray-400">{{ __('installer.wizard.brand_subtitle') }}</p>
                    </div>
                </div>

                <div class="flex flex-col items-start space-y-8 relative">
                    <div id="progress-line" class="absolute left-3.75 top-2 w-0.5 bg-emerald-400/50 rounded-full" style="height: calc({{ (100 / $steps->count()) * $currentIndex }}% + 36px)"></div>
                    <!-- Steps -->
                    @foreach ($steps as $index => $s)
                        <div class="flex items-center space-x-4 z-10">
                            @if ($index < $currentIndex)
                                <div id="step-{{ $index }}" class="relative flex items-center justify-center w-8 h-8 rounded-full bg-emerald-400">
                                    <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            @elseif($index === $currentIndex)
                                <div id="step-{{ $index }}" class="relative flex items-center justify-center w-8 h-8 rounded-full bg-gray-950 text-white ring-4 ring-gray-300">
                                    <span class="text-sm font-semibold">{{ $loop->index + 1 }}</span>
                                </div>
                            @else
                                <div id="step-{{ $index }}" class="relative flex items-center justify-center w-8 h-8 rounded-full bg-gray-200">
                                    <span class="text-sm font-semibold">{{ $index + 1 }}</span>
                                </div>
                            @endif

                            <div class="flex-1">
                                <p class="text-gray-500 font-semibold">{{ __('installer.steps.' . $s['key'] . '.label') }}</p>
                                <p class="text-sm text-gray-400">{{ __('installer.steps.' . $s['key'] . '.description') }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Step Content -->
            <div class="flex flex-col grow pe-6">
                @if (isset($step['component']))
                    <h1 class="text-xl font-semibold">{{ __('installer.steps.' . $step['key'] . '.label') }}</h1>
                    <p class="mt-1.5 text-sm text-gray-500 leading-relaxed">{{ __('installer.steps.' . $step['key'] . '.guide') }}</p>

                    <div class="mt-3 py-3 pe-4">
                        @livewire($step['component'], ['wizard' => $this])
                    </div>

                    <div class="mt-auto flex justify-between items-center gap-3 pt-6">
                        @if ($currentIndex > 0 && $currentIndex < $steps->count() - 1)
                            {{-- wire:loading.attr senza wire:target: si disabilita per QUALSIASI richiesta
                                Livewire in corso, non solo previousStep — inclusi i salvataggi "on blur" dei
                                campi dello step corrente, altrimenti un click subito dopo l'ultimo carattere
                                digitato può partire prima che quel salvataggio arrivi al server. --}}
                            <button class="btn-secondary flex items-center justify-center gap-2" wire:click="previousStep" wire:loading.attr="disabled">
                                {{ __('installer.actions.back') }}
                            </button>
                        @else
                            <span></span>
                        @endif

                        <div class="flex gap-3">
                            @if ($skippable)
                                <button class="btn-primary flex items-center justify-center gap-2" wire:click="skipStep" wire:loading.attr="disabled">
                                    <svg wire:loading wire:target="skipStep" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span wire:loading.remove wire:target="skipStep">{{ __('installer.actions.skip') }}</span>
                                    <span wire:loading wire:target="skipStep">{{ __('installer.actions.loading') }}</span>
                                </button>
                            @endif

                            @if ($currentIndex === $steps->count() - 1)
                                <button class="btn-primary flex items-center justify-center gap-2" wire:click="completeStep" wire:loading.attr="disabled">
                                    <svg wire:loading wire:target="completeStep" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span wire:loading.remove wire:target="completeStep">{{ __('installer.actions.finish') }}</span>
                                    <span wire:loading wire:target="completeStep">{{ __('installer.actions.loading') }}</span>
                                </button>
                            @else
                                <button class="btn-primary flex items-center justify-center gap-2" wire:click="completeStep" wire:loading.attr="disabled" @disabled(!$canProceed)>
                                    <svg wire:loading wire:target="completeStep" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span wire:loading.remove wire:target="completeStep">{{ __('installer.actions.next') }}</span>
                                    <span wire:loading wire:target="completeStep">{{ __('installer.actions.loading') }}</span>
                                </button>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

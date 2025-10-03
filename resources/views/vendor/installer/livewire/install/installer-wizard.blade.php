<div>
    @if (session('installer.error'))
        <div class="error my-2"> {{ session('installer.error') }}</div>
    @endif

    <div class="flex gap-12 bg-white rounded-2xl shadow-xl p-12">
        <!-- Stepper -->
        <div class="shrink-0 max-w-xs border-r p-4">
            <div class="flex flex-col items-start space-y-12 relative">
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
                            <div id="step-{{ $index }}" class="relative flex items-center justify-center w-8 h-8 rounded-full bg-gray-800 text-white ring-4 ring-gray-200">
                                <span class="text-sm font-semibold">{{ $loop->index + 1 }}</span>
                            </div>
                        @else
                            <div id="step-{{ $index }}" class="relative flex items-center justify-center w-8 h-8 rounded-full bg-gray-200">
                                <span class="text-sm font-semibold">{{ $index + 1 }}</span>
                            </div>
                        @endif

                        <div class="flex-1">
                            <p class="text-gray-500 font-semibold">{{ $s['label'] }}</p>
                            <p class="text-sm text-gray-400">{{ $s['description'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Step Content -->
        <div class="flex flex-col grow pe-6">
            @if (isset($step['component']))
                <h1 class="text-xl font-semibold">{{ $step['label'] }}</h1>

                <div class="mt-4 max-h-[40vh] overflow-auto py-4 pe-4">
                    @livewire($step['component'], ['wizard' => $this])
                </div>

                <div class="mt-auto flex justify-end gap-3">
                    @if ($currentIndex === $steps->count() - 1)
                        <button class="btn-primary mt-12" wire:click="completeStep">
                            {{ __('Finish') }}
                        </button>
                    @else
                        <button class="btn-primary mt-12" wire:click="completeStep" @disabled(!$canProceed)>
                            {{ __('Next') }}
                        </button>
                    @endif
                </div>
            @else
                {{--  --}}
            @endif
        </div>
    </div>
</div>

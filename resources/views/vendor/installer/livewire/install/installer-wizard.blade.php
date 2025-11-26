<div x-data="{ showWaitScreen: @entangle('showWaitScreen') }">
    <div class="w-full max-w-screen-lg min-w-5xl mx-auto">
        @if (session('installer.error'))
            <div class="error my-2"> {{ session('installer.error') }}</div>
        @endif

        <div x-show="showWaitScreen" x-cloak>
            <div class="absolute w-full min-h-screen inset-0 bg-black/10 z-50 flex justify-center items-center">
                <div class="bg-white rounded-xl p-8 shadow-lg flex gap-2 items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M15.24 2H8.76C5 2 4.71 5.38 6.74 7.22l10.52 9.56C19.29 18.62 19 22 15.24 22H8.76C5 22 4.71 18.62 6.74 16.78l10.52-9.56C19.29 5.38 19 2 15.24 2Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                    <span>{{ __('Working. Please wait...') }}</span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-12 flex gap-12">
            <!-- Stepper -->
            <div class="shrink-0 max-w-xs border-r p-4">
                <div class="flex flex-col items-start space-y-12 relative">
                    {{-- <div class="absolute left-3.5 top-2 bottom-0 w-0.5 bg-gray-100 rounded-full"></div> --}}
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
                                <div id="step-{{ $index }}" class="relative flex items-center justify-center w-8 h-8 rounded-full bg-gray-800 text-white ring-4 ring-gray-300">
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

                        @if ($skippable)
                            <button class="btn-primary mt-12" wire:click="skipStep">
                                {{ __('Skip') }}
                            </button>
                        @endif

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
                @endif
            </div>
        </div>
    </div>
</div>

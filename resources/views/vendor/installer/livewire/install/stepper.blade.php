<div class="border-e pe-6">
    <!-- Stepper Navigation (Left Column) -->
    <div class="flex flex-col items-start space-y-12 relative">
        <div class="absolute left-3.5 top-2 bottom-0 w-1 bg-gray-100 rounded-full"></div>
        <div id="progress-line" class="absolute left-3.5 top-2 w-1 bg-orange-500 rounded-full z-10" style="height: calc({{ (100 / 4) * $currentStep }}% - 36px)"></div>
        <!-- Steps -->
        @foreach ($steps as $step)
            <div class="flex items-center space-x-4 z-10">
                @if ($loop->index + 1 < $currentStep)
                    <div id="step-{{ $loop->index + 1 }}" class="relative flex items-center justify-center w-8 h-8 rounded-full bg-green-500">
                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                @elseif($loop->index + 1 === $currentStep)
                    <div id="step-{{ $loop->index + 1 }}" class="relative flex items-center justify-center w-8 h-8 rounded-full bg-amber-400 ring-2 ring-offset-2 ring-amber-300">
                        <span class="text-sm font-semibold">{{ $loop->index + 1 }}</span>
                    </div>
                @else
                    <div id="step-{{ $loop->index + 1 }}" class="relative flex items-center justify-center w-8 h-8 rounded-full bg-gray-200">
                        <span class="text-sm font-semibold">{{ $loop->index + 1 }}</span>
                    </div>
                @endif

                <div class="flex-1">
                    <p class="text-gray-500 font-semibold truncate">{{ $step['label'] }}</p>
                    <p class="text-sm text-gray-400 truncate">{{ $step['description'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</div>

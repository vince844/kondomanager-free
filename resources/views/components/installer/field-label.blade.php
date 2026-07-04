@props(['for', 'tooltip' => null])

<label for="{{ $for }}" class="flex items-center gap-1.5 text-sm font-medium text-gray-600 mb-1">
    <span>{{ $slot }}</span>

    @if ($tooltip)
        <span
            x-data="{ open: false }"
            class="relative inline-flex"
        >
            <button
                type="button"
                tabindex="0"
                @mouseenter="open = true"
                @mouseleave="open = false"
                @focus="open = true"
                @blur="open = false"
                class="flex items-center justify-center w-4 h-4 rounded-full bg-gray-200 text-gray-500 text-[10px] font-semibold hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-400"
                aria-label="{{ $tooltip }}"
            >?</button>

            <span
                x-show="open"
                x-cloak
                x-transition
                class="absolute z-20 bottom-full left-1/2 -translate-x-1/2 mb-2 w-56 rounded-lg bg-gray-900 text-white text-xs leading-snug p-2.5 shadow-lg pointer-events-none"
            >
                {{ $tooltip }}
                <span class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 w-2 h-2 bg-gray-900 rotate-45"></span>
            </span>
        </span>
    @endif
</label>

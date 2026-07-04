<div class="flex flex-col items-center justify-center text-center p-12">
    <h2 class="text-2xl font-bold mb-4">🎉 {{ __('installer.finish.title') }}</h2>
    <p class="text-gray-600 mb-12">
        {{ __('installer.finish.description') }}
    </p>

    <div class="flex gap-4">
        <button class="px-6 py-2 bg-black text-white rounded-lg cursor-pointer active:scale-95 transition-all duration-200" wire:click="downloadSettings">
            {{ __('installer.finish.save_settings') }}
        </button>
    </div>
</div>

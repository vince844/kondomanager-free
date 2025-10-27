<div class="w-full">
    @isset($errorMessage)
        <p class="text-red-500 mb-4">{{ $errorMessage }}</p>
    @endisset


    {{-- APP URL --}}
    <h2 class="border-b w-full pb-1 text-gray-400">App</h2>

    <div class="mt-6">
        <label for="app-url">{{ __('App Url') }}</label>
        <input type="text" name="app-url" id="app-url" placeholder="https://domain.com" wire:model.blur="appUrl">
        @error('appUrl')
            <span class="error">{{ $message }}</span>
        @enderror
    </div>

    {{-- DATABASE --}}
    @if ($isDatabaseRequired)
        <h2 class="border-b w-full pb-1 mt-12 text-gray-400">{{ __('Database') }}</h2>
        <div class="grid grid-cols-2 gap-4 mt-6">
            <div>
                <label for="db-host">{{ __('Host') }}</label>
                <input type="text" name="db-host" id="db-host" placeholder="127.0.0.1" wire:model.blur="dbHost">
                @error('dbHost')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label for="db-port">{{ __('Port') }}</label>
                <input type="text" name="db-port" id="db-port" placeholder="3306" wire:model.blur="dbPort">
                @error('dbPort')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="col-span-full">
                <label for="db-database">{{ __('Database') }}</label>
                <input type="text" name="db-database" id="db-database" placeholder="Laravel" wire:model.blur="dbDatabase">
                @error('dbDatabase')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label for="db-username">{{ __('Username') }}</label>
                <input type="text" name="db-username" id="db-username" placeholder="root" wire:model.blur="dbUsername">
                @error('dbUsername')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label for="db-password">{{ __('Password') }}</label>
                <input type="password" name="db-password" id="db-password" wire:model.blur="dbPassword">
                @error('dbPassword')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>
        </div>
    @endif

</div>

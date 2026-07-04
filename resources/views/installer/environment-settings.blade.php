<div class="w-full">
    @isset($errorMessage)
        <p class="text-red-500 mb-4">{{ $errorMessage }}</p>
    @endisset


    {{-- APP --}}
    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-installer.field-label for="app-name" :tooltip="__('installer.fields.app_name.tooltip')">
                {{ __('installer.fields.app_name.label') }}
            </x-installer.field-label>
            <input type="text" name="app-name" id="app-name" placeholder="Kondomanager" wire:model.blur="appName">
            @error('appName')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <x-installer.field-label for="app-url" :tooltip="__('installer.fields.app_url.tooltip')">
                {{ __('installer.fields.app_url.label') }}
            </x-installer.field-label>
            <input type="text" name="app-url" id="app-url" placeholder="https://dominio.com" wire:model.blur="appUrl">
            @error('appUrl')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <x-installer.field-label for="app-locale" :tooltip="__('installer.fields.app_locale.tooltip')">
                {{ __('installer.fields.app_locale.label') }}
            </x-installer.field-label>
            <select name="app-locale" id="app-locale" wire:model.blur="appLocale">
                @foreach (config('installer.available_locales', []) as $locale => $label)
                    <option value="{{ $locale }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('appLocale')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>
    </div>

    {{-- DATABASE --}}
    @if ($isDatabaseRequired)
        <h2 class="border-b w-full pb-1 mt-8 text-gray-400">{{ __('installer.sections.database') }}</h2>
        <div class="grid grid-cols-2 gap-4 mt-4">
            <div>
                <x-installer.field-label for="db-host" :tooltip="__('installer.fields.db_host.tooltip')">
                    {{ __('installer.fields.db_host.label') }}
                </x-installer.field-label>
                <input type="text" name="db-host" id="db-host" placeholder="127.0.0.1" wire:model.blur="dbHost">
                @error('dbHost')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <x-installer.field-label for="db-port" :tooltip="__('installer.fields.db_port.tooltip')">
                    {{ __('installer.fields.db_port.label') }}
                </x-installer.field-label>
                <input type="text" name="db-port" id="db-port" placeholder="3306" wire:model.blur="dbPort">
                @error('dbPort')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="col-span-full">
                <x-installer.field-label for="db-database" :tooltip="__('installer.fields.db_database.tooltip')">
                    {{ __('installer.fields.db_database.label') }}
                </x-installer.field-label>
                <input type="text" name="db-database" id="db-database" placeholder="kondomanager" wire:model.blur="dbDatabase">
                @error('dbDatabase')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <x-installer.field-label for="db-username" :tooltip="__('installer.fields.db_username.tooltip')">
                    {{ __('installer.fields.db_username.label') }}
                </x-installer.field-label>
                <input type="text" name="db-username" id="db-username" placeholder="root" wire:model.blur="dbUsername">
                @error('dbUsername')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <x-installer.field-label for="db-password" :tooltip="__('installer.fields.db_password.tooltip')">
                    {{ __('installer.fields.db_password.label') }}
                </x-installer.field-label>
                <x-installer.password-input id="db-password" name="db-password" wire:model.blur="dbPassword" />
                @error('dbPassword')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="col-span-full flex items-center gap-3 mt-2">
                <button type="button" class="btn-secondary flex items-center justify-center gap-2" wire:click="testDatabaseConnection" wire:loading.attr="disabled" wire:target="testDatabaseConnection">
                    <svg wire:loading wire:target="testDatabaseConnection" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span wire:loading.remove wire:target="testDatabaseConnection">{{ __('installer.database.test_button') }}</span>
                    <span wire:loading wire:target="testDatabaseConnection">{{ __('installer.actions.loading') }}</span>
                </button>

                @if ($dbTestStatus === 'success')
                    <span class="text-sm text-green-600">{{ $dbTestMessage }}</span>
                @elseif ($dbTestStatus === 'error')
                    <span class="text-sm text-red-500">{{ $dbTestMessage }}</span>
                @endif
            </div>
        </div>
    @endif

</div>

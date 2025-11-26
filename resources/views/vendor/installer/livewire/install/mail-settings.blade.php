<div class="w-full">
    @isset($errorMessage)
        <p class="text-red-500 mb-4">{{ $errorMessage }}</p>
    @endisset

    {{-- MAIL --}}
    @if ($isMailRequired)
        <h2 class="border-b w-full pb-1 mt-12 text-gray-400">{{ __('Mail') }}</h2>
        <div class="grid grid-cols-2 gap-4 mt-6">
            <div>
                <label for="mail-mailer">{{ __('Mailer') }}</label>
                <input type="text" name="mail-mailer" id="mail-mailer" placeholder="root" wire:model.blur="mailMailer">
                @error('mailMailer')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div></div>

            <div>
                <label for="mail-host">{{ __('Host') }}</label>
                <input type="text" name="mail-host" id="mail-host" placeholder="127.0.0.1" wire:model.blur="mailHost">
                @error('mailHost')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label for="mail-port">{{ __('Port') }}</label>
                <input type="text" name="mail-port" id="mail-port" wire:model.blur="mailPort">
                @error('mailPort')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label for="mail-username">{{ __('Username') }}</label>
                <input type="text" name="mail-username" id="mail-username" wire:model.blur="mailUsername">
                @error('mailUsername')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label for="mail-password">{{ __('Password') }}</label>
                <input type="password" name="mail-password" id="mail-password" wire:model.blur="mailPassword">
                @error('mailPassword')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="col-span-full">
                <label for="mail-from-address">{{ __('From Address') }}</label>
                <input type="text" name="mail-from-address" id="mail-from-address" placeholder="yourmail@domain.com" wire:model.blur="mailFromAddress">
                @error('mailFromAddress')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="col-span-full">
                <label for="mail-from-name">{{ __('From Name') }}</label>
                <input type="text" name="mail-from-name" id="mail-from-name" placeholder="Admin" wire:model.blur="mailFromName">
                @error('mailFromName')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>
        </div>
    @endif

</div>

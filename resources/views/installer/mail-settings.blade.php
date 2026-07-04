<div class="w-full">
    @isset($errorMessage)
        <p class="text-red-500 mb-4">{{ $errorMessage }}</p>
    @endisset

    {{-- MAIL --}}
    @if ($isMailRequired)
        <h2 class="border-b w-full pb-1 mt-12 text-gray-400">{{ __('installer.sections.mail') }}</h2>
        <div class="grid grid-cols-2 gap-4 mt-6">
            <div>
                <x-installer.field-label for="mail-mailer" :tooltip="__('installer.fields.mail_mailer.tooltip')">
                    {{ __('installer.fields.mail_mailer.label') }}
                </x-installer.field-label>
                <input type="text" name="mail-mailer" id="mail-mailer" placeholder="smtp" wire:model.live.blur="mailMailer" wire:key="field-mailMailer">
                @error('mailMailer')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div></div>

            <div>
                <x-installer.field-label for="mail-host" :tooltip="__('installer.fields.mail_host.tooltip')">
                    {{ __('installer.fields.mail_host.label') }}
                </x-installer.field-label>
                <input type="text" name="mail-host" id="mail-host" placeholder="127.0.0.1" wire:model.live.blur="mailHost" wire:key="field-mailHost">
                @error('mailHost')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <x-installer.field-label for="mail-port" :tooltip="__('installer.fields.mail_port.tooltip')">
                    {{ __('installer.fields.mail_port.label') }}
                </x-installer.field-label>
                <input type="text" name="mail-port" id="mail-port" wire:model.live.blur="mailPort" wire:key="field-mailPort">
                @error('mailPort')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <x-installer.field-label for="mail-username" :tooltip="__('installer.fields.mail_username.tooltip')">
                    {{ __('installer.fields.mail_username.label') }}
                </x-installer.field-label>
                <input type="text" name="mail-username" id="mail-username" wire:model.live.blur="mailUsername" wire:key="field-mailUsername">
                @error('mailUsername')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <x-installer.field-label for="mail-encryption" :tooltip="__('installer.fields.mail_encryption.tooltip')">
                    {{ __('installer.fields.mail_encryption.label') }}
                </x-installer.field-label>
                <select name="mail-encryption" id="mail-encryption" wire:model.live.blur="mailEncryption" wire:key="field-mailEncryption">
                    <option value="tls">TLS</option>
                    <option value="ssl">SSL</option>
                    <option value="null">{{ __('installer.fields.mail_encryption.none') }}</option>
                </select>
                @error('mailEncryption')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="col-span-full">
                <x-installer.field-label for="mail-password" :tooltip="__('installer.fields.mail_password.tooltip')">
                    {{ __('installer.fields.mail_password.label') }}
                </x-installer.field-label>
                <x-installer.password-input id="mail-password" name="mail-password" wire:model.live.blur="mailPassword" wire:key="field-mailPassword" />
                @error('mailPassword')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="col-span-full">
                <x-installer.field-label for="mail-from-address" :tooltip="__('installer.fields.mail_from_address.tooltip')">
                    {{ __('installer.fields.mail_from_address.label') }}
                </x-installer.field-label>
                <input type="text" name="mail-from-address" id="mail-from-address" placeholder="amministrazione@dominio.com" wire:model.live.blur="mailFromAddress" wire:key="field-mailFromAddress">
                @error('mailFromAddress')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="col-span-full">
                <x-installer.field-label for="mail-from-name" :tooltip="__('installer.fields.mail_from_name.tooltip')">
                    {{ __('installer.fields.mail_from_name.label') }}
                </x-installer.field-label>
                <input type="text" name="mail-from-name" id="mail-from-name" placeholder="Amministrazione" wire:model.live.blur="mailFromName" wire:key="field-mailFromName">
                @error('mailFromName')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="col-span-full mt-2 pt-4 border-t">
                <x-installer.field-label for="mail-test-recipient" :tooltip="__('installer.mail.test_recipient_tooltip')">
                    {{ __('installer.mail.test_recipient_label') }}
                </x-installer.field-label>
                <div class="flex items-center gap-3">
                    <input type="text" name="mail-test-recipient" id="mail-test-recipient" placeholder="tu@esempio.com" class="grow" wire:model.live.blur="testEmailRecipient" wire:key="field-testEmailRecipient">
                    <button type="button" class="btn-primary shrink-0 flex items-center justify-center gap-2" wire:click="sendTestEmail" wire:loading.attr="disabled" wire:target="sendTestEmail">
                        <svg wire:loading wire:target="sendTestEmail" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="sendTestEmail">{{ __('installer.mail.test_button') }}</span>
                        <span wire:loading wire:target="sendTestEmail">{{ __('installer.actions.loading') }}</span>
                    </button>
                </div>
                @error('testEmailRecipient')
                    <span class="error">{{ $message }}</span>
                @enderror

                @if ($testStatus === 'success')
                    <p class="text-sm text-green-600 mt-2">{{ $testMessage }}</p>
                @elseif ($testStatus === 'error')
                    <p class="text-sm text-red-500 mt-2">{{ $testMessage }}</p>
                @endif
            </div>
        </div>
    @endif
</div>

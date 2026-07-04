<div>
    <div class="space-y-6">
        <div>
            <x-installer.field-label for="name" :tooltip="__('installer.fields.admin_name.tooltip')">
                {{ __('installer.fields.admin_name.label') }}
            </x-installer.field-label>
            <input type="text" name="name" id="name" wire:model.live.blur="name" wire:key="field-name" required>
            @error('name')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <x-installer.field-label for="email" :tooltip="__('installer.fields.admin_email.tooltip')">
                {{ __('installer.fields.admin_email.label') }}
            </x-installer.field-label>
            <input type="email" name="email" id="email" wire:model.live.blur='email' wire:key='field-email' required>
            @error('email')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <x-installer.field-label for="password" :tooltip="__('installer.fields.admin_password.tooltip')">
                {{ __('installer.fields.admin_password.label') }}
            </x-installer.field-label>
            <x-installer.password-input id="password" name="password" wire:model.live.blur="password" wire:key="field-password" required />
            @error('password')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <x-installer.field-label for="password_confirmation" :tooltip="__('installer.fields.admin_password_confirmation.tooltip')">
                {{ __('installer.fields.admin_password_confirmation.label') }}
            </x-installer.field-label>
            <x-installer.password-input id="password_confirmation" name="password_confirmation" wire:model.live.blur="password_confirmation" wire:key="field-password_confirmation" required />
        </div>
    </div>
</div>

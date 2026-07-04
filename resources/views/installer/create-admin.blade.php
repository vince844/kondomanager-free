<div>
    <div class="space-y-6">
        <div>
            <x-installer.field-label for="name" :tooltip="__('installer.fields.admin_name.tooltip')">
                {{ __('installer.fields.admin_name.label') }}
            </x-installer.field-label>
            <input type="text" name="name" id="name" wire:model.blur="name" required>
            @error('name')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <x-installer.field-label for="email" :tooltip="__('installer.fields.admin_email.tooltip')">
                {{ __('installer.fields.admin_email.label') }}
            </x-installer.field-label>
            <input type="email" name="email" id="email" wire:model.blur='email' required>
            @error('email')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <x-installer.field-label for="password" :tooltip="__('installer.fields.admin_password.tooltip')">
                {{ __('installer.fields.admin_password.label') }}
            </x-installer.field-label>
            <x-installer.password-input id="password" name="password" wire:model="password" required />
            @error('password')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <div>
            <x-installer.field-label for="password_confirmation" :tooltip="__('installer.fields.admin_password_confirmation.tooltip')">
                {{ __('installer.fields.admin_password_confirmation.label') }}
            </x-installer.field-label>
            <x-installer.password-input id="password_confirmation" name="password_confirmation" wire:model.blur="password_confirmation" required />
        </div>
    </div>
</div>

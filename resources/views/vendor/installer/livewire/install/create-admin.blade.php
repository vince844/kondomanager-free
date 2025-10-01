 <div>
     <div class="space-y-6">
         <div>
             <label for="name">{{ __('Full Name') }}</label>
             <input type="text" name="name" id="name" wire:model.blur="name" required>
             @error('name')
                 <span class="error">{{ $message }}</span>
             @enderror
         </div>

         <div>
             <label for="email">{{ __('Email Address') }}</label>
             <input type="email" name="email" id="email" wire:model.blur='email' required>
             @error('email')
                 <span class="error">{{ $message }}</span>
             @enderror
         </div>

         <div>
             <label for="password">{{ __('Password') }}</label>
             <input type="password" name="password" id="password" wire:model='password' required>
             @error('password')
                 <span class="error">{{ $message }}</span>
             @enderror
         </div>

         <div>
             <label for="password_confirmation">{{ __('Confirm Password') }}</label>
             <input type="password" name="password_confirmation" id="password_confirmation" wire:model.blur='password_confirmation' required>
         </div>
     </div>
 </div>

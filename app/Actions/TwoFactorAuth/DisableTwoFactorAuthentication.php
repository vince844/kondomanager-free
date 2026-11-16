<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Actions\TwoFactorAuth;

use App\Models\User;

class DisableTwoFactorAuthentication
{
    /**
     * Disable two factor authentication for the user.
     *
     * @return void
     */
    public function __invoke($user)
    {
        if (! is_null($user->two_factor_secret) ||
            ! is_null($user->two_factor_recovery_codes) ||
            ! is_null($user->two_factor_confirmed_at)) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
        }
    }
}
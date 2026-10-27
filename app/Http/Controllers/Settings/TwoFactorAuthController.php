<?php

namespace App\Http\Controllers\Settings;

use App\Actions\TwoFactorAuth\DisableTwoFactorAuthentication;
use App\Actions\TwoFactorAuth\GenerateNewRecoveryCodes;
use App\Actions\TwoFactorAuth\GenerateQrCodeAndSecretKey;
use App\Actions\TwoFactorAuth\VerifyTwoFactorCode;
use App\Http\Controllers\Controller;
use App\Services\Notifiche\AvvisiDiSicurezza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TwoFactorAuthController extends Controller
{
    /**
     * Show the two factor auth settings page
     * route[GET] => 'settings/two-factor'
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Inertia\Response
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $confirmed = !is_null($user->two_factor_confirmed_at);

        // If 2fa is not confirmed, we want to clear out secret and recovery codes
        // This happens when a user enables 2fa and does not finish confirmation
        if (!$confirmed) {
            app(DisableTwoFactorAuthentication::class)($user);
        }

        return Inertia::render('settings/TwoFactor', [
            'confirmed' => $confirmed,
            'recoveryCodes' => !is_null($user->two_factor_secret) ? json_decode(decrypt($user->two_factor_recovery_codes)) : [],
        ]);
    }

    /**
     * Enable two factor authentication
     * route[POST] => 'settings/two-factor'
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function enable(Request $request)
    {
        // Una 2FA già confermata non si sostituisce con un segreto nuovo: prima si disattiva, e la
        // disattivazione avvisa il titolare (Code 261 e 267). La pagina non chiama mai questa rotta con
        // la 2FA attiva.
        if (! is_null($request->user()->two_factor_confirmed_at)) {
            return response()->json([
                'message' => __('settings.two_factor.already_enabled'),
            ], 409);
        }

        [$qrCode, $secret] = app(GenerateQrCodeAndSecretKey::class)($request->user());
        
        $request->user()->forceFill([
            'two_factor_secret' => encrypt($secret)
        ])->save();
        
        return response()->json([
            'qrCode' => $qrCode,
            'secret' => $secret
        ]);
    }

    /**
     * Verify and confirm the user's two-factor authentication.
     * route[POST] => 'settings/two-factor/confirm'
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirm(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        // Verify the code: la stessa finestra di ±30 secondi della sfida, e un codice usato qui non
        // vale una seconda volta al login (Coda 259).
        $valid = app(VerifyTwoFactorCode::class)($request->user(), $request->code);

        if ($valid) {
            // Generate recovery codes when confirming 2FA
            $recoveryCodes = app(GenerateNewRecoveryCodes::class)($request->user());
            
            // Update user with recovery codes and confirm 2FA
            $request->user()->forceFill([
                'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
                'two_factor_confirmed_at' => now()
            ])->save();

            // Coda 267: la 2FA si dice attivata qui, alla conferma, non quando nasce il segreto.
            app(AvvisiDiSicurezza::class)->avvisa($request->user(), AvvisiDiSicurezza::DUE_FATTORI_ATTIVATA);
            
            return response()->json([
                'status' => 'two-factor-authentication-confirmed',
                'recovery_codes' => $recoveryCodes
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'The provided two-factor authentication code was invalid.'
        ], 422);
    }

    /**
     * Generate new recovery codes for the user.
     * route[POST] => 'settings/two-factor/recovery-codes'
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerateRecoveryCodes(Request $request)
    {
        $codes = app(GenerateNewRecoveryCodes::class)($request->user());
        
        $request->user()->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($codes))
        ])->save();

        // Coda 267: i codici di prima non valgono più, e il titolare deve saperlo.
        app(AvvisiDiSicurezza::class)->avvisa($request->user(), AvvisiDiSicurezza::CODICI_RIGENERATI);
        
        return response()->json([
            'recovery_codes' => $codes
        ]);
    }

    /**
     * Disable two factor authentication for the user.
     * route[DELETE] => 'settings/two-factor'
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    public function disable(Request $request)
    {
        $eraAttiva = ! is_null($request->user()->two_factor_confirmed_at);

        $disableTwoFactorAuthentication = app(DisableTwoFactorAuthentication::class);
        $disableTwoFactorAuthentication($request->user());

        // Coda 267: si avvisa solo se c'era qualcosa da togliere. Annullare una configurazione mai
        // confermata non cambia la sicurezza dell'account.
        if ($eraAttiva) {
            app(AvvisiDiSicurezza::class)->avvisa($request->user(), AvvisiDiSicurezza::DUE_FATTORI_DISATTIVATA);
        }
    }
}
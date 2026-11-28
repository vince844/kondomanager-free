<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Notifiche;

use App\Models\Anagrafica;
use App\Models\User;
use App\Notifications\Assemblee\PromemoriaDellAssembleaNotification;
use App\Notifications\CustomResetPasswordNotification;
use App\Notifications\CustomVerifyEmailNotification;
use App\Notifications\Gestionale\AvvisoCompensazioneNotification;
use App\Notifications\Gestionale\FoglioConguaglioNotification;
use App\Notifications\Gestionale\LetteraNotification;
use App\Notifications\Gestionale\RicevutaIncassoNotification;
use App\Notifications\InviteUserNotification;
use App\Notifications\NewUserEmailNotification;
use App\Notifications\Users\AvvisoDiSicurezzaNotification;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * La regola, una sola, per chi è sospeso (Coda 694): un avviso non parte verso un utente sospeso, né verso l'anagrafica di un utente sospeso. Sta
 * sull'evento `NotificationSending`, quindi vale per ogni invio, anche per quelli in coda (si guarda al momento di spedire), e per le notifiche nuove senza
 * che chi le scrive debba ricordarsene.
 *
 * Passano comunque le notifiche di {@see PASSANO}: la sicurezza e l'accesso dell'account, e ciò che va alla persona e non all'account (lettere, ricevute,
 * fogli, convocazioni), che di norma parte verso un indirizzo (`Notification::route`) e qui non c'entra. La prova `OgniAvvisoSaDelSospesoTest` chiede a
 * ogni classe di `app/Notifications` di essere in un elenco, con il motivo. Registrata in `AvvisiServiceProvider`.
 */
final class FermaGliAvvisiAChiESospeso
{
    /** Le notifiche che partono anche verso un account sospeso: classe => perché. */
    public const PASSANO = [
        AvvisoDiSicurezzaNotification::class => 'avviso di sicurezza dell\'account',
        CustomResetPasswordNotification::class => 'ripristino della password: chi è sospeso non entra comunque',
        CustomVerifyEmailNotification::class => 'verifica dell\'email: chi è sospeso non entra comunque',
        NewUserEmailNotification::class => 'il link del primo accesso, spedito dallo studio con un gesto suo',
        InviteUserNotification::class => 'l\'invito va a un indirizzo che non ha ancora un account',
        LetteraNotification::class => 'una lettera va alla persona',
        RicevutaIncassoNotification::class => 'una ricevuta va alla persona',
        AvvisoCompensazioneNotification::class => 'l\'avviso di una compensazione va alla persona, come la ricevuta',
        FoglioConguaglioNotification::class => 'il foglio del conguaglio va alla persona',
        PromemoriaDellAssembleaNotification::class => 'il promemoria della riunione che prosegue va ai convocati',
    ];

    /** `false` ferma l'invio; `null` lascia decidere agli altri. */
    public function handle(NotificationSending $evento): ?bool
    {
        foreach (array_keys(self::PASSANO) as $classe) {
            if ($evento->notification instanceof $classe) {
                return null;
            }
        }

        $utenteId = match (true) {
            $evento->notifiable instanceof User => $evento->notifiable->getKey(),
            $evento->notifiable instanceof Anagrafica => $evento->notifiable->user_id,
            default => null,
        };
        if ($utenteId === null) {
            return null;
        }

        // Si rilegge dal database: l'oggetto del destinatario può essere stato caricato con poche colonne, o prima della sospensione.
        return User::query()->whereKey($utenteId)->whereNotNull('suspended_at')->exists() ? false : null;
    }
}

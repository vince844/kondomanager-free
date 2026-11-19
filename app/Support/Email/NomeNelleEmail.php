<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Email;

use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Settings\PrintSettings;

/**
 * Il nome che il titolo in alto e la firma delle email dicono (decisione 490).
 *
 * Il nome dello studio scritto nella pagina delle stampe, se c'è; altrimenti il nome dell'applicazione delle impostazioni generali, se
 * l'amministratore l'ha cambiato in qualcosa di diverso da quello di serie; altrimenti «KondoManager». Il nome di serie si scrive «KondoManager», anche
 * dove l'installazione ha ancora il vecchio «Kondomanager». È testo scritto dall'amministratore: arriva come testo (`testoComeTesto`), senza le parentesi
 * quadre che nelle email formano un collegamento.
 */
final class NomeNelleEmail
{
    use TestoScrittoDaAltri;

    public const DI_SERIE = 'KondoManager';

    public static function nome(): string
    {
        try {
            $studio = trim((string) app(PrintSettings::class)->studio_nome);
        } catch (\Throwable) {
            $studio = '';
        }
        if ($studio !== '') {
            return self::testoComeTesto($studio) ?: self::DI_SERIE;
        }

        $applicazione = trim((string) config('app.name'));

        return $applicazione === '' || strcasecmp($applicazione, self::DI_SERIE) === 0 ? self::DI_SERIE : self::testoComeTesto($applicazione);
    }
}

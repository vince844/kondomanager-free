<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Models\Evento;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\Inbox\TipiDeiCompiti;
use App\Services\Gestionale\RispostePromemoria;

/**
 * I compiti che il programma chiude da solo per le assemblee (R10, R12 del progetto della tappa 2), con la frase nella risposta del compito («Chiuso: il
 * punto è nell'ordine del giorno del 15/12/2026», «Chiuso dalla delibera del 15/12/2026, punto 3»). Non sono righe del registro delle modifiche (decisione
 * 547 (2)). «Fatto» e «Non serve» restano a chi apre il compito (decisione 395 (1)(a)).
 */
final class ChiusuraDeiCompiti
{
    /** I compiti dello sforo di una fattura: l'unica chiave è `meta.context.fattura_id` (`SyncScadenziarioWithFattura`). */
    public const TIPI_DELLO_SFORO = ['convocazione_urgenza', 'ratifica_sforo'];

    /** Chiude un compito con la frase; uno già chiuso resta com'è. Rende se l'ha chiuso. */
    public static function chiudi(?Evento $compito, string $frase): bool
    {
        if ($compito === null || $compito->is_completed) {
            return false;
        }

        return app(RispostePromemoria::class)->chiudiDalProgramma($compito, $frase) !== null;
    }

    /**
     * Chiude i compiti aperti dello sforo della fattura («Assemblee da convocare» e «Ratifiche in assemblea»), con la frase; rende i nomi dei compiti chiusi,
     * per la riga della ratifica.
     *
     * @return list<string>
     */
    public static function delloSforo(FatturaPassiva $fattura, string $frase): array
    {
        $chiusi = [];
        foreach (self::TIPI_DELLO_SFORO as $tipo) {
            $compiti = Evento::query()->where('meta->type', $tipo)->where('meta->context->fattura_id', (int) $fattura->id)
                ->where('is_completed', false)->orderBy('id')->get();
            foreach ($compiti as $compito) {
                if (self::chiudi($compito, $frase)) {
                    $chiusi[$tipo] = TipiDeiCompiti::nome($tipo);
                }
            }
        }

        return array_values($chiusi);
    }
}

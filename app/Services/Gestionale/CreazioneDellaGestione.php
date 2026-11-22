<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\DB;

/**
 * Una gestione nuova, con la sua riga nell'esercizio: il corpo che stava nel controllore delle gestioni (`GestioneController::store`), in un servizio che
 * chiamano la pagina «Nuova gestione» e «Crea il piano straordinario» del seguito delle delibere. Le regole sono quelle di prima:
 *
 * - la gestione nasce attiva, nel condominio dell'indirizzo;
 * - si lega all'esercizio scelto (non per forza aperto), con il periodo della gestione dentro l'esercizio (Coda 439), con la riga nel registro delle modifiche;
 * - la creazione e il legame in una transazione del metodo; dentro quella di chi chiama, un punto di salvataggio.
 *
 * Il piano dei conti della gestione nasce come lo fa la pagina «Nuovo piano dei conti» (`PianoContiController::store`): una riga con la gestione e il condominio.
 */
final class CreazioneDellaGestione
{
    /**
     * @param array{nome: string, descrizione: string, data_inizio: mixed, data_fine: mixed, tipo: string, note?: ?string} $dati
     */
    public static function crea(Condominio $condominio, Esercizio $esercizio, array $dati): Gestione
    {
        // Una transazione del metodo (decisione 554): la «Creata» aspetta il legame con l'esercizio; dentro quella di chi chiama è un punto di salvataggio.
        return DB::transaction(function () use ($condominio, $esercizio, $dati) {
            $gestione = Gestione::create(['condominio_id' => $condominio->id, 'attiva' => true] + $dati);

            // Associa la gestione all'esercizio selezionato (non per forza aperto), con il periodo della gestione dentro l'esercizio
            RegistroModifiche::legame($gestione, 'esercizi', 'Esercizi', fn () => $esercizio->gestioni()->attach($gestione->id,
                ['attiva' => true] + self::periodoNellEsercizio($gestione, $esercizio)));

            return $gestione;
        });
    }

    /** Il piano dei conti di una gestione nuova, come «Nuovo piano dei conti»: il nome senza l'anno, perché il piano è della gestione e non di un esercizio. */
    public static function pianoDeiConti(Gestione $gestione, string $descrizione): PianoConto
    {
        return PianoConto::create(['gestione_id' => $gestione->id, 'condominio_id' => $gestione->condominio_id, 'nome' => 'Piano dei conti',
            'descrizione' => $descrizione]);
    }

    /**
     * Coda 439 (decisione 183.4): il periodo della gestione dentro l'esercizio, per la riga di `esercizio_gestione`: l'intersezione fra le date della
     * gestione e quelle dell'esercizio, il più tardo degli inizi e il più presto delle fini. Se non hanno un giorno in comune (la gestione del 2026
     * agganciata al 2027 da «Modifica gestione», un'opera del 2028 creata dal 2027) il periodo è vuoto, le due date nulle come le lascia
     * l'importatore: prima usciva rovesciato, «dal 01/01/2027 al 31/12/2026».
     *
     * @return array{data_inizio: ?\Carbon\Carbon, data_fine: ?\Carbon\Carbon}
     */
    public static function periodoNellEsercizio(Gestione $gestione, Esercizio $esercizio): array
    {
        $inizio = $gestione->data_inizio->greaterThan($esercizio->data_inizio) ? $gestione->data_inizio : $esercizio->data_inizio;
        $fine = $gestione->data_fine->lessThan($esercizio->data_fine) ? $gestione->data_fine : $esercizio->data_fine;

        return $inizio->greaterThan($fine) ? ['data_inizio' => null, 'data_fine' => null] : ['data_inizio' => $inizio, 'data_fine' => $fine];
    }
}

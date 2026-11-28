<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Esercizio;
use App\Models\Gestione;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Una spesa registrata in una gestione deve stare nella gestione della sua voce, in un esercizio a cui la gestione è agganciata, e (salvo conferma
 * di chi registra) nel periodo della gestione dentro quell'esercizio. Il giornale scrive la gestione scelta, il consuntivo per voce legge la gestione
 * del piano dei conti della voce: se le due non coincidono i due rendiconti non tornano.
 *
 * La regola sta qui, in un posto solo, perché la chiamano tutte le registrazioni di una spesa (la regolazione immediata, la fattura passiva).
 * Ogni metodo restituisce la frase da mostrare sul campo, o null se va bene.
 */
final class VoceDellaGestione
{
    /** La frase dell'avviso sulla data: comincia sempre così, il resto dice il periodo. */
    public const AVVISO_DATA = 'La data è fuori dalla stagione: confermi?';

    /** La voce è di un piano dei conti di un'altra gestione: la frase che lo dice, o null. */
    public static function rifiutoPerLaVoce(int $gestioneId, int $contoId): ?string
    {
        $riga = DB::table('conti')
            ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')
            ->leftJoin('gestioni', 'gestioni.id', '=', 'piani_conti.gestione_id')
            ->where('conti.id', $contoId)
            ->first(['conti.nome as voce', 'piani_conti.gestione_id', 'gestioni.nome as gestione']);

        if ($riga === null || (int) $riga->gestione_id === $gestioneId) {
            return null;
        }

        $scelta = (string) Gestione::query()->whereKey($gestioneId)->value('nome');

        return "La voce «{$riga->voce}» è della gestione «{$riga->gestione}», non di «{$scelta}»: scegli la gestione «{$riga->gestione}», "
            .'oppure una voce di questa gestione.';
    }

    /** La gestione non è agganciata all'esercizio: la frase che lo dice, o null. */
    public static function rifiutoPerLEsercizio(int $gestioneId, int $esercizioId): ?string
    {
        if (DB::table('esercizio_gestione')->where('gestione_id', $gestioneId)->where('esercizio_id', $esercizioId)->exists()) {
            return null;
        }

        $gestione = (string) Gestione::query()->whereKey($gestioneId)->value('nome');
        $esercizio = (string) Esercizio::query()->whereKey($esercizioId)->value('nome');

        return "La gestione «{$gestione}» non è agganciata all'esercizio «{$esercizio}»: scegli una gestione dell'esercizio, "
            .'oppure agganciala dalla pagina «Gestioni».';
    }

    /**
     * Il periodo della gestione dentro l'esercizio: le date della riga di `esercizio_gestione` (le scrive la creazione della gestione, con
     * `CreazioneDellaGestione::periodoNellEsercizio`). Null se la riga non ha le date (l'importatore, le gestioni di prima, una gestione riusata
     * negli anni con le sue date del primo): allora non si avvisa, perché le date della gestione da sole non dicono il periodo di quest'anno.
     *
     * @return array{dal: Carbon, al: Carbon}|null
     */
    public static function periodo(int $gestioneId, int $esercizioId): ?array
    {
        $pivot = DB::table('esercizio_gestione')->where('gestione_id', $gestioneId)->where('esercizio_id', $esercizioId)
            ->first(['data_inizio', 'data_fine']);

        if ($pivot === null || $pivot->data_inizio === null || $pivot->data_fine === null) {
            return null;
        }

        return ['dal' => Carbon::parse($pivot->data_inizio)->startOfDay(), 'al' => Carbon::parse($pivot->data_fine)->startOfDay()];
    }

    /** La data fuori dal periodo della gestione nell'esercizio: la frase dell'avviso che chi registra conferma, o null. */
    public static function avvisoPerLaData(int $gestioneId, int $esercizioId, string $data): ?string
    {
        $periodo = self::periodo($gestioneId, $esercizioId);
        if ($periodo === null) {
            return null;
        }

        $giorno = Carbon::parse($data)->startOfDay();
        if ($giorno->betweenIncluded($periodo['dal'], $periodo['al'])) {
            return null;
        }
        $nome = (string) Gestione::query()->whereKey($gestioneId)->value('nome');

        return self::AVVISO_DATA." La gestione «{$nome}» in questo esercizio va dal {$periodo['dal']->format('d/m/Y')} al {$periodo['al']->format('d/m/Y')}; "
            ."la data scritta è il {$giorno->format('d/m/Y')}.";
    }
}

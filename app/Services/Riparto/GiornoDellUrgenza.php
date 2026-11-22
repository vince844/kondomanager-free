<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Riparto;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Coda 688, «Urgenza» sulle voci di una gestione straordinaria: **il giorno dei lavori** («Lavori ordinati il 02/03/2027»), scritto nel piano.
 *
 * - **Un giorno solo, non un periodo**: nessuna pronuncia decide chi paga i lavori urgenti dopo una vendita; la più vicina (Cass. II, 12/07/2011 n. 15309)
 *   lega l'obbligo all'accertamento dell'emergenza, e la divisione a giorni non ha nessuna fonte. Chi era titolare quel giorno paga tutto; l'amministratore
 *   cambia il giorno (il patto nel rogito, l'urgenza contestata).
 * - **Dove sta**: nella colonna del piano (`piani_rate.giorno_dei_lavori`), scritta alla creazione sulle due schede. Sulla scheda delle voci si scrive anche
 *   come competenza di ogni voce, con l'inizio uguale alla fine (`competenze_capitolo`): così la fattura registrata dopo sulla voce lo trova proposto
 *   ({@see CompetenzaDichiarataPerVoce}). Un piano d'urgenza scritto prima della colonna ha il giorno quando tutte le sue voci dicono lo stesso giorno. Sulla
 *   scheda delle fatture le voci non hanno la competenza: la fattura registrata dopo sulla voce di una delle sue fatture trova il giorno della colonna
 *   ({@see perVociDaiPianiDelleFatture()}).
 * - **Chi lo legge**: la competenza del piano ({@see CompetenzaDelPiano::perPiano()}), che lo dà al motore come competenza dichiarata di un giorno, al posto
 *   della delibera che non c'è: sulle voci vale per tutto il piano, sulle fatture per la fattura che non dice il suo periodo; il conguaglio dell'opera
 *   ({@see \App\Services\Gestionale\DeliberaDellOpera}), per la fattura pagata senza periodo; la pagina del piano, che lo ricorda in testa.
 *
 * Il giorno decide **chi** paga, non **quanto**: le cifre restano quelle della voce, in centesimi, come le scrive il piano.
 */
final class GiornoDellUrgenza
{
    /**
     * Il giorno dei lavori di un piano d'urgenza, `Y-m-d`: quello della colonna del piano; per un piano scritto prima della colonna, quello di tutte le sue
     * voci. Null per gli altri piani, o se le voci non dicono tutte lo stesso giorno.
     */
    public static function delPiano(PianoRate $piano): ?string
    {
        if ($piano->tipo_autorizzazione !== 'urgenza' || $piano->id === null) {
            return null;
        }
        if ($piano->giorno_dei_lavori !== null) {
            return Carbon::parse($piano->giorno_dei_lavori)->toDateString();
        }

        return self::perPiani([(int) $piano->id])[(int) $piano->id] ?? null;
    }

    /**
     * I giorni dei lavori di più piani in una lettura: piano => `Y-m-d`, solo per i piani d'urgenza. Prima la colonna del piano (le due schede); per i piani
     * scritti prima della colonna, le voci, quando dicono tutte lo stesso giorno.
     *
     * @param list<int> $piani
     * @return array<int, string>
     */
    public static function perPiani(array $piani): array
    {
        if ($piani === []) {
            return [];
        }
        $giorni = DB::table('piani_rate')->whereIn('id', $piani)->where('tipo_autorizzazione', 'urgenza')->whereNotNull('giorno_dei_lavori')
            ->pluck('giorno_dei_lavori', 'id')->mapWithKeys(fn ($g, $id) => [(int) $id => substr((string) $g, 0, 10)])->all();
        $senza = array_values(array_diff(array_map('intval', $piani), array_keys($giorni)));
        if ($senza === []) {
            return $giorni;
        }
        $righe = DB::table('competenze_capitolo as cc')->join('piano_rate_capitoli as prc', 'prc.id', '=', 'cc.piano_rate_capitolo_id')
            ->join('piani_rate as pr', 'pr.id', '=', 'prc.piano_rate_id')->whereIn('pr.id', $senza)->where('pr.tipo_autorizzazione', 'urgenza')
            ->get(['pr.id as piano', 'prc.id as riga', 'cc.dal', 'cc.al']);
        foreach ($righe->groupBy('piano') as $piano => $suoi) {
            $date = $suoi->flatMap(fn ($r) => [substr((string) $r->dal, 0, 10), substr((string) $r->al, 0, 10)])->unique()->values();
            $tutteLeRighe = DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano)->count();
            if ($date->count() === 1 && $suoi->pluck('riga')->unique()->count() === $tutteLeRighe) {
                $giorni[(int) $piano] = (string) $date->first();
            }
        }

        return $giorni;
    }

    /**
     * La fattura registrata dopo sulla voce di un piano d'urgenza fatto **dalle fatture** (la n. 52 sulla voce della n. 45 messa a rate con «Urgenza»): voce
     * => il piano più recente, bozza o approvato, che ha una fattura con una riga su quella voce e il giorno dei lavori, letto da {@see perPiani()} come per
     * ogni altro lettore. I piani fatti dalle voci lo dicono già nella competenza della voce.
     *
     * @param list<int> $conti
     * @return array<int, array{piano_rate_id: int, piano: string, giorno: string}>
     */
    public static function perVociDaiPianiDelleFatture(array $conti): array
    {
        if ($conti === []) {
            return [];
        }
        $righe = DB::table('piani_rate as pr')->join('piano_rate_fatture as prf', 'prf.piano_rate_id', '=', 'pr.id')
            ->join('righe_fattura as rf', 'rf.fattura_passiva_id', '=', 'prf.fattura_passiva_id')
            ->where('pr.tipo_autorizzazione', 'urgenza')->whereIn('pr.stato', ['bozza', 'approvato'])->whereIn('rf.conto_id', $conti)
            ->orderByDesc('pr.id')->get(['rf.conto_id', 'pr.id', 'pr.nome']);
        $giorni = self::perPiani($righe->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $perVoce = [];
        foreach ($righe as $r) {
            if (! isset($perVoce[(int) $r->conto_id]) && isset($giorni[(int) $r->id])) {
                $perVoce[(int) $r->conto_id] = ['piano_rate_id' => (int) $r->id, 'piano' => (string) $r->nome, 'giorno' => $giorni[(int) $r->id]];
            }
        }

        return $perVoce;
    }

    /**
     * Le competenze da scrivere sulla pivot di un piano d'urgenza nuovo: ogni voce del piano con il giorno, dall'inizio alla fine.
     *
     * @return list<array{conto_id: int, tratti: list<array{dal: string, al: string}>}>
     */
    public static function competenzeDelGiorno(PianoRate $piano, string $giorno): array
    {
        return DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->pluck('conto_id')->unique()
            ->map(fn ($conto) => ['conto_id' => (int) $conto, 'tratti' => [['dal' => $giorno, 'al' => $giorno]]])->values()->all();
    }

    /**
     * La testa del piano d'urgenza (la scena 13 della bozza approvata con la decisione 623): il giorno dei lavori, il motivo, e la prima assemblea a cui si
     * riferisce. Null per gli altri piani.
     *
     * @return ?array{giorno: ?string, motivo: ?string, frase: string, assemblea: string}
     */
    public static function perLaPagina(PianoRate $piano, ?User $utente): ?array
    {
        if ($piano->tipo_autorizzazione !== 'urgenza') {
            return null;
        }
        $giorno = self::delPiano($piano);
        $riferita = AccessoAlleAssemblee::vede($utente) ? CollegamentiDelleDelibere::delibera($piano, RuoloCollegamento::RIFERIMENTO->value) : null;
        $condominio = (string) DB::table('condomini')->where('id', $piano->condominio_id)->value('nome');

        return [
            'giorno' => $giorno === null ? null : Carbon::parse($giorno)->format('d/m/Y'),
            'motivo' => $piano->motivazione_autorizzazione,
            'frase' => 'Lavori urgenti (art. 1135 c. 2 c.c.): il piano è nato approvato, senza una delibera'
                . ($giorno === null ? '; il periodo lo dice ogni fattura.'
                    : ($piano->tipo === 'straordinario' ? '. La fattura senza periodo prende il giorno dei lavori; quella con il suo periodo tiene il suo.' : '.')),
            'assemblea' => $riferita !== null
                ? 'Riferito all\'assemblea del ' . $riferita->deliberata_il?->format('d/m/Y') . '.'
                : 'Da riferire alla prima assemblea di ' . $condominio . ': il punto è già proposto quando la prepari.',
        ];
    }
}

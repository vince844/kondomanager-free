<?php

namespace App\Services\Riparto;

use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Illuminate\Support\Facades\DB;

/**
 * I giorni che una riga congelata del riparto copre davvero (1.11.0-beta.34, Fase 1-bis R5 e R6), letti in un posto
 * solo dal conguaglio del passaggio e dal prospetto degli oneri accessori.
 *
 * Due informazioni non stanno intere sulla riga, e vanno ricostruite:
 *
 * - **la competenza a tratti del capitolo** (decisione 20): la riga porta solo gli estremi dell'insieme
 *   (`competenza_dal/al`), i tratti veri stanno in `competenze_capitolo` — {@see trattiPerConto()}, per conto della pivot;
 * - **il tratto di una riga di ripiego** (decisione 22): `titolarita_dal/al` è un intervallo solo, e quando i giorni
 *   senza titolare sono due buchi (gennaio–febbraio e novembre–dicembre attorno a un inquilino) il motore congela
 *   l'estensione, tutto l'anno, mentre `giorni_titolarita` dice i giorni veri. {@see senzaGliAltri()} toglie i tratti
 *   delle righe della stessa voce e dello stesso ruolo richiesto risolte su quel ruolo (l'inquilino), non quelli delle
 *   altre righe di ripiego (1.11.0-beta.47: sull'unità mista le gemelle pagano gli stessi giorni), e controlla che i
 *   giorni tornino; se non tornano la riga non si divide — non si tira a indovinare.
 */
final class PeriodoDellaRiga
{
    /**
     * I tratti di competenza delle voci del piano (decisione 20), per `conto_id` della pivot.
     *
     * @return array<int, InsiemePeriodi>
     */
    public static function trattiPerConto(int $pianoRateId): array
    {
        $righe = DB::table('competenze_capitolo as cc')
            ->join('piano_rate_capitoli as prc', 'prc.id', '=', 'cc.piano_rate_capitolo_id')
            ->where('prc.piano_rate_id', $pianoRateId)
            ->orderBy('cc.dal')
            ->get(['prc.conto_id', 'cc.dal', 'cc.al']);

        $perConto = [];
        foreach ($righe->groupBy('conto_id') as $contoId => $tratti) {
            $perConto[(int) $contoId] = new InsiemePeriodi(...$tratti->map(fn ($t) => new PeriodoCompetenza(substr((string) $t->dal, 0, 10), substr((string) $t->al, 0, 10)))->all());
        }

        return $perConto;
    }

    /**
     * Il periodo della riga senza i giorni coperti da righe della stessa voce, tabella e ruolo richiesto risolte proprio
     * sul ruolo richiesto: per una riga di ripiego, i giorni dell'inquilino (dalla 1.11.0-beta.47 non più quelli di
     * un'altra riga di ripiego, DL5 e DL6). Si applica solo quando `giorni_titolarita` c'è e non torna con `$periodo`:
     * altrimenti il periodo è già giusto e si restituisce com'è. `null` se anche dopo la sottrazione i giorni non tornano.
     *
     * @param array<string,mixed>|object $riga
     * @param iterable<array<string,mixed>|object> $righeStessaUnita le righe `riparto` dello stesso piano e della stessa unità
     */
    public static function senzaGliAltri(InsiemePeriodi $periodo, array|object $riga, iterable $righeStessaUnita): ?InsiemePeriodi
    {
        $v = fn ($r, string $k) => is_array($r) ? ($r[$k] ?? null) : ($r->{$k} ?? null);
        $giorni = $v($riga, 'giorni_titolarita');
        if ($giorni === null || (int) $giorni === $periodo->giorni()) {
            return $periodo;
        }

        $risultato = $periodo;
        foreach ($righeStessaUnita as $altra) {
            if ($altra === $riga
                || (int) $v($altra, 'conto_id') !== (int) $v($riga, 'conto_id')
                || (int) $v($altra, 'tabella_id') !== (int) $v($riga, 'tabella_id')
                || $v($altra, 'ruolo_richiesto') !== $v($riga, 'ruolo_richiesto')
                || $v($altra, 'ruolo_risolto') === $v($riga, 'ruolo_risolto')
                // DL5, DL6 (1.11.0-beta.47): si tolgono solo i giorni di chi il ruolo richiesto ce l'ha davvero (l'inquilino), non
                // quelli di un'altra riga di ripiego. Sull'unità mista il ripiego va al ruolo e al suo gemello (usufruttuario e
                // proprietario pieno, decisione 31.1), che pagano gli stessi giorni anche quando i loro tratti non coincidono (un
                // usufrutto che finisce a metà anno): toglierli l'uno dall'altro lasciava la riga «non risolta», e nessun
                // conguaglio. Sull'unità piena le righe di ripiego di ruoli diversi si susseguono senza sovrapporsi, e toglierle
                // non cambiava niente.
                || $v($altra, 'ruolo_risolto') !== $v($altra, 'ruolo_richiesto')
                || empty($v($altra, 'titolarita_dal')) || empty($v($altra, 'titolarita_al'))) {
                continue;
            }
            $risultato = $risultato->meno(new PeriodoCompetenza(substr((string) $v($altra, 'titolarita_dal'), 0, 10), substr((string) $v($altra, 'titolarita_al'), 0, 10)));
            if ($risultato === null) {
                return null;
            }
        }

        return $risultato->giorni() === (int) $giorni ? $risultato : null;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Subentro;

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Immobile;
use App\Services\Riparto\RisolutoreTitolari;
use App\Services\Riparto\RuoloDellaVoce;
use App\Support\PeriodoCompetenza;
use Illuminate\Support\Facades\DB;

/**
 * Le unità in usufrutto che in un piano dell'ordinaria pagano delle voci con il nudo proprietario: le voci sono sul «Proprietario» (una voce
 * nuova nasce così) e, dove l'unità non ha un proprietario pieno, il motore le dà al nudo proprietario. Per la legge l'ordinaria è
 * dell'usufruttuario (art. 1004 c.c.), ma lo sceglie l'amministratore: la pagina del piano lo dice con una riga, senza fermare niente.
 *
 * Si legge dal dettaglio del riparto del piano (le righe con il ruolo richiesto «Proprietario» e risolto «Nudo proprietario», e l'addebito diretto
 * all'unità risolto «Nudo proprietario» su una voce sul solo «Proprietario», che segue la voce: `RuoloDellaVoce`), quindi dice ciò che il piano ha scritto; l'usufrutto è quello in corso alla fine del periodo del piano. Sulle gestioni straordinarie non dice niente: lì la spesa è
 * del nudo proprietario (art. 1005 c.c.).
 *
 * Il rimedio è quello vero: sul piano che non si ricalcola più (`PianoRate::eImmutabile()`) la voce spostata vale per i piani che verranno. La voce
 * che un passaggio ha tenuto sul «Proprietario» togliendo la spunta (`voci_tenute` nel registro del passaggio) non si chiede di spostarla: la frase
 * dice che è la scelta del passaggio.
 */
class UsufruttiConLOrdinariaAlNudo
{
    public function __construct(private readonly RisolutoreTitolari $titolari) {}

    /**
     * Una riga per unità: `['immobile_id' => …, 'frase' => …]`. Vuoto se il piano non è dell'ordinaria o nessuna unità è in questo caso.
     *
     * @return list<array{immobile_id: int, frase: string}>
     */
    public function perLaPagina(PianoRate $piano): array
    {
        if (($piano->gestione?->tipo ?? null) !== 'ordinaria') {
            return [];
        }
        $righe = DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('tipo', RigaRiparto::TIPO_RIPARTO)
            ->where('ruolo_richiesto', RuoloAnagraficaImmobile::PROPRIETARIO->value)->where('ruolo_risolto', RuoloAnagraficaImmobile::NUDA_PROPRIETA->value)
            ->orderBy('id')->get(['immobile_id', 'anagrafica_id', 'conto_id', 'conto_nome']);
        // L'addebito diretto su una voce va dove dice la voce: è della riga quando la voce è sul solo «Proprietario». Senza voce, o con una voce
        // che non dice un ruolo solo, l'ha deciso il passaggio o l'ordine di sempre, e la riga non ha una voce da spostare.
        // La voce dell'addebito è quella della riga di riparto, altrimenti quella della sua riga di fattura (`RuoloDellaVoce::idDellaRigaDiRiparto`):
        // la riga scritta dalla pagina della fattura ha la voce comune vuota, e la voce scelta resta sulla riga di fattura.
        $voci = [];
        $addebiti = DB::table('righe_riparto as rr')->leftJoin('righe_fattura as rf', 'rf.id', '=', 'rr.riga_fattura_id')
            ->where('rr.piano_rate_id', $piano->id)->where('rr.tipo', RigaRiparto::TIPO_AD_PERSONAM)
            ->where(fn ($q) => $q->whereNotNull('rr.conto_id')->orWhereNotNull('rf.conto_addebito_id'))
            ->where('rr.ruolo_risolto', RuoloAnagraficaImmobile::NUDA_PROPRIETA->value)->orderBy('rr.id')
            ->get(['rr.immobile_id', 'rr.anagrafica_id', DB::raw('COALESCE(rr.conto_id, rf.conto_addebito_id) as conto_id'), 'rr.conto_nome'])
            ->filter(fn ($r) => ($voci[(int) $r->conto_id] ??= [RuoloDellaVoce::di((int) $r->conto_id)])[0] === RuoloAnagraficaImmobile::PROPRIETARIO)->values();
        $nomiDelleVoci = DB::table('conti')->whereIn('id', $addebiti->pluck('conto_id')->unique()->all())->pluck('nome', 'id');
        $addebiti = $addebiti->map(function ($r) use ($nomiDelleVoci) {
            $r->conto_nome = $r->conto_nome ?? $nomiDelleVoci[(int) $r->conto_id] ?? null;

            return $r;
        });
        $righe = $righe->concat($addebiti)->values();
        if ($righe->isEmpty()) {
            return [];
        }
        $fine = substr((string) ($piano->esercizio?->data_fine ?? $piano->gestione?->data_fine ?? now()->toDateString()), 0, 10);
        $usufruttuari = $this->titolari->vincolaQuery(DB::table('anagrafica_immobile')->whereIn('immobile_id', $righe->pluck('immobile_id')->unique()->all()),
            PeriodoCompetenza::puntuale($fine))
            ->where('tipologia', RuoloAnagraficaImmobile::USUFRUTTUARIO->value)->get(['immobile_id', 'anagrafica_id'])->groupBy('immobile_id');
        $nomi = Anagrafica::whereIn('id', $righe->pluck('anagrafica_id')->merge($usufruttuari->flatten()->pluck('anagrafica_id'))->unique()->all())->pluck('nome', 'id');
        $unita = Immobile::whereIn('id', $usufruttuari->keys()->all())->get()->keyBy('id');

        $immutabile = $piano->eImmutabile();
        $tenute = $this->vociTenuteDaiPassaggi($righe->pluck('immobile_id')->unique()->all(), $fine);

        $esito = [];
        foreach ($righe->groupBy('immobile_id') as $iid => $diUnita) {
            if (! isset($usufruttuari[$iid], $unita[$iid])) {
                continue;
            }
            $usu = $usufruttuari[$iid]->pluck('anagrafica_id')->unique()->map(fn ($id) => (string) ($nomi[$id] ?? ''))->values()->all();
            $voci = fn ($righeVoci) => $righeVoci->unique('conto_id')->map(fn ($r) => '«'.$r->conto_nome.'»')->values()->all();
            $nudi = fn ($righeVoci) => $righeVoci->pluck('anagrafica_id')->unique()->map(fn ($id) => (string) ($nomi[$id] ?? ''))->values()->all();
            $tenuteQui = $tenute[(int) $iid] ?? [];
            [$tenuteDalPassaggio, $daSpostare] = $diUnita->partition(fn ($r) => isset($tenuteQui[(int) $r->conto_id]));
            $etichetta = (string) $unita[$iid]->etichetta;
            $frase = '';
            if ($daSpostare->isNotEmpty()) {
                $frase = self::frase($etichetta, $voci($daSpostare), $nudi($daSpostare), $usu, $immutabile);
            }
            if ($tenuteDalPassaggio->isNotEmpty()) {
                $date = $tenuteDalPassaggio->map(fn ($r) => $tenuteQui[(int) $r->conto_id])->unique()->sort()->values()->all();
                $frase = trim($frase.' '.self::fraseDellaScelta($frase === '' ? $etichetta : null, $voci($tenuteDalPassaggio), $nudi($tenuteDalPassaggio), $date));
            }
            $esito[] = ['immobile_id' => (int) $iid, 'frase' => $frase];
        }

        return $esito;
    }

    /**
     * Le voci che i passaggi delle unità hanno tenuto sul «Proprietario» (`voci_tenute` del registro, le associazioni voce–tabella), per voce:
     * unità → [conto → la data del passaggio]. Solo i passaggi non annullati che decorrono entro la fine del piano.
     *
     * @param  list<int>  $immobileIds
     * @return array<int, array<int, string>>
     */
    private function vociTenuteDaiPassaggi(array $immobileIds, string $fine): array
    {
        $passaggi = DB::table('subentri')->whereIn('immobile_id', $immobileIds)->whereNull('annullato_il')->whereDate('decorrenza', '<=', $fine)
            ->orderBy('decorrenza')->get(['immobile_id', 'decorrenza', 'registro'])
            ->map(fn ($p) => [(int) $p->immobile_id, substr((string) $p->decorrenza, 0, 10), array_map('intval', (array) (json_decode((string) $p->registro, true)['voci_tenute'] ?? []))])
            ->filter(fn ($p) => $p[2] !== []);
        if ($passaggi->isEmpty()) {
            return [];
        }
        $contoDi = DB::table('conto_tabella_millesimale')->whereIn('id', $passaggi->pluck(2)->flatten()->unique()->all())->pluck('conto_id', 'id');
        $out = [];
        foreach ($passaggi as [$iid, $decorrenza, $ids]) {
            foreach ($ids as $id) {
                if (isset($contoDi[$id])) {
                    // Il passaggio più recente decide.
                    $out[$iid][(int) $contoDi[$id]] = $decorrenza;
                }
            }
        }

        return $out;
    }

    /**
     * «Interno 3 è in usufrutto: le voci «Ascensore» e «Pulizie scale» sono sul «Proprietario» e in questo piano vanno a …». Sul piano che non si
     * ricalcola più la voce spostata vale per i piani che verranno.
     */
    private static function frase(string $unita, array $voci, array $nudi, array $usufruttuari, bool $immutabile = false): string
    {
        $una = count($voci) === 1;
        $testa = sprintf('%s è in usufrutto: %s %s %s sul «Proprietario» e in questo piano %s %s, %s.',
            $unita, $una ? 'la voce' : 'le voci', self::elenco($voci), $una ? 'è' : 'sono', $una ? 'va' : 'vanno',
            self::a(self::elenco($nudi)), count($nudi) === 1 ? 'nudo proprietario' : 'nudi proprietari');
        $a = sprintf('%s %s, %s', $una ? 'darla' : 'darle', self::a(self::elenco($usufruttuari)), count($usufruttuari) === 1 ? 'usufruttuario' : 'usufruttuari');
        $metti = $una ? 'mettila' : 'mettile';

        return $immutabile
            ? sprintf('%s Questo piano non si ricalcola più e resta così: per %s, nei piani che verranno, %s su «Usufruttuario» nella tabella della voce.', $testa, $a, $metti)
            : sprintf('%s Per %s, %s su «Usufruttuario» nella tabella della voce e ricalcola.', $testa, $a, $metti);
    }

    /**
     * La voce che il passaggio ha tenuto sul «Proprietario»: è una scelta, non una cosa da correggere. Con `$unita` la frase apre la riga.
     *
     * @param  list<string>  $date  le date dei passaggi, in ordine
     */
    private static function fraseDellaScelta(?string $unita, array $voci, array $nudi, array $date): string
    {
        $una = count($voci) === 1;
        $giorni = array_map(fn ($d) => \Carbon\CarbonImmutable::parse($d)->format('d/m/Y'), $date);
        $corpo = sprintf('%s %s %s sul «Proprietario» e %s %s, %s: è la scelta %s %s, che %s lì.',
            $unita === null ? ($una ? 'La voce' : 'Le voci') : ($una ? 'la voce' : 'le voci'), self::elenco($voci), $una ? 'resta' : 'restano',
            $una ? 'va' : 'vanno', self::a(self::elenco($nudi)), count($nudi) === 1 ? 'nudo proprietario' : 'nudi proprietari',
            count($giorni) === 1 ? 'del passaggio del' : 'dei passaggi del', self::elenco($giorni), $una ? 'l\'ha tenuta' : 'le ha tenute');

        return $unita === null ? $corpo : sprintf('%s è in usufrutto: %s', $unita, $corpo);
    }

    /** «a Laura», «ad Anna»: la preposizione davanti ai nomi. */
    private static function a(string $nomi): string
    {
        return (preg_match('/^[aA]/u', $nomi) ? 'ad ' : 'a ').$nomi;
    }

    /** «A», «A e B», «A, B e C». @param list<string> $voci */
    private static function elenco(array $voci): string
    {
        return count($voci) <= 1 ? ($voci[0] ?? '') : implode(', ', array_slice($voci, 0, -1)).' e '.$voci[count($voci) - 1];
    }
}

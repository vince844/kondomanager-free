<?php

namespace App\Services\Subentro;

use App\Enums\NaturaGestione;
use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\Conto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decisioni 31.5 e 31.6 (1.11.0-beta.41): le voci che, scegliendo al passaggio «l'ordinaria all'usufruttuario» (art. 1004
 * c.c., la proposta di legge), si spostano dal «Proprietario» all'«Usufruttuario», perché i piani futuri diano l'ordinaria a
 * chi gode del bene come la dà il conguaglio. Senza, lo stesso euro andava a due persone diverse a seconda che arrivasse da
 * un piano o da un conguaglio (Coda 171).
 *
 * **Quali.** Le associazioni voce × tabella delle gestioni **ordinarie** con un esercizio aperto che arrivano al giorno
 * dell'atto — una gestione finita prima non riguarda i piani che verranno, e spostarne le voci cambierebbe il riparto di un
 * anno passato sulle altre unità in usufrutto —, su una tabella in cui
 * l'unità del passaggio ha millesimi, che chiedono il «Proprietario» — anche quelle senza nessun coefficiente scritto, che
 * il motore legge come «Proprietario 100 %». Una voce non ha una natura sua: «ordinaria» la dice la gestione. Per questo
 * le voci si elencano tutte spuntate e l'amministratore toglie la spunta a quelle che nella sostanza sono straordinarie e
 * restano al nudo proprietario (31.6).
 *
 * **Chi altro toccano.** Una voce vale per tutta la tabella. Sulle unità senza usufrutto «Usufruttuario» porta al
 * proprietario come «Proprietario» (catena usufruttuario → proprietario): cambiano solo le unità in usufrutto, e il pannello
 * le elenca con i nomi e, quando l'ultimo piano della gestione le ha ripartite, con gli importi che passerebbero dal nudo
 * proprietario all'usufruttuario.
 *
 * **Le voci bloccate.** Una voce compresa in un piano approvato, emesso o chiuso ha la ripartizione bloccata: la pagina della
 * voce la fa cambiare solo annullando il piano (`Conto::getHasRateEmesseAttribute()`, e per i piani da fatture le voci delle
 * loro fatture, come `ContoResource`). Il passaggio rispetta lo stesso blocco (decisione 31.8, 02/10/2026): la voce si
 * elenca con `bloccata`, non si sposta, e il piano già emesso resta com'è — anche nelle stampe ricostruite dei piani senza
 * dettaglio del riparto, che leggono i coefficienti di oggi. Un piano «globale» (senza capitoli: comprende tutte le voci della
 * gestione) approvato, emesso o chiuso le blocca tutte, anche se la pagina della voce non lo vede (decisione 31.9, 03/10/2026).
 *
 * Sola lettura: lo spostamento lo scrive `RegistraSubentroAction`, dentro la transazione del passaggio.
 */
final class VociDaSpostare
{
    /** Le percentuali si scrivono con due decimali. */
    private const TOLLERANZA = 0.01;

    /**
     * @param list<int> $immobileIds l'unità del passaggio e le pertinenze
     * @param CarbonImmutable $decorrenza il giorno dell'atto
     * @return list<array{id: int, conto_id: int, conto: string, tabella: string, gestione: string, percentuale: float, bloccata: bool, altre_unita: list<array{immobile_id: int, immobile: string, usufruttuari: string, nudi: string, importo: ?int, importo_formattato: ?string}>}>
     */
    public function candidate(int $condominioId, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        if ($immobileIds === []) {
            return [];
        }

        $gestioni = DB::table('gestioni')
            ->where('condominio_id', $condominioId)
            ->where(fn ($q) => $q->whereNull('data_fine')->orWhereDate('data_fine', '>=', $decorrenza->toDateString()))
            ->whereIn('id', DB::table('esercizio_gestione')->join('esercizi', 'esercizi.id', '=', 'esercizio_gestione.esercizio_id')
                ->where('esercizi.stato', 'aperto')->select('esercizio_gestione.gestione_id'))
            ->get(['id', 'nome', 'tipo'])
            ->filter(fn ($g) => NaturaGestione::daStringa($g->tipo) === NaturaGestione::Ordinaria)
            ->keyBy('id');
        if ($gestioni->isEmpty()) {
            return [];
        }

        $associazioni = DB::table('conto_tabella_millesimale as ctm')
            ->join('conti', 'conti.id', '=', 'ctm.conto_id')
            ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')
            ->join('tabelle', 'tabelle.id', '=', 'ctm.tabella_id')
            ->whereIn('piani_conti.gestione_id', $gestioni->keys())
            ->whereExists(fn ($q) => $q->from('quote_tabella')->whereColumn('quote_tabella.tabella_id', 'ctm.tabella_id')
                ->whereIn('quote_tabella.immobile_id', $immobileIds)->where('quote_tabella.valore', '>', 0))
            ->orderBy('piani_conti.gestione_id')->orderBy('conti.nome')->orderBy('tabelle.nome')
            ->get(['ctm.id', 'ctm.conto_id', 'ctm.tabella_id', 'conti.nome as conto', 'tabelle.nome as tabella', 'piani_conti.gestione_id']);
        if ($associazioni->isEmpty()) {
            return [];
        }

        $ripartizioni = DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', $associazioni->pluck('id'))
            ->get(['conto_tabella_millesimale_id', 'soggetto', 'percentuale'])->groupBy('conto_tabella_millesimale_id');
        $bloccati = $this->contiBloccati($associazioni->pluck('conto_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $globali = $this->gestioniConPianoGlobale($gestioni->keys()->map(fn ($id) => (int) $id)->all());
        $piani = $this->pianiCheBloccano(array_keys($bloccati), array_keys($globali));

        $voci = [];
        foreach ($associazioni as $a) {
            $righe = $ripartizioni[$a->id] ?? collect();
            // Senza coefficienti il motore legge «Proprietario 100 %» (`CalcoloQuoteService::distribuisciSuTabelle`).
            $percentuale = $righe->isEmpty() ? 100.0 : (float) $righe->where('soggetto', 'proprietario')->sum('percentuale');
            // Coefficienti che non fanno 100 sono già da correggere nella pagina della voce: lo spostamento non li propone.
            if ($percentuale <= 0.0 || ($righe->isNotEmpty() && abs((float) $righe->sum('percentuale') - 100.0) > self::TOLLERANZA)) {
                continue;
            }
            $voci[] = [
                'id'          => (int) $a->id,
                'conto_id'    => (int) $a->conto_id,
                'conto'       => (string) $a->conto,
                'tabella'     => (string) $a->tabella,
                'gestione'    => (string) ($gestioni[$a->gestione_id]->nome ?? ''),
                'percentuale' => $percentuale,
                'bloccata'    => isset($bloccati[(int) $a->conto_id]) || isset($globali[(int) $a->gestione_id]),
                // Decisione 31.9: la ragione, perché il pannello la dica — la pagina della voce non blocca per un piano globale.
                'bloccata_da' => match (true) {
                    isset($bloccati[(int) $a->conto_id]) => 'piano',
                    isset($globali[(int) $a->gestione_id]) => 'piano_globale',
                    default => null,
                },
                // I piani che la bloccano, con quello che serve a dire il rimedio vero (revisione della Fase 1-ter, T-A1): se
                // hanno rate a giornale (il conguaglio applica la scelta) e se vengono da fatture (un piano straordinario).
                // Seconda revisione della Fase 1-ter (M2-5, T2-2): per la 31.9 il piano globale blocca la voce anche quando la bloccano
                // già i capitoli o le fatture di un altro piano, e il pannello deve nominarlo (riportare in bozza solo l'altro non basta).
                'piani_bloccanti' => array_values(array_merge($piani['conti'][(int) $a->conto_id] ?? [], $piani['gestioni'][(int) $a->gestione_id] ?? [])),
                'altre_unita' => $this->altreUnitaInUsufrutto((int) $a->tabella_id, (int) $a->conto_id, (int) $a->gestione_id, $immobileIds),
            ];
        }

        return $voci;
    }

    /**
     * I piani che bloccano ciascuna voce data — con i capitoli, con le fatture di uno straordinario o, per la 31.9, il piano
     * globale della sua gestione — anche per le voci che il pannello non propone (coefficienti che non fanno 100). Serve al
     * cancello della riserva, che per una voce bloccata non deve consigliare uno spostamento (seconda revisione della Fase
     * 1-ter, nota dello scettico di D2-2). Le voci che nessun piano blocca non compaiono.
     *
     * @param list<int> $contoIds
     * @return array<int, list<int>> conto → id dei piani che la bloccano
     */
    public function bloccatiDa(array $contoIds): array
    {
        if ($contoIds === []) {
            return [];
        }
        $gestioni = DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->whereIn('conti.id', $contoIds)->pluck('piani_conti.gestione_id', 'conti.id');
        $globali = $this->gestioniConPianoGlobale($gestioni->unique()->map(fn ($id) => (int) $id)->values()->all());
        $piani = $this->pianiCheBloccano(array_keys($this->contiBloccati($contoIds)), array_keys($globali));
        $esito = [];
        foreach ($contoIds as $id) {
            $ids = array_column(array_merge($piani['conti'][(int) $id] ?? [], $piani['gestioni'][(int) ($gestioni[$id] ?? 0)] ?? []), 'id');
            if ($ids !== []) {
                $esito[(int) $id] = array_values(array_unique($ids));
            }
        }

        return $esito;
    }

    /**
     * Le voci con la ripartizione bloccata, come le blocca la pagina della voce: comprese in un piano approvato, emesso o
     * chiuso (`Conto::getHasRateEmesseAttribute()`), o nelle fatture di un piano straordinario in uno di quegli stati.
     *
     * @param list<int> $contoIds
     * @return array<int, true>
     */
    private function contiBloccati(array $contoIds): array
    {
        // Gli stessi stati di `Conto::getHasRateEmesseAttribute()`.
        $stati = ['approvato', 'emesso', 'chiuso'];
        $bloccati = Conto::whereIn('id', $contoIds)->get()->filter(fn (Conto $c) => $c->has_rate_emesse)->pluck('id')->all();
        $daFatture = DB::table('righe_fattura')
            ->join('piano_rate_fatture', 'piano_rate_fatture.fattura_passiva_id', '=', 'righe_fattura.fattura_passiva_id')
            ->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_fatture.piano_rate_id')
            ->whereIn('righe_fattura.conto_id', $contoIds)
            ->where('piani_rate.tipo', 'straordinario')->whereIn('piani_rate.stato', $stati)
            ->pluck('righe_fattura.conto_id')->all();

        return array_fill_keys(array_map('intval', array_merge($bloccati, $daFatture)), true);
    }

    /**
     * I piani che bloccano le voci date (con i capitoli o con le fatture, per conto) e quelli globali delle gestioni date: il
     * nome, se hanno almeno una quota a giornale, se vengono da fatture.
     *
     * @param list<int> $contoIds
     * @param list<int> $gestioneIds
     * @return array{conti: array<int, list<array{id: int, nome: string, a_giornale: bool, da_fatture: bool}>>, gestioni: array<int, list<array{id: int, nome: string, a_giornale: bool, da_fatture: bool}>>}
     */
    private function pianiCheBloccano(array $contoIds, array $gestioneIds): array
    {
        $stati = ['approvato', 'emesso', 'chiuso'];
        $conCapitoli = DB::table('piano_rate_capitoli')->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_capitoli.piano_rate_id')
            ->whereIn('piano_rate_capitoli.conto_id', $contoIds)->whereIn('piani_rate.stato', $stati)->get(['piano_rate_capitoli.conto_id', 'piani_rate.id', 'piani_rate.nome']);
        // Le voci figlie bloccate dal piano della voce padre (`Conto::getHasRateEmesseAttribute()`, controllo 2).
        $padri = DB::table('conti')->whereIn('id', $contoIds)->whereNotNull('parent_id')->pluck('parent_id', 'id');
        $daiPadri = DB::table('piano_rate_capitoli')->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_capitoli.piano_rate_id')
            ->whereIn('piano_rate_capitoli.conto_id', $padri->values()->all())->whereIn('piani_rate.stato', $stati)->get(['piano_rate_capitoli.conto_id', 'piani_rate.id', 'piani_rate.nome']);
        $daFatture = DB::table('righe_fattura')
            ->join('piano_rate_fatture', 'piano_rate_fatture.fattura_passiva_id', '=', 'righe_fattura.fattura_passiva_id')
            ->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_fatture.piano_rate_id')
            ->whereIn('righe_fattura.conto_id', $contoIds)->where('piani_rate.tipo', 'straordinario')->whereIn('piani_rate.stato', $stati)
            ->get(['righe_fattura.conto_id', 'piani_rate.id', 'piani_rate.nome']);
        $globali = DB::table('piani_rate')->whereIn('gestione_id', $gestioneIds)->where('tipo', 'ordinario')->whereIn('stato', $stati)
            ->whereNotExists(fn ($q) => $q->from('piano_rate_capitoli')->whereColumn('piano_rate_capitoli.piano_rate_id', 'piani_rate.id'))
            ->get(['gestione_id', 'id', 'nome']);
        $ids = collect([$conCapitoli, $daiPadri, $daFatture, $globali])->flatten(1)->pluck('id')->unique()->values()->all();
        // Decisione 34.1 (1.11.0-beta.42): «a giornale» qui vuol dire che il piano non si ricalcola più — una quota a giornale o
        // un movimento. Un piano con un incasso su una bozza non torna in bozza: il rimedio «riportalo in bozza» sarebbe falso.
        $aGiornale = array_fill_keys(\App\Models\Gestionale\PianoRate::immutabiliFra($ids), true);
        $piano = fn ($p, bool $fatture) => ['id' => (int) $p->id, 'nome' => (string) $p->nome, 'a_giornale' => isset($aGiornale[(int) $p->id]), 'da_fatture' => $fatture];
        $conti = [];
        foreach ($conCapitoli as $p) {
            $conti[(int) $p->conto_id][(int) $p->id] = $piano($p, false);
        }
        foreach ($padri as $figlio => $padre) {
            foreach ($daiPadri->where('conto_id', $padre) as $p) {
                $conti[(int) $figlio][(int) $p->id] ??= $piano($p, false);
            }
        }
        foreach ($daFatture as $p) {
            $conti[(int) $p->conto_id][(int) $p->id] ??= $piano($p, true);
        }
        $gestioni = [];
        foreach ($globali as $p) {
            $gestioni[(int) $p->gestione_id][(int) $p->id] = $piano($p, false);
        }

        return ['conti' => array_map('array_values', $conti), 'gestioni' => array_map('array_values', $gestioni)];
    }

    /**
     * Decisione 31.9 (03/10/2026, Fase 1-ter della beta.41): le gestioni con un piano «globale» approvato, emesso o chiuso —
     * un piano ordinario senza capitoli, che il motore ripartisce su tutte le voci della gestione («un piano rate attivo che
     * include tutte le spese», `PianoRateCreatorService`). Comprende ogni voce della gestione, quindi le blocca tutte per la
     * ragione della 31.8: spostarne una cambierebbe il nome nelle stampe ricostruite di un piano senza dettaglio del riparto,
     * che è proprio il piano globale della 1.10.0. La pagina della voce non lo vede (guarda i capitoli): qui il blocco è più
     * largo, e il pannello dice perché.
     *
     * @param list<int> $gestioneIds
     * @return array<int, true>
     */
    private function gestioniConPianoGlobale(array $gestioneIds): array
    {
        $ids = DB::table('piani_rate')
            ->whereIn('gestione_id', $gestioneIds)
            ->where('tipo', 'ordinario')
            ->whereIn('stato', ['approvato', 'emesso', 'chiuso'])
            ->whereNotExists(fn ($q) => $q->from('piano_rate_capitoli')->whereColumn('piano_rate_capitoli.piano_rate_id', 'piani_rate.id'))
            ->pluck('gestione_id')->map(fn ($id) => (int) $id)->all();

        return array_fill_keys($ids, true);
    }

    /**
     * Le altre unità della tabella che oggi hanno un usufruttuario: per loro lo spostamento cambia chi paga. L'importo è
     * quello che l'ultimo piano della gestione ha dato al nudo proprietario su quella voce, se c'è.
     *
     * @param list<int> $escluse
     * @return list<array{immobile_id: int, immobile: string, usufruttuari: string, nudi: string, importo: ?int, importo_formattato: ?string}>
     */
    private function altreUnitaInUsufrutto(int $tabellaId, int $contoId, int $gestioneId, array $escluse): array
    {
        $oggi = DateHelper::oggiUtenteImmutable()->toDateString();
        $titolari = DB::table('anagrafica_immobile')
            ->join('anagrafiche', 'anagrafiche.id', '=', 'anagrafica_immobile.anagrafica_id')
            ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')
            ->whereIn('anagrafica_immobile.immobile_id', DB::table('quote_tabella')->where('tabella_id', $tabellaId)->where('valore', '>', 0)->select('immobile_id'))
            ->whereNotIn('anagrafica_immobile.immobile_id', $escluse)
            ->whereIn('anagrafica_immobile.tipologia', ['usufruttuario', 'nuda_proprietario'])
            ->where('anagrafica_immobile.attivo', true)->where('anagrafica_immobile.quota', '>', 0)
            ->where(fn ($q) => $q->whereNull('anagrafica_immobile.data_fine')->orWhereDate('anagrafica_immobile.data_fine', '>=', $oggi))
            ->orderBy('immobili.nome')
            ->get(['anagrafica_immobile.immobile_id', 'immobili.nome as immobile', 'anagrafica_immobile.tipologia', 'anagrafiche.nome']);

        $ultimoPiano = DB::table('righe_riparto')->join('piani_rate', 'piani_rate.id', '=', 'righe_riparto.piano_rate_id')
            ->where('piani_rate.gestione_id', $gestioneId)->where('righe_riparto.conto_id', $contoId)->max('piani_rate.id');

        return $titolari->groupBy('immobile_id')
            ->filter(fn (Collection $righe) => $righe->contains('tipologia', 'usufruttuario'))
            ->map(function (Collection $righe, $immobileId) use ($ultimoPiano, $contoId, $tabellaId) {
                $importo = $ultimoPiano === null ? null : (int) DB::table('righe_riparto')
                    ->where('piano_rate_id', $ultimoPiano)->where('conto_id', $contoId)->where('tabella_id', $tabellaId)
                    ->where('immobile_id', $immobileId)->where('tipo', 'riparto')
                    ->where('ruolo_richiesto', 'proprietario')->where('ruolo_risolto', 'nuda_proprietario')->sum('importo');

                return [
                    'immobile_id'  => (int) $immobileId,
                    'immobile'     => (string) $righe->first()->immobile,
                    'usufruttuari' => $righe->where('tipologia', 'usufruttuario')->pluck('nome')->unique()->implode(', '),
                    'nudi'         => $righe->where('tipologia', 'nuda_proprietario')->pluck('nome')->unique()->implode(', '),
                    'importo'      => $importo,
                    'importo_formattato' => $importo === null ? null : MoneyHelper::format($importo),
                ];
            })->values()->all();
    }

    /**
     * «Proprietario 70 %, Usufruttuario 30 %»: i coefficienti di una voce come li scrive il registro del passaggio
     * (`voci_spostate[].prima` e `.dopo`), per lo storico e per l'annullamento.
     *
     * @param array<array{soggetto: string, percentuale: float|int}> $righe
     */
    public static function coefficientiAParole(array $righe): string
    {
        return implode(', ', array_map(fn (array $r) => sprintf('%s %s %%',
            RuoloAnagraficaImmobile::tryFrom((string) ($r['soggetto'] ?? ''))?->label() ?? ucfirst((string) ($r['soggetto'] ?? '?')),
            rtrim(rtrim(number_format((float) ($r['percentuale'] ?? 0), 2, ',', '.'), '0'), ',')), array_values($righe)));
    }

    /**
     * Rilievo S1 della Fase 1-bis: l'impronta dell'elenco che il pannello ha mostrato. La registrazione ricalcola le candidate
     * dentro la sua transazione; se nel frattempo l'elenco è cambiato — un piano riportato in bozza che sblocca una voce, una
     * voce nuova, un coefficiente cambiato, un usufrutto nuovo su un'altra unità della tabella — si sposterebbero voci che
     * nessuno ha visto, o con una parte diversa da quella mostrata. Conta ciò che decide lo spostamento e ciò che il pannello
     * ne dice: la voce, se è bloccata, la sua parte sul «Proprietario», le altre unità che tocca.
     *
     * @param list<array{id: int, bloccata: bool, percentuale: float, altre_unita: list<array{immobile_id: int}>}> $candidate
     */
    public static function impronta(array $candidate): string
    {
        $righe = array_map(fn (array $v) => [
            (int) $v['id'], (bool) $v['bloccata'], round((float) $v['percentuale'], 2),
            collect($v['altre_unita'] ?? [])->pluck('immobile_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ], $candidate);
        usort($righe, fn ($a, $b) => $a[0] <=> $b[0]);

        return sha1(json_encode($righe));
    }

    /**
     * Sposta su «Usufruttuario» la parte «Proprietario» delle associazioni date, e restituisce per ognuna i coefficienti di
     * prima e di dopo, che il passaggio scrive nel suo registro (decisione 31.7: l'annullamento li nomina, non li disfa).
     * Se l'associazione ha già una riga «Usufruttuario», la parte si somma a quella.
     *
     * @param list<array{id: int, conto: string, tabella: string, gestione: string}> $voci
     * @return list<array{id: int, conto: string, tabella: string, gestione: string, prima: list<array{soggetto: string, percentuale: float}>, dopo: list<array{soggetto: string, percentuale: float}>}>
     */
    public function sposta(array $voci): array
    {
        $registro = [];
        foreach ($voci as $v) {
            $righe = DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $v['id'])->lockForUpdate()->orderBy('id')->get();
            $prima = $righe->isEmpty()
                ? [['soggetto' => 'proprietario', 'percentuale' => 100.0]]
                : $righe->map(fn ($r) => ['soggetto' => (string) $r->soggetto, 'percentuale' => (float) $r->percentuale])->all();

            $dopo = [];
            foreach ($prima as $r) {
                $soggetto = $r['soggetto'] === 'proprietario' ? 'usufruttuario' : $r['soggetto'];
                $dopo[$soggetto] = ['soggetto' => $soggetto, 'percentuale' => round(($dopo[$soggetto]['percentuale'] ?? 0.0) + $r['percentuale'], 2)];
            }
            $dopo = array_values($dopo);
            // La somma resta quella di prima: se non fa 100, la voce non si tocca (lo spostamento conserverebbe lo sbilancio).
            $sommaPercentuali = array_sum(array_column($dopo, 'percentuale'));
            if (abs($sommaPercentuali - 100.0) > self::TOLLERANZA) {
                continue;
            }

            DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $v['id'])->delete();
            DB::table('conto_tabella_ripartizioni')->insert(array_map(fn ($r) => [
                'conto_tabella_millesimale_id' => $v['id'], 'soggetto' => $r['soggetto'], 'percentuale' => $r['percentuale'],
                'created_at' => now(), 'updated_at' => now(),
            ], $dopo));

            $registro[] = ['id' => (int) $v['id'], 'conto' => $v['conto'], 'tabella' => $v['tabella'], 'gestione' => $v['gestione'], 'prima' => $prima, 'dopo' => $dopo];
        }

        return $registro;
    }
}

<?php

namespace App\Services\Gestionale\Debiti;

use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Services\Gestionale\Lettere\RegistroLettere;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * L'elenco «Rate scadute» di un condominio, costruito sulla lettura comune dei debiti ({@see LetturaDebiti}): una riga per persona con lo
 * scaduto (lordo: il credito si mostra accanto e non si sottrae), le fasce, il ritardo più vecchio in parole, la data più vicina dei sei mesi
 * ({@see SeiMesi}) con la dispensa se c'è, e le sue rate. Ci sono le persone con uno scaduto maggiore di zero alla data dell'elenco. La vista
 * «In scadenza nei prossimi N giorni» è la stessa lettura, per le rate emesse che scadono fra la data dell'elenco e N giorni dopo.
 *
 * La data dell'elenco è di serie il giorno dell'amministratore; si può scegliere un giorno passato (per l'assemblea), e allora il credito non
 * c'è: la lettura non lo sa per una data passata.
 *
 * Le rate con la scadenza passata non ancora emesse non sono nella lettura, e sono dovute anche in bozza: l'elenco non le somma allo scaduto,
 * ma le conta in una riga a parte ({@see nonEmesse()}), con i saldi non ancora in un piano approvato.
 *
 * I filtri lavorano sulla lettura già fatta: persona, fascia («ha qualcosa in questa fascia»), importo minimo dello scaduto lordo, gestione (il
 * parametro della lettura), «la data dei sei mesi entro N giorni», «con pregresso»; e sul registro delle lettere: «mai scritto» e «nessuna
 * lettera negli ultimi N giorni», che contano solo le lettere partite o spedite (una in coda, non partita, o solo stampata o scaricata senza
 * la spedizione registrata no; per una spedita il giorno della spedizione), e «stampate, spedizione da registrare», per registrarla in blocco.
 * Ogni riga porta l'ultima lettera partita, stampata o spedita e, se dopo ce n'è una in coda o non partita, anche quella. L'ordine di serie è per scaduto decrescente, come la
 * Tesoreria; a scelta per la data dei sei mesi più vicina.
 *
 * **L'accordo di rientro** ({@see AccordiRientro}): le rate coperte restano nell'elenco (per la legge sono scadute) e portano la parte in
 * accordo; la riga porta il segno di ogni accordo in corso, con le due cifre (riconosciuti, e quanto resta oggi). Il filtro «con accordo»
 * mostra le persone con un accordo in corso alla data dell'elenco.
 */
final class ElencoRateScadute
{
    public const VISTA_SCADUTE = 'scadute';
    public const VISTA_IN_SCADENZA = 'in_scadenza';
    public const VISTE = [self::VISTA_SCADUTE, self::VISTA_IN_SCADENZA];
    public const GIORNI_DI_SERIE = 30;
    public const ORDINI = ['scaduto', 'sei_mesi'];

    /** Le chiavi dei filtri nell'indirizzo. */
    public const CHIAVI = ['alla', 'vista', 'giorni', 'cerca', 'anagrafica_id', 'fascia', 'minimo', 'gestione_id', 'sei_mesi_entro', 'con_pregresso', 'ordine',
        'senza_lettere_da', 'mai_scritto', 'da_spedire', 'con_accordo'];

    public function __construct(private readonly LetturaDebiti $lettura, private readonly SeiMesi $seiMesi, private readonly RegistroLettere $lettere,
        private readonly AccordiRientro $accordi) {}

    /**
     * I filtri come li usa l'elenco: ogni valore fuori posto torna al suo valore di serie, mai un errore su una GET. L'importo minimo arriva in
     * euro, come lo scrive chi lavora, e qui diventa centesimi, una volta sola.
     *
     * @param  array<string, mixed>  $grezzi
     * @return array{alla:string, vista:string, giorni:int, cerca:?string, anagrafica_id:?int, fascia:?string, minimo:?int, gestione_id:?int, sei_mesi_entro:?int, con_pregresso:bool, ordine:string, senza_lettere_da:?int, mai_scritto:bool, da_spedire:bool, con_accordo:bool}
     */
    public function filtriNormalizzati(array $grezzi): array
    {
        $oggi = DateHelper::oggiUtente();
        $alla = is_string($grezzi['alla'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $grezzi['alla']) && checkdate((int) substr($grezzi['alla'], 5, 2), (int) substr($grezzi['alla'], 8, 2), (int) substr($grezzi['alla'], 0, 4))
            ? min($grezzi['alla'], $oggi) : $oggi;
        $intero = fn ($v, int $min, int $max) => is_numeric($v) && (int) $v >= $min && (int) $v <= $max ? (int) $v : null;
        $minimo = $grezzi['minimo'] ?? null;
        if (is_scalar($minimo) && trim((string) $minimo) !== '' && preg_match('/^[\d.,\s€]+$/u', (string) $minimo)) {
            $minimo = trim(str_replace(['€', ' '], '', (string) $minimo));
            // «1.000» scritto all'italiana è mille euro: senza la virgola, un punto seguito da gruppi di tre cifre è delle migliaia (per
            // `toCents` sarebbe il decimale, cioè un euro). Con una o due cifre dopo il punto resta il decimale.
            if (! str_contains($minimo, ',') && preg_match('/^\d{1,3}(\.\d{3})+$/', $minimo)) {
                $minimo = str_replace('.', '', $minimo);
            }
            $minimo = MoneyHelper::toCents($minimo);
        } else {
            $minimo = null;
        }

        return [
            'alla' => $alla,
            'vista' => in_array($grezzi['vista'] ?? null, self::VISTE, true) ? $grezzi['vista'] : self::VISTA_SCADUTE,
            'giorni' => $intero($grezzi['giorni'] ?? null, 1, 366) ?? self::GIORNI_DI_SERIE,
            'cerca' => is_string($grezzi['cerca'] ?? null) && trim($grezzi['cerca']) !== '' ? mb_substr(trim($grezzi['cerca']), 0, 100) : null,
            'anagrafica_id' => $intero($grezzi['anagrafica_id'] ?? null, 1, PHP_INT_MAX),
            'fascia' => array_key_exists((string) ($grezzi['fascia'] ?? ''), Scadenza::FASCE) ? (string) $grezzi['fascia'] : null,
            'minimo' => $minimo !== null && $minimo > 0 ? $minimo : null,
            'gestione_id' => $intero($grezzi['gestione_id'] ?? null, 1, PHP_INT_MAX),
            'sei_mesi_entro' => $intero($grezzi['sei_mesi_entro'] ?? null, 0, 3660),
            'con_pregresso' => filter_var($grezzi['con_pregresso'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'ordine' => in_array($grezzi['ordine'] ?? null, self::ORDINI, true) ? $grezzi['ordine'] : 'scaduto',
            'senza_lettere_da' => $intero($grezzi['senza_lettere_da'] ?? null, 1, 3660),
            'mai_scritto' => filter_var($grezzi['mai_scritto'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'da_spedire' => filter_var($grezzi['da_spedire'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'con_accordo' => filter_var($grezzi['con_accordo'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Le righe dell'elenco, filtrate e ordinate: una per persona.
     *
     * @param  array<string, mixed>  $filtri  già normalizzati
     * @param  list<int>|null  $solo  le sole persone (la selezione spuntata)
     * @param  bool  $conVersatiOltre  il segno dell'accordo con la parte versata oltre il capitale riconosciuto (la pagina sì, le lettere no)
     * @return Collection<int, array<string, mixed>>
     */
    public function righe(Condominio $condominio, array $filtri, ?array $solo = null, bool $conVersatiOltre = true): Collection
    {
        $alla = $filtri['alla'];
        $oggi = $alla === DateHelper::oggiUtente();
        $letture = $this->lettura->perCondominio((int) $condominio->id, $alla, $filtri['gestione_id']);
        $inScadenza = $filtri['vista'] === self::VISTA_IN_SCADENZA;
        $fino = CarbonImmutable::parse($alla, 'UTC')->addDays($filtri['giorni'])->format('Y-m-d');

        $scelte = [];
        foreach ($letture as $persona => $l) {
            if ($solo !== null && ! in_array((int) $persona, $solo, true)) {
                continue;
            }
            $rate = array_values(array_filter($l['rate'], fn ($r) => $r['residuo'] > 0
                && ($inScadenza ? ! $r['scaduta'] && $r['scadenza'] <= $fino : $r['scaduta'])));
            if ($rate !== []) {
                $scelte[(int) $persona] = ['lettura' => $l, 'rate' => $rate];
            }
        }
        if ($filtri['anagrafica_id'] !== null) {
            $scelte = array_intersect_key($scelte, [$filtri['anagrafica_id'] => true]);
        }

        $nomi = Anagrafica::whereIn('id', array_keys($scelte) ?: [0])->pluck('nome', 'id');
        $unita = Immobile::whereIn('id', collect($scelte)->flatMap(fn ($x) => array_column($x['rate'], 'immobile_id'))->filter()->unique()->values()->all() ?: [0])
            ->get()->keyBy('id');
        $piani = DB::table('piani_rate')->whereIn('id', collect($scelte)->flatMap(fn ($x) => array_column($x['rate'], 'piano_rate_id'))->unique()->values()->all() ?: [0])
            ->pluck('nome', 'id');
        $calcolo = $this->seiMesi->perCondominio((int) $condominio->id, $alla);
        // L'ultima lettera di ognuno, alla data dell'elenco (partita, stampata o spedita), e quella che conta per i filtri (partita o spedita).
        $lettere = $this->lettere->ultimePerPersone((int) $condominio->id, array_keys($scelte), $alla);
        // Gli accordi di rientro in corso alla data dell'elenco.
        $accordi = $this->accordi->inCorso((int) $condominio->id, $alla, array_keys($scelte));

        $righe = collect();
        foreach ($scelte as $persona => $x) {
            $nome = (string) ($nomi[$persona] ?? '');
            if ($filtri['cerca'] !== null && ! str_contains(mb_strtolower($nome), mb_strtolower($filtri['cerca']))) {
                continue;
            }
            // Il segno conta tutte le rate dell'accordo: con il filtro di una gestione le rate coperte di un'altra non sono nella lettura filtrata,
            // e si rilegge la persona senza il filtro. Il residuo di una rata non dipende dal filtro: la parte in accordo delle righe è la stessa.
            $rateDelSegno = isset($accordi[$persona]) && $filtri['gestione_id'] !== null
                ? $this->lettura->perPersona((int) $condominio->id, (int) $persona, $alla)['rate'] : $x['lettura']['rate'];
            $segni = isset($accordi[$persona]) ? $this->accordi->segni($accordi[$persona], $rateDelSegno, $alla, $conVersatiOltre) : ['accordi' => [], 'parti' => []];
            $dettaglio = array_map(function ($r) use ($unita, $piani, $calcolo, $persona, $inScadenza, $segni) {
                return [
                    'rata_id' => $r['rata_id'],
                    'immobile_id' => $r['immobile_id'],
                    'numero_rata' => $r['numero_rata'],
                    'piano' => (string) ($piani[$r['piano_rate_id']] ?? ''),
                    'unita' => $r['immobile_id'] !== null && isset($unita[$r['immobile_id']]) ? $unita[$r['immobile_id']]->etichetta : null,
                    'scadenza' => $r['scadenza'],
                    'residuo' => $r['residuo'],
                    'giorni_ritardo' => $r['giorni_ritardo'],
                    'pregresso' => $r['pregresso'] === null ? null : $r['pregresso'] + ['etichetta' => OriginePregresso::etichetta($r['pregresso'])],
                    'pregresso_nella_rata' => $r['pregresso_nella_rata'],
                    'sei_mesi' => $inScadenza ? null : $calcolo->perRiga($r, $persona),
                    // La parte della rata in un accordo di rientro in corso: il segno, e quanto le lettere lasciano fuori.
                    'accordo' => $segni['parti'][AccordiRientro::chiave($r['rata_id'], $r['immobile_id'])] ?? null,
                ];
            }, $x['rate']);
            $importo = (int) array_sum(array_column($x['rate'], 'residuo'));
            $t = $x['lettura']['totali'];
            $riga = [
                'anagrafica_id' => $persona,
                'nome' => $nome,
                'unita' => array_values(array_unique(array_filter(array_column($dettaglio, 'unita')))),
                'importo' => $importo,
                'credito' => $oggi ? (int) ($x['lettura']['credito_disponibile']['totale'] ?? 0) : null,
                'rate' => $dettaglio,
                'ultima_lettera' => $lettere[$persona]['ultima'] ?? null,
                'lettera_in_sospeso' => $lettere[$persona]['in_sospeso'] ?? null,
                'accordi' => $segni['accordi'],
            ];
            if ($inScadenza) {
                $riga['prima_scadenza'] = min(array_column($dettaglio, 'scadenza'));
            } else {
                $riga += [
                    'fasce' => $t['fasce'],
                    'ritardo_massimo' => $t['ritardo_massimo'],
                    'anzianita_non_nota' => $t['anzianita_non_nota'],
                    'ritardo_testo' => OriginePregresso::fraseRitardo($t['ritardo_massimo'], $t['anzianita_non_nota']),
                    'sei_mesi' => self::piuVicinaDellaPersona($dettaglio),
                    // «Con pregresso»: la rata 0 (o la gemella di un passaggio), e anche il pregresso dentro la rata 1 dei piani che lo spalmano.
                    'con_pregresso' => collect($dettaglio)->contains(fn ($d) => $d['pregresso'] !== null || $d['pregresso_nella_rata']),
                    'origine_non_dichiarata' => collect($dettaglio)->contains(fn ($d) => ($d['pregresso']['origine'] ?? null) === OriginePregresso::NON_DICHIARATA),
                    'pregresso_nella_rata' => collect($dettaglio)->contains(fn ($d) => $d['pregresso_nella_rata']),
                ];
            }
            if ($filtri['minimo'] !== null && $importo < $filtri['minimo']) {
                continue;
            }
            // Per «mai scritto» e «negli ultimi N giorni» conta una lettera partita o spedita: una solo stampata o scaricata no.
            $conta = $lettere[$persona]['conta'] ?? null;
            if ($filtri['mai_scritto'] && $conta !== null) {
                continue;
            }
            // «Negli ultimi N giorni» sono il giorno dell'elenco e gli N − 1 giorni prima; per una lettera spedita conta il giorno della spedizione.
            if ($filtri['senza_lettere_da'] !== null && $conta !== null
                && RegistroLettere::giorniDa($conta['data_dei_filtri'] ?? $conta['data'], $alla) < $filtri['senza_lettere_da']) {
                continue;
            }
            // «Stampate, spedizione da registrare»: l'ultima lettera è stampata o scaricata, e la spedizione non è registrata.
            if ($filtri['da_spedire'] && ($riga['ultima_lettera']['invii'] ?? []) === []) {
                continue;
            }
            if ($filtri['con_accordo'] && $riga['accordi'] === []) {
                continue;
            }
            if (! $inScadenza) {
                if ($filtri['fascia'] !== null && ($riga['fasce'][$filtri['fascia']] ?? 0) <= 0) {
                    continue;
                }
                if ($filtri['con_pregresso'] && ! $riga['con_pregresso']) {
                    continue;
                }
                if ($filtri['sei_mesi_entro'] !== null) {
                    $limite = CarbonImmutable::parse($alla, 'UTC')->addDays($filtri['sei_mesi_entro'])->format('Y-m-d');
                    if ($riga['sei_mesi']['data'] === null || $riga['sei_mesi']['data'] > $limite) {
                        continue;
                    }
                }
            }
            $righe->push($riga);
        }

        return $righe->sort(function ($a, $b) use ($filtri, $inScadenza) {
            if ($inScadenza) {
                return [$a['prima_scadenza'], -$a['importo'], $a['nome']] <=> [$b['prima_scadenza'], -$b['importo'], $b['nome']];
            }
            if ($filtri['ordine'] === 'sei_mesi') {
                return [$a['sei_mesi']['data'] === null ? 1 : 0, $a['sei_mesi']['data'] ?? '', -$a['importo'], $a['nome']]
                    <=> [$b['sei_mesi']['data'] === null ? 1 : 0, $b['sei_mesi']['data'] ?? '', -$b['importo'], $b['nome']];
            }

            return [-$a['importo'], $a['nome'], $a['anagrafica_id']] <=> [-$b['importo'], $b['nome'], $b['anagrafica_id']];
        })->values();
    }

    /**
     * La data più vicina dei sei mesi di una persona: fra le date delle sue rate non coperte da una dispensa; se tutte sono coperte, la più
     * vicina fra quelle coperte, con la dispensa. E quante rate non hanno una data.
     *
     * @param  list<array<string,mixed>>  $dettaglio
     * @return array{data:?string, dispensa:?array, mancano:int}
     */
    public static function piuVicinaDellaPersona(array $dettaglio): array
    {
        $libere = [];
        $coperte = [];
        $mancano = 0;
        foreach ($dettaglio as $d) {
            $s = $d['sei_mesi'];
            if ($s === null || $s['piu_vicina'] === null) {
                $mancano++;

                continue;
            }
            $s['piu_vicina_dispensa'] === null ? $libere[] = $s['piu_vicina'] : $coperte[] = [$s['piu_vicina'], $s['piu_vicina_dispensa']];
        }
        if ($libere !== []) {
            return ['data' => min($libere), 'dispensa' => null, 'mancano' => $mancano];
        }
        if ($coperte !== []) {
            usort($coperte, fn ($a, $b) => $a[0] <=> $b[0]);

            return ['data' => $coperte[0][0], 'dispensa' => $coperte[0][1], 'mancano' => $mancano];
        }

        return ['data' => null, 'dispensa' => null, 'mancano' => $mancano];
    }

    /**
     * Le rate con la scadenza passata e non ancora emesse alla data (il pregresso compreso), che sono dovute anche in bozza (le rende dovute la delibera, non l'emissione)
     * ma non sono nella lettura: quante persone e quanto, sulle quote a debito dei piani approvati; e a parte i saldi a debito non ancora in un
     * piano approvato (liberi, o fermi in un piano in bozza). Non si sommano allo scaduto.
     *
     * @return array{persone:int, importo:int, saldi:int}
     */
    public function nonEmesse(Condominio $condominio, string $alla, ?int $gestioneId): array
    {
        $dopo = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');
        $quote = DB::table('rate_quote as q')
            ->join('rate', 'rate.id', '=', 'q.rata_id')
            ->join('piani_rate as p', 'p.id', '=', 'rate.piano_rate_id')
            ->leftJoin('scritture_contabili as em', 'em.id', '=', 'q.scrittura_contabile_id')
            ->where('p.condominio_id', $condominio->id)
            ->where('p.stato', '!=', 'bozza')
            ->when($gestioneId !== null, fn ($q) => $q->where('p.gestione_id', $gestioneId))
            ->whereNotNull('q.anagrafica_id')
            ->where('rate.data_scadenza', '<', $alla)
            ->whereColumn('q.importo', '>', 'q.importo_pagato')
            ->where('q.importo', '>', 0)
            ->where(fn ($w) => $w->whereNull('q.scrittura_contabile_id')->orWhereNull('em.id')->orWhereNotNull('em.deleted_at')
                ->orWhere('em.tipo_movimento', '!=', 'emissione_rata')->orWhere('em.data_competenza', '>=', $dopo))
            ->get(['q.anagrafica_id', 'q.importo', 'q.importo_pagato']);

        // I saldi a debito non ancora in un piano approvato: quelli liberi, e quelli fermi in un piano ancora in bozza. Un piano nasce in
        // bozza (dopo un'importazione, per esempio) e le sue rate ordinarie non contano finché non è approvato; il pregresso che ha preso
        // dai saldi invece è un debito degli anni prima, e senza questa riga non sarebbe in nessuna cifra della pagina.
        $saldi = (int) DB::table('saldi as s')->leftJoin('piani_rate as ps', 'ps.id', '=', 's.piano_rate_id')
            ->where('s.condominio_id', $condominio->id)->whereNull('s.fornitore_id')->where('s.saldo_iniziale', '>', 0)
            ->where(fn ($w) => $w->where('s.is_applicato', false)->orWhere('ps.stato', 'bozza'))
            ->when($gestioneId !== null, fn ($q) => $q->where('s.gestione_id', $gestioneId))->sum('s.saldo_iniziale');

        return [
            'persone' => $quote->pluck('anagrafica_id')->unique()->count(),
            'importo' => (int) $quote->sum(fn ($q) => (int) $q->importo - (int) $q->importo_pagato),
            'saldi' => $saldi,
        ];
    }
}

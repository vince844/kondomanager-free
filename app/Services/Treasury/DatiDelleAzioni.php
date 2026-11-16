<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Treasury;

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Enums\StatoPianoRate;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Services\Gestionale\Inbox\FasceDeiCompiti;
use App\Services\Gestionale\PlafondRitenuteService;
use App\Services\Gestionale\RiportoDiCassa;
use App\Services\Gestionale\SaldiDelleBanche;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * I dati delle azioni consigliate della tesoreria oltre al recupero e alle fatture, con la risposta della tesoreria: le regole stanno in
 * `resources/js/lib/gestionale/tesoreria/azioniConsigliate.ts`, qui si leggono solo i fatti, con letture sommate nel database.
 *
 * - **Le rate non ancora emesse** dei piani approvati del condominio (non il piano degli addebiti, che emette da sé), con la scadenza entro la finestra o già
 *   passata: «emessa» vuol dire a giornale, come nell'emissione ({@see Rata::aGiornale()}). La cifra di una rata è, per persona, la sua parte da pagare
 *   (le sue quote meno il già pagato), mai sotto zero: una quota a credito non porta denaro e non toglie quello degli altri. Il saldo previsto conta solo le rate
 *   emesse ({@see \App\Services\Gestionale\Debiti\LetturaDebiti}): queste sono in più. Per piano fino a 8 rate, le prime per scadenza, con quante e la cifra di tutte;
 *   e le scadenze di tutte, per giorno, perché «anche con tutte le rate» le conta al loro giorno.
 * - **I bonifici segnalati** dal portale da verificare: i compiti aperti `verifica_pagamento` del condominio, con la regola dell'Inbox
 *   ({@see Evento::scopeCompitiAperti()}); quanti e la somma delle cifre segnalate. La richiesta di saldare la rata con tutto il credito (nessun bonifico,
 *   `importo_dichiarato` zero) è una compensazione: non conta.
 * - **Le ritenute da versare con l'F24**, con le regole della pagina «Ritenute e F24»: le deleghe da versare (bozza o confermata) alla loro scadenza, e le
 *   ritenute dei pagamenti non ancora in una delega alla scadenza della legge, raggruppate come le raggruppa «Aggiorna scadenze» ({@see PlafondRitenuteService});
 *   per giorno, entro la finestra o già scadute.
 * - **I deficit ereditati** da ripianare: i compiti aperti `pianifica_ripianamento_deficit`, quanti e la somma degli scoperti.
 * - **L'esercizio aperto di oggi**, dove si crea un piano rate.
 * - **Le banche e i contanti** con il saldo di oggi, e **i fondi che non si possono usare** con il saldo sopra zero, il tipo e il vincolo: dalla stessa
 *   fotografia di «Disponibile oggi» ({@see DisponibilitaDiOggi}). Il saldo di una banca è quello che dice la banca: la parte libera più i fondi che stanno nello
 *   stesso conto corrente, con la regola della scheda «Cassa» e del controllo della banca ({@see SaldiDelleBanche}): con un conto in banca solo tutte le
 *   partizioni (i fondi e le casse virtuali), con più conti gli accantonamenti partiti da lui. La parte libera, ciò che un conto può dare a un altro, è `libero`.
 */
final class DatiDelleAzioni
{
    /** Le rate di un piano che si nominano una per una; le altre si contano. */
    public const RATE_PER_PIANO = 8;

    /**
     * @param  array<string, mixed>  $disponibile  «Disponibile oggi» della tesoreria
     * @return array{
     *     rateDaEmettere: list<array{pianoId:int, piano:string, esercizioId:?int, rate:list<array{rataId:int, numero:int, scadenza:string, cifra:int}>, quante:int, cifra:int,
     *         scadenze: list<array{giorno:string, cifra:int}>}>,
     *     bonifici: array{quanti:int, cifra:int, rimandati:int, rimandatiAl:?string, rimandatiGiorni:int},
     *     ritenute: array{scadenze: list<array{giorno:string, cifra:int}>},
     *     deficit: array{quanti:int, cifra:int},
     *     esercizioId: ?int,
     *     conti: list<array{cassaId:int, nome:string, tipo:string, saldo:int, libero:int, attiva:bool}>,
     *     fondi: list<array{cassaId:int, nome:string, saldo:int, sottotipo:?string, vincolo:?string}>
     * }
     */
    public function perCondominio(Condominio $condominio, ?int $gestioneId, CarbonImmutable $oggi, CarbonImmutable $limite, array $disponibile): array
    {
        $esercizioId = isset($disponibile['esercizio_id']) ? (int) $disponibile['esercizio_id'] : null;

        return [
            'rateDaEmettere' => $this->rateDaEmettere((int) $condominio->id, $gestioneId, $limite, $esercizioId),
            'bonifici' => $this->compiti((int) $condominio->id, 'verifica_pagamento', 'importo_dichiarato', conIRimandati: true),
            'ritenute' => ['scadenze' => $this->ritenute($condominio, $limite)],
            'deficit' => $this->compiti((int) $condominio->id, 'pianifica_ripianamento_deficit', 'importo_buco'),
            'esercizioId' => $esercizioId,
            'conti' => $this->conti($condominio, $oggi, $disponibile),
            'fondi' => $this->fondi((int) $condominio->id, $disponibile),
        ];
    }

    /** Le rate non ancora emesse dei piani approvati, entro la finestra o già scadute, per piano; la cifra sommata nel database, per rata e per persona. */
    private function rateDaEmettere(int $condominioId, ?int $gestioneId, CarbonImmutable $limite, ?int $esercizioDiOggi): array
    {
        $rate = self::rateNonEmesse($condominioId)
            ->when($gestioneId !== null, fn ($q) => $q->where('p.gestione_id', $gestioneId))
            // «Minore del giorno dopo»: su SQLite le date sono testo, con o senza l'ora.
            ->where('r.data_scadenza', '<', $limite->addDay()->toDateString())
            ->orderBy('r.data_scadenza')->orderBy('p.id')->orderBy('r.numero_rata')
            ->get(['r.id', 'r.numero_rata', 'r.data_scadenza', 'p.id as piano_id', 'p.nome as piano', 'p.esercizio_id']);
        if ($rate->isEmpty()) {
            return [];
        }

        $cifre = self::cifrePerRata($rate->pluck('id')->all());

        $piani = [];
        foreach ($rate as $r) {
            $cifra = (int) ($cifre[$r->id] ?? 0);
            if ($cifra <= 0) {
                continue;
            }
            $piano = (int) $r->piano_id;
            $piani[$piano] ??= ['pianoId' => $piano, 'piano' => (string) $r->piano, 'esercizioId' => $r->esercizio_id !== null ? (int) $r->esercizio_id : $esercizioDiOggi,
                'rate' => [], 'quante' => 0, 'cifra' => 0, 'scadenze' => []];
            $giorno = substr((string) $r->data_scadenza, 0, 10);
            if (count($piani[$piano]['rate']) < self::RATE_PER_PIANO) {
                $piani[$piano]['rate'][] = ['rataId' => (int) $r->id, 'numero' => (int) $r->numero_rata, 'scadenza' => $giorno, 'cifra' => $cifra];
            }
            $piani[$piano]['quante']++;
            $piani[$piano]['cifra'] += $cifra;
            $piani[$piano]['scadenze'][$giorno] = ($piani[$piano]['scadenze'][$giorno] ?? 0) + $cifra;
        }

        // Le scadenze di tutte le rate, per giorno e in ordine (le rate sono lette in ordine di scadenza).
        return array_values(array_map(fn (array $p) => array_merge($p, ['scadenze' => array_map(fn ($giorno, $cifra) => ['giorno' => (string) $giorno,
            'cifra' => (int) $cifra], array_keys($p['scadenze']), $p['scadenze'])]), $piani));
    }

    /**
     * I compiti aperti di un tipo nel condominio, con la regola dell'Inbox: quanti, e la somma di una cifra in `meta` (in centesimi). Per i bonifici segnalati
     * non conta la richiesta di saldare la rata con tutto il credito: nessun bonifico arriva sul conto.
     *
     * Con `conIRimandati` (i bonifici segnalati) contano anche i compiti nascosti da un «Ricordamelo» in attesa: la rata del condòmino resta in verifica, e la
     * tesoreria non deve far sollecitare chi ha detto di aver pagato. Allora dice anche quanti sono rimandati, il primo giorno a cui sono rimandati e in quanti
     * giorni diversi. Il «Ricordamelo» resta: nell'Inbox il compito torna il giorno scelto.
     *
     * @return array{quanti:int, cifra:int, rimandati?:int, rimandatiAl?:?string, rimandatiGiorni?:int}
     */
    private function compiti(int $condominioId, string $tipo, string $chiave, bool $conIRimandati = false): array
    {
        $query = $conIRimandati ? Evento::query()->compitiNonChiusi() : Evento::query()->compitiAperti();
        $righe = $query
            ->whereHas('condomini', fn ($c) => $c->where('condomini.id', $condominioId))
            ->whereRaw(FasceDeiCompiti::sqlTipo() . ' = ?', [$tipo])
            ->toBase()
            ->get(['eventi.id', 'eventi.meta']);
        $cifra = 0;
        $quanti = 0;
        $contati = [];
        foreach ($righe as $riga) {
            $m = $riga->meta;
            $dati = is_array($m) ? $m : (array) json_decode((string) $m, true);
            $c = is_numeric($dati[$chiave] ?? null) ? (int) $dati[$chiave] : 0;
            if ($tipo === 'verifica_pagamento' && $c <= 0 && filter_var($dati['intent_usa_credito'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $quanti++;
            $cifra += $c;
            $contati[] = (int) $riga->id;
        }
        if (! $conIRimandati) {
            return ['quanti' => $quanti, 'cifra' => $cifra];
        }

        // I rimandati: contati qui, ma nascosti nell'Inbox (fuori dai compiti aperti), con il giorno del loro «Ricordamelo».
        $aperti = $contati === [] ? [] : Evento::query()->compitiAperti()->whereIn('eventi.id', $contati)->pluck('eventi.id')->map(fn ($id) => (int) $id)->all();
        $rimandati = array_values(array_diff($contati, $aperti));
        $giorni = $rimandati === [] ? collect() : DB::table('risposte_promemoria')
            ->whereIn('evento_id', $rimandati)
            ->where('argomento', 'compito')
            ->where('stato', 'ricordamelo')
            ->pluck('ricordami_il')
            ->map(fn ($g) => substr((string) $g, 0, 10))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return ['quanti' => $quanti, 'cifra' => $cifra, 'rimandati' => count($rimandati), 'rimandatiAl' => $giorni->first(), 'rimandatiGiorni' => $giorni->count()];
    }

    /**
     * Le ritenute da versare entro la finestra o già scadute, per giorno di scadenza: le deleghe da versare con la loro scadenza e il loro totale, e le ritenute
     * dei pagamenti non ancora in una delega viva alla scadenza della legge. Come la pagina «Ritenute e F24»: «Da versare» e «Aggiorna scadenze».
     *
     * @return list<array{giorno:string, cifra:int}>
     */
    private function ritenute(Condominio $condominio, CarbonImmutable $limite): array
    {
        $fino = $limite->toDateString();
        $perGiorno = [];
        foreach (DelegaF24::delCondominio((int) $condominio->id)->aperte()->get(['data_scadenza', 'totale_debito']) as $d) {
            $giorno = $d->data_scadenza?->toDateString();
            if ($giorno !== null && $giorno <= $fino && (int) $d->totale_debito > 0) {
                $perGiorno[$giorno] = ($perGiorno[$giorno] ?? 0) + (int) $d->totale_debito;
            }
        }
        // Le ritenute non ancora in una delega, raggruppate con le regole della legge (la soglia di ogni regime, le date fisse) come le raggruppa «Aggiorna
        // scadenze»: senza scrivere niente.
        $scoperte = app(GeneraDelegheF24Action::class)->ritenuteDaVersare($condominio)->filter(fn ($r) => $r['data_pagamento'] !== null)->values();
        foreach ($scoperte->isEmpty() ? [] : app(PlafondRitenuteService::class)->raggruppaPerScadenza($scoperte->all()) as $gruppo) {
            $giorno = $gruppo['scadenza']->toDateString();
            if ($giorno <= $fino && (int) $gruppo['importo'] > 0) {
                $perGiorno[$giorno] = ($perGiorno[$giorno] ?? 0) + (int) $gruppo['importo'];
            }
        }
        ksort($perGiorno);

        return array_values(array_map(fn ($giorno, $cifra) => ['giorno' => (string) $giorno, 'cifra' => (int) $cifra], array_keys($perGiorno), $perGiorno));
    }

    /**
     * Le banche e i contanti di «Disponibile oggi», con il saldo di oggi: per una banca quello che dice la banca, con dentro i fondi del suo conto corrente
     * (con un conto in banca solo tutte le partizioni di «Disponibile oggi»; con più conti gli accantonamenti partiti da lui, fino a oggi); `libero` è la parte
     * libera, il conto contabile da solo. Per i contanti le due cifre sono la stessa.
     */
    private function conti(Condominio $condominio, CarbonImmutable $oggi, array $disponibile): array
    {
        $righe = $disponibile['righe'] ?? [];
        $reali = array_values(array_filter($righe, fn ($r) => in_array($r['tipo'] ?? null, ['banca', 'contanti'], true) && ($r['cassa_id'] ?? null) !== null));
        // Le banche che contano, come nel controllo della banca: attive o con un saldo.
        $banche = array_values(array_filter($reali, fn ($r) => $r['tipo'] === 'banca' && SaldiDelleBanche::contaIlConto((bool) ($r['attiva'] ?? true), (int) $r['saldo'], false)));
        $partizioni = (int) array_sum(array_map(fn ($r) => (int) $r['saldo'],
            array_filter($righe, fn ($r) => in_array($r['tipo'] ?? null, RiportoDiCassa::CASSE_PARTIZIONE, true) && ($r['cassa_id'] ?? null) !== null)));

        $conti = [];
        foreach ($reali as $r) {
            $libero = (int) $r['saldo'];
            $neiFondi = 0;
            if ($r['tipo'] === 'banca' && in_array((int) $r['cassa_id'], array_map(fn ($b) => (int) $b['cassa_id'], $banche), true)) {
                $neiFondi = count($banche) === 1 ? $partizioni
                    : (($r['conto_contabile_id'] ?? null) === null ? 0 : SaldiDelleBanche::accantonatiDa($condominio, (int) $r['conto_contabile_id'], $oggi->toDateString()));
            }
            $conti[] = ['cassaId' => (int) $r['cassa_id'], 'nome' => (string) $r['voce'], 'tipo' => (string) $r['tipo'], 'saldo' => $libero + $neiFondi, 'libero' => $libero,
                'attiva' => (bool) ($r['attiva'] ?? true)];
        }

        return $conti;
    }

    /** I fondi di «Disponibile oggi» che non si possono usare, con il saldo sopra zero, il tipo della cassa e il vincolo. */
    private function fondi(int $condominioId, array $disponibile): array
    {
        $righe = array_values(array_filter($disponibile['righe'] ?? [], fn ($r) => ($r['tipo'] ?? null) === 'fondo' && ($r['si_puo_usare'] ?? true) === false
            && (int) ($r['saldo'] ?? 0) > 0 && ($r['cassa_id'] ?? null) !== null));
        if ($righe === []) {
            return [];
        }
        $casse = DB::table('casse')->where('condominio_id', $condominioId)->whereIn('id', array_column($righe, 'cassa_id'))
            ->get(['id', 'sottotipo_fondo', 'vincolo_descrizione'])->keyBy('id');

        return array_map(function ($r) use ($casse) {
            $cassa = $casse[(int) $r['cassa_id']] ?? null;
            $sottotipo = $cassa !== null && filled($cassa->sottotipo_fondo) ? (string) $cassa->sottotipo_fondo : null;
            $vincolo = $cassa !== null && filled($cassa->vincolo_descrizione) ? trim((string) $cassa->vincolo_descrizione) : null;

            return ['cassaId' => (int) $r['cassa_id'], 'nome' => (string) $r['voce'], 'saldo' => (int) $r['saldo'], 'sottotipo' => $sottotipo, 'vincolo' => $vincolo];
        }, $righe);
    }

    /**
     * Le rate non ancora emesse dei piani approvati del condominio, non del piano degli addebiti (che emette ogni addebito quando lo registra): «emessa» vuol
     * dire a giornale, come nell'emissione. Le legge anche la finestra «Emetti le rate» ({@see FinestraDiEmissione}), per ogni scadenza.
     */
    public static function rateNonEmesse(int $condominioId): \Illuminate\Database\Query\Builder
    {
        return DB::table('rate as r')
            ->join('piani_rate as p', 'p.id', '=', 'r.piano_rate_id')
            ->where('p.condominio_id', $condominioId)
            ->where('p.stato', StatoPianoRate::APPROVATO->value)
            ->where(fn ($q) => $q->whereNull('p.natura')->orWhere('p.natura', '!=', PianoRate::NATURA_ADDEBITI))
            ->whereNotExists(Rata::aGiornale('r.id'));
    }

    /**
     * Per rata, la parte da pagare di ogni persona (le sue quote meno il già pagato), mai sotto zero, sommata nel database: una quota a credito non porta denaro
     * e non toglie quello degli altri. La stessa cifra nell'azione e nella finestra «Emetti le rate».
     *
     * @param  list<int>  $rataIds
     * @return array<int, int> l'id della rata => i centesimi
     */
    public static function cifrePerRata(array $rataIds): array
    {
        if ($rataIds === []) {
            return [];
        }
        $perPersona = DB::table('rate_quote')
            ->whereIn('rata_id', $rataIds)
            ->groupBy('rata_id', 'anagrafica_id')
            ->selectRaw('rata_id, anagrafica_id, SUM(importo - COALESCE(importo_pagato, 0)) as dovuto');

        return DB::query()->fromSub($perPersona, 'q')
            ->groupBy('rata_id')
            ->selectRaw('rata_id, SUM(CASE WHEN dovuto > 0 THEN dovuto ELSE 0 END) as cifra')
            ->pluck('cifra', 'rata_id')
            ->mapWithKeys(fn ($cifra, $rata) => [(int) $rata => (int) $cifra])
            ->all();
    }
}

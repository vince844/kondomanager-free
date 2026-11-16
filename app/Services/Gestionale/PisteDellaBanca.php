<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le possibili cause di una differenza fra il saldo dell'estratto conto della banca e quello della contabilità, che il programma trova nei movimenti del
 * conto, ognuna con la correzione consigliata: un collegamento all'azione che il programma ha già, aperta su quel movimento o già compilata. La
 * correzione la conferma l'amministratore; il programma non corregge niente da solo e non dice mai «errore»: la frase dice «può dipendere da».
 *
 * LE CAUSE, per un conto con l'estratto al giorno D e la differenza d = estratto − contabilità (positiva: la banca ha di più):
 * - **un movimento in transito**: registrato in contabilità nei {@see GIORNI} giorni fino a D, e non ancora in banca: il suo importo, con il segno, è −d
 *   (o due movimenti che insieme fanno −d);
 * - **un movimento registrato due volte**: due movimenti dello stesso tipo, con lo stesso importo −d, a non più di {@see GIORNI_DOPPIO} giorni l'uno
 *   dall'altro, che pagano la stessa cosa: due incassi con una rata della stessa persona in comune, due pagamenti con una fattura in comune, due altri
 *   movimenti senza rata né fattura con la stessa persona o lo stesso fornitore e la stessa causale. Due versamenti giusti della stessa persona per due
 *   rate diverse non lo sono; un doppio vero che il secondo incasso ha messo su un'altra rata non si propone: è il prezzo di non proporre lo storno di un
 *   versamento giusto;
 * - **lo stesso importo su un altro conto**: un movimento di d su un'altra cassa reale (banca o contanti) nell'esercizio, fino a D: la banca lo ha, la
 *   contabilità lo ha messo altrove;
 * - **le spese della banca non registrate**: la banca ha di meno, e sul conto non c'è una spesa registrata subito (una regolazione immediata) nell'ultimo
 *   mese fino a D;
 * - **un movimento con la data dopo l'estratto**: nei {@see GIORNI} giorni dopo D, con l'importo d: la banca lo aveva già, la contabilità lo ha dopo;
 * - **il denaro dei fondi**, solo con più conti in banca: la banca ha di più esattamente quanto i fondi aperti con un saldo loro (tutti, o uno), che non
 *   sono partiti da nessuna banca e che la contabilità non sa in quale conto stanno. Con un conto solo i fondi sono già nella contabilità del conto
 *   ({@see SaldiDelleBanche}); con più conti gli accantonamenti stanno nella banca da cui sono partiti.
 * Di ogni tipo al più {@see MASSIMO} cause, le più vicine a D. I movimenti stornati e gli storni non contano, e nemmeno gli accantonamenti fra il conto e i
 * suoi fondi: il denaro resta sullo stesso conto.
 *
 * «IGNORA». Ogni causa si può ignorare con una nota, che resta scritta nel registro della nota sintetica (la forma delle righe è quella di
 * {@see NotaSinteticaDelRendiconto::registra()}): una causa ignorata non si ripropone, dice «ignorata il … da …: …», e si riapre. La chiave di una causa
 * è stabile finché i suoi movimenti sono gli stessi.
 */
final class PisteDellaBanca
{
    public const GIORNI = 15;

    public const GIORNI_DOPPIO = 5;

    public const MASSIMO = 3;

    public const IGNORATA = 'pista_ignorata';

    public const RIAPERTA = 'pista_riaperta';

    public function __construct(private readonly StatoPatrimonialeService $statoPatrimoniale) {}

    /**
     * Le cause di ogni conto con una differenza, per cassa: le aperte e le ignorate, ognuna con la sua correzione.
     *
     * @param  list<array<string,mixed>>  $banche  il confronto dei conti ({@see SaldiDelleBanche::confronto()})
     * @return array<int, list<array<string,mixed>>>
     */
    public function perIConti(Condominio $condominio, Esercizio $esercizio, array $banche): array
    {
        $ignorate = self::ignorate(NotaSinteticaDelRendiconto::contenuto($esercizio)['registro']);
        $casse = DB::table('casse')->where('condominio_id', $condominio->id)->whereNotNull('conto_contabile_id')->get(['id', 'nome', 'tipo', 'conto_contabile_id', 'saldo_iniziale'])
            ->keyBy('id');
        $esito = [];
        foreach ($banche as $b) {
            if (($b['differenza'] ?? null) === null || $b['differenza'] === 0 || ! isset($casse[$b['cassa_id']])) {
                continue;
            }
            $esito[(int) $b['cassa_id']] = array_map(fn (array $p) => $p + ['ignorata' => $ignorate[self::chiaveCompleta((int) $b['cassa_id'], $p['chiave'])] ?? null],
                $this->perConto($condominio, $esercizio, $b, $casse));
        }

        return $esito;
    }

    /** La chiave di una causa nel registro: il conto e la causa. */
    public static function chiaveCompleta(int $cassa, string $chiave): string
    {
        return $cassa.':'.$chiave;
    }

    /**
     * Le cause ignorate e non riaperte, dal registro: chiave => quando, chi, la nota.
     *
     * @return array<string, array{il:string, quando:string, utente:string, nota:string}>
     */
    public static function ignorate(array $registro): array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $stato = [];
        foreach ($registro as $r) {
            if (! in_array($r['tipo'] ?? null, [self::IGNORATA, self::RIAPERTA], true) || ! isset($r['dati']['cassa_id'], $r['dati']['chiave'])) {
                continue;
            }
            $k = self::chiaveCompleta((int) $r['dati']['cassa_id'], (string) $r['dati']['chiave']);
            if ($r['tipo'] === self::RIAPERTA) {
                unset($stato[$k]);

                continue;
            }
            $stato[$k] = ['il' => (string) $r['il'], 'quando' => CarbonImmutable::parse($r['il'])->setTimezone($fuso)->format('d/m/Y'),
                'utente' => (string) ($r['utente'] ?? ''), 'nota' => (string) ($r['nota'] ?? '')];
        }

        return $stato;
    }

    /** «Ignora» o «Riapri» di una causa, nel registro della nota: solo dal programma, con chi e quando. */
    public static function scrivi(Esercizio $esercizio, string $tipo, int $cassa, array $pista, string $nota, ?User $utente): void
    {
        $c = NotaSinteticaDelRendiconto::contenuto($esercizio);
        $c = NotaSinteticaDelRendiconto::registra($c, $tipo, $nota, $utente, ['cassa_id' => $cassa, 'chiave' => (string) $pista['chiave'],
            'frase' => (string) ($pista['frase'] ?? ''), 'differenza' => $pista['differenza'] ?? null, 'data' => $pista['data'] ?? null]);
        NotaSinteticaDelRendiconto::salva($esercizio, $c, $utente);
    }

    /** @return list<array<string,mixed>> */
    private function perConto(Condominio $condominio, Esercizio $esercizio, array $b, Collection $casse): array
    {
        $d = (int) $b['differenza'];
        $giorno = CarbonImmutable::parse($b['data']);
        $cassa = $casse[$b['cassa_id']];
        $conto = (int) $cassa->conto_contabile_id;
        $dal = substr((string) $esercizio->data_inizio, 0, 10);
        $prima = $giorno->subDays(self::GIORNI - 1)->format('Y-m-d');
        $dopo = $giorno->addDays(self::GIORNI)->format('Y-m-d');
        $alla = $giorno->format('Y-m-d');
        $piste = [];

        // Un movimento, o due, registrati nei giorni fino all'estratto che la banca non aveva ancora: il loro importo con il segno è −d.
        $vicini = $this->movimenti($condominio, [$conto], $prima, $alla);
        $uno = $vicini->filter(fn ($m) => $m['importo'] === -$d)->sortByDesc('data')->take(self::MASSIMO);
        foreach ($uno as $m) {
            $piste[] = $this->pista('transito:'.$m['scrittura_id'], 'transito', 'Può dipendere da un movimento registrato in contabilità che la banca al '.$giorno->format('d/m/Y')
                .' non aveva ancora: '.self::descrizione($m).'.', [$m], self::correzione($condominio, $m, 'data'));
        }
        if ($uno->isEmpty()) {
            foreach ($this->coppie($vicini->filter(fn ($m) => $m['importo'] !== 0 && ($m['importo'] < 0) === ($d > 0))->values(), -$d) as [$m1, $m2]) {
                $piste[] = $this->pista('due:'.$m1['scrittura_id'].'-'.$m2['scrittura_id'], 'transito', 'Può dipendere da due movimenti registrati in contabilità che la banca al '
                    .$giorno->format('d/m/Y').' non aveva ancora: '.self::descrizione($m1).' e '.self::descrizione($m2).', insieme '.FattiDellaNota::euro(abs($d)).'.',
                    [$m1, $m2], self::correzione($condominio, $m1, 'data'));
            }
        }

        // Lo stesso movimento due volte: stesso tipo, stesso importo −d, a pochi giorni, e la stessa cosa pagata (una rata della stessa persona, una
        // fattura; senza l'una e l'altra, la stessa persona o lo stesso fornitore e la stessa causale).
        $uguali = $this->movimenti($condominio, [$conto], $dal, $alla)->filter(fn ($m) => $m['importo'] === -$d)->sortBy([['data', 'asc'], ['scrittura_id', 'asc']])->values();
        $paga = $this->cosePagate($uguali->pluck('scrittura_id')->all());
        $doppi = 0;
        foreach ($uguali as $i => $a) {
            foreach ($uguali->slice($i + 1) as $c) {
                // I giorni interi: con Carbon 3 `diffInDays()` ha la virgola.
                $giorni = (int) abs(CarbonImmutable::parse($a['data'])->diffInDays(CarbonImmutable::parse($c['data'])));
                if ($doppi >= self::MASSIMO || $giorni > self::GIORNI_DOPPIO || $a['tipo'] !== $c['tipo']
                    || ! self::stessaCosa($a, $c, $paga[$a['scrittura_id']] ?? [], $paga[$c['scrittura_id']] ?? [])) {
                    continue;
                }
                $piste[] = $this->pista('doppio:'.$a['scrittura_id'].'-'.$c['scrittura_id'], 'doppio', 'Può dipendere da un movimento che sembra registrato due volte: '
                    .self::descrizione($a).' e '.self::descrizione($c).($giorni === 0 ? ', lo stesso giorno' : ', a '.$giorni.($giorni === 1 ? ' giorno' : ' giorni').' di distanza').'.',
                    [$a, $c], self::correzione($condominio, $c, 'doppio'));
                $doppi++;
            }
        }

        // Un movimento di d su un'altra cassa reale nell'esercizio, fino all'estratto: la banca lo ha, la contabilità lo ha messo altrove. I giroconti no: il
        // loro altro lato è su questo conto.
        $altre = $casse->filter(fn ($c) => (int) $c->id !== (int) $b['cassa_id'] && in_array($c->tipo, ['banca', 'contanti'], true))->pluck('conto_contabile_id')->map(fn ($v) => (int) $v)->all();
        if ($altre !== []) {
            $suAltri = $this->movimenti($condominio, $altre, $dal, $alla)->filter(fn ($m) => $m['importo'] === $d && $m['tipo'] !== TipoMovimentoContabile::GIROCONTO->value)
                ->sortByDesc('data')->take(self::MASSIMO);
            foreach ($suAltri as $m) {
                $piste[] = $this->pista('altro:'.$m['scrittura_id'], 'altro_conto', 'Può dipendere da un movimento registrato su un altro conto, «'.$m['cassa'].'», che la banca '
                    .'ha su questo: '.self::descrizione($m).'.', [$m], self::correzione($condominio, $m, 'conto'));
            }
        }

        // Le spese della banca: la banca ha di meno, e nell'ultimo mese fino all'estratto sul conto non c'è una spesa registrata subito.
        if ($d < 0) {
            $ultima = $this->movimenti($condominio, [$conto], '1900-01-01', $alla)->filter(fn ($m) => $m['tipo'] === TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA->value)
                ->sortByDesc('data')->first();
            if ($ultima === null || $ultima['data'] <= $giorno->subMonth()->format('Y-m-d')) {
                $piste[] = $this->pista('spese:'.$alla.':'.abs($d), 'spese_banca', 'Può dipendere dalle spese della banca (commissioni, bolli, canone) non ancora registrate: '
                    .($ultima === null ? 'su questo conto non c\'è una spesa registrata con una regolazione immediata.'
                        : 'l\'ultima registrata su questo conto con una regolazione immediata è del '.CarbonImmutable::parse($ultima['data'])->format('d/m/Y').'.'),
                    [], ['etichetta' => 'Registra la spesa', 'rotta' => 'gestionale.regolazioni-immediate.create', 'parametri' => ['condominio' => $condominio->id],
                        'query' => array_filter(['cassa_id' => (int) $b['cassa_id'], 'esercizio_id' => $esercizio->stato === 'aperto' ? (int) $esercizio->id : null,
                            'importo' => number_format(abs($d) / 100, 2, '.', ''), 'data' => $alla, 'conto_id' => $ultima['voce_id'] ?? null,
                            'causale' => 'Spese della banca dal suo estratto conto al '.$giorno->format('d/m/Y')], fn ($v) => $v !== null)]);
            }
        }

        // Un movimento con la data dopo l'estratto, che la banca aveva già: il suo importo è d.
        $poi = $this->movimenti($condominio, [$conto], $giorno->addDay()->format('Y-m-d'), $dopo)->filter(fn ($m) => $m['importo'] === $d)->sortBy('data')->take(self::MASSIMO);
        foreach ($poi as $m) {
            $piste[] = $this->pista('dopo:'.$m['scrittura_id'], 'dopo_estratto', 'Può dipendere da un movimento registrato con una data dopo quella del saldo della banca, che la banca al '
                .$giorno->format('d/m/Y').' aveva già: '.self::descrizione($m).'.', [$m], self::correzione($condominio, $m, 'data'));
        }

        // I fondi aperti con un saldo loro, con più conti in banca: la banca ha di più esattamente quanto loro alla stessa data.
        if ($d > 0 && ($b['fondi'] ?? 'tutti') === 'accantonati') {
            $fondi = $this->fondiPropri($condominio, $casse, $alla);
            $tutti = (int) array_sum(array_column($fondi, 'saldo'));
            $uguale = $tutti === $d ? $fondi : array_values(array_filter($fondi, fn ($f) => $f['saldo'] === $d));
            if ($uguale !== [] && ($tutti === $d || count($uguale) === 1)) {
                $piste[] = $this->pista('fondi:'.$alla.':'.$d, 'fondi', 'Può dipendere dai fondi aperti con un saldo loro: con più conti in banca la contabilità non sa '
                    .'in quale conto stanno ('.implode(', ', array_map(fn ($f) => '«'.$f['nome'].'» '.FattiDellaNota::euro($f['saldo']), $uguale)).' al '.$giorno->format('d/m/Y')
                    .'). Se stanno su questo, scrivilo nella spiegazione della differenza.', [], null);
            }
        }

        return array_map(fn ($p) => $p + ['differenza' => $d, 'data' => $alla], $piste);
    }

    private function pista(string $chiave, string $tipo, string $frase, array $movimenti, ?array $correzione): array
    {
        return ['chiave' => $chiave, 'tipo' => $tipo, 'frase' => $frase, 'movimenti' => array_map(fn ($m) => array_intersect_key($m,
            array_flip(['scrittura_id', 'data', 'protocollo', 'causale', 'importo', 'cassa', 'etichetta'])), $movimenti), 'correzione' => $correzione];
    }

    /** «l'incasso delle rate del 30/12/2026 (prot. INC-3), € 60,00 in entrata» */
    private static function descrizione(array $m): string
    {
        return mb_strtolower($m['etichetta']).' del '.CarbonImmutable::parse($m['data'])->format('d/m/Y').($m['protocollo'] ? ' (prot. '.$m['protocollo'].')' : '').', '
            .FattiDellaNota::euro(abs($m['importo'])).($m['importo'] >= 0 ? ' in entrata' : ' in uscita');
    }

    /**
     * La correzione consigliata per un movimento, con l'azione che il programma ha già: il pagamento si modifica (la data, il conto) o si apre per lo
     * storno; un incasso delle rate si storna dall'elenco degli incassi, aperto su di lui, e si registra di nuovo; le altre registrazioni si aprono.
     *
     * @param  string  $perche  'data', 'conto' o 'doppio'
     */
    private static function correzione(Condominio $condominio, array $m, string $perche): array
    {
        if ($m['pagamento_id'] !== null) {
            return $perche === 'doppio'
                ? ['etichetta' => 'Apri il pagamento per stornarlo', 'rotta' => 'gestionale.pagamenti-fornitori.show', 'parametri' => ['condominio' => $condominio->id, 'pagamento' => $m['pagamento_id']], 'query' => []]
                : ['etichetta' => $perche === 'conto' ? 'Modifica il conto del pagamento' : 'Modifica la data del pagamento', 'rotta' => 'gestionale.pagamenti-fornitori.edit',
                    'parametri' => ['condominio' => $condominio->id, 'pagamento' => $m['pagamento_id']], 'query' => []];
        }
        if ($m['tipo'] === TipoMovimentoContabile::INCASSO_RATA->value) {
            return ['etichetta' => match ($perche) {
                'doppio' => 'Storna l\'incasso registrato due volte',
                'conto' => 'Storna l\'incasso e registralo sul conto giusto',
                default => 'Storna l\'incasso e registralo con la data giusta',
            }, 'rotta' => 'gestionale.movimenti-rate.index', 'parametri' => ['condominio' => $condominio->id], 'query' => array_filter(['search' => $m['protocollo']])];
        }

        return ['etichetta' => 'Apri la registrazione', 'rotta' => 'gestionale.scritture.show', 'parametri' => ['condominio' => $condominio->id, 'scrittura' => $m['scrittura_id']],
            'query' => []];
    }

    /**
     * I movimenti dei conti dati fra due giorni compresi, in ogni esercizio: la registrazione, la data, il protocollo, la causale, l'importo con il segno
     * (positivo in entrata), la cassa, il tipo, la persona o il fornitore, il pagamento e la voce. Senza gli storni, gli originali stornati, le aperture e
     * gli accantonamenti verso i fondi.
     *
     * @param  list<int>  $conti
     */
    private function movimenti(Condominio $condominio, array $conti, string $dal, string $al): Collection
    {
        $storni = TipoMovimentoContabile::storniDiScrittura();
        $dopo = CarbonImmutable::parse($al)->addDay()->format('Y-m-d');

        // Senza le righe di un accantonamento fra il conto e una partizione (e del suo ritorno): il denaro resta sullo stesso conto, la contabilità del conto
        // lo conta già con i fondi, e un giroconto verso un fondo non è mai la causa di una differenza con la banca.
        return RiportoDiCassa::senzaAccantonamenti(DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id'))
            ->leftJoin('casse as ca', 'ca.id', '=', 'rs.cassa_id')
            ->leftJoin('pagamenti_fornitori as pf', 'pf.scrittura_contabile_id', '=', 'sc.id')
            ->where('sc.condominio_id', $condominio->id)->whereNull('sc.deleted_at')->whereIn('rs.conto_contabile_id', $conti)
            ->where('sc.data_competenza', '>=', $dal)->where('sc.data_competenza', '<', $dopo)
            ->where('sc.stato', '!=', 'annullata')
            ->where(fn ($q) => $q->whereNull('sc.tipo_movimento')->orWhereNotIn('sc.tipo_movimento', [...$storni, TipoMovimentoContabile::APERTURA->value]))
            ->where(fn ($q) => $q->whereNull('sc.tipo_movimento')->orWhere('sc.tipo_movimento', '!=', TipoMovimentoContabile::RETTIFICA->value)->orWhereNull('sc.scrittura_padre_id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('scritture_contabili as f')->whereColumn('f.scrittura_padre_id', 'sc.id')->whereIn('f.tipo_movimento', $storni)
                ->whereNull('f.deleted_at'))
            ->orderBy('sc.data_competenza')->orderBy('sc.id')
            ->get(['sc.id as scrittura_id', 'sc.data_competenza', 'sc.numero_protocollo', 'sc.causale', 'sc.tipo_movimento', 'rs.tipo_riga', 'rs.importo', 'ca.nome as cassa',
                'pf.id as pagamento_id', 'pf.fornitore_id',
                DB::raw('(SELECT MIN(r2.anagrafica_id) FROM righe_scritture r2 WHERE r2.scrittura_id = sc.id) as anagrafica_id'),
                DB::raw('(SELECT MIN(r3.voce_spesa_id) FROM righe_scritture r3 WHERE r3.scrittura_id = sc.id) as voce_id')])
            ->map(fn ($r) => [
                'scrittura_id' => (int) $r->scrittura_id, 'data' => substr((string) $r->data_competenza, 0, 10), 'protocollo' => $r->numero_protocollo, 'causale' => $r->causale,
                'tipo' => (string) $r->tipo_movimento, 'etichetta' => TipoMovimentoContabile::tryFrom((string) $r->tipo_movimento)?->label() ?? 'Movimento',
                'importo' => $r->tipo_riga === 'dare' ? (int) $r->importo : -(int) $r->importo, 'cassa' => (string) ($r->cassa ?? ''),
                'pagamento_id' => $r->pagamento_id === null ? null : (int) $r->pagamento_id, 'fornitore_id' => $r->fornitore_id === null ? null : (int) $r->fornitore_id,
                'anagrafica_id' => $r->anagrafica_id === null ? null : (int) $r->anagrafica_id, 'voce_id' => $r->voce_id === null ? null : (int) $r->voce_id,
            ]);
    }

    /**
     * Due movimenti che insieme fanno l'importo: le prime {@see MASSIMO} coppie, le più vicine all'estratto.
     *
     * @return list<array{0:array, 1:array}>
     */
    private function coppie(Collection $movimenti, int $importo): array
    {
        $coppie = [];
        $visti = [];
        foreach ($movimenti->sortByDesc('data')->values() as $m) {
            foreach ($visti[$importo - $m['importo']] ?? [] as $altro) {
                // Il più vecchio prima: le date si leggono in ordine.
                $coppie[] = [$m, $altro];
                if (count($coppie) >= self::MASSIMO) {
                    return $coppie;
                }
            }
            $visti[$m['importo']][] = $m;
        }

        return $coppie;
    }

    /**
     * I fondi aperti con un saldo loro, al giorno: il saldo del conto del fondo nella fotografia del giornale a quel giorno, più il saldo di apertura non
     * ancora a giornale, meno quello che gli è arrivato (o è tornato) con un giroconto da o verso una cassa reale. È la parte del fondo che non è partita da
     * nessuna banca. La fotografia è quella da cui {@see SaldiDelleBanche::confronto()} legge il conto della banca.
     *
     * @return list<array{nome:string, saldo:int}>
     */
    private function fondiPropri(Condominio $condominio, Collection $casse, string $alla): array
    {
        $fondi = $casse->filter(fn ($c) => $c->tipo === 'fondo');
        if ($fondi->isEmpty()) {
            return [];
        }
        $saldi = collect($this->statoPatrimoniale->calcola($condominio, allaData: $alla)['attivo']['voci'])->mapWithKeys(fn ($v) => [(int) $v['id'] => (int) $v['saldo']]);
        $dopo = CarbonImmutable::parse($alla)->addDay()->format('Y-m-d');
        $dalleBanche = DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')->where('sc.condominio_id', $condominio->id)
            ->whereNull('sc.deleted_at')->whereIn('rs.conto_contabile_id', $fondi->pluck('conto_contabile_id'))->where('sc.data_competenza', '<', $dopo)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('righe_scritture as rs2')->join('casse as ca2', 'ca2.id', '=', 'rs2.cassa_id')
                ->whereColumn('rs2.scrittura_id', 'rs.scrittura_id')->whereColumn('rs2.id', '!=', 'rs.id')->whereIn('ca2.tipo', RiportoDiCassa::CASSE_REALI))
            ->groupBy('rs.conto_contabile_id')->selectRaw("rs.conto_contabile_id as conto, SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as netto")
            ->pluck('netto', 'conto');

        return $fondi->map(fn ($f) => ['nome' => (string) $f->nome, 'saldo' => (int) ($saldi[(int) $f->conto_contabile_id] ?? 0) + (int) $f->saldo_iniziale
            - (int) ($dalleBanche[$f->conto_contabile_id] ?? 0)])->filter(fn ($f) => $f['saldo'] > 0)->values()->all();
    }

    /**
     * Che cosa pagano le registrazioni date: le rate, con la persona («rata:<rata>:<persona>»), e le fatture («fattura:<fattura>»).
     *
     * @param  list<int>  $scritture
     * @return array<int, list<string>>
     */
    private function cosePagate(array $scritture): array
    {
        if ($scritture === []) {
            return [];
        }
        $paga = [];
        foreach (DB::table('righe_scritture')->whereIn('scrittura_id', $scritture)->whereNotNull('rata_id')->get(['scrittura_id', 'rata_id', 'anagrafica_id']) as $r) {
            $paga[(int) $r->scrittura_id][] = 'rata:'.$r->rata_id.':'.$r->anagrafica_id;
        }
        foreach (DB::table('fattura_scrittura')->whereIn('scrittura_contabile_id', $scritture)->where('tipo', 'pagamento')->get(['scrittura_contabile_id', 'fattura_passiva_id']) as $r) {
            $paga[(int) $r->scrittura_contabile_id][] = 'fattura:'.$r->fattura_passiva_id;
        }

        return $paga;
    }

    /** Due movimenti pagano la stessa cosa: una rata o una fattura in comune; senza l'una e l'altra, la stessa persona o lo stesso fornitore e la stessa causale. */
    private static function stessaCosa(array $a, array $c, array $pagaA, array $pagaC): bool
    {
        if ($pagaA !== [] || $pagaC !== []) {
            return array_intersect($pagaA, $pagaC) !== [];
        }

        return $a['anagrafica_id'] === $c['anagrafica_id'] && $a['fornitore_id'] === $c['fornitore_id'] && trim((string) $a['causale']) === trim((string) $c['causale']);
    }
}

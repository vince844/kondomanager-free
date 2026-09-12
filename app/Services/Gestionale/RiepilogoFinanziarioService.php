<?php

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Enums\TipoMovimentoContabile;
use App\Models\Esercizio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Il riepilogo finanziario dell'esercizio — D19 di docs/registri_contabili.md — la sezione 3b della
 * pagina Stato patrimoniale e il § 4 del fac-simile: per ciascuna cassa, disponibilità iniziale,
 * entrate, uscite, disponibilità finale. Art. 1130-bis c.c.: «la disponibilità iniziale di ciascuna
 * risorsa e di ciascun fondo, il totale delle entrate e delle uscite, la disponibilità finale».
 *
 * DUE PERIMETRI, DICHIARATI. L'INIZIALE è uno stock per data (D16): tutto ciò che è stato registrato
 * con data precedente all'inizio dell'esercizio, su tutti gli esercizi, più il `saldo_iniziale` in
 * colonna se l'apertura non è ancora a giornale. I FLUSSI (entrate, uscite) sono le righe delle
 * scritture DELL'ESERCIZIO — per `esercizio_id`, non per data — perché è il perimetro del Libro
 * Giornale e del consuntivo per voce: una fattura è costo dell'esercizio a cui è stata registrata,
 * ovunque cada la sua data. La revisione della beta.25 ha trovato che il taglio per data faceva
 * dire alla stessa fattura «costo 2025» qui e «costo 2026» nel giornale. Il finale è iniziale +
 * entrate − uscite; se non coincide con lo stock alla data di fine è perché ci sono movimenti datati
 * fuori dal periodo del loro esercizio — ed è R2 a misurarlo, non questa classe a nasconderlo.
 *
 * ⚠️ **L'apertura vale come iniziale, mai come flusso.** La scrittura di apertura di una cassa (tipo
 * `apertura`) è la disponibilità con cui si comincia: entra nell'iniziale a qualunque data sia
 * registrata, e resta fuori dalle entrate. Stesso perimetro per il risultato di gestione e il
 * raccordo (StatoPatrimonialePaginaService), così le tre grandezze non si contraddicono.
 *
 * ⚠️ **I trasferimenti fra casse contano nelle righe, non nel piede** (D19). Un accantonamento
 * banca→fondo è un'uscita della banca e un'entrata del fondo: senza contarlo la riga del fondo non
 * quadrerebbe. Il piede espone i flussi con l'ESTERNO: per ogni scrittura, la parte in cui le righe
 * di cassa si compensano fra loro è interna, il resto è esterno. È una compensazione per scrittura
 * — `min(Σ dare di cassa, Σ avere di cassa)` — e non «esiste un'altra riga di cassa»: la seconda
 * forma classificava come interno un incasso ripartito su due casse e faceva saltare il piede con
 * un'eccezione, cioè una pagina bianca in produzione (revisione della beta.25). Con la
 * compensazione il piede quadra per costruzione su qualunque forma di scrittura.
 *
 * AGGREGAZIONE PER CONTO CONTABILE, NON PER cassa_id. `righe_scritture.cassa_id` è derivato e può
 * restare nullo (catene fra moduli, catena «netto da pagare», trappola 2); `SaldoCassaService` — la
 * fonte unica del saldo — aggrega per `conto_contabile_id`. Qui lo stesso.
 *
 * CASSE REALI SOTTO ZERO: si guarda il saldo GIORNO PER GIORNO, non i due estremi — una cassa può
 * scendere sotto zero a marzo e risalire a luglio, e «mai sotto zero» sarebbe falso. Il controllo
 * riporta il minimo e la data in cui l'ha toccato.
 */
class RiepilogoFinanziarioService
{
    /** Ordine di presentazione: prima il denaro reale, poi le partizioni. */
    private const ORDINE_TIPI = ['banca' => 0, 'contanti' => 1, 'fondo' => 2, 'virtuale' => 3];

    private const CASSE_REALI = ['banca', 'contanti'];

    /**
     * @return array{
     *   dal:string, al:string, stato_esercizio:string,
     *   righe: array<int, array<string, mixed>>,
     *   totale: array{iniziale:int, entrate:int, uscite:int, finale:int},
     *   giroconti: array{entrate:int, uscite:int},
     *   stornate: array{coppie:int, importo:int},
     *   piede_quadra: bool,
     *   casse_reali_negative: array<int, array{cassa:string, minimo:int, data:?string}>
     * }
     */
    public function perEsercizio(Esercizio $esercizio, ?string $oggi = null): array
    {
        // «Oggi» nel fuso dell'utente, non in UTC: i movimenti si registrano con la data di
        // DateHelper::oggiUtente(), e fra mezzanotte e le due un incasso di oggi restava fuori
        // dalla fotografia intitolata a ieri (revisione della beta.25).
        $oggi = $oggi ?? DateHelper::oggiUtente();
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $fine = $esercizio->data_fine->format('Y-m-d');
        $stato = $dal > $oggi ? 'futuro' : ($fine < $oggi ? 'chiuso' : 'aperto');
        $al = $stato === 'chiuso' ? $fine : $oggi;

        $casse = DB::table('casse')
            ->where('condominio_id', $esercizio->condominio_id)
            ->whereNotNull('conto_contabile_id')
            ->get(['id', 'nome', 'tipo', 'sottotipo_fondo', 'conto_contabile_id', 'saldo_iniziale']);

        if ($casse->isEmpty()) {
            return $this->vuoto($dal, $al, $stato);
        }

        $contiCassa = $casse->pluck('conto_contabile_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        // 0. Le coppie originale + storno dentro l'esercizio NON contano fra i flussi (deciso da
        //    Vincenzo al test reale del 12/09/2026: «+100 −100 fa zero, si annullano»). Nei saldi si
        //    annullano da sole; qui, dove si contano i movimenti, contavano entrambe — una spesa da
        //    800 stornata faceva «entrate 1.300, uscite 2.088» in un anno in cui la banca ha visto
        //    500 in entrata. Il riepilogo espone i flussi reali con l'esterno; il registro (Prima
        //    nota) continua a mostrare le due righe, come chiede l'art. 2219. Se lo storno è in un
        //    altro esercizio le due righe contano ciascuna nel suo anno, altrimenti la riga non
        //    quadrerebbe più. Lo stesso criterio del registro: figlia `storno_*` con padre, oppure
        //    la `rettifica` di un incasso (`stato = annullata`); le rettifiche di RiallineaFondi non
        //    sono storni e restano.
        $coppie = DB::table('scritture_contabili as f')
            ->join('scritture_contabili as p', 'p.id', '=', 'f.scrittura_padre_id')
            ->whereNull('f.deleted_at')->whereNull('p.deleted_at')
            ->where('f.esercizio_id', $esercizio->id)->where('p.esercizio_id', $esercizio->id)
            // Elenco esplicito, non LIKE: `storno_credito` non è uno storno (vedi l'enum).
            ->where(fn ($q) => $q->whereIn('f.tipo_movimento', TipoMovimentoContabile::storniDiScrittura())->orWhere('p.stato', 'annullata'))
            ->get(['f.id as figlia', 'p.id as padre']);
        $esclusi = $coppie->flatMap(fn ($c) => [(int) $c->figlia, (int) $c->padre])->unique()->values()->all();
        // Per la nota si contano i soli movimenti di CASSA annullati: un incasso stornato ha anche una
        // figlia «quota a credito» senza righe di cassa, e contarla farebbe dire «3 movimenti» per uno.
        $stornate = ['coppie' => 0, 'importo' => 0];
        if ($esclusi !== []) {
            $padriConCassa = DB::table('righe_scritture as rs')
                ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
                ->whereIn('rs.conto_contabile_id', $contiCassa)
                ->whereIn('sc.id', $coppie->pluck('padre')->map(fn ($i) => (int) $i)->unique()->all())
                ->groupBy('sc.id')
                ->select('sc.id', DB::raw('SUM(rs.importo) as importo'))
                ->get();
            $stornate = ['coppie' => $padriConCassa->count(), 'importo' => (int) $padriConCassa->sum('importo')];
        }

        // 1. Iniziale per conto: apertura (a qualunque data) + righe datate prima dell'inizio.
        $iniziali = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->whereIn('rs.conto_contabile_id', $contiCassa)
            ->where(fn ($q) => $q->where('sc.tipo_movimento', 'apertura')->orWhere('sc.data_competenza', '<', $dal))
            ->groupBy('rs.conto_contabile_id')
            ->select('rs.conto_contabile_id', DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END), 0) as netto"))
            ->pluck('netto', 'conto_contabile_id');

        // 2. Flussi per conto: le scritture dell'esercizio, senza le aperture.
        $flussi = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->whereIn('rs.conto_contabile_id', $contiCassa)
            ->where('sc.esercizio_id', $esercizio->id)
            ->where('sc.tipo_movimento', '<>', 'apertura')
            ->whereNotIn('sc.id', $esclusi)
            ->groupBy('rs.conto_contabile_id')
            ->select('rs.conto_contabile_id',
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as entrate"),
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as uscite"))
            ->get()->keyBy('conto_contabile_id');

        // 3. La parte interna, scrittura per scrittura: min(dare di cassa, avere di cassa).
        $perScrittura = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->whereIn('rs.conto_contabile_id', $contiCassa)
            ->where('sc.esercizio_id', $esercizio->id)
            ->where('sc.tipo_movimento', '<>', 'apertura')
            ->whereNotIn('sc.id', $esclusi)
            ->groupBy('rs.scrittura_id')
            ->select('rs.scrittura_id',
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare"),
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere"))
            ->get();
        $interno = 0;
        foreach ($perScrittura as $sc) {
            $interno += min((int) $sc->dare, (int) $sc->avere);
        }

        // 4. Il minimo giorno per giorno delle casse reali.
        $minimi = $this->minimiCasseReali($esercizio, $casse, $iniziali, $esclusi);

        $righe = [];
        $totale = ['iniziale' => 0, 'entrate' => 0, 'uscite' => 0, 'finale' => 0];
        $negative = [];

        foreach ($casse as $cassa) {
            $conto = (int) $cassa->conto_contabile_id;
            $f = $flussi->get($conto);
            // Il saldo in colonna è l'apertura non ancora a giornale: RegistraAperturaCassaAction lo
            // azzera nella stessa transazione in cui scrive la scrittura, quindi non conta due volte.
            $iniziale = (int) $cassa->saldo_iniziale + (int) ($iniziali[$conto] ?? 0);
            $entrate = $f ? (int) $f->entrate : 0;
            $uscite = $f ? (int) $f->uscite : 0;
            $finale = $iniziale + $entrate - $uscite;
            $minimo = $minimi[$conto] ?? ['minimo' => min($iniziale, $finale), 'data' => null];
            $reale = in_array($cassa->tipo, self::CASSE_REALI, true);
            $negativa = $reale && $minimo['minimo'] < 0;

            $righe[] = [
                'cassa_id' => (int) $cassa->id,
                'cassa' => $cassa->nome,
                'tipo' => $cassa->tipo,
                'sottotipo_fondo' => $cassa->sottotipo_fondo,
                'conto_contabile_id' => $conto,
                'iniziale' => $iniziale,
                'entrate' => $entrate,
                'uscite' => $uscite,
                'finale' => $finale,
                'negativa' => $negativa,
                'minimo' => $reale ? $minimo['minimo'] : null,
                'minimo_il' => $reale ? $minimo['data'] : null,
            ];

            $totale['iniziale'] += $iniziale;
            $totale['finale'] += $finale;
            $totale['entrate'] += $entrate;
            $totale['uscite'] += $uscite;

            if ($negativa) {
                $negative[] = [
                    'cassa' => $cassa->nome, 'cassa_id' => (int) $cassa->id, 'minimo' => $minimo['minimo'], 'data' => $minimo['data'],
                    // Per la diagnosi: una cassa senza apertura e senza incassi non ha «un movimento
                    // sbagliato», le manca il saldo iniziale o le mancano gli incassi.
                    'iniziale' => $iniziale, 'entrate' => $entrate,
                ];
            }
        }

        // Il piede: i flussi esterni sono i flussi totali meno la parte che si compensa per scrittura.
        $totale['entrate'] -= $interno;
        $totale['uscite'] -= $interno;

        usort($righe, fn ($x, $y) => [self::ORDINE_TIPI[$x['tipo']] ?? 9, $x['cassa']] <=> [self::ORDINE_TIPI[$y['tipo']] ?? 9, $y['cassa']]);

        // Regge per costruzione (la parte interna è sottratta uguale da entrambi i lati). Se non
        // regge è un difetto del calcolo: si scrive nel log e si espone, non si lancia — una pagina
        // bianca in produzione è peggio di un totale segnalato.
        $piedeQuadra = $totale['iniziale'] + $totale['entrate'] - $totale['uscite'] === $totale['finale'];
        if (! $piedeQuadra) {
            Log::warning('Riepilogo finanziario: il piede non quadra', ['esercizio' => $esercizio->id, 'totale' => $totale]);
        }

        return [
            'dal' => $dal,
            'al' => $al,
            'stato_esercizio' => $stato,
            'righe' => $righe,
            'totale' => $totale,
            'giroconti' => ['entrate' => $interno, 'uscite' => $interno],
            'stornate' => $stornate,
            'piede_quadra' => $piedeQuadra,
            'casse_reali_negative' => $negative,
        ];
    }

    /**
     * Per ogni cassa reale, il saldo più basso toccato nell'esercizio e il giorno in cui l'ha
     * toccato: righe dell'esercizio in ordine cronologico, a partire dall'iniziale.
     *
     * @return array<int, array{minimo:int, data:?string}>  per conto contabile
     */
    private function minimiCasseReali(Esercizio $esercizio, $casse, $iniziali, array $esclusi = []): array
    {
        $contiReali = $casse->whereIn('tipo', self::CASSE_REALI)->pluck('conto_contabile_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($contiReali === []) {
            return [];
        }

        // ⚠️ **Un giorno per volta, non una scrittura per volta.** `data_competenza` è una data
        // senza ora: dentro lo stesso giorno l'ordine delle scritture è quello di inserimento, non
        // un fatto. Sommando riga per riga, una spesa da 800 e il suo storno dello stesso giorno
        // facevano passare la banca per −72 «alle 16:58», un istante che nessun estratto conto ha
        // mai visto — il controllo restava rosso dopo lo storno. Il saldo di fine giornata è
        // l'unica grandezza definita, ed è quella che il registro (art. 2219, storno = riga nuova)
        // e l'estratto conto possono confermare. Trovato al test reale del 12/09/2026.
        $giorni = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->whereIn('rs.conto_contabile_id', $contiReali)
            ->where('sc.esercizio_id', $esercizio->id)
            ->where('sc.tipo_movimento', '<>', 'apertura')
            // Una coppia stornata non è mai successa: non può aver mandato la cassa sotto zero.
            ->whereNotIn('sc.id', $esclusi)
            ->groupBy('rs.conto_contabile_id', 'sc.data_competenza')
            ->orderBy('sc.data_competenza')
            ->get([
                'rs.conto_contabile_id',
                'sc.data_competenza',
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END), 0) as variazione"),
            ]);

        $saldo = [];
        $minimo = [];
        foreach ($casse->whereIn('tipo', self::CASSE_REALI) as $cassa) {
            $conto = (int) $cassa->conto_contabile_id;
            $saldo[$conto] = (int) $cassa->saldo_iniziale + (int) ($iniziali[$conto] ?? 0);
            $minimo[$conto] = ['minimo' => $saldo[$conto], 'data' => null];
        }
        foreach ($giorni as $g) {
            $conto = (int) $g->conto_contabile_id;
            $saldo[$conto] += (int) $g->variazione;
            if ($saldo[$conto] < $minimo[$conto]['minimo']) {
                $minimo[$conto] = ['minimo' => $saldo[$conto], 'data' => Carbon::parse($g->data_competenza)->format('Y-m-d')];
            }
        }

        return $minimo;
    }

    private function vuoto(string $dal, string $al, string $stato): array
    {
        return [
            'dal' => $dal, 'al' => $al, 'stato_esercizio' => $stato,
            'righe' => [], 'totale' => ['iniziale' => 0, 'entrate' => 0, 'uscite' => 0, 'finale' => 0],
            'giroconti' => ['entrate' => 0, 'uscite' => 0], 'stornate' => ['coppie' => 0, 'importo' => 0], 'piede_quadra' => true, 'casse_reali_negative' => [],
        ];
    }
}

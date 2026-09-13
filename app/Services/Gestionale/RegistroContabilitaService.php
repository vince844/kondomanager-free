<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Helpers\DateHelper;
use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Il registro di contabilità ex art. 1130, comma 1, n. 7 c.c. — entrate e uscite reali,
 * in ordine cronologico, con saldo progressivo. Vedi docs/registri_contabili.md §6, D10, D15.
 *
 * PERIMETRO (§6 punto 2, D15). Una riga entra nel registro solo se appoggiata a una cassa di
 * liquidità reale (`banca`, `contanti`): `fondo` e `virtuale` sono partizioni contabili
 * dell'unico conto corrente reale, non denaro che si muove davvero. Di conseguenza è esclusa
 * anche la riga reale gemella di un accantonamento verso una di quelle due: un banca→fondo
 * scrive comunque una riga vera sul conto banca, ma quella riga non è né un'entrata né
 * un'uscita — è la banca che parla con sé stessa. Si esclude guardando le righe SORELLE
 * della stessa scrittura, non il tipo_movimento: la regola vale per ogni scrittura che lo fa,
 * presente o futura, senza un elenco di enum da tenere aggiornato.
 *
 * ⚠️ **L'assunzione su cui questo poggia**: nessuna scrittura mescola, sulla stessa
 * `scrittura_id`, una riga di cassa reale che rappresenta un movimento indipendente dal fondo
 * insieme a una riga sul fondo. Vera oggi (i giroconti sono sempre due righe, cassa↔cassa,
 * senza terze righe estranee) — verificata dalla Fase 1-bis su tutti gli scrittori di
 * fondo/virtuale esistenti. **Se una futura scrittura la violasse** (es. un incasso e un
 * accantonamento uniti nella stessa registrazione), la riga di cassa reale ne uscirebbe
 * esclusa per errore. Da ricontrollare quando nasce una scrittura così.
 *
 * ESCLUSIONE ELIMINATE, NON ANNULLATE. Una scrittura con `stato = 'annullata'` è l'originale
 * di uno storno (`StornoIncassoRateAction`): resta nel registro, intatta, mentre una seconda
 * scrittura "rettifica" ne inverte dare e avere. Le due si annullano da sole nella somma. Il
 * filtro è sul soft-delete (`deleted_at`), stesso perimetro di `SaldoCassaService`.
 *
 * CONTROPARTE — il dato non vive in un posto solo (§6): la riga di cassa di un incasso o di
 * un pagamento fornitore non porta MAI `anagrafica_id` (lo portano le righe di debito/credito
 * "sorelle", sulla stessa scrittura); un pagamento fornitore lo sa solo `pagamenti_fornitori`;
 * un versamento F24 non ha altra controparte che l'erario; un giroconto ha come controparte
 * l'altra cassa della stessa scrittura. Risolta con quattro fonti in ordine di priorità,
 * mai con un JOIN diretto sulle tabelle 1:N (moltiplicherebbe la riga di cassa).
 *
 * ⚠️ **Un conto contabile NON è una controparte, e la cella vuota è più onesta.** La norma chiede
 * «il destinatario del pagamento o il soggetto che lo ha corrisposto»: un soggetto, non una voce
 * di piano dei conti. Una versione precedente, quando non trovava né fornitore né erario né
 * anagrafica, ripiegava sul nome del conto della riga sorella — e in stampa uscivano controparti
 * come «Attivo», «Costi per Servizi», «Fondo Passate Gestioni», che a un condòmino non dicono
 * niente e a un CTU dicono una cosa falsa. Visto solo generando la stampa con 5.000 movimenti
 * veri. Oggi l'ultima fonte guarda **solo le casse**, perché in un giroconto l'altra cassa è
 * davvero la controparte del movimento; per tutto il resto la colonna resta vuota, ed è corretto
 * così: un saldo di apertura non ha un destinatario.
 */
class RegistroContabilitaService
{
    /** Tipi di cassa che rappresentano liquidità reale — D15. */
    private const CASSE_REALI = ['banca', 'contanti'];

    /** Tipi di cassa che sono partizioni contabili, non liquidità propria — D15. */
    private const CASSE_PARTIZIONE = ['fondo', 'virtuale'];

    /**
     * Il registro di contabilità dell'esercizio: righe cronologiche, numerate, con saldo
     * progressivo.
     *
     * ⚠️ **Numero e saldo si calcolano sull'esercizio INTERO, il filtro si applica dopo.** È la
     * differenza fra un registro e un elenco filtrabile, e non è una raffinatezza: la norma vuole
     * questo registro perché dia «un continuo e diretto controllo, in tempo reale, della
     * situazione contabile e delle somme a disposizione del condominio» (§2-ter). Se filtrando
     * giugno il saldo ripartisse da zero, la colonna direbbe una cifra che non è mai stata sul
     * conto — e il numero progressivo, che D5 vuole calcolato in lettura per identificare
     * l'operazione, cambierebbe a ogni filtro, cioè non identificherebbe più niente.
     *
     * Costo accettato: si legge tutto l'esercizio anche quando se ne mostra un mese. Il volume è
     * quello dei soli movimenti di cassa reale di un anno — l'ordine delle centinaia, non delle
     * migliaia — e il filtro in PHP su un array già in memoria non è la parte cara.
     *
     * @param  array{data_da?:string,data_a?:string,search?:string}  $filtri
     * @return array<int, array<string, mixed>>
     */
    public function registro(Esercizio $esercizio, array $filtri = []): array
    {
        return $this->applicaFiltri($this->calcola($esercizio), $filtri);
    }

    /**
     * Il registro filtrato E il riepilogo che gli si mette accanto — saldo finale e saldo per
     * cassa — calcolati come vanno calcolati: sull'esercizio intero, a una data di riferimento.
     *
     * ⚠️ **Tre difetti della revisione della beta.24 avevano la stessa radice: il riepilogo era
     * calcolato sulle righe FILTRATE.** (1) Una cassa ferma nel periodo spariva dal dettaglio, e
     * il conto scoperto con lei; (2) ogni cassa portava il saldo del suo ultimo movimento
     * *mostrato*, non quello alla data dichiarata in testa, e le due cifre non tornavano;
     * (3) con zero righe il saldo diventava lo zero del `??`, stampato come «Saldo di cassa
     * € 0,00» su un conto che era a −370,56.
     *
     * La regola che li chiude: si fissa una **data di riferimento** — quella dell'ultima riga
     * mostrata, oppure `data_a` se il filtro non lascia righe, oppure l'ultima dell'esercizio —
     * e ogni saldo è il progressivo dell'esercizio intero **a quella data, inclusa**. Il totale è
     * la somma dei saldi per cassa per costruzione, perché tagliano tutti nello stesso punto.
     * Movimenti, entrate e uscite per cassa restano invece contati sulle righe mostrate: sono
     * dichiarati parziali, e lo sono.
     *
     * @param  array{data_da?:string,data_a?:string,search?:string}  $filtri
     * @return array{
     *   righe: array<int, array<string, mixed>>,
     *   data_riferimento: ?string,
     *   saldo_finale: int,
     *   saldi_per_cassa: array<int, array{cassa:string, saldo:int, movimenti:int, entrate:int, uscite:int}>
     * }
     */
    public function registroConRiepilogo(Esercizio $esercizio, array $filtri = []): array
    {
        $tutte = $this->calcola($esercizio);
        $righe = $this->applicaFiltri($tutte, $filtri);

        $dataRiferimento = $righe !== []
            ? $righe[array_key_last($righe)]['data']
            : ($this->dataFiltro($filtri['data_a'] ?? null)
                ?? ($tutte !== [] ? $tutte[array_key_last($tutte)]['data'] : null));

        // Il saldo a quella data, per cassa e in totale, sull'esercizio intero: l'ultima riga
        // di ogni cassa con data ≤ riferimento porta il progressivo giusto. Le righe sono già
        // in ordine cronologico, quindi basta sovrascrivere.
        $saldoFinale = 0;
        $saldi = [];
        foreach ($tutte as $riga) {
            if ($dataRiferimento !== null && $riga['data'] > $dataRiferimento) {
                break;
            }
            $saldoFinale = $riga['saldo_progressivo'];
            $saldi[$riga['cassa']] = [
                'cassa' => $riga['cassa'],
                'saldo' => $riga['saldo_cassa_progressivo'],
                'movimenti' => 0,
                'entrate' => 0,
                'uscite' => 0,
            ];
        }

        // Il parziale, invece, è delle righe mostrate — come i due totali in testa alla pagina.
        foreach ($righe as $riga) {
            $c = $riga['cassa'];
            if (! isset($saldi[$c])) {
                // Non può succedere — una riga mostrata è ≤ riferimento per costruzione — ma
                // una cassa senza saldo sarebbe un numero inventato, meglio un errore visibile.
                throw new \LogicException("Cassa «{$c}» mostrata ma senza saldo alla data di riferimento.");
            }
            $saldi[$c]['movimenti']++;
            $saldi[$c]['entrate'] += $riga['entrata'];
            $saldi[$c]['uscite'] += $riga['uscita'];
        }

        return [
            'righe' => $righe,
            'data_riferimento' => $dataRiferimento,
            'saldo_finale' => $saldoFinale,
            'saldi_per_cassa' => array_values($saldi),
        ];
    }

    /**
     * Il mastrino di un conto contabile — la seconda proiezione della stessa query (D10, D21).
     *
     * Stessa fonte del registro — le righe di scrittura di un conto, in ordine cronologico —
     * con due differenze che sono di natura, non di forma: la **restrizione** è un conto
     * contabile qualunque e non le casse reali; il **perimetro è per data**, non per
     * `esercizio_id`. Un estratto conto risponde a «cosa c'era sul conto a quella data»: è il
     * dettaglio della fotografia dello Stato patrimoniale (D16), e deve chiudere sul suo numero
     * sempre — anche quando una scrittura è datata fuori dal periodo del suo esercizio, che è il
     * caso che il controllo R2 segnala. Il registro di contabilità resta per `esercizio_id`
     * perché è un registro **numerato** dentro l'esercizio (D5, D15). Due nature, dichiarate.
     *
     * Il **riporto** è la somma di tutto ciò che precede il periodo, su tutti gli esercizi: è
     * da lì che il saldo progressivo parte, non da zero come nel registro. Il saldo è nel verso
     * naturale del conto — dare − avere per attivo e costo, avere − dare per passivo e ricavo —
     * cioè la stessa convenzione di `StatoPatrimonialeService::saldiPerConto()`: il numero in
     * fondo al mastrino è quello della riga da cui si è cliccato, senza conversioni.
     *
     * ⚠️ **Il saldo di apertura di una cassa non ancora registrato a giornale non è una riga e
     * non si somma.** La fotografia lo conta (`liquidita_non_contabilizzata`), il mastrino no:
     * la differenza si dichiara (`apertura_non_registrata`) invece di nasconderla in una riga
     * inventata. È lo stesso importo che il controllo di quadratura patrimoniale segnala.
     *
     * @param  array{data_da?:string,data_a?:string,search?:string}  $filtri
     * @return array<string, mixed>
     */
    public function mastrino(Esercizio $esercizio, ContoContabile $conto, array $filtri = []): array
    {
        ['dal' => $dal, 'al' => $al, 'stato' => $stato, 'riporto_al' => $riportoAl] = $this->periodo($esercizio);
        $dopoAl = Carbon::parse($al)->addDay()->format('Y-m-d');
        // Il riporto si ferma dove comincia il periodo — o dove finisce, se il periodo è vuoto
        // perché l'esercizio è nel futuro: così riporto + righe = fotografia ad `al`, sempre.
        $fineRiporto = min($dal, $dopoAl);

        $naturaDare = in_array($conto->tipo->value, ['attivo', 'costo'], true);
        $verso = fn (int $dare, int $avere) => $naturaDare ? $dare - $avere : $avere - $dare;

        $base = fn () => DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('rs.scrittura_id', '=', 'sc.id')
                    ->whereNull('sc.deleted_at');
            })
            ->where('rs.conto_contabile_id', $conto->id);

        // ⚠️ `< giorno` e mai `<= giorno`: su SQLite `data_competenza` è testo 'Y-m-d 00:00:00'
        // e il confronto con 'Y-m-d' esclude il giorno stesso — stessa trappola già pagata da
        // StatoPatrimonialeService. Con «minore del giorno dopo» le due basi rispondono uguale.
        $riporto = $base()
            ->where('sc.data_competenza', '<', $fineRiporto)
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare")
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere")
            ->first();
        $saldoRiporto = $verso((int) $riporto->dare, (int) $riporto->avere);

        // Postdatate: righe oltre `al`. Non ha senso solo per un esercizio chiuso — lì «dopo la
        // data di fine» è semplicemente l'esercizio successivo, non un'anomalia. Per uno futuro
        // sì: una riga fra oggi e la data di inizio non è né riporto né riga, e va contata.
        $postdatate = $stato !== 'chiuso'
            ? (int) $base()->where('sc.data_competenza', '>=', $dopoAl)->count()
            : 0;

        // D21.9: il caso speculare delle righe di altri esercizi — una riga di QUESTO esercizio
        // datata prima del suo inizio (una fattura pregressa) sta nel riporto. Contata, perché
        // «il riporto contiene gli esercizi precedenti» sarebbe falso in silenzio.
        $riportoProprie = (int) $base()
            ->where('sc.esercizio_id', $esercizio->id)
            ->where('sc.data_competenza', '<', $fineRiporto)
            ->count();

        $righe = $base()
            ->leftJoin('casse as ca', 'ca.id', '=', 'rs.cassa_id')
            ->leftJoin('esercizi as es', 'es.id', '=', 'sc.esercizio_id')
            ->where('sc.data_competenza', '>=', $dal)
            ->where('sc.data_competenza', '<', $dopoAl)
            ->orderBy('sc.data_competenza')
            ->orderBy('sc.id')
            ->orderBy('rs.id')
            ->select([
                'rs.id as riga_id',
                'sc.id as scrittura_id',
                'sc.esercizio_id',
                'es.nome as esercizio_nome',
                'sc.data_competenza',
                'sc.data_registrazione',
                'sc.numero_protocollo',
                'sc.causale',
                'sc.note',
                'sc.stato',
                DB::raw($this->stornataSql().' as stornata'),
                'sc.tipo_movimento',
                'ca.nome as cassa_nome',
                'rs.tipo_riga',
                'rs.importo',
                DB::raw($this->controparteSql().' as controparte'),
            ])
            ->get();

        $saldo = $saldoRiporto;
        $numero = 0;
        $altriEsercizi = 0;

        $tutte = $righe->map(function ($r) use (&$saldo, &$numero, &$altriEsercizi, $esercizio, $verso) {
            $dare = $r->tipo_riga === 'dare' ? (int) $r->importo : null;
            $avere = $r->tipo_riga === 'avere' ? (int) $r->importo : null;
            $saldo += $verso($dare ?? 0, $avere ?? 0);
            $numero++;

            $altroEsercizio = (int) $r->esercizio_id !== (int) $esercizio->id;
            if ($altroEsercizio) {
                $altriEsercizi++;
            }

            return [
                'id' => (int) $r->riga_id,
                'scrittura_id' => (int) $r->scrittura_id,
                'numero' => $numero,
                'data' => Carbon::parse($r->data_competenza)->format('Y-m-d'),
                'data_registrazione' => Carbon::parse($r->data_registrazione)->format('Y-m-d'),
                'protocollo' => $r->numero_protocollo,
                'descrizione' => $r->causale,
                'controparte' => $r->controparte,
                'stato' => $r->stato,
                'stornata' => (bool) $r->stornata,
                // Il nome dell'esercizio a cui la scrittura appartiene, solo quando non è quello
                // del periodo: è l'anomalia che R2 segnala, vista dal conto (D21.2).
                'altro_esercizio' => $altroEsercizio ? (string) $r->esercizio_nome : null,
                // Presente solo sulle righe di cassa: serve alla ricerca (stessi campi del
                // registro) e al pannello, non a una colonna.
                'cassa' => (string) ($r->cassa_nome ?? ''),
                'tipo_movimento' => $r->tipo_movimento,
                'tipo_movimento_label' => TipoMovimentoContabile::tryFrom((string) $r->tipo_movimento)?->label()
                    ?? $r->tipo_movimento,
                'nota' => $r->note,
                'dare' => $dare,
                'avere' => $avere,
                'saldo_progressivo' => $saldo,
            ];
        })->all();

        // Senza il nome della cassa fra i campi cercati: sul mastrino di una cassa ogni riga lo
        // porta, e una parola del nome avrebbe selezionato tutto dichiarandolo «ricerca».
        $mostrate = $this->applicaFiltri($tutte, $filtri, ['descrizione', 'protocollo', 'controparte']);

        // Come nel registro: il saldo dichiarato è quello alla data dell'ultima riga mostrata,
        // sul periodo intero — non il netto delle righe filtrate. Una `data_a` fuori dal periodo
        // si ritaglia dentro [riporto_al, al]: prima di riporto_al il riporto NON è il saldo a
        // quella data (revisione della beta.26), e il controller già scarta i filtri che cadono
        // tutti fuori dal periodo.
        $dataFiltroA = $this->dataFiltro($filtri['data_a'] ?? null);
        if ($dataFiltroA !== null) {
            $dataFiltroA = max(min($dataFiltroA, $al), $riportoAl);
        }
        $dataRiferimento = $mostrate !== []
            ? $mostrate[array_key_last($mostrate)]['data']
            : ($dataFiltroA
                ?? ($tutte !== [] ? $tutte[array_key_last($tutte)]['data'] : null));

        $saldoAllaData = $saldoRiporto;
        foreach ($tutte as $riga) {
            if ($dataRiferimento !== null && $riga['data'] > $dataRiferimento) {
                break;
            }
            $saldoAllaData = $riga['saldo_progressivo'];
        }

        // D21.9 (a): la liquidità di una cassa mai passata a giornale, dichiarata sopra il riporto.
        $aperturaNonRegistrata = (int) DB::table('casse')
            ->where('condominio_id', $conto->condominio_id)
            ->where('conto_contabile_id', $conto->id)
            ->sum('saldo_iniziale');

        return [
            'conto' => [
                'id' => (int) $conto->id,
                'codice' => $conto->codice,
                'nome' => $conto->nome,
                'tipo' => $conto->tipo->value,
                'natura_dare' => $naturaDare,
            ],
            'periodo' => ['dal' => $dal, 'al' => $al, 'stato' => $stato],
            // La data a cui il riporto è davvero il saldo: il giorno prima del periodo, oppure
            // oggi per un esercizio non ancora cominciato (dove il riporto è la fotografia a oggi).
            'riporto_al' => $riportoAl,
            'riporto' => $saldoRiporto,
            'riporto_dare' => (int) $riporto->dare,
            'riporto_avere' => (int) $riporto->avere,
            'righe' => $mostrate,
            'totale_righe' => count($tutte),
            'totale_dare' => (int) array_sum(array_column($mostrate, 'dare')),
            'totale_avere' => (int) array_sum(array_column($mostrate, 'avere')),
            'saldo_finale' => $tutte !== [] ? $tutte[array_key_last($tutte)]['saldo_progressivo'] : $saldoRiporto,
            'saldo_alla_data' => $saldoAllaData,
            'data_riferimento' => $dataRiferimento,
            'altri_esercizi' => $altriEsercizi,
            'riporto_proprie' => $riportoProprie,
            'postdatate' => $postdatate,
            'apertura_non_registrata' => $aperturaNonRegistrata,
        ];
    }

    /**
     * Il libro mastro dell'esercizio: i mastrini di tutti i conti foglia che hanno qualcosa da
     * dire nel periodo — righe, oppure un riporto diverso da zero — nell'ordine del piano dei
     * conti. È la stampa «tutti in una volta» chiesta da Vincenzo guardando la pagina: la stessa
     * proiezione di `mastrino()`, ripetuta, senza filtri (un libro mastro è intero per
     * definizione, e con un filtro sarebbe un estratto di ogni conto).
     *
     * @return array{periodo:array{dal:string,al:string,stato:string}, mastrini:array<int, array<string, mixed>>, righe_totali:int}
     */
    public function libroMastro(Esercizio $esercizio): array
    {
        $periodo = $this->periodo($esercizio);
        $mastrini = [];
        $righeTotali = 0;

        foreach ($this->contiPerSelettore($esercizio) as $voce) {
            $conto = ContoContabile::find($voce['id']);
            if ($conto === null) {
                continue;
            }
            $m = $this->mastrino($esercizio, $conto);
            if ($m['righe'] === [] && $m['riporto'] === 0 && $m['apertura_non_registrata'] === 0) {
                continue;
            }
            $mastrini[] = $m;
            $righeTotali += count($m['righe']);
        }

        return ['periodo' => $periodo, 'mastrini' => $mastrini, 'righe_totali' => $righeTotali];
    }

    /**
     * Il periodo del mastrino (D21.2): dal primo giorno dell'esercizio ad `al`, che è lo stesso
     * della fotografia dello Stato patrimoniale — oggi per l'esercizio aperto, la data di fine
     * per uno chiuso. Un esercizio non ancora cominciato ha un periodo vuoto e un riporto che
     * vale la fotografia a oggi.
     *
     * ⚠️ «Oggi» è quello dell'utente (`DateHelper::oggiUtente()`, Europe/Rome), non `Carbon::today()`
     * in UTC: fra le 00:00 e le 02:00 ora italiana le due pagine avrebbero tagliato a giorni
     * diversi, e il mastrino non avrebbe più chiuso sul numero da cui si è cliccato. Trovato
     * dalla revisione della beta.26 — la stessa lezione già scritta nel riepilogo finanziario.
     *
     * @return array{dal:string, al:string, stato:string, riporto_al:string}
     */
    public function periodo(Esercizio $esercizio): array
    {
        $oggi = DateHelper::oggiUtente();
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $fine = $esercizio->data_fine->format('Y-m-d');
        $stato = $dal > $oggi ? 'futuro' : ($fine < $oggi ? 'chiuso' : 'aperto');
        $al = $stato === 'chiuso' ? $fine : $oggi;
        $riportoAl = $stato === 'futuro' ? $al : Carbon::parse($dal)->subDay()->format('Y-m-d');

        return ['dal' => $dal, 'al' => $al, 'stato' => $stato, 'riporto_al' => $riportoAl];
    }

    /**
     * Le righe sorelle di alcune scritture — la contropartita vera del mastrino, anche quando
     * sono più di una — per il pannello della riga. Si chiede solo per le righe a video, non
     * per tutto il periodo: è un dettaglio che si apre una riga alla volta.
     *
     * @param  array<int>  $scrittureIds
     * @return array<int, array<int, array{conto:string, codice:string, dare:?int, avere:?int, cassa:?string}>>
     */
    public function sorelle(array $scrittureIds): array
    {
        if ($scrittureIds === []) {
            return [];
        }

        $righe = DB::table('righe_scritture as rs')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->leftJoin('casse as ca', 'ca.id', '=', 'rs.cassa_id')
            ->whereIn('rs.scrittura_id', $scrittureIds)
            ->orderBy('rs.scrittura_id')
            // Dare prima di avere, come nel Libro Giornale — per indice esplicito: su MySQL
            // `tipo_riga` è un ENUM e `ORDER BY DESC` seguirebbe l'indice (avere prima), non il testo.
            ->orderByRaw("CASE WHEN rs.tipo_riga = 'dare' THEN 0 ELSE 1 END")
            ->orderBy('rs.id')
            ->select(['rs.scrittura_id', 'rs.id', 'cc.codice', 'cc.nome', 'rs.tipo_riga', 'rs.importo', 'ca.nome as cassa'])
            ->get();

        $perScrittura = [];
        foreach ($righe as $r) {
            $perScrittura[(int) $r->scrittura_id][] = [
                'id' => (int) $r->id,
                'codice' => $r->codice,
                'conto' => $r->nome,
                'dare' => $r->tipo_riga === 'dare' ? (int) $r->importo : null,
                'avere' => $r->tipo_riga === 'avere' ? (int) $r->importo : null,
                'cassa' => $r->cassa,
            ];
        }

        return $perScrittura;
    }

    /**
     * I conti contabili foglia del condominio con il numero di righe nel periodo del mastrino:
     * il selettore in testa alla pagina (D21.1). I mastri — chi ha figli — non hanno un
     * mastrino: le righe stanno sulle foglie.
     *
     * @return array<int, array{id:int, codice:string, nome:string, tipo:string, righe:int}>
     */
    public function contiPerSelettore(Esercizio $esercizio): array
    {
        ['dal' => $dal, 'al' => $al] = $this->periodo($esercizio);
        $dopoAl = Carbon::parse($al)->addDay()->format('Y-m-d');

        $conteggi = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at');
            })
            ->where('sc.condominio_id', $esercizio->condominio_id)
            ->where('sc.data_competenza', '>=', $dal)
            ->where('sc.data_competenza', '<', $dopoAl)
            ->groupBy('rs.conto_contabile_id')
            ->select('rs.conto_contabile_id', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'conto_contabile_id');

        return ContoContabile::query()
            ->where('condominio_id', $esercizio->condominio_id)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('conti_contabili as figli')
                    ->whereColumn('figli.parent_id', 'conti_contabili.id')
                    ->whereNull('figli.deleted_at');
            })
            ->orderBy('codice')
            ->get(['id', 'codice', 'nome', 'tipo'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'codice' => $c->codice,
                'nome' => $c->nome,
                'tipo' => $c->tipo->value,
                'righe' => (int) ($conteggi[$c->id] ?? 0),
            ])
            ->all();
    }

    /**
     * «Stornata» come espressione SQL, condivisa dalle proiezioni.
     *
     * ⚠️ **«Stornata» non è `stato = 'annullata'`, e la differenza è l'art. 2219.** Solo lo
     * storno di un incasso marca l'originale `annullata`; giroconto, regolazione immediata,
     * pagamento fornitore e F24 non lo fanno — scrivono la scrittura contraria con
     * `scrittura_padre_id` e lasciano l'originale `registrata`. Guardando lo stato, tre storni
     * su quattro uscivano in stampa senza marca sotto una legenda che promette il contrario. Il
     * segnale che vale per tutti: esiste una figlia di tipo storno. `RETTIFICA` non entra nel
     * criterio perché `RiallineaFondiService` la usa per correzioni che non sono storni; gli
     * incassi restano coperti dallo stato. Trovato dalla revisione della beta.24.
     * ⚠️ Elenco esplicito, non `LIKE 'storno_%'`: `storno_credito` è la quota pagata con un
     * credito, figlia di un incasso VALIDO — col LIKE quell'incasso usciva «stornato» in stampa
     * (trovato il 12/09/2026 sul condominio «Via roma»).
     */
    private function stornataSql(): string
    {
        $storni = implode(',', array_map(fn ($t) => "'".$t."'", TipoMovimentoContabile::storniDiScrittura()));

        // Terzo ramo (revisione della beta.26): lo storno di una FATTURA non scrive
        // `scrittura_padre_id` (la nota di credito generata non lo porta) e lascia l'originale
        // `registrata`: sul registro non si vedeva, perché una fattura non ha righe di cassa; sul
        // mastrino dei fornitori e dei costi la fattura stornata usciva senza marca. Il segnale
        // che il documento lascia è `stato_pagamento = 'stornata'` sulla fattura collegata.
        return "CASE WHEN sc.stato = 'annullata' OR EXISTS (
                    SELECT 1 FROM scritture_contabili f
                    WHERE f.scrittura_padre_id = sc.id
                      AND f.tipo_movimento IN (".$storni.")
                      AND f.deleted_at IS NULL
                ) OR EXISTS (
                    SELECT 1 FROM fattura_scrittura fs
                    JOIN fatture_passive fp ON fp.id = fs.fattura_passiva_id
                    WHERE fs.scrittura_contabile_id = sc.id
                      AND fs.tipo = 'competenza'
                      AND fp.stato_pagamento = 'stornata'
                ) THEN 1 ELSE 0 END";
    }

    /**
     * Tutte le righe dell'esercizio, numerate e con i saldi progressivi: il registro di legge,
     * prima di qualunque filtro di visualizzazione.
     *
     * @return array<int, array<string, mixed>>
     */
    private function calcola(Esercizio $esercizio): array
    {
        $righe = $this->queryBase($esercizio)
            ->orderBy('sc.data_competenza')
            ->orderBy('sc.id')
            ->orderBy('rs.id')
            ->select([
                'rs.id as riga_id',
                'sc.id as scrittura_id',
                'sc.data_competenza',
                'sc.data_registrazione',
                'sc.numero_protocollo',
                'sc.causale',
                'sc.note',
                'sc.stato',
                // Il criterio di «stornata» è uno solo per tutte le proiezioni: vedi stornataSql().
                DB::raw($this->stornataSql().' as stornata'),
                'sc.tipo_movimento',
                'ca.nome as cassa_nome',
                'rs.tipo_riga',
                'rs.importo',
                DB::raw($this->controparteSql().' as controparte'),
            ])
            ->get();

        $saldo = 0;
        $numero = 0;

        // Un secondo saldo, uno per cassa. Serve a una domanda che quello complessivo non può
        // rispondere — «e su QUESTO conto quanto c'era?» — quando le casse reali sono più di una
        // (banca + contanti, o due conti bancari). Non è una colonna: vive nel pannello che si
        // apre sulla riga, perché è una domanda che si fa su un movimento, non su tutti.
        $saldiPerCassa = [];

        $tutte = $righe->map(function ($r) use (&$saldo, &$numero, &$saldiPerCassa) {
            $entrata = $r->tipo_riga === 'dare' ? (int) $r->importo : null;
            $uscita = $r->tipo_riga === 'avere' ? (int) $r->importo : null;
            $saldo += ($entrata ?? 0) - ($uscita ?? 0);

            $chiaveCassa = (string) $r->cassa_nome;
            $saldiPerCassa[$chiaveCassa] = ($saldiPerCassa[$chiaveCassa] ?? 0) + ($entrata ?? 0) - ($uscita ?? 0);
            $numero++;

            $dataCompetenza = \Carbon\Carbon::parse($r->data_competenza);
            $dataRegistrazione = \Carbon\Carbon::parse($r->data_registrazione);

            return [
                'id' => (int) $r->riga_id,
                'scrittura_id' => (int) $r->scrittura_id,
                // D5: il numero dell'operazione non è una colonna a database, si calcola in
                // lettura sull'ordine cronologico dell'esercizio. La fonte citata al §2 elenca
                // fra i dati minimi del registro proprio il «numero operazione», prima ancora
                // della data. Conseguenza accettata da D5: un movimento annotato in ritardo —
                // che la norma consente entro trenta giorni — rinumera quelli successivi.
                'numero' => $numero,
                'data' => $dataCompetenza->format('Y-m-d'),
                'data_annotazione' => $dataRegistrazione->format('Y-m-d'),
                // D9: lo scarto si misura, non si nasconde — la norma dà trenta giorni.
                // ⚠️ **Il verso conta: da Carbon 3 `diffInDays()` ha il segno.** `a->diffInDays(b)`
                // vale `b − a`: qui deve essere «annotazione meno movimento», positivo quando
                // l'annotazione è in ritardo. Scritto al contrario dava sempre un numero
                // negativo e il flag non scattava mai — trovato dalla revisione della beta.24 con
                // 73 giorni di ritardo e `false`. Un movimento postdatato (annotato PRIMA della
                // sua data effettiva) dà un valore negativo e resta correttamente non marcato:
                // non è un'annotazione tardiva.
                'oltre_trenta_giorni' => $dataCompetenza->diffInDays($dataRegistrazione) > 30,
                'protocollo' => $r->numero_protocollo,
                'descrizione' => $r->causale,
                'controparte' => $r->controparte,
                'stato' => $r->stato,
                'stornata' => (bool) $r->stornata,
                // Quale cassa reale ha effettivamente mosso il denaro — non deducibile dal
                // resto della riga quando il condominio ne ha più di una (due conti bancari,
                // banca + contanti): senza questo campo un saldo combinato in calo non direbbe
                // quale dei due è scoperto. Richiesto da Vincenzo aprendo la pagina a video.
                'cassa' => $r->cassa_nome,
                'tipo_movimento' => $r->tipo_movimento,
                'tipo_movimento_label' => TipoMovimentoContabile::tryFrom((string) $r->tipo_movimento)?->label()
                    ?? $r->tipo_movimento,
                'nota' => $r->note,
                'entrata' => $entrata,
                'uscita' => $uscita,
                'saldo_progressivo' => $saldo,
                'saldo_cassa_progressivo' => $saldiPerCassa[$chiaveCassa],
            ];
        })->all();

        return $tutte;
    }

    /**
     * Il filtro di visualizzazione, applicato DOPO la numerazione e i saldi — mai dentro la
     * query, per la ragione scritta nel docblock di `registro()`.
     *
     * Gli stessi filtri valgono per lo schermo e per la stampa: se divergessero, il PDF
     * mostrerebbe un registro diverso da quello che l'amministratore ha guardato.
     *
     * @param  array<int, array<string, mixed>>  $righe
     * @param  array{data_da?:string,data_a?:string,search?:string}  $filtri
     * @return array<int, array<string, mixed>>
     */
    private function applicaFiltri(array $righe, array $filtri, array $campi = ['descrizione', 'protocollo', 'controparte', 'cassa']): array
    {
        $da = ! empty($filtri['data_da']) ? $this->dataFiltro($filtri['data_da']) : null;
        $a = ! empty($filtri['data_a']) ? $this->dataFiltro($filtri['data_a']) : null;
        $cerca = ! empty($filtri['search']) && is_string($filtri['search'])
            ? mb_strtolower(trim($filtri['search']))
            : null;

        if ($da === null && $a === null && $cerca === null) {
            return $righe;
        }

        return array_values(array_filter($righe, function (array $riga) use ($da, $a, $cerca, $campi) {
            if ($da !== null && $riga['data'] < $da) {
                return false;
            }

            if ($a !== null && $riga['data'] > $a) {
                return false;
            }

            if ($cerca !== null) {
                $testo = mb_strtolower(implode(' ', array_map(fn ($c) => (string) ($riga[$c] ?? ''), $campi)));

                if (! str_contains($testo, $cerca)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Una data di filtro in `Y-m-d`, o null se non è una data.
     *
     * Non lancia mai: il valore arriva dalla query string e può essere qualunque cosa — `pippo`,
     * un array, niente. Stessa tolleranza che l'elenco del Libro Giornale aveva già, e che la
     * Fase 1-bis della beta.23 aveva dovuto imporre alla sua stampa dopo un 500.
     */
    private function dataFiltro($valore): ?string
    {
        return self::dataValida($valore);
    }

    /**
     * L'unica forma ammessa per una data di filtro è `Y-m-d`, quella dei campi `<input type="date">`.
     *
     * ⚠️ **`Carbon::parse()` leggeva «10/01/2026» come 1° ottobre, senza errore.** Un indirizzo
     * battuto a mano in formato italiano produceva un registro filtrato su un altro mese, con la
     * pagina che dichiarava il filtro chiesto. Qui una data che non è `Y-m-d` — o che lo è ma non
     * esiste, come il 31/02 — vale «nessun filtro», e il controller rimanda indietro solo i
     * filtri davvero applicati, così «(parziale)» compare quando il registro lo è. Pubblica e
     * statica perché la stampa deve scrivere nella fascia la stessa data che il registro ha usato.
     */
    public static function dataValida(mixed $valore): ?string
    {
        if (! is_string($valore) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($valore), $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($valore) : null;
    }

    /** Il perimetro del registro: righe di cassa reale dell'esercizio, in ordine cronologico. */
    private function queryBase(Esercizio $esercizio)
    {
        return DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('rs.scrittura_id', '=', 'sc.id')
                    ->whereNull('sc.deleted_at');
            })
            ->join('casse as ca', 'ca.id', '=', 'rs.cassa_id')
            ->where('sc.esercizio_id', $esercizio->id)
            ->whereIn('ca.tipo', self::CASSE_REALI)
            // La riga vera di un accantonamento verso una partizione (D15): esclusa
            // guardando le sorelle, non il tipo_movimento — vedi il docblock della classe.
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('righe_scritture as rs2')
                    ->join('casse as ca2', 'ca2.id', '=', 'rs2.cassa_id')
                    ->whereColumn('rs2.scrittura_id', 'rs.scrittura_id')
                    ->whereColumn('rs2.id', '!=', 'rs.id')
                    ->whereIn('ca2.tipo', self::CASSE_PARTIZIONE);
            });
    }

    /**
     * La controparte, risolta con quattro fonti in ordine di priorità — mai un JOIN
     * diretto sulla riga di cassa, perché nessuna delle quattro fonti vive lì (vedi
     * docblock della classe). Ogni fonte è una sottoquery scalare, non un JOIN: una
     * scrittura con più righe di debito/credito (co-intestatari, quote multiple)
     * moltiplicherebbe altrimenti la riga di cassa.
     *
     * ⚠️ **Ogni sottoquery ordina esplicitamente, anche con `LIMIT 1`.** Trovato dalla Fase
     * 1-bis: senza un `ORDER BY`, quale riga vince fra più corrispondenze è deciso dal piano
     * di esecuzione del motore, non dalla query — e SQLite (i test) e MySQL (la produzione)
     * non garantiscono di scegliere la stessa. Le ultime due sono stabili sull'id della riga.
     *
     * ⚠️ **Le prime due NON usano `WHERE ... OR ... ORDER BY (x = sc.id) DESC` per preferire il
     * collegamento diretto a quello del padre** — la forma naturale, provata per prima — perché
     * **SQLite rifiuta la query**: «no such column: sc.id», un parse-error, non un risultato
     * sbagliato. Riferire due volte la stessa colonna esterna dentro una sottoquery scalare
     * (una volta nel `WHERE`, di nuovo nell'`ORDER BY`) supera qualcosa che il planner di SQLite
     * non risolve in questa posizione — MySQL lo accetta, i test (SQLite) no. Misurato isolando
     * la query col client `sqlite3` diretto, fuori da Eloquent, per togliere ogni dubbio che
     * fosse un problema di binding. La forma che regge sono **due sottoquery scalari separate**,
     * ciascuna con un solo confronto: `COALESCE` prende la prima non nulla, cioè il collegamento
     * diretto se c'è, il padre altrimenti — stesso risultato, senza il doppio riferimento che
     * SQLite non digerisce.
     */
    /*
     * ⚠️ **La regolazione immediata non scrive il fornitore da nessuna parte: lo «tagga» con
     * l'anagrafica del suo referente** (`RegistraRegolazioneImmediataAction`, stesso pattern
     * delle fatture) — cioè una PERSONA. Letto dal solo ramo dell'anagrafica sorella, il registro
     * stampava «Mario Rossi» dove il pagato era «Mario Rossi Impianti s.r.l.»: il nome di una
     * persona fisica su un documento da assemblea, al posto della società. Il ramo aggiunto
     * risale dal referente al fornitore attraverso `anagrafica_fornitore` — la stessa strada
     * che l'azione ha fatto all'andata — ma **solo se il referente appartiene a un fornitore
     * solo**: chi tiene la contabilità di tre ditte non decide per noi quale sia stata pagata,
     * e in quel caso il ramo torna NULL e vale il nome della persona, come prima. Ristretto ai
     * due tipi di movimento in cui il tag ha quel significato. Revisione della beta.24.
     */
    /*
     * ➕ 13/09/2026, beta.26 (mastrino): **la fattura risolve il suo fornitore da `fattura_scrittura`.**
     * Sul registro non serviva — una riga di cassa non appartiene mai a una scrittura di competenza —
     * ma sul mastrino di «Debiti v/Fornitori» la riga della fattura usciva senza controparte, che
     * per un mastrino fornitori è la colonna che conta. Il ramo sta dopo pagamenti ed Erario e prima
     * delle sorelle: per un pagamento vince comunque `pagamenti_fornitori`, per una fattura (e per
     * il suo storno, via padre) risponde il documento.
     */
    private function controparteSql(): string
    {
        return "COALESCE(
            (SELECT f.ragione_sociale
                FROM pagamenti_fornitori pf
                JOIN fornitori f ON f.id = pf.fornitore_id
                WHERE pf.scrittura_contabile_id = sc.id
                LIMIT 1),
            (SELECT f.ragione_sociale
                FROM pagamenti_fornitori pf
                JOIN fornitori f ON f.id = pf.fornitore_id
                WHERE pf.scrittura_contabile_id = sc.scrittura_padre_id
                LIMIT 1),
            (SELECT 'Erario'
                FROM deleghe_f24 f24
                WHERE f24.scrittura_contabile_id = sc.id
                LIMIT 1),
            (SELECT 'Erario'
                FROM deleghe_f24 f24
                WHERE f24.scrittura_contabile_id = sc.scrittura_padre_id
                LIMIT 1),
            (SELECT f.ragione_sociale
                FROM fattura_scrittura fs
                JOIN fatture_passive fp ON fp.id = fs.fattura_passiva_id
                JOIN fornitori f ON f.id = fp.fornitore_id
                WHERE fs.scrittura_contabile_id = sc.id
                ORDER BY fs.id ASC
                LIMIT 1),
            (SELECT f.ragione_sociale
                FROM fattura_scrittura fs
                JOIN fatture_passive fp ON fp.id = fs.fattura_passiva_id
                JOIN fornitori f ON f.id = fp.fornitore_id
                WHERE fs.scrittura_contabile_id = sc.scrittura_padre_id
                ORDER BY fs.id ASC
                LIMIT 1),
            (SELECT CASE WHEN COUNT(DISTINCT f.id) = 1 THEN MIN(f.ragione_sociale) END
                FROM righe_scritture rs5
                JOIN anagrafica_fornitore af ON af.anagrafica_id = rs5.anagrafica_id
                JOIN fornitori f ON f.id = af.fornitore_id
                WHERE rs5.scrittura_id = rs.scrittura_id
                  AND sc.tipo_movimento IN ('regolazione_immediata', 'storno_regolazione_immediata')),
            (SELECT an0.nome
                FROM anagrafiche an0
                WHERE an0.id = rs.anagrafica_id),
            (SELECT an.nome
                FROM righe_scritture rs3
                JOIN anagrafiche an ON an.id = rs3.anagrafica_id
                WHERE rs3.scrittura_id = rs.scrittura_id AND rs3.id != rs.id
                ORDER BY rs3.id ASC
                LIMIT 1),
            (SELECT ca3.nome
                FROM righe_scritture rs4
                JOIN casse ca3 ON ca3.id = rs4.cassa_id
                WHERE rs4.scrittura_id = rs.scrittura_id AND rs4.id != rs.id
                ORDER BY rs4.id ASC
                LIMIT 1)
        )";
    }
}

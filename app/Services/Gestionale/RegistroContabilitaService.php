<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Models\Esercizio;
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
                // ⚠️ **«Stornata» non è `stato = 'annullata'`, e la differenza è l'art. 2219.**
                // Solo lo storno di un incasso marca l'originale `annullata`; giroconto,
                // regolazione immediata, pagamento fornitore e F24 non lo fanno — scrivono la
                // scrittura contraria con `scrittura_padre_id` e lasciano l'originale
                // `registrata`. Guardando lo stato, tre storni su quattro uscivano in stampa
                // senza marca sotto una legenda che promette il contrario. Il segnale che vale
                // per tutti: esiste una figlia di tipo `storno_*`. `RETTIFICA` non entra nel
                // criterio perché `RiallineaFondiService` la usa per correzioni che non sono
                // storni; gli incassi restano coperti dallo stato. Trovato dalla revisione della
                // beta.24 fra i reperti che il tetto aveva lasciato non verificati.
                DB::raw("CASE WHEN sc.stato = 'annullata' OR EXISTS (
                    SELECT 1 FROM scritture_contabili f
                    WHERE f.scrittura_padre_id = sc.id
                      AND f.tipo_movimento LIKE 'storno_%'
                      AND f.deleted_at IS NULL
                ) THEN 1 ELSE 0 END as stornata"),
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
    private function applicaFiltri(array $righe, array $filtri): array
    {
        $da = ! empty($filtri['data_da']) ? $this->dataFiltro($filtri['data_da']) : null;
        $a = ! empty($filtri['data_a']) ? $this->dataFiltro($filtri['data_a']) : null;
        $cerca = ! empty($filtri['search']) && is_string($filtri['search'])
            ? mb_strtolower(trim($filtri['search']))
            : null;

        if ($da === null && $a === null && $cerca === null) {
            return $righe;
        }

        return array_values(array_filter($righe, function (array $riga) use ($da, $a, $cerca) {
            if ($da !== null && $riga['data'] < $da) {
                return false;
            }

            if ($a !== null && $riga['data'] > $a) {
                return false;
            }

            if ($cerca !== null) {
                $campi = mb_strtolower(implode(' ', [
                    $riga['descrizione'],
                    $riga['protocollo'],
                    (string) $riga['controparte'],
                    $riga['cassa'],
                ]));

                if (! str_contains($campi, $cerca)) {
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
            (SELECT CASE WHEN COUNT(DISTINCT f.id) = 1 THEN MIN(f.ragione_sociale) END
                FROM righe_scritture rs5
                JOIN anagrafica_fornitore af ON af.anagrafica_id = rs5.anagrafica_id
                JOIN fornitori f ON f.id = af.fornitore_id
                WHERE rs5.scrittura_id = rs.scrittura_id AND rs5.id != rs.id
                  AND sc.tipo_movimento IN ('regolazione_immediata', 'storno_regolazione_immediata')),
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

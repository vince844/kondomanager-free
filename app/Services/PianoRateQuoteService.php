<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services;

use App\Services\Gestionale\Debiti\Scadenza;

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Services\Riparto\QuotaDellaChiamata;
use Illuminate\Support\Collection;

class PianoRateQuoteService
{
    /**
     * Helper privato: Scansiona il piano per capire se include saldi.
     */
    private function determinaSePianoUsaSaldi(PianoRate $pianoRate): bool
    {
        // C'era un `take(50)` **senza `orderBy`**, e non era una questione di prestazioni: le
        // 50 righe erano quelle che il database restituiva per caso. Su un piano con più di
        // cinquanta quote la risposta dipendeva da quali capitavano nel campione — un piano
        // che usa i saldi poteva rispondere «no» perché nessuna delle righe pescate ne
        // conteneva uno. Una domanda a sì/no non si risponde a campione.
        //
        // Il cursore legge in streaming e il `return true` esce alla prima riga utile, che è
        // il caso frequente; quando la risposta è «no» il costo è quello di saperlo davvero.
        // Non è un `exists()` in SQL perché la condizione vive dentro una colonna JSON con
        // **due forme storiche** — `importi.saldo_usato` e `audit.saldo_usato` — e una query
        // per percorso JSON si comporterebbe in modo diverso fra MySQL e lo SQLite dei test:
        // esattamente il tipo di divergenza fra schema di prova e schema reale che la beta.34
        // ha pagato.
        $quoteCampione = $pianoRate->rate()
            ->join('rate_quote', 'rate.id', '=', 'rate_quote.rata_id')
            ->whereNotNull('rate_quote.regole_calcolo')
            ->select('rate_quote.regole_calcolo')
            ->cursor();

        foreach ($quoteCampione as $riga) {
            $json = $riga->regole_calcolo;
            // Qui json_decode serve ancora perché pluck() su query builder restituisce stringhe grezze
            $snapshot = is_array($json) ? $json : json_decode($json, true);
            if (isset($snapshot['importi']['saldo_usato']) && $snapshot['importi']['saldo_usato'] != 0) {
                return true;
            }
            if (isset($snapshot['audit']['saldo_usato']) && $snapshot['audit']['saldo_usato'] != 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Quanto il piano ha **davvero messo in rata** come spesa di gestione, in centesimi.
     *
     * Somma `regole_calcolo.importi.quota_pura_gestione`, cioè la sola componente di spesa dello
     * snapshot: il pregresso non entra **per costruzione**, e non c'è niente da sottrarre.
     *
     * ## Perché sottrarre i saldi era la strada sbagliata
     *
     * Il cruscotto della pagina del piano faceva `totaleTeorico − saldiPregressi`, e quella
     * sottrazione è esattamente ciò che si è rotto sui **saldi solidali**: una riga con
     * `anagrafica_id` nullo non compare in un'aggregazione per persona, quindi mancava dalla
     * sottrazione e il badge «Disallineato» restava acceso per sempre.
     *
     * Leggere la componente pura toglie il problema alla radice invece di rattopparlo — tanto
     * che la correzione del 10/08/2026 (`totaleSaldiPregressiCents()`) è stata **cancellata**
     * quando questo metodo l'ha resa inutile.
     *
     * ## Il ripiego, e quanto vale
     *
     * Le quote senza `quota_pura_gestione` nello snapshot contano il loro `importo`, escluse
     * quelle di Rata Zero e di tipo `saldo_iniziale` — altrimenti il pregresso rientrerebbe
     * dalla finestra. È il comportamento che il cruscotto ha da sempre e va conservato: sui dati
     * reali dell'11/08/2026 non si attiva mai (**0 quote su 369** sono prive della chiave), ma
     * esiste per i piani generati prima che lo snapshot la portasse.
     *
     * ## Nel database, senza caricare le quote
     *
     * Le due somme si fanno con due letture aggregate, una per la componente pura e una per il ripiego, senza caricare le quote come modelli: la copertura
     * del bilancio della dashboard chiede il verdetto per ogni piano della gestione, e a 3.000 persone × 12 rate erano 36.000 quote in memoria per una cifra
     * (Coda 520). La regola è la stessa di prima, e la prova differenziale lo controlla su ogni forma dello snapshot (`AllineamentoSommeNelDatabaseTest`):
     *
     * - la componente pura c'è se il percorso `importi.quota_pura_gestione` esiste e non è `null`, come `isset()` (il `whereNotNull` di un percorso JSON
     *   di Laravel, che su MySQL esclude anche il `null` scritto nel JSON); uno snapshot che non è un oggetto non ha il percorso;
     * - la cifra si tronca all'intero come `(int)`, e la scritta come testo conta per il suo numero;
     * - il ripiego conta l'importo delle quote senza la componente pura, fuori la rata 0 e le quote di tipo `saldo_iniziale`.
     */
    public function totalePuroGeneratoCents(PianoRate $pianoRate): int
    {
        $db = $pianoRate->getConnection();
        $percorso = 'rate_quote.regole_calcolo->importi->quota_pura_gestione';
        $quote = fn () => $db->table('rate_quote')
            ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate.piano_rate_id', $pianoRate->id);

        $selettore = $quote()->getGrammar()->wrap($percorso);
        $intero = match ($db->getDriverName()) {
            'sqlite' => 'INTEGER',
            'pgsql' => 'BIGINT',
            default => 'SIGNED',
        };
        $pura = (int) $quote()->whereNotNull($percorso)->sum($db->raw("CAST({$selettore} AS {$intero})"));

        $ripiego = (int) $quote()->whereNull($percorso)
            ->where(fn ($q) => $q->whereNull('rate.numero_rata')->orWhere('rate.numero_rata', '<>', 0))
            ->where(fn ($q) => $q->whereNull('rate_quote.tipo')->orWhere('rate_quote.tipo', '<>', 'saldo_iniziale'))
            ->sum('rate_quote.importo');

        return $pura + $ripiego;
    }

    /**
     * Quanto il piano chiede alle persone e quanto accredita loro, in centesimi e in valore assoluto.
     *
     * Le voci di spesa sommano alle spese, quelle di entrata alle entrate: il segno di ogni voce è quello con cui il motore la
     * distribuisce (`Conto::ripartoPerSegno()`, letto alla data del piano). Un capitolo i cui sottoconti sono di tipo diverso divide
     * l'importo della pivot fra spese ed entrate come lo divide il motore. La pivot `piano_rate_capitoli` tiene l'importo positivo,
     * come lo scrive la pagina; il segno lo rifà chi legge. Un piano straordinario chiede la somma delle fatture collegate e non ha
     * entrate.
     *
     * `id_spese` sono le voci con una parte di spesa: il già versato si legge solo su quelle. Per la riga di un capitolo senza tabelle sono anche i suoi sottoconti
     * con una parte di spesa, quelli che il motore guarda (`sottocontiConParteDiSpesa`): il già versato sta su di loro, non sul capitolo.
     *
     * @return array{spese: int, entrate: int, id_spese: int[]}
     */
    public function totaliPerSegnoCents(PianoRate $pianoRate): array
    {
        if ($pianoRate->tipo === 'straordinario') {
            $pianoRate->loadMissing('fattureStraordinarie');

            return [
                'spese' => (int) $pianoRate->fattureStraordinarie->sum(fn ($fattura) => $fattura->pivot->importo_collegato),
                'entrate' => 0,
                'id_spese' => [],
            ];
        }

        $pianoRate->loadMissing(['capitoli.tabelleMillesimali', 'capitoli.sottoconti.tabelleMillesimali']);

        $spese = 0;
        $entrate = 0;
        $idSpese = [];

        $conRiga = array_fill_keys($pianoRate->capitoli->pluck('id')->map(fn ($id) => (int) $id)->all(), true);

        foreach ($pianoRate->capitoli as $capitolo) {
            // Un capitolo senza cifra nel piano il motore lo porta con il suo importo e con i sottoconti, ognuno con il suo.
            if ($capitolo->pivot->importo === null && $capitolo->sottoconti->isNotEmpty()) {
                $parti = self::partiSenzaCifra($capitolo, $conRiga);
                $spese += $parti['spese'];
                $entrate += $parti['entrate'];
                array_push($idSpese, ...$parti['id_spese']);

                continue;
            }

            $parti = $capitolo->ripartoPerSegno((int) ($capitolo->pivot->importo ?? $capitolo->importo), $pianoRate->created_at);

            $spese += $parti['spese'];
            $entrate += $parti['entrate'];

            if ($parti['spese'] > 0 || $capitolo->segno($pianoRate->created_at) > 0) {
                $idSpese[] = $capitolo->id;
            }

            // Il motore passa la copia ai sottoconti solo se è positiva: con la copia a zero salta il capitolo, e il già versato dei sottoconti non entra.
            if ($capitolo->tabelleMillesimali->isEmpty() && $capitolo->sottoconti->isNotEmpty() && (int) ($capitolo->pivot->importo ?? $capitolo->importo) > 0) {
                array_push($idSpese, ...$this->sottocontiConParteDiSpesa($capitolo, $pianoRate->created_at));
            }
        }

        return ['spese' => $spese, 'entrate' => $entrate, 'id_spese' => array_values(array_unique($idSpese))];
    }

    /**
     * Le spese e le entrate di una voce che il piano tiene senza cifra, come le chiede il motore (`CalcoloQuoteService::processaConti`, il ramo senza
     * copia): l'importo della voce dal lato del suo tipo, in valore assoluto, più i sottoconti a ogni livello, ognuno con il suo importo. Un sottoconto che
     * ha la sua riga nel piano non si somma qui: lo conta la sua riga. `id_spese` sono la voce e i sottoconti con una parte di spesa (il già versato sta
     * su di loro).
     *
     * @param  array<int, true>  $conRiga  le voci che hanno una riga nel piano
     * @return array{spese: int, entrate: int, id_spese: list<int>}
     */
    public static function partiSenzaCifra(Conto $voce, array $conRiga = []): array
    {
        $parti = Conto::partiDelTipo((string) ($voce->tipo ?? 'spesa'), (int) $voce->importo);
        $idSpese = Conto::segnoDelTipo((string) ($voce->tipo ?? 'spesa')) > 0 ? [(int) $voce->id] : [];

        foreach ($voce->sottoconti as $figlio) {
            if (isset($conRiga[(int) $figlio->id])) {
                continue;
            }
            $delFiglio = self::partiSenzaCifra($figlio, $conRiga);
            $parti['spese'] += $delFiglio['spese'];
            $parti['entrate'] += $delFiglio['entrate'];
            array_push($idSpese, ...$delFiglio['id_spese']);
        }

        return ['spese' => $parti['spese'], 'entrate' => $parti['entrate'], 'id_spese' => $idSpese];
    }

    /**
     * I sottoconti con una parte di spesa che il motore guarda sotto la riga di un capitolo senza tabelle con la copia positiva (il chiamante lo controlla):
     * quelli nati fino alla creazione del piano o, se nessuno lo è, tutti quelli di adesso (come `Conto::ripartoPerSegno`); un sottoconto senza tabelle e con dei sottoconti suoi passa la parte ai suoi, a ogni livello.
     * Il già versato di una voce con le tabelle sta su di lei, e il motore lo toglie dalle sue quote.
     *
     * @return list<int>
     */
    private function sottocontiConParteDiSpesa(Conto $capitolo, ?\Carbon\CarbonInterface $creatoIl): array
    {
        $figli = $creatoIl === null
            ? $capitolo->sottoconti
            : $capitolo->sottoconti->filter(fn (Conto $s) => $s->created_at === null || $s->created_at->lte($creatoIl));
        if ($figli->isEmpty()) {
            $figli = $capitolo->sottoconti;
        }

        $ids = [];
        foreach ($figli as $figlio) {
            if ($figlio->tabelleMillesimali->isEmpty() && $figlio->sottoconti->isNotEmpty()) {
                array_push($ids, ...$this->sottocontiConParteDiSpesa($figlio, $creatoIl));
            } elseif ($figlio->segno($creatoIl) > 0) {
                $ids[] = (int) $figlio->id;
            }
        }

        return $ids;
    }

    /**
     * Quanto il piano **dovrebbe** mettere in rata: le spese dei capitoli collegati meno le loro entrate, o le fatture se è
     * straordinario.
     *
     * Con la sola entrata il netto è negativo (le persone ricevono un credito) e resta tale: il tetto a zero vale per le spese.
     */
    public function totaleAttesoCents(PianoRate $pianoRate): int
    {
        $totali = $this->totaliPerSegnoCents($pianoRate);

        if ($pianoRate->tipo === 'straordinario') {
            return $totali['spese'];
        }

        // ⚠️ **Il già versato va tolto dall'atteso, o il badge grida al lupo su un piano giusto.**
        //
        // `eDisallineato()` confronta questo totale con la somma delle quote generate. Su un
        // capitolo con del già versato il motore chiede **solo il residuo** — è il senso di quella
        // funzione — quindi le quote generate valgono meno del budget **per costruzione**, e fino
        // alla beta.75 ogni piano che sfruttasse il netting nasceva marcato «Disallineato:
        // ricalcola!». Misurato sul condominio di prova: budget € 5.000,00, già versato
        // € 2.000,00, quote generate € 3.000,00 — corrette, e segnalate come sbagliate.
        //
        // Il danno non era solo cosmetico: quel badge invita a premere «Ricalcola», che è
        // esattamente la cosa da non fare su un piano che sta bene.
        //
        // ⚠️ **Resta un caso di bordo, più stretto di quello che chiude.** Il netting non porta
        // mai una quota sotto zero: se un'unità ha versato **più** della sua parte, l'applicato è
        // minore del registrato e il confronto torna a divergere. Quella sovra-contribuzione ha
        // però già la sua segnalazione dedicata nell'Inbox, e non passa di qui.
        //
        // Il già versato si legge sulle sole voci di spesa: la pagina «Già versato» non offre le voci di
        // entrata, e il motore non applica il netting a una quota negativa.
        //
        // Si toglie quello che il motore toglie, non tutto: con un acconto (o un saldo) il piano chiede una
        // parte della voce, e ne toglie la stessa parte del già versato, con l'arrotondamento per difetto di
        // ogni parte (di persona o dell'unità). Togliere tutto il già versato a un acconto darebbe un atteso
        // più basso delle quote generate, e il badge accenderebbe «Disallineato» su un piano giusto che
        // «Ricalcola» non spegne. Non si legge il netting registrato in `righe_riparto`: un già versato
        // scritto dopo la generazione non ci sta, e il badge acceso è quello che invita a ricalcolare.
        $conVersato = ContributoVersato::where('target_type', Conto::class)
            ->whereIn('target_id', $totali['id_spese'])
            ->distinct()
            ->pluck('target_id');

        $giaVersato = 0;
        foreach ($conVersato as $contoId) {
            $giaVersato += QuotaDellaChiamata::giaVersatoApplicato((int) $pianoRate->id, (int) $contoId);
        }

        return max(0, $totali['spese'] - $giaVersato) - $totali['entrate'];
    }

    /**
     * Il verdetto: questo piano è ancora allineato al preventivo?
     *
     * **Esiste per avere un posto solo.** Fino all'11/08/2026 la domanda aveva due risposte: il
     * cruscotto la calcolava in PHP leggendo la componente pura con tolleranza **zero**, la
     * pagina del piano la ricalcolava in TypeScript sottraendo i saldi con tolleranza **€ 2,00**.
     * Un piano scostato di € 1,00 era verde di là e «URGENTE» di qua.
     *
     * **Tolleranza zero, e i dati lo confermano:** la distribuzione penny-perfect somma esatta
     * ai capitoli — misurato l'11/08/2026 su tutti i piani reali, delta 0 su ognuno. Una
     * tolleranza che non scatta mai può solo nascondere.
     *
     * Un piano senza rate non è disallineato: non è ancora stato generato.
     *
     * Dalla 1.11.0-beta.59 (Coda 414) «disallineato» è il terzo dei tre stati di `statoAllineamento()`: un piano con una quota non
     * assegnata accettata e mai cambiato dopo è in «quota aperta», non disallineato. Il confronto resta a tolleranza zero.
     */
    public function eDisallineato(PianoRate $pianoRate): bool
    {
        return $this->statoAllineamento($pianoRate) === self::DISALLINEATO;
    }

    /** Lo scarto è zero: le rate mettono in conto quello che il piano chiede (o il piano non è ancora generato). */
    public const ALLINEATO = 'allineato';

    /** Lo scarto è quello registrato alla generazione, e lo spiegano le quote non assegnate accettate: il piano non si è mosso da allora. */
    public const QUOTA_APERTA = 'quota_aperta';

    /** Lo scarto è un altro: qualcosa è cambiato dopo la generazione, o lo scarto non viene dalle quote non assegnate. */
    public const DISALLINEATO = 'disallineato';

    /**
     * Allineato, quota aperta o disallineato (Coda 414, decisione 153.6).
     *
     * Lo stato si deriva dalle quote registrate da `GeneratePianoRateAction` in `piani_rate.quote_non_assegnate`, non dalla presenza di
     * `nota_scoperti`: la nota resta sui piani accettati prima della 1.11.0-beta.59, che non hanno l'elenco e restano disallineati (lo
     * scarto non è registrato, e dirli in quota aperta nasconderebbe un disallineamento vero).
     *
     * «Quota aperta» vuole tre cose: lo scarto di adesso è diverso da zero, è uguale al centesimo a quello registrato (il piano non si è
     * mosso da quando l'amministratore ha accettato), e le voci registrate lo spiegano. L'ultima condizione esiste perché lo scarto
     * registrato è quello di tutta la generazione: se alla generazione il piano era già scostato per un'altra ragione, la registrazione
     * l'avrebbe messa agli atti con le quote, e la quota aperta la nasconderebbe. Le voci la spiegano se la loro somma, ognuna con il
     * segno della sua voce (meno per una voce di entrata), è a non più di un centesimo per voce dallo scarto, e se nessuna è una quota
     * nuova che nessuno ha accettato: il motore registra ogni quota con il suo arrotondamento e toglie dalla voce un arrotondamento solo
     * (`CalcoloQuoteService::distribuisciSuTabelle`), e con due quote sulla stessa voce le due cifre possono differire di un centesimo.
     * Una voce senza cifra (il millesimo non compilato) non spiega niente: la sua parte è andata alle altre.
     */
    public function statoAllineamento(PianoRate $pianoRate): string
    {
        // Il piano degli addebiti non ha capitoli: l'atteso sarebbe zero e ogni addebito uno scarto, con «Ricalcola» che lo cancellerebbe.
        if ($pianoRate->eDegliAddebiti()) {
            return self::ALLINEATO;
        }

        $pianoRate->loadMissing('rate');

        if ($pianoRate->rate->isEmpty()) {
            return self::ALLINEATO;
        }

        $scarto = $this->scartoCents($pianoRate);
        if ($scarto === 0) {
            return self::ALLINEATO;
        }

        return $this->scartoRegistratoCents($pianoRate) === $scarto ? self::QUOTA_APERTA : self::DISALLINEATO;
    }

    /** Quanto il piano dovrebbe mettere in rata meno quanto c'è nelle rate, in centesimi: positivo se le rate chiedono meno. */
    public function scartoCents(PianoRate $pianoRate): int
    {
        return $this->totaleAttesoCents($pianoRate) - $this->totalePuroGeneratoCents($pianoRate);
    }

    /**
     * Lo scarto registrato alla generazione, se le voci registrate lo spiegano (vedi `statoAllineamento()`); null se la colonna è vuota
     * (nessuna quota accettata, o un piano generato prima della 1.11.0-beta.59) o se le voci non lo spiegano.
     */
    public function scartoRegistratoCents(PianoRate $pianoRate): ?int
    {
        $registrate = $pianoRate->quote_non_assegnate;
        if (! is_array($registrate) || ! isset($registrate['scarto_cents'])) {
            return null;
        }

        $conCifra = array_values(array_filter((array) ($registrate['voci'] ?? []), fn ($voce) => (int) ($voce['importo_cents'] ?? 0) !== 0));
        // Una quota che nessuno ha accettato («Rimuovi voce e ricalcola» l'ha trovata nuova) non fa quota aperta.
        if ($conCifra === [] || collect($conCifra)->contains(fn ($voce) => ($voce['accettata'] ?? true) === false)) {
            return null;
        }

        $scarto = (int) $registrate['scarto_cents'];
        // Con il segno della voce: la quota di una voce di entrata è un credito che nessuno riceve, e lo scarto la conta con il meno.
        // Il già versato dell'unità sulla voce l'atteso lo toglie già (`totaleAttesoCents`): la quota registrata è lorda, e spiega lo scarto per ciò che resta.
        $versato = fn (array $voce) => (int) ($voce['segno'] ?? 1) < 0 || empty($voce['immobile_id']) || empty($voce['conto_id'])
            ? 0 : $this->giaVersatoSullUnita((int) $pianoRate->id, (int) $voce['conto_id'], (int) $voce['immobile_id']);
        $spiegato = array_sum(array_map(fn ($voce) => ((int) ($voce['segno'] ?? 1) < 0 ? -1 : 1) * max(0, abs((int) $voce['importo_cents']) - $versato($voce)), $conCifra));

        return abs($scarto - $spiegato) <= count($conCifra) ? $scarto : null;
    }

    /** Il già versato di un'unità su una voce che il piano toglie, con la quota del preventivo che il piano chiede (`QuotaDellaChiamata::giaVersatoApplicato`). */
    private function giaVersatoSullUnita(int $pianoRateId, int $contoId, int $immobileId): int
    {
        $parti = ContributoVersato::perImmobileESoggetto(Conto::class, $contoId, QuotaDellaChiamata::esercizioDelPiano($pianoRateId))[$immobileId] ?? [];
        if (array_sum($parti) <= 0) {
            return 0;
        }
        $quota = QuotaDellaChiamata::perConto($pianoRateId, $contoId) ?? 1.0;

        return array_sum(array_map(fn ($v) => $v > 0 ? QuotaDellaChiamata::applica((int) $v, $quota) : 0, $parti));
    }

    public function quotePerAnagrafica(PianoRate $pianoRate): Collection
    {
        // Coda 75: l'esercizio del piano, non «il primo attivo» del pivot. La stessa gestione ordinaria si aggancia a ogni esercizio
        // (`CondominioService::createDefaultGestione`, l'importatore) e nessuno spegne le righe vecchie: senza un ordine il primo era a caso.
        $esercizio = app(CompetenzaDelPiano::class)->esercizioDelPiano($pianoRate);

        $pianoUsaSaldi = $this->determinaSePianoUsaSaldi($pianoRate);

        return $pianoRate->rate
            ->flatMap->rateQuote
            ->groupBy('anagrafica_id')
            ->map(function ($quotes) use ($pianoRate, $esercizio, $pianoUsaSaldi) {

                $anagrafica = $quotes->first()->anagrafica;
                
                $saldoIniziale = 0;
                if ($pianoUsaSaldi && $esercizio) {
                    // La gestione è il filtro che mancava. Senza, con
                    // un'ordinaria e una straordinaria aperte sullo stesso
                    // esercizio l'avviso dell'una stampava anche il pregresso
                    // dell'altra — e nel verso opposto, un credito altrove
                    // abbassava il dovuto. È l'asse su cui filtra da sempre
                    // GenerateSaldiAction: la stampa deve mostrare ciò che il
                    // piano ha davvero assorbito, non un'altra somma.
                    $saldoRecord = Saldo::where('esercizio_id', $esercizio->id)
                        ->where('condominio_id', $pianoRate->condominio_id)
                        ->where('gestione_id', $pianoRate->gestione_id)
                        ->whereNull('fornitore_id')
                        ->where('anagrafica_id', $anagrafica->id)
                        ->sum('saldo_iniziale');
                    $saldoIniziale = (int) $saldoRecord;
                }

                $rate = $quotes
                    ->groupBy(fn($q) => $q->rata->numero_rata)
                    ->map(function ($q) {
                        $rata = $q->first()->rata;
                        $importo = $q->sum('importo');
                        $pagato  = $q->sum('importo_pagato');
                        $residuo = max(0, $importo - $pagato);
                        
                        $stato = 'da_pagare';
                        if ($q->first()->stato === 'annullata') $stato = 'annullata';
                        elseif ($importo < 0) $stato = 'credito';
                        elseif ($pagato >= $importo && $importo > 0) $stato = 'pagata';
                        elseif ($pagato > 0 && $pagato < $importo) $stato = 'parzialmente_pagata';

                        $dataPagamento = $q->whereNotNull('data_pagamento')
                                           ->sortByDesc('data_pagamento')
                                           ->first()
                                           ?->data_pagamento;

                        // --- FIX V1.9: Rimosso json_decode (Laravel lo casta già in array) ---

                        $dettaglioQuote = $q->map(function ($quota) {
                            $componenteSpesa = $quota->importo;
                            $componenteSaldo = 0;

                            $meta = $quota->regole_calcolo;

                            if (!empty($meta) && is_array($meta)) {
                                $componenteSpesa = $meta['importi']['quota_pura_gestione'] ?? $meta['audit']['quota_pura'] ?? $quota->importo;
                                $componenteSaldo = $meta['importi']['saldo_usato'] ?? $meta['audit']['saldo_usato'] ?? 0;
                            }

                            // FIX: Esporre immobile e tipo per lo "Spaccato Scontrino" nel frontend
                            return [
                                'id'               => $quota->id,
                                'immobile_id'      => $quota->immobile_id,
                                'immobile_nome'    => $quota->immobile ? $quota->immobile->nome . ($quota->immobile->interno ? ' (Int. ' . $quota->immobile->interno . ')' : '') : 'Condominio',
                                'tipo'             => $quota->tipo, // 'ordinaria' o 'saldo_iniziale'
                                'importo'          => $quota->importo,
                                'residuo'          => max(0, $quota->importo - $quota->importo_pagato),
                                'componente_spesa' => $componenteSpesa,
                                'componente_saldo' => $componenteSaldo,
                            ];
                        })->values()->toArray();
                        
                        return [
                            'numero'          => $rata->numero_rata,
                            'scadenza'        => optional($rata->data_scadenza)->format('Y-m-d'),
                            // Emessa: una quota a giornale. Scaduta: il predicato del giorno (il giorno della scadenza non
                            // è scaduto, nel giorno dell'amministratore). La pagina li usa e non ricalcola nel browser.
                            'emessa'          => $q->contains(fn ($quota) => $quota->scrittura_contabile_id !== null),
                            'scaduta'         => Scadenza::scaduta($rata->data_scadenza),
                            'importo'         => $importo,
                            'importo_pagato'  => $pagato,
                            'residuo'         => $residuo, 
                            'stato'           => $stato,
                            'data_pagamento'  => $dataPagamento ? $dataPagamento->format('Y-m-d') : null,
                            'dettaglio_quote' => $dettaglioQuote,
                        ];
                    })
                    ->sortBy('numero')
                    ->values();

                return [
                    'anagrafica' => [
                        'id'        => $anagrafica->id,
                        'nome'      => $anagrafica->nome,
                        'indirizzo' => $anagrafica->indirizzo,
                    ],
                    'saldo_iniziale' => $saldoIniziale,
                    'rate' => $rate,
                ];
            })
            ->values();
    }

    public function quotePerImmobile(PianoRate $pianoRate): Collection
    {
        // Coda 75: l'esercizio del piano, come in quotePerAnagrafica.
        $esercizio = app(CompetenzaDelPiano::class)->esercizioDelPiano($pianoRate);

        $pianoUsaSaldi = $this->determinaSePianoUsaSaldi($pianoRate);

        return $pianoRate->rate
            ->flatMap->rateQuote
            ->whereNotNull('immobile_id')
            ->groupBy('immobile_id')
            ->map(function ($quotes) use ($pianoRate, $esercizio, $pianoUsaSaldi) {

                $immobile = $quotes->first()->immobile;

                $totaleDebiti = 0;
                $totaleCrediti = 0;

                if ($pianoUsaSaldi && $esercizio) {
                    // Stesso filtro di quotePerAnagrafica, e per la stessa
                    // ragione: il totale stampato per l'unità deve essere
                    // quello della gestione del piano, non di tutte insieme.
                    $saldiRecords = Saldo::where('esercizio_id', $esercizio->id)
                        ->where('condominio_id', $pianoRate->condominio_id)
                        ->where('gestione_id', $pianoRate->gestione_id)
                        ->whereNull('fornitore_id')
                        ->where('immobile_id', $immobile->id)
                        ->get();

                    foreach ($saldiRecords as $s) {
                        if ($s->saldo_iniziale > 0) {
                            $totaleDebiti += $s->saldo_iniziale;
                        } else {
                            $totaleCrediti += $s->saldo_iniziale;
                        }
                    }
                }

                $rate = $quotes
                    ->groupBy('rata_id')
                    ->map(function ($q) {
                        $rata = $q->first()->rata;
                        $importo = $q->sum('importo');
                        $pagato = $q->sum('importo_pagato');
                        // Il credito è netto per la persona: sull'unità si sommano i residui di ognuna, e il credito di una non toglie dal debito di un'altra.
                        $residuo = (int) $q->groupBy('anagrafica_id')->sum(fn ($diUno) => max(0, $diUno->sum('importo') - $diUno->sum('importo_pagato')));
                        
                        $stato = 'da_pagare';
                        if ($q->first()->stato === 'annullata') $stato = 'annullata';
                        elseif ($importo < 0) $stato = 'credito';
                        elseif ($pagato >= $importo && $importo > 0) $stato = 'pagata';
                        elseif ($pagato > 0 && $pagato < $importo) $stato = 'parzialmente_pagata';

                        // --- FIX V1.9: Rimosso json_decode ---
                        $dettaglioQuote = $q->map(function ($quota) {
                            $componenteSpesa = $quota->importo;
                            $componenteSaldo = 0;

                            $meta = $quota->regole_calcolo;

                            if (!empty($meta) && is_array($meta)) {
                                $componenteSpesa = $meta['importi']['quota_pura_gestione'] ?? $meta['audit']['quota_pura'] ?? $quota->importo;
                                $componenteSaldo = $meta['importi']['saldo_usato'] ?? $meta['audit']['saldo_usato'] ?? 0;
                            }

                            return [
                                'id'               => $quota->id,
                                'importo'          => $quota->importo,
                                'residuo'          => max(0, $quota->importo - $quota->importo_pagato),
                                'componente_spesa' => $componenteSpesa,
                                'componente_saldo' => $componenteSaldo,
                            ];
                        })->values()->toArray();

                        return [
                            'numero'   => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'emessa'   => $q->contains(fn ($quota) => $quota->scrittura_contabile_id !== null),
                            'scaduta'  => Scadenza::scaduta($rata->data_scadenza),
                            'importo'  => $importo,
                            'importo_pagato' => $pagato,
                            'residuo'         => $residuo, 
                            'stato'          => $stato,
                            'data_pagamento' => $q->sortByDesc('data_pagamento')->first()?->data_pagamento?->format('Y-m-d'),
                            'dettaglio_quote' => $dettaglioQuote,
                        ];
                    })
                    ->sortBy('numero')
                    ->values();

                return [
                    'immobile' => [
                        'id'         => $immobile->id,
                        'nome'       => $immobile->nome ?? 'Sconosciuto',
                        'interno'    => $immobile->interno,
                        'piano'      => $immobile->piano,
                        'superficie' => $immobile->superficie,
                    ],
                    'totale_debiti'  => (int) $totaleDebiti,
                    'totale_crediti' => (int) $totaleCrediti, 
                    'rate' => $rate,
                ];
            })
            ->values();
    }
}
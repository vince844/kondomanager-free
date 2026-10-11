<?php

namespace App\Services\Gestionale;

use App\Exceptions\Gestionale\ContoPassateGestioniMancanteException;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Riparto\CompetenzaDelPiano;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il riallineamento dei crediti che la 1.9 e la 1.10 possono aver scritto male (decisioni 75.1 e 76.1, voce P9 della
 * 1.11.0-beta.49): il comando `kondomanager:riallinea-crediti` e la card «Crediti da riallineare» della pagina «Incassi rate».
 * Il rilevamento è per posizione, non per versione: trova anche i dati delle beta della 1.11 che avevano lo stesso prelievo e lo
 * stesso salto nell'emissione.
 * Sul modello di `RiallineaFondiService` (1.10.0-beta.19): rilevamento in sola lettura, anteprima, conferma esplicita, solo
 * scritture in aggiunta. Mai una migrazione dati automatica sul libro giornale: correggere è una decisione dell'amministratore.
 *
 * Che cosa scrive, e solo questo (76.1):
 * - **la rettifica di DC1**: una compensazione che ha preso il credito riportato da una quota a credito ancora senza scrittura e
 *   lo ha prelevato da «Crediti verso condomini», dove l'emissione non l'aveva mai scritto. La si riconosce dalla posizione, non
 *   dalla storia: la quota è ancora senza scrittura e su «Crediti» resta, per la sua persona, rata e unità, un netto aperto fra
 *   la compensazione e le sue rettifiche. AVERE «Crediti verso condomini» / DARE «Passate gestioni», a nome della persona, nella
 *   data e nell'esercizio della compensazione, **figlia della compensazione**: lo storno dell'incasso la rovescia insieme a lei
 *   (`StornoIncassoRateAction`, contrasto 3 del progetto);
 * - **la quota a credito rimasta fuori da una rata mista** già a giornale (DC2-b), con le regole dell'emissione nuova
 *   (`RataQuote::scritturaDiEmissione`, il credito già uscito letto dal giornale): una scrittura d'emissione legata alla quota,
 *   nella data, nell'esercizio e nella gestione dell'emissione che la rata ha già. Se l'emissione si annulla, se ne va con lei.
 *
 * Che cosa non scrive: le rate di soli crediti che la 1.9 e la 1.10 hanno segnato «emesse» senza righe le elenca (`da_emettere`,
 * con il collegamento alla pagina del piano) e le emette l'amministratore, con la data e l'avviso che sceglie lui (76.1); solo
 * quelle che l'emissione porterebbe davvero a giornale (rilievo LDA-3 della Fase 1-bis).
 * Restano come sono, e lo si dice (`restano`): le scritture in un esercizio chiuso (un bilancio chiuso non si tocca); le quote a
 * credito, o a zero, senza il dettaglio del calcolo (piani della 1.7.x: non si indovina quanta parte è spesa e quanta credito,
 * decisione 76.3, ritoccata l'11/10/2026: l'emissione le ferma da sole); le rate «emesse» senza righe che l'emissione non
 * porterebbe a giornale, perché il loro credito è già uscito tutto. La card le mostra in sola lettura, anche quando sono le sole
 * voci (rilievo LDA-8). Restano fuori in silenzio le quote gemelle (decisione 25, dati delle sole beta della 1.11) e la rata
 * «emessa» senza righe che un passaggio della .41 ha preso nel conguaglio (W1, 75.2: la ferma l'emissione).
 *
 * Una compensazione è DC1 solo per la parte riportata del credito (rilievo K2): la spesa negativa delle entrate dell'anno sta fra
 * i crediti verso condomini, e prelevata da lì era giusta anche con la 1.10.
 */
class RiallineaCreditiService
{
    /**
     * Nella causale delle scritture del comando: le riconoscono lo storno (la famiglia) e l'estratto conto (che le tiene fuori).
     * Resta nel libro giornale per sempre, quindi dice la versione senza la beta (Vincenzo, 11/10/2026). Non si cambia più: una
     * scrittura con il marcatore vecchio non verrebbe più riconosciuta.
     */
    public const MARCATORE = 'Riallineamento crediti (1.11)';

    /**
     * Sola lettura.
     *
     * @return array{
     *     compensazioni: list<array{scrittura_id: int, protocollo: ?string, data: ?string, quota_id: int, rata_id: int, numero_rata: int, anagrafica_id: int, persona: string, immobile_id: ?int, importo: int, importo_formattato: string}>,
     *     rate: list<array<string, mixed>>,
     *     da_emettere: list<array{rata_id: int, numero_rata: int, piano: string, url: string}>,
     *     restano: list<array{rata_id?: int, scrittura_id?: int, motivo: string}>
     * }
     */
    public function rileva(Condominio $condominio): array
    {
        [$compensazioni, $restanoC] = $this->compensazioni($condominio);
        $inArrivo = [];
        foreach ($compensazioni as $c) {
            $chiave = $c['rata_id'] . '|' . $c['anagrafica_id'] . '|' . ($c['immobile_id'] ?? '');
            $inArrivo[$chiave] = ($inArrivo[$chiave] ?? 0) + $c['importo'];
        }
        [$rate, $daEmettere, $restanoR] = $this->rate($condominio, $inArrivo);

        return ['compensazioni' => $compensazioni, 'rate' => $rate, 'da_emettere' => $daEmettere, 'restano' => [...$restanoC, ...$restanoR]];
    }

    /**
     * Niente da scrivere, niente da emettere e niente da dire: la card non compare. Le voci che restano come sono la tengono accesa,
     * in sola lettura (rilievo LDA-8 della Fase 1-bis e 76.3 ritoccata): che un credito resti com'è in un bilancio chiuso, o che una
     * quota della 1.7.x sia rimasta fuori dall'emissione, lo deve dire la pagina, non solo il terminale.
     */
    public function vuoto(array $rilevato): bool
    {
        return $this->daScrivere($rilevato) === 0 && $rilevato['da_emettere'] === [] && $rilevato['restano'] === [];
    }

    /** Quante scritture il comando scriverebbe adesso. */
    public function daScrivere(array $rilevato): int
    {
        return count($rilevato['compensazioni']) + collect($rilevato['rate'])->where('azione', 'scrivi')->count();
    }

    /**
     * Scrive, ogni voce nella sua transazione, rileggendo lo stato sotto blocco: prima le rettifiche di DC1, poi le quote delle rate
     * miste (il credito già uscito si legge dopo le rettifiche). Senza il conto «Passate gestioni» non scrive niente.
     *
     * @return array{rettifiche: list<string>, emissioni: list<string>, restano: list<array<string, mixed>>}
     *
     * @throws ContoPassateGestioniMancanteException
     */
    public function esegui(Condominio $condominio, ?int $utenteId = null): array
    {
        $rilevato = $this->rileva($condominio);
        $fatto = ['rettifiche' => [], 'emissioni' => [], 'restano' => $rilevato['restano']];
        if ($this->daScrivere($rilevato) === 0) {
            return $fatto;
        }

        $crediti = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->firstOrFail();
        $passate = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->whereNull('deleted_at')->first()
            ?? throw new ContoPassateGestioniMancanteException();
        $gestioneRate = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'gestione_rate')->first();

        // L'incasso padre di ogni compensazione, letto fuori dalle transazioni: dentro, la prima lettura deve essere il blocco.
        $padri = ScritturaContabile::whereIn('id', collect($rilevato['compensazioni'])->pluck('scrittura_id'))->pluck('scrittura_padre_id', 'id');

        foreach ($rilevato['compensazioni'] as $voce) {
            DB::transaction(function () use ($voce, $condominio, $crediti, $passate, $utenteId, $padri, &$fatto) {
                // Prima l'incasso padre, la riga che blocca anche lo storno (`StornoIncassoRateAction`), poi la compensazione: così
                // riallineamento e storno dello stesso incasso si mettono in fila (rilievo S2 della Fase 1-bis). Bloccata solo la
                // compensazione, lo storno poteva leggere la famiglia senza la rettifica appena scritta e lasciarla senza specchio.
                // Il blocco è la prima lettura della transazione: su MySQL l'istantanea delle letture semplici nasce alla prima
                // lettura senza blocco, e nata prima del blocco non vedrebbe lo storno appena confermato.
                $padreId = $padri[$voce['scrittura_id']] ?? null;
                if ($padreId !== null && ScritturaContabile::whereKey($padreId)->lockForUpdate()->value('stato') === 'annullata') {
                    return;
                }
                $compensazione = ScritturaContabile::whereKey($voce['scrittura_id'])->lockForUpdate()->firstOrFail();
                if ($compensazione->stato === 'annullata') {
                    return;
                }
                [$ancora] = $this->compensazioni($condominio);
                $ancora = collect($ancora)->first(fn (array $x) => $x['scrittura_id'] === $voce['scrittura_id'] && $x['quota_id'] === $voce['quota_id']);
                if ($ancora === null) {
                    return;
                }
                $rettifica = ScritturaContabile::create([
                    'condominio_id'      => $condominio->id,
                    'esercizio_id'       => $compensazione->esercizio_id,
                    'gestione_id'        => $compensazione->gestione_id,
                    'scrittura_padre_id' => $compensazione->id,
                    'data_registrazione' => now()->toDateString(),
                    'data_competenza'    => $compensazione->data_competenza->toDateString(),
                    'causale'            => self::MARCATORE . " — rettifica prot. {$compensazione->numero_protocollo}",
                    'tipo_movimento'     => 'rettifica',
                    'stato'              => 'registrata',
                    'created_by'         => $utenteId,
                    'note'               => 'Il credito usato era riportato da un esercizio precedente e non era ancora a giornale: esce da «Passate gestioni», non da «Crediti verso condomini».',
                ]);
                foreach ([[$crediti, 'avere'], [$passate, 'dare']] as [$conto, $verso]) {
                    $rettifica->righe()->create([
                        'conto_contabile_id' => $conto->id,
                        'anagrafica_id'      => $ancora['anagrafica_id'],
                        'rata_id'            => $ancora['rata_id'],
                        'immobile_id'        => $ancora['immobile_id'],
                        'tipo_riga'          => $verso,
                        'importo'            => $ancora['importo'],
                        'note'               => 'Riallineamento del credito riportato usato in compensazione',
                    ]);
                }
                DoubleEntryValidator::validateOrFail($rettifica->id);
                $fatto['rettifiche'][] = (string) $rettifica->numero_protocollo;
            });
        }

        foreach ($rilevato['rate'] as $voce) {
            if ($voce['azione'] !== 'scrivi') {
                continue;
            }
            DB::transaction(function () use ($voce, $condominio, $crediti, $passate, $gestioneRate, $utenteId, &$fatto) {
                DB::table('rate_quote')->where('rata_id', $voce['rata_id'])->lockForUpdate()->pluck('id');
                $rata = Rata::with('rateQuote')->findOrFail($voce['rata_id']);
                $quote = $rata->rateQuote->whereIn('id', $voce['quote'])->whereNull('scrittura_contabile_id')->values();
                // Ricalcolate qui, dopo le rettifiche: il credito già uscito da «Passate gestioni» è cambiato.
                $perQuota = array_filter(RataQuote::scritturaDiEmissione($quote, (int) $passate->id), fn (array $x) => $x['righe'] !== []);
                if ($perQuota === []) {
                    return;
                }
                $emissione = ScritturaContabile::create([
                    'condominio_id'      => $condominio->id,
                    'esercizio_id'       => $voce['esercizio_id'],
                    'gestione_id'        => $voce['gestione_id'],
                    'data_registrazione' => now(),
                    'data_competenza'    => $voce['data'],
                    'causale'            => self::MARCATORE . ' — emissione ' . $rata->descrizione,
                    'tipo_movimento'     => 'emissione_rata',
                    'stato'              => 'registrata',
                    'created_by'         => $utenteId,
                    'note'               => 'La quota a credito era rimasta fuori dall\'emissione della sua rata: va a giornale con la spesa e il credito riportato, come la scrive l\'emissione dalla 1.11.0-beta.49.',
                ]);
                $gestione = 0;
                $pregresso = 0;
                foreach ($quote as $quota) {
                    if (! isset($perQuota[(int) $quota->id])) {
                        continue;
                    }
                    foreach ($perQuota[(int) $quota->id]['righe'] as $i => [$verso, $importo]) {
                        $emissione->righe()->create([
                            'conto_contabile_id' => $crediti->id,
                            'anagrafica_id'      => $quota->anagrafica_id,
                            'immobile_id'        => $quota->immobile_id,
                            'rata_id'            => $rata->id,
                            'tipo_riga'          => $verso,
                            'importo'            => $importo,
                            'note'               => $quota->notaDiEmissione($i, $verso, (string) $rata->descrizione),
                        ]);
                    }
                    $quota->update(['scrittura_contabile_id' => $emissione->id]);
                    $gestione += $perQuota[(int) $quota->id]['gestione'];
                    $pregresso += $perQuota[(int) $quota->id]['pregresso'];
                }
                foreach ([[$gestioneRate, $gestione, 'Totale emissione ' . $rata->descrizione], [$passate, $pregresso, 'Riporto esercizi precedenti — ' . $rata->descrizione]] as [$conto, $totale, $nota]) {
                    if ($totale === 0) {
                        continue;
                    }
                    $emissione->righe()->create(['conto_contabile_id' => $conto->id, 'tipo_riga' => $totale > 0 ? 'avere' : 'dare', 'importo' => abs($totale), 'note' => $nota]);
                }
                DoubleEntryValidator::validateOrFail($emissione->id);
                $fatto['emissioni'][] = (string) $emissione->numero_protocollo;
            });
        }

        return $fatto;
    }

    /**
     * DC1: per ogni coppia (compensazione, quota a credito ancora senza scrittura) il netto che resta aperto su «Crediti verso
     * condomini» per la persona, la rata e l'unità della quota, sommando la compensazione e le sue rettifiche (lo storno, una
     * rettifica già fatta). Una quota strapagata (importo positivo) non c'entra: quel credito è denaro entrato e scritto su
     * «Crediti» dall'incasso, e il prelievo da lì è giusto. Neanche la spesa negativa delle entrate dell'anno (rilievo K2 della
     * Fase 1-bis): sta fra i crediti verso condomini. DC1 è solo il riporto a credito della quota, e la rettifica è al più il
     * riporto non ancora uscito da «Passate gestioni», contato una volta sola fra le compensazioni della stessa quota.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} [da scrivere, restano]
     */
    private function compensazioni(Condominio $condominio): array
    {
        $crediti = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->value('id');
        if (! $crediti) {
            return [[], []];
        }
        $coppie = DB::table('quota_scrittura as qs')
            ->join('scritture_contabili as s', 's.id', '=', 'qs.scrittura_contabile_id')
            ->join('rate_quote as q', 'q.id', '=', 'qs.rate_quota_id')
            ->join('rate as r', 'r.id', '=', 'q.rata_id')
            ->leftJoin('anagrafiche as a', 'a.id', '=', 'q.anagrafica_id')
            ->where('s.condominio_id', $condominio->id)->where('s.tipo_movimento', 'storno_credito')->whereNull('s.deleted_at')
            ->where('q.importo', '<', 0)->whereNull('q.scrittura_contabile_id')
            ->select('s.id as s_id', 's.numero_protocollo', 's.data_competenza', 's.esercizio_id', 'q.id as q_id', 'q.rata_id', 'r.numero_rata',
                'q.anagrafica_id', 'a.nome as persona', 'q.immobile_id')
            ->distinct()->orderBy('s.id')->orderBy('q.id')->get();

        $passate = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->whereNull('deleted_at')->value('id');
        $esito = [];
        $restano = [];
        $riportoDaRettificare = [];
        foreach ($coppie as $c) {
            $immobileId = $c->immobile_id === null ? null : (int) $c->immobile_id;
            if (self::gemelle((int) $c->rata_id, (int) $c->anagrafica_id, $immobileId) > 1) {
                continue; // decisione 25: due quote della stessa persona sulla stessa rata e unità, dati delle sole beta 1.11
            }
            if (! array_key_exists((int) $c->q_id, $riportoDaRettificare)) {
                $quota = RataQuote::find((int) $c->q_id);
                $riporto = $quota === null ? 0 : $quota->componenti()['riporto'];
                $riportoDaRettificare[(int) $c->q_id] = $riporto >= 0 ? 0
                    : max(0, -$riporto - ($passate ? $quota->creditoUscitoDaPassate((int) $passate) : 0));
            }
            $ids = DB::table('scritture_contabili')->where('scrittura_padre_id', $c->s_id)->where('tipo_movimento', 'rettifica')->whereNull('deleted_at')
                ->pluck('id')->push($c->s_id)->all();
            $netto = (int) DB::table('righe_scritture')->whereIn('scrittura_id', $ids)->where('conto_contabile_id', $crediti)
                ->where('rata_id', $c->rata_id)->where('anagrafica_id', $c->anagrafica_id)
                ->where(fn ($w) => $immobileId === null ? $w->whereNull('immobile_id') : $w->where('immobile_id', $immobileId))
                ->selectRaw("COALESCE(SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END), 0) as n")->value('n');
            $netto = min($netto, $riportoDaRettificare[(int) $c->q_id]);
            if ($netto <= 0) {
                continue;
            }
            $riportoDaRettificare[(int) $c->q_id] -= $netto;
            $voce = [
                'scrittura_id'       => (int) $c->s_id,
                'protocollo'         => $c->numero_protocollo,
                'data'               => $c->data_competenza ? \Illuminate\Support\Carbon::parse($c->data_competenza)->format('d/m/Y') : null,
                'quota_id'           => (int) $c->q_id,
                'rata_id'            => (int) $c->rata_id,
                'numero_rata'        => (int) $c->numero_rata,
                'anagrafica_id'      => (int) $c->anagrafica_id,
                'persona'            => (string) $c->persona,
                'immobile_id'        => $immobileId,
                'importo'            => $netto,
                'importo_formattato' => MoneyHelper::format($netto),
            ];
            if (DB::table('esercizi')->where('id', $c->esercizio_id)->value('stato') !== 'aperto') {
                $restano[] = ['scrittura_id' => (int) $c->s_id, 'motivo' => "la compensazione prot. {$c->numero_protocollo} di {$voce['persona']} ({$voce['importo_formattato']} sulla rata {$voce['numero_rata']}) è in un esercizio chiuso"];

                continue;
            }
            $esito[] = $voce;
        }

        return [$esito, $restano];
    }

    /**
     * DC2-b e le rate «emesse» senza righe. Una rata a giornale (almeno una quota ha la scrittura: si guarda il giornale, non lo
     * stato) con una quota a credito, o a zero, ancora fuori: la quota si scrive nell'emissione che la rata ha già. Una rata
     * «emessa» senza nessuna scrittura e fatta di sole quote a credito: la elenca la card, la emette l'amministratore — se
     * l'emissione la porterebbe a giornale (rilievo LDA-3 della Fase 1-bis). Non la porterebbe se ha solo quote senza il dettaglio
     * del calcolo, che l'emissione ferma, o se il suo credito è già uscito tutto (le rettifiche di DC1 in arrivo comprese): allora
     * resta com'è, e la card lo dice. Una quota a credito, o a zero, senza il dettaglio (1.7.x) resta com'è in tutti e due i casi.
     *
     * @param array<string, int> $inArrivo il credito che le rettifiche di DC1 faranno uscire da «Passate gestioni», per persona, rata e unità
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>} [rate, da emettere, restano]
     */
    private function rate(Condominio $condominio, array $inArrivo): array
    {
        $rate = Rata::where(fn ($w) => $w->where('stato', 'emessa')->orWhereExists(Rata::aGiornale('rate.id')))
            ->whereHas('pianoRate', fn ($p) => $p->where('condominio_id', $condominio->id))
            ->whereHas('rateQuote', fn ($q) => $q->whereNull('scrittura_contabile_id')->where('importo', '<=', 0))
            ->with(['rateQuote.anagrafica:id,nome', 'pianoRate'])->orderBy('id')->get();
        $passate = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->whereNull('deleted_at')->value('id');

        $esito = [];
        $daEmettere = [];
        $restano = [];
        foreach ($rate as $rata) {
            $aGiornale = $rata->rateQuote->whereNotNull('scrittura_contabile_id');
            $fuori = $rata->rateQuote->filter(fn (RataQuote $q) => $q->scrittura_contabile_id === null && (int) $q->importo <= 0)
                ->reject(fn (RataQuote $q) => self::gemelle((int) $q->rata_id, (int) $q->anagrafica_id, $q->immobile_id === null ? null : (int) $q->immobile_id) > 1)
                ->values();

            // Rilievo K6: anche una quota a zero senza il dettaglio (un credito pari alla spesa: la 1.7.0 non creava quote vuote).
            $senzaDettaglio = $fuori->filter(fn (RataQuote $q) => $q->fermaSenzaDettaglio());
            // Giro stretto sulle correzioni della Fase 1-bis: la quota a zero non ha un credito da usare (LT-3 e G3), e il motivo dice la
            // strada (LT-6), il ricalcolo; emettere la rata non la porta a giornale.
            foreach ($senzaDettaglio as $q) {
                $restano[] = ['rata_id' => (int) $rata->id, 'motivo' => sprintf('la quota %s di %s sulla rata %d (%s) non ha il dettaglio del calcolo (piano generato prima della 1.8.0): non si sa quanta parte è spesa e quanta credito riportato, e resta fuori dal giornale %s; per portarla a giornale si ricalcola il piano, come dice la guida della pagina del piano: emettere la rata non basta',
                    (int) $q->importo === 0 ? 'a zero' : 'a credito', $q->anagrafica?->nome ?? 'una persona', $rata->numero_rata, $rata->pianoRate?->nome ?? 'piano',
                    (int) $q->importo === 0
                        ? 'insieme alla spesa che il credito riportato paga per intero (non c\'è un credito da usare né da rimborsare)'
                        : 'con il suo credito, da usare o rimborsare')];
            }
            $conDettaglio = $fuori->filter(fn (RataQuote $q) => $q->componenti()['dettaglio'])->values();
            $calcolo = $this->conIlCreditoInArrivo($conDettaglio, $passate === null ? null : (int) $passate, $inArrivo);
            $daScrivere = $conDettaglio->filter(fn (RataQuote $q) => ($calcolo[(int) $q->id]['righe'] ?? []) !== [])->values();

            if ($aGiornale->isNotEmpty()) {
                if ($daScrivere->isEmpty()) {
                    continue;
                }
                $sorella = ScritturaContabile::find($aGiornale->first()->scrittura_contabile_id);
                $voce = [
                    'forma'       => 'mista',
                    'rata_id'     => (int) $rata->id,
                    'numero_rata' => (int) $rata->numero_rata,
                    'piano'       => (string) $rata->pianoRate?->nome,
                    'quote'       => $daScrivere->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    // Le righe che il comando scriverebbe per la persona: la spesa (DARE), negativa se è una quota delle entrate
                    // dell'anno (una voce di tipo «entrata», rilievo K2: la sua riga in AVERE), e il credito riportato (AVERE).
                    'persone'     => $daScrivere->map(function (RataQuote $q) use ($calcolo) {
                        $righe = collect($calcolo[(int) $q->id]['righe']);
                        $entrate = max(0, -$q->componenti()['spesa']);

                        return [
                            'nome'    => (string) $q->anagrafica?->nome,
                            'spesa'   => (int) $righe->where(0, 'dare')->sum(1) - $entrate,
                            'credito' => (int) $righe->where(0, 'avere')->sum(1) - $entrate,
                        ];
                    })->all(),
                ];
                if (($sorella?->esercizio?->stato) !== 'aperto') {
                    $restano[] = ['rata_id' => (int) $rata->id, 'motivo' => "la rata {$rata->numero_rata} ({$voce['piano']}) è emessa in un esercizio chiuso"];
                    $esito[] = $voce + ['azione' => 'esercizio_chiuso'];

                    continue;
                }
                $esito[] = $voce + ['azione' => 'scrivi', 'esercizio_id' => (int) $sorella->esercizio_id, 'gestione_id' => $sorella->gestione_id,
                    'data' => $sorella->data_competenza?->toDateString()];

                continue;
            }

            $piano = $rata->pianoRate;
            // «Emessa» senza scritture con una quota da pagare: non l'ha scritta la 1.9 né la 1.10. W1, decisione 75.2: la ferma
            // l'emissione. Tutte e due restano fuori in silenzio, anche le quote senza dettaglio messe sopra fra quelle che restano.
            if ($rata->rateQuote->contains(fn (RataQuote $q) => (int) $q->importo > 0)
                || collect($piano->passaggiCheLoHannoConguagliato())->contains(fn ($s) => $piano->presoSoloInParteDa($s))) {
                $restano = array_values(array_filter($restano, fn (array $r) => ($r['rata_id'] ?? null) !== (int) $rata->id));

                continue;
            }
            // Rilievo LDA-3: solo se l'emissione la porterebbe a giornale. Le quote senza dettaglio le ferma (sono già in `restano`,
            // sopra); se il credito è già uscito tutto non scriverebbe niente, e la rata resterebbe in bozza.
            if ($daScrivere->isEmpty()) {
                if ($senzaDettaglio->isEmpty()) {
                    // La strada (giro stretto sulle correzioni della Fase 1-bis, LT-6): emessa dalla pagina del piano, la rata resta in
                    // bozza e lo stato «emessa» senza scritture se ne va, con questa voce (decisione 34). Se il credito esce tutto solo
                    // con le rettifiche di DC1 ancora da scrivere, prima le rettifiche: emessa adesso, la rata porterebbe il credito.
                    $conRettifiche = $fuori->contains(fn (RataQuote $q) => ($inArrivo[$q->rata_id . '|' . $q->anagrafica_id . '|' . ($q->immobile_id ?? '')] ?? 0) > 0);
                    $strada = ($conRettifiche ? 'dopo le rettifiche delle compensazioni, ' : '')
                        . 'se la emetti dalla pagina del piano resta in bozza, e questa voce non compare più';
                    $restano[] = ['rata_id' => (int) $rata->id, 'motivo' => $fuori->contains(fn (RataQuote $q) => (int) $q->importo < 0)
                        ? sprintf('la rata %d (%s) è segnata «emessa» senza scritture, e il suo credito è già uscito tutto (usato o rimborsato): a giornale non c\'è niente da portare, e la pagina del piano la mostra in bozza; %s', $rata->numero_rata, $piano->nome, $strada)
                        : sprintf('la rata %d (%s) è segnata «emessa» senza scritture e non ha quote da pagare: a giornale non c\'è niente da portare, e la pagina del piano la mostra in bozza; %s', $rata->numero_rata, $piano->nome, $strada)];
                }

                continue;
            }
            $esercizioId = $piano->esercizio_id ?? app(CompetenzaDelPiano::class)->esercizioDelPiano($piano)?->id;
            $daEmettere[] = [
                'rata_id'     => (int) $rata->id,
                'numero_rata' => (int) $rata->numero_rata,
                'piano'       => (string) $piano->nome,
                'url'         => route('admin.gestionale.esercizi.piani-rate.show', ['condominio' => $condominio->id, 'esercizio' => $esercizioId, 'pianoRate' => $piano->id]),
            ];
        }

        return [$esito, $daEmettere, $restano];
    }

    /**
     * Le righe dell'emissione delle quote come le scriverà il comando, contando anche il credito che le rettifiche di DC1 faranno
     * uscire da «Passate gestioni» prima (l'anteprima della card dice quello che il comando farà).
     *
     * @param Collection<int, RataQuote> $quote
     * @param array<string, int> $inArrivo
     * @return array<int, array{righe: list<array{0: string, 1: int}>, gestione: int, pregresso: int}>
     */
    private function conIlCreditoInArrivo(Collection $quote, ?int $passate, array $inArrivo): array
    {
        $calcolo = RataQuote::scritturaDiEmissione($quote, $passate);
        foreach ($quote as $quota) {
            $chiave = $quota->rata_id . '|' . $quota->anagrafica_id . '|' . ($quota->immobile_id ?? '');
            if (($inArrivo[$chiave] ?? 0) <= 0) {
                continue;
            }
            $giaUscito = ($passate === null ? 0 : $quota->creditoUscitoDaPassate($passate)) + $inArrivo[$chiave];
            $calcolo[(int) $quota->id]['righe'] = $quota->righeDiEmissione($giaUscito);
        }

        return $calcolo;
    }

    private static function gemelle(int $rataId, int $anagraficaId, ?int $immobileId): int
    {
        return DB::table('rate_quote')->where('rata_id', $rataId)->where('anagrafica_id', $anagraficaId)
            ->where(fn ($w) => $immobileId === null ? $w->whereNull('immobile_id') : $w->where('immobile_id', $immobileId))->count();
    }
}

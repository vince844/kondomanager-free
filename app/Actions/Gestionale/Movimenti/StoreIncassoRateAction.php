<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Exceptions\Gestionale\CreditoDiAltroSoggettoException;
use App\Exceptions\Gestionale\CreditoInsufficienteException;
use App\Exceptions\Gestionale\CreditoFraGestioniDaScegliereException;
use App\Exceptions\Gestionale\CreditoNonPiuDisponibileException;
use App\Exceptions\Gestionale\GestioneIncassoDaScegliereException;
use App\Exceptions\Gestionale\DebitoNonDelPaganteException;
use App\Exceptions\Gestionale\ParteInPiuSenzaRateException;
use App\Exceptions\Gestionale\RataDellaParteInPiuDaScegliereException;
use App\Exceptions\Gestionale\TotaleIncassoNonCorrispondenteException;
use App\Models\Condominio;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Anagrafica;
use App\Helpers\MoneyHelper;
use App\Models\Esercizio;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Services\Gestionale\PianoCreditoIncasso;
use Illuminate\Support\Facades\DB;

class StoreIncassoRateAction
{
    /**
     * Non si alloca su un debito che non è del pagante.
     *
     * Cercando **per immobile** la situazione debitoria aggrega le quote di tutti i
     * comproprietari (`SituazioneDebitoriaController:39-45`): una riga può portare il debito di
     * Verdi mentre chi paga è Bianchi. Il motore però tocca solo le quote del pagante, e prima
     * della beta.48 allocare su una riga altrui non produceva un errore ma un **giro a vuoto**:
     *
     * - con il credito, il DARE prelevava e l'AVERE non trovava niente da chiudere, così il
     *   blocco del riaccredito restituiva tutto — due scritture che si annullano, un protocollo
     *   consumato, il debito intatto;
     * - con il contante, quello che non trovava dove andare diventava **anticipo del pagante**,
     *   lasciando aperto il debito che l'amministratore credeva di saldare.
     *
     * In entrambi i casi l'operazione riusciva senza fare quello che diceva.
     *
     * ## Perché sta qui e non dentro i due cicli
     *
     * È la lezione della beta.47: **il gate dei prerequisiti appartiene all'orchestratore, non
     * al passo.** Controllando prima di aprire la transazione, nessun ramo può scavalcarlo — e
     * soprattutto non resta niente a database di un'operazione rifiutata, nemmeno per il tempo
     * di un rollback.
     *
     * ## Cosa NON blocca, deliberatamente
     *
     * Se il pagante ha una quota su quella rata ma è **già saldata**, si passa: è la corsa fra
     * il caricamento della pagina e il salvataggio, non c'è niente da pagare ed è benigno.
     * Trasformarlo in errore sostituirebbe un difetto con un altro.
     *
     * E non riguarda **di chi è il credito**: quella domanda l'ha chiusa la beta.46
     * (`:281-297`), che permette al credito di un comproprietario di pagare il debito del
     * pagante quando i due condividono l'unità. Qui si guarda solo chi ha il **debito**.
     *
     * @param  array<int,array{rata_id:int,importo:float}>  $pagamentiOrdinari
     *
     * @throws DebitoNonDelPaganteException
     */
    private function guardiaDebitoDelPagante(array $pagamentiOrdinari, int $paganteId): void
    {
        foreach ($pagamentiOrdinari as $pagamento) {
            $quotaFaro = RataQuote::find($pagamento['rata_id']);

            if (! $quotaFaro) {
                continue; // Rata inesistente: la segnala il ciclo che scrive, con il suo errore.
            }

            $paganteHaDebito = RataQuote::where('rata_id', $quotaFaro->rata_id)
                ->where('anagrafica_id', $paganteId)
                ->where('importo', '>', 0)
                ->exists();

            if ($paganteHaDebito) {
                continue;
            }

            // Il messaggio nomina chi ha davvero il debito: senza, l'amministratore vede un
            // rifiuto su una riga che sullo schermo sembra sua e non capisce di chi sia.
            $intestatari = RataQuote::with('anagrafica')
                ->where('rata_id', $quotaFaro->rata_id)
                ->where('importo', '>', 0)
                ->get()
                // `anagrafiche` non ha una colonna `cognome`: `nome` porta il nome intero
                // («Aurora Bassi»). È la stessa lettura che fa il resto dell'azione a `:332`.
                ->map(fn ($q) => $q->anagrafica?->nome)
                ->filter()
                ->unique()
                ->implode(', ');

            // Eccezione dedicata e non `RuntimeException`: il controller cattura per tipo, e un
            // tipo generico uscirebbe come pagina 500 buttando via la distribuzione fatta a mano.
            // Vedi la classe per il resto della storia.
            throw new DebitoNonDelPaganteException(
                $quotaFaro->rata->numero_rata ?? '?',
                $intestatari
            );
        }
    }

    /**
     * La quota di chi ha versato per un altro su cui va la parte in più (Coda 167, decisioni 30.3 e 30.8).
     *
     * La sceglie l'amministratore (`quota_parte_in_piu_id`, già validata: sua, emessa, di questo condominio). Senza la
     * sua scelta — il modulo gliela propone già fatta, ma il server non conta sul modulo — vale la stessa proposta: la
     * **prima ancora da pagare** nella gestione dell'incasso, che la parte in più riduce; se lì sono tutte pagate,
     * l'**ultima**, su cui diventa credito spendibile con «Usa credito». In un'altra gestione non si va da soli: è un
     * trasferimento fra gestioni, e lo decide l'amministratore scegliendo la rata.
     * Solo rate **emesse**: un pagamento su una quota in bozza fa scattare `PianoRate::haIncassiRegistrati()` e blocca
     * il ricalcolo di quel piano (precisazione della decisione 30.3).
     *
     * La regola della Fase 1 (stessa rata del debitore, poi la più recente della gestione, poi del condominio) è
     * sostituita: «la più recente» era la scadenza più lontana, e il terzo passo attraversava le gestioni senza conferma.
     *
     * @throws ParteInPiuSenzaRateException se chi ha versato non ha rate emesse nel condominio
     * @throws RataDellaParteInPiuDaScegliereException se ne ha, ma nessuna nella gestione dell'incasso, e la scelta manca
     */
    private function quotaDiChiHaVersato(int $versatoDaId, string $nome, ?int $sceltaId, int $gestioneId, int $condominioId, int $parteInPiuCents, int $debitoCents): RataQuote
    {
        $sue = fn () => RataQuote::where('anagrafica_id', $versatoDaId)
            ->where('importo', '>', 0)
            ->whereHas('rata', fn ($r) => $r->where('stato', 'emessa'))
            ->whereHas('rata.pianoRate', fn ($p) => $p->where('condominio_id', $condominioId));

        if ($sceltaId) {
            // La scelta dell'amministratore, o niente: ripiegare sulla proposta sostituirebbe in silenzio la sua decisione.
            return $sue()->whereKey($sceltaId)->lockForUpdate()->first()
                ?? throw new RataDellaParteInPiuDaScegliereException($nome, $parteInPiuCents, sceltaNonPiuValida: true);
        }

        $nellaGestione = fn () => $sue()->whereHas('rata.pianoRate', fn ($p) => $p->where('gestione_id', $gestioneId));

        $proposta = $nellaGestione()->whereColumn('importo_pagato', '<', 'importo')
                ->orderBy('data_scadenza')->orderBy('id')->lockForUpdate()->first()
            ?? $nellaGestione()->orderByDesc('data_scadenza')->orderByDesc('id')->lockForUpdate()->first();

        if ($proposta) {
            return $proposta;
        }

        if ($sue()->exists()) {
            throw new RataDellaParteInPiuDaScegliereException($nome, $parteInPiuCents);
        }

        throw new ParteInPiuSenzaRateException($nome, $parteInPiuCents, $debitoCents);
    }

    public function execute(array $validated, Condominio $condominio, Esercizio $esercizio): ScritturaContabile
    {
        $pagamentiOrdinari = array_filter(
            $validated['dettaglio_pagamenti'],
            fn($item) => $item['importo'] > 0
        );

        $pagamentiCredito = array_filter(
            $validated['dettaglio_pagamenti'],
            fn($item) => $item['importo'] < 0
        );

        $sommaAlgebricaCents = array_reduce(
            $validated['dettaglio_pagamenti'],
            fn($carry, $item) => $carry + MoneyHelper::toCents($item['importo']),
            0
        );

        $eccedenzaInizialeCents = MoneyHelper::toCents($validated['eccedenza'] ?? 0);
        $importoTotaleCents     = MoneyHelper::toCents($validated['importo_totale']);

        if ($importoTotaleCents !== ($sommaAlgebricaCents + $eccedenzaInizialeCents)) {
            // Eccezione dedicata dalla beta.43: prima era un `RuntimeException` generico che
            // il controller non catturava, e una guardia di dominio finiva addosso
            // all'amministratore come pagina 500. Vedi la classe per il resto della storia.
            throw new TotaleIncassoNonCorrispondenteException(
                $importoTotaleCents,
                $sommaAlgebricaCents,
                $eccedenzaInizialeCents
            );
        }

        $this->guardiaDebitoDelPagante($pagamentiOrdinari, (int) $validated['pagante_id']);

        // Coda 167, 1.11.0-beta.40 (decisione 30): il pagante è la **posizione**, cioè il debitore, e il motore
        // continua a saldare le sue quote; `versato_da_id` dice chi ha versato davvero, se è un'altra persona. Non
        // cambia chi deve né dove va il denaro del debito: cambiano le note, il legame nel campo `riferimento` delle
        // righe che chiudono il debito, e dove va la parte in più (a chi ha versato, non al debitore).
        $paganteId  = (int) $validated['pagante_id'];
        $versatoDaId = ! empty($validated['versato_da_id']) && (int) $validated['versato_da_id'] !== $paganteId
            ? (int) $validated['versato_da_id']
            : null;

        // Decisione 30.7: con «Versato da» e un credito della posizione l'amministratore sceglie se il credito si usa
        // adesso, **prima** dei soldi versati («Si usa adesso»), o resta suo («Resta a X»: prima i soldi, il credito solo
        // sullo scoperto — il motore di sempre). Senza «Versato da» l'ordine è sempre quello di sempre.
        $creditoPrima = $versatoDaId !== null && ! empty($validated['credito_prima']);

        // La gestione di ogni riga, per le decisioni 30.11 e 30.12. Si legge una volta, prima della transazione.
        $quoteRighe = RataQuote::with('rata.pianoRate.gestione:id,nome')
            ->whereIn('id', collect($validated['dettaglio_pagamenti'])->pluck('rata_id'))
            ->get()
            ->keyBy('id');
        $gestioneDi = fn (array $p) => $quoteRighe[$p['rata_id']]?->rata?->pianoRate?->gestione_id;
        $nomiGestioni = fn ($ids) => collect($ids)->map(fn ($g) => $quoteRighe->first(fn ($q) => $q->rata?->pianoRate?->gestione_id === $g)?->rata?->pianoRate?->gestione?->nome ?? "gestione #{$g}")->unique()->values()->all();

        // Decisione 30.12: la gestione dell'incasso. Con il filtro «Gestione» il modulo la manda; senza, se le rate che
        // l'incasso paga — col denaro o coperte dal credito: sono le righe a importo positivo — sono di una gestione sola
        // è quella, se sono di più gestioni la sceglie l'amministratore. Contano anche le rate coperte dal credito perché
        // la gestione intesta tutte e due le scritture, l'incasso e la compensazione (SCRITTURA 2): leggere solo le rate
        // del denaro intesterebbe la compensazione in silenzio (reperto T4 del terzo giro). Prima si prendeva la gestione
        // della quota con l'id più basso — un criterio casuale, diverso da quello che il modulo mostrava (reperto S2 del
        // rigiro della Fase 1-bis).
        $gestioniRighe = collect($pagamentiOrdinari)->map($gestioneDi)->filter()->unique()->values();
        $gestioneId = ! empty($validated['gestione_id']) ? (int) $validated['gestione_id'] : null;
        if ($gestioneId === null && $gestioniRighe->count() > 1) {
            throw new GestioneIncassoDaScegliereException($nomiGestioni($gestioniRighe));
        }
        if ($gestioneId !== null && $gestioniRighe->isNotEmpty() && ! $gestioniRighe->contains($gestioneId)) {
            throw new GestioneIncassoDaScegliereException($nomiGestioni($gestioniRighe), sceltaFuori: true);
        }
        $gestioneId ??= $gestioniRighe->first();

        // Decisioni 30.7 e 30.11: quanto credito va su quale riga, e quanto della riga paga il denaro. Il credito di una
        // gestione copre da sé solo le righe della sua gestione; su un'altra solo con la scelta dell'amministratore, che
        // si pretende quando cambierebbe il risultato. La regola è una sola, qui e nel modulo: `PianoCreditoIncasso`.
        $fraGestioni = array_key_exists('credito_fra_gestioni', $validated) && $validated['credito_fra_gestioni'] !== null
            ? (bool) $validated['credito_fra_gestioni']
            : null;
        $piano = PianoCreditoIncasso::pianifica(
            collect($pagamentiOrdinari)->map(fn ($p, $k) => ['chiave' => $k, 'importo' => MoneyHelper::toCents($p['importo']), 'gestione' => $gestioneDi($p)])->values()->all(),
            collect($pagamentiCredito)->map(fn ($p, $c) => ['chiave' => $c, 'importo' => MoneyHelper::toCents(abs($p['importo'])), 'gestione' => $gestioneDi($p)])->values()->all(),
            $importoTotaleCents,
            $creditoPrima,
            $fraGestioni,
        );
        if ($piano['serve_scelta'] && $fraGestioni === null) {
            $gestioniCredito = collect($pagamentiCredito)->map($gestioneDi)->filter()->unique()->values();
            throw new CreditoFraGestioniDaScegliereException(
                $nomiGestioni($gestioniCredito),
                $nomiGestioni($gestioniRighe->diff($gestioniCredito)),
            );
        }

        return DB::transaction(function () use (
            $validated,
            $condominio,
            $esercizio,
            $importoTotaleCents,
            $eccedenzaInizialeCents,
            $pagamentiOrdinari,
            $pagamentiCredito,
            $paganteId,
            $versatoDaId,
            $piano,
            $gestioneId
        ) {
            $nomePagante   = Anagrafica::find($paganteId)->nome;
            $nomeVersatoDa = $versatoDaId !== null ? Anagrafica::find($versatoDaId)->nome : null;
            $cassa        = Cassa::with('contoContabile')->findOrFail($validated['cassa_id']);
            $contoCrediti = ContoContabile::where('condominio_id', $condominio->id)
                                ->where('ruolo', 'crediti_condomini')
                                ->firstOrFail();
            $contoAnticipi = ContoContabile::where('condominio_id', $condominio->id)
                                ->where('ruolo', 'anticipi_condomini')
                                ->first() ?? $contoCrediti;

            // La gestione è stata stabilita prima della transazione (30.12); senza nessuna riga a importo positivo (solo
            // parte in più, o rate senza gestione), la prima dell'esercizio, come sempre.
            if (!$gestioneId) {
                $gestioneId = $esercizio->gestioni()->first()->id;
            }

            // VARIABILI DI CONTROLLO CASSA
            $budgetCashCents      = $importoTotaleCents;
            $eccedenzaFinaleCents = $eccedenzaInizialeCents;

            // ---------------------------------------------------------
            // SCRITTURA 1 — INCASSO REALE (CONTANTI)
            // ---------------------------------------------------------
            $scritturaIncasso = ScritturaContabile::create([
                'condominio_id'      => $condominio->id,
                'esercizio_id'       => $esercizio->id,
                'gestione_id'        => $gestioneId,
                'data_registrazione' => now(),
                'data_competenza'    => $validated['data_pagamento'],
                'causale'            => $validated['descrizione'] ?: 'Incasso rate',
                'tipo_movimento'     => 'incasso_rata',
                'stato'              => 'registrata',
            ]);

            $rigaCassa = null;
            if ($importoTotaleCents > 0) {
                $rigaCassa = $scritturaIncasso->righe()->create([
                    'conto_contabile_id' => $cassa->contoContabile->id,
                    'cassa_id'           => $cassa->id,
                    'tipo_riga'          => 'dare',
                    'importo'            => $importoTotaleCents,
                    // Il denaro è entrato da chi ha versato: la riga di cassa lo nomina, e dice per conto di chi.
                    'note'               => $versatoDaId !== null
                        ? "Versamento rate {$nomeVersatoDa} per conto di {$nomePagante}"
                        : 'Versamento rate ' . $nomePagante,
                ]);
            }

            // Quota di appoggio per l'eventuale eccedenza: l'ultima quota del
            // pagante toccata dall'incasso (vedi blocco eccedenza più sotto).
            $quotaPerEccedenza = null;
            $ultimaQuotaFaro   = null;
            $versatoAlDebitoCents = 0;

            foreach ($pagamentiOrdinari as $k => $pagamento) {
                if ($budgetCashCents <= 0) break;

                // Il denaro paga della riga quello che il credito non copre (`PianoCreditoIncasso`, 30.7 e 30.11).
                $targetRataCents = $piano['contante'][$k] ?? MoneyHelper::toCents($pagamento['importo']);
                $importoDaDistribuireCents = min($targetRataCents, $budgetCashCents);

                if ($importoDaDistribuireCents <= 0) continue;

                $quotaFaro = RataQuote::findOrFail($pagamento['rata_id']);
                $ultimaQuotaFaro = $quotaFaro;

                $quoteDaSaldare = RataQuote::where('rata_id', $quotaFaro->rata_id)
                    ->where('anagrafica_id', $validated['pagante_id'])
                    ->where('importo', '>', 0)
                    ->lockForUpdate()
                    ->get();

                $quoteOrdinate = $quoteDaSaldare->sortByDesc(function ($quota) {
                    $quotaPura = $quota->importo;
                    $regole    = $quota->regole_calcolo;
                    if (!empty($regole)) {
                        $jsonArr  = is_string($regole) ? json_decode($regole, true) : (array) $regole;
                        $quotaPura = $jsonArr['importi']['quota_pura_gestione'] ?? ($jsonArr['audit']['quota_pura'] ?? $quota->importo);
                    }
                    return ($quotaPura * 1000000) - $quota->id;
                })->values();

                foreach ($quoteOrdinate as $quota) {
                    if ($importoDaDistribuireCents <= 0) break;

                    $debitoResiduoQuota = $quota->importo - $quota->importo_pagato;

                    if ($debitoResiduoQuota > 0) {
                        $importoDaVersareQui = min($importoDaDistribuireCents, $debitoResiduoQuota);

                        // 1. Attach alla pivot
                        $quota->pagamenti()->attach($scritturaIncasso->id, [
                            'importo_pagato' => $importoDaVersareQui,
                            'data_pagamento' => $validated['data_pagamento'],
                        ]);

                        // 2. Crea riga contabile
                        $scritturaIncasso->righe()->create([
                            'conto_contabile_id' => $contoCrediti->id,
                            'anagrafica_id'      => $quota->anagrafica_id,
                            'rata_id'            => $quota->rata_id,
                            'immobile_id'        => $quota->immobile_id,
                            'tipo_riga'          => 'avere',
                            'importo'            => $importoDaVersareQui,
                            // Coda 167: la riga che chiude il debito è quella che l'estratto conto del debitore
                            // mostra, e dice chi ha pagato al posto suo; il `riferimento` lega la riga a chi ha
                            // versato, ed è da lì che si ricostruisce il suo riquadro (`VersamentiPerContoDiAltri`).
                            'note'               => 'Incasso rata n.' . ($quota->rata->numero_rata ?? '')
                                . ($versatoDaId !== null ? ", versato da {$nomeVersatoDa}" : ''),
                            'riferimento_type'   => $versatoDaId !== null ? Anagrafica::class : null,
                            'riferimento_id'     => $versatoDaId,
                        ]);

                        // 3. Ricalcola lo stato e salva automaticamente
                        $quota->ricalcolaStato();

                        $quotaPerEccedenza = $quota;

                        $importoDaDistribuireCents -= $importoDaVersareQui;
                        $budgetCashCents -= $importoDaVersareQui;
                        $versatoAlDebitoCents += $importoDaVersareQui;
                    }
                }

                if ($importoDaDistribuireCents > 0) {
                    $eccedenzaFinaleCents += $importoDaDistribuireCents;
                    $budgetCashCents -= $importoDaDistribuireCents;
                }
            }

            // 30.7 b, caso limite: il credito del debitore ha coperto da solo tutto il debito, e del denaro versato niente
            // è andato a lui — finisce intero sulla rata di chi l'ha versato. Allora non è un versamento «per conto di»:
            // la riga di cassa lo dice, e nessuna riga porta il legame (riquadro, elenco e registro lo leggono da lì).
            if ($versatoDaId !== null && $versatoAlDebitoCents === 0 && $rigaCassa) {
                $rigaCassa->update(['note' => 'Versamento rate ' . $nomeVersatoDa]);
            }

            // Solo la parte di eccedenza effettivamente finanziata dai CONTANTI
            // appartiene a questa scrittura, che in DARE porta i soli contanti.
            // Quando l'incasso combina denaro e credito pregresso (es. debito 100,
            // credito 40, contanti 90 → eccedenza 30), l'eccedenza è finanziata dal
            // credito: contabilizzarla qui sbilanciava il giornale di quell'importo.
            // La parte residua viene restituita al credito nella scrittura 2.
            $eccedenzaCassaCents = min($eccedenzaFinaleCents, $budgetCashCents);
            $eccedenzaDaCreditoCents = $eccedenzaFinaleCents - $eccedenzaCassaCents;

            if ($eccedenzaCassaCents > 0) {
                // L'eccedenza viene registrata come STRAPAGAMENTO sull'ultima quota
                // del pagante toccata dall'incasso: così resta visibile nella
                // situazione debitoria come credito (residuo negativo) ed è
                // spendibile in seguito tramite "Usa credito" (compensazione).
                $noteEccedenza = 'Anticipo / Eccedenza';

                if ($versatoDaId !== null) {
                    // Coda 167, decisioni 30.3 e 30.8: la parte in più è di chi ha versato, non del debitore — e le
                    // quote toccate qui sopra sono tutte del debitore. Se chi ha versato non ha rate nel condominio, o
                    // le ha solo in un'altra gestione e l'amministratore non ne ha scelta una, l'incasso si ferma, e la
                    // transazione non lascia niente.
                    $quotaAccredito = $this->quotaDiChiHaVersato(
                        $versatoDaId,
                        $nomeVersatoDa,
                        ! empty($validated['quota_parte_in_piu_id']) ? (int) $validated['quota_parte_in_piu_id'] : null,
                        (int) $gestioneId,
                        $condominio->id,
                        $eccedenzaCassaCents,
                        $importoTotaleCents - $eccedenzaCassaCents,
                    );

                    // «Per conto di» solo se del denaro è andato davvero al debitore: quando il suo credito ha coperto
                    // tutto (30.7 b), il versamento è di chi l'ha fatto e basta (S14).
                    $noteEccedenza = $versatoAlDebitoCents > 0
                        ? "Anticipo / Eccedenza, dal versamento per conto di {$nomePagante}"
                        : 'Anticipo / Eccedenza';

                    // In un'altra gestione ci si arriva solo scegliendo la rata: la riga lo dice, come la compensazione
                    // fra gestioni dice di essere stata confermata.
                    $gestioneAccredito = $quotaAccredito->rata?->pianoRate?->gestione;
                    if ($gestioneAccredito && (int) $gestioneAccredito->id !== (int) $gestioneId) {
                        $noteEccedenza .= " — gestione {$gestioneAccredito->nome}, scelta dall'amministratore";
                    }
                } else {
                    $quotaAccredito = $quotaPerEccedenza;

                    if (!$quotaAccredito && $ultimaQuotaFaro) {
                        $quotaAccredito = RataQuote::where('rata_id', $ultimaQuotaFaro->rata_id)
                            ->where('anagrafica_id', $validated['pagante_id'])
                            ->where('importo', '>', 0)
                            ->orderByDesc('importo')
                            ->first();
                    }
                }

                if ($quotaAccredito) {
                    $quotaAccredito->pagamenti()->attach($scritturaIncasso->id, [
                        'importo_pagato' => $eccedenzaCassaCents,
                        'data_pagamento' => $validated['data_pagamento'],
                    ]);

                    $scritturaIncasso->righe()->create([
                        'conto_contabile_id' => $contoCrediti->id,
                        'anagrafica_id'      => $quotaAccredito->anagrafica_id,
                        'rata_id'            => $quotaAccredito->rata_id,
                        'immobile_id'        => $quotaAccredito->immobile_id,
                        'tipo_riga'          => 'avere',
                        'importo'            => $eccedenzaCassaCents,
                        'note'               => $noteEccedenza,
                    ]);

                    $quotaAccredito->ricalcolaStato();
                } else {
                    // Fallback legacy: nessuna quota di appoggio disponibile,
                    // l'eccedenza resta una pura scrittura contabile sugli anticipi.
                    $scritturaIncasso->righe()->create([
                        'conto_contabile_id' => $contoAnticipi->id,
                        'anagrafica_id'      => $validated['pagante_id'],
                        'tipo_riga'          => 'avere',
                        'importo'            => $eccedenzaCassaCents,
                        'note'               => 'Anticipo / Eccedenza',
                    ]);
                }
            }

            // ---------------------------------------------------------
            // SCRITTURA 2 — COMPENSAZIONE CREDITO
            // ---------------------------------------------------------
            if (!empty($pagamentiCredito)) {
                $scritturaStorno = ScritturaContabile::create([
                    'condominio_id'      => $condominio->id,
                    'esercizio_id'       => $esercizio->id,
                    'gestione_id'        => $gestioneId,
                    'scrittura_padre_id' => $scritturaIncasso->id, // 🟢 IL LINK DI SANGUE!
                    'data_registrazione' => now(),
                    'data_competenza'    => $validated['data_pagamento'],
                    'causale'            => 'Compensazione credito pregresso - ' . ($validated['descrizione'] ?: 'Incasso rate'),
                    'tipo_movimento'     => 'storno_credito',
                    'stato'              => 'registrata',
                ]);

                foreach ($pagamentiCredito as $c => $pagamentoCredito) {
                    $creditoDaConsumareCents = MoneyHelper::toCents(abs($pagamentoCredito['importo']));

                    // La quota passata dal frontend identifica la RATA di origine del
                    // credito. Su quella rata raccogliamo TUTTE le quote del pagante
                    // che portano credito, in entrambe le forme supportate:
                    // - saldo iniziale / anticipo (importo negativo non consumato)
                    // - strapagamento (importo_pagato > importo)
                    $quotaRef = RataQuote::with('rata.pianoRate.gestione:id,nome')
                        ->lockForUpdate()
                        ->findOrFail($pagamentoCredito['rata_id']);

                    $quoteCredito = RataQuote::where('rata_id', $quotaRef->rata_id)
                        ->where('anagrafica_id', $validated['pagante_id'])
                        ->with('rata.pianoRate.gestione:id,nome')
                        ->lockForUpdate()
                        ->get()
                        ->filter(fn($q) => $q->credito_disponibile > 0)
                        ->values();

                    // Il credito è intestato a un'altra persona? Succede nella ricerca
                    // per immobile, fra comproprietari della stessa unità, ed è
                    // legittimo — ma sposta denaro fra due soggetti diversi, che è
                    // cosa più delicata della compensazione fra gestioni (per la quale
                    // il sistema chiede già una conferma esplicita a schermo).
                    // Si accetta solo se i due condividono davvero quell'unità.
                    $creditoDaAltroSoggetto = false;

                    if ($quoteCredito->isEmpty() && $quotaRef->credito_disponibile > 0) {
                        // Senza filtro su `attivo` e di proposito (inventario B1, progetto sul
                        // subentro §4.3): «hanno condiviso l'unità» vale anche per una titolarità
                        // cessata, e `exists()` è indifferente all'ordine. Non passa dal risolutore.
                        $condividonoUnita = $quotaRef->immobile_id && DB::table('anagrafica_immobile')
                            ->where('immobile_id', $quotaRef->immobile_id)
                            ->where('anagrafica_id', $validated['pagante_id'])
                            ->exists();

                        if (! $condividonoUnita) {
                            throw new CreditoDiAltroSoggettoException;
                        }

                        $creditoDaAltroSoggetto = true;
                        $quoteCredito = collect([$quotaRef]);
                    }

                    if ($quoteCredito->isEmpty()) {
                        throw new CreditoNonPiuDisponibileException($pagamentoCredito['rata_id']);
                    }

                    // Tutte le quote di $quoteCredito condividono la stessa rata,
                    // quindi la stessa gestione: la leggiamo una sola volta per
                    // sapere se questa compensazione attraversa gestioni diverse.
                    $gestioneCredito = $quoteCredito->first()->rata->pianoRate->gestione ?? null;

                    $creditoResiduo = $quoteCredito->sum(fn($q) => $q->credito_disponibile);

                    if ($creditoDaConsumareCents > $creditoResiduo) {
                        throw new CreditoInsufficienteException($creditoResiduo, $creditoDaConsumareCents);
                    }

                    // --- LATO DARE (Svuotiamo il Salvadanaio / lo strapagamento) ---
                    $daPrelevareCents = $creditoDaConsumareCents;

                    foreach ($quoteCredito as $quotaCredito) {
                        if ($daPrelevareCents <= 0) break;

                        $prelievoCents = min($daPrelevareCents, $quotaCredito->credito_disponibile);

                        $scritturaStorno->righe()->create([
                            'conto_contabile_id' => $contoCrediti->id,
                            'anagrafica_id'      => $quotaCredito->anagrafica_id,
                            'rata_id'            => $quotaCredito->rata_id,
                            'immobile_id'        => $quotaCredito->immobile_id,
                            'tipo_riga'          => 'dare',
                            'importo'            => $prelievoCents,
                            // Se il credito viene da un altro soggetto la riga lo dichiara:
                            // in estratto conto deve restare leggibile di chi era quel denaro.
                            'note'               => $creditoDaAltroSoggetto
                                ? 'Utilizzo credito pregresso di ' . ($quotaCredito->anagrafica?->nome ?? "anagrafica #{$quotaCredito->anagrafica_id}")
                                    . ' (comproprietario della stessa unità)'
                                : 'Utilizzo credito pregresso',
                        ]);

                        // 1. Attach alla pivot (con importo negativo per ridurre il credito)
                        $quotaCredito->pagamenti()->attach($scritturaStorno->id, [
                            'importo_pagato' => -$prelievoCents,
                            'data_pagamento' => $validated['data_pagamento'],
                        ]);

                        // 2. Ricalcola (senza update manuale ridondante)
                        $quotaCredito->ricalcolaStato();

                        $daPrelevareCents -= $prelievoCents;
                    }

                    // --- LATO AVERE (Chiudiamo il debito sulla rata) ---
                    $budgetCreditoCents = $creditoDaConsumareCents;

                    foreach ($pagamentiOrdinari as $k => $pagOrd) {
                        if ($budgetCreditoCents <= 0) break;

                        // Quanto di questo credito va su questa riga lo dice il piano: solo righe della sua gestione,
                        // salvo la scelta dell'amministratore (30.11); il resto del credito si riaccredita qui sotto.
                        $tettoRigaCents = $piano['credito'][$c][$k] ?? 0;
                        if ($tettoRigaCents <= 0) continue;

                        $quotaFaroOrd = RataQuote::find($pagOrd['rata_id']);
                        if (!$quotaFaroOrd) continue;

                        $quoteOrdConResiduo = RataQuote::where('rata_id', $quotaFaroOrd->rata_id)
                            ->where('anagrafica_id', $validated['pagante_id'])
                            ->where('importo', '>', 0)
                            ->with('rata.pianoRate.gestione:id,nome')
                            ->lockForUpdate()
                            ->get()
                            ->filter(fn($q) => ($q->importo - $q->importo_pagato) > 0);

                        foreach ($quoteOrdConResiduo as $quotaOrd) {
                            if ($budgetCreditoCents <= 0 || $tettoRigaCents <= 0) break;

                            $residuoOrd = $quotaOrd->importo - $quotaOrd->importo_pagato;
                            $daApplicare = min($budgetCreditoCents, $residuoOrd, $tettoRigaCents);

                            $gestioneRata = $quotaOrd->rata->pianoRate->gestione ?? null;

                            // Compensazione tra gestioni diverse (es. credito ordinaria usato su rata
                            // straordinaria): arriva qui solo se l'amministratore l'ha scelta
                            // (`credito_fra_gestioni`, decisione 30.11), perché senza la scelta il
                            // pianificatore non mette il credito su un'altra gestione e, quando
                            // cambierebbe il risultato, l'incasso si ferma prima della transazione
                            // (CreditoFraGestioniDaScegliereException). La nota lo dice.
                            $noteCompensazione = ($gestioneCredito && $gestioneRata && $gestioneCredito->id !== $gestioneRata->id)
                                ? "Compensazione cross-gestione confermata dall'amministratore: {$gestioneCredito->nome} → {$gestioneRata->nome} — rata n." . ($quotaOrd->rata->numero_rata ?? '')
                                : 'Compensazione credito su rata n.' . ($quotaOrd->rata->numero_rata ?? '');

                            $scritturaStorno->righe()->create([
                                'conto_contabile_id' => $contoCrediti->id,
                                'anagrafica_id'      => $quotaOrd->anagrafica_id,
                                'rata_id'            => $quotaOrd->rata_id,
                                'immobile_id'        => $quotaOrd->immobile_id,
                                'tipo_riga'          => 'avere',
                                'importo'            => $daApplicare,
                                'note'               => $noteCompensazione,
                            ]);

                            // 1. Attach alla pivot
                            $quotaOrd->pagamenti()->attach($scritturaStorno->id, [
                                'importo_pagato' => $daApplicare,
                                'data_pagamento' => $validated['data_pagamento'],
                            ]);

                            // 2. Ricalcola (senza update manuale ridondante)
                            $quotaOrd->ricalcolaStato();

                            $budgetCreditoCents -= $daApplicare;
                            $tettoRigaCents -= $daApplicare;
                        }
                    }

                    // Il credito prelevato in DARE può eccedere il debito residuo:
                    // succede ogni volta che contanti + credito superano il dovuto
                    // (debito 100, credito 40, contanti 90 → 30 in eccesso). Quella
                    // parte non è stata usata e va RESTITUITA al credito, altrimenti
                    // la scrittura resta sbilanciata e il condòmino perde credito che
                    // non ha speso. La restituzione va sulle stesse quote da cui il
                    // credito è stato prelevato: effetto netto = solo quanto servito.
                    if ($budgetCreditoCents > 0) {
                        foreach ($quoteCredito as $quotaCredito) {
                            if ($budgetCreditoCents <= 0) break;

                            $giaPrelevato = abs((int) DB::table('quota_scrittura')
                                ->where('rate_quota_id', $quotaCredito->id)
                                ->where('scrittura_contabile_id', $scritturaStorno->id)
                                ->sum('importo_pagato'));

                            $daRestituire = min($budgetCreditoCents, $giaPrelevato);
                            if ($daRestituire <= 0) continue;

                            $scritturaStorno->righe()->create([
                                'conto_contabile_id' => $contoCrediti->id,
                                'anagrafica_id' => $quotaCredito->anagrafica_id,
                                'rata_id' => $quotaCredito->rata_id,
                                'immobile_id' => $quotaCredito->immobile_id,
                                'tipo_riga' => 'avere',
                                'importo' => $daRestituire,
                                'note' => 'Credito non utilizzato, riaccreditato',
                            ]);

                            $quotaCredito->pagamenti()->attach($scritturaStorno->id, [
                                'importo_pagato' => $daRestituire,
                                'data_pagamento' => $validated['data_pagamento'],
                            ]);

                            $quotaCredito->ricalcolaStato();

                            $budgetCreditoCents -= $daRestituire;
                        }
                    }
                }
            }

            // Quadratura verificata anche qui: fino alla v1.10 il DoubleEntryValidator
            // era chiamato solo dal ciclo passivo (fatture e pagamenti), mentre incassi,
            // storni incassi ed emissioni rate scrivevano nel giornale senza alcun
            // controllo. Il check a monte (riga 38) valida l'INPUT dell'utente, non il
            // ledger prodotto: sono due cose diverse.
            DoubleEntryValidator::validateOrFail($scritturaIncasso->id);

            if (isset($scritturaStorno) && $scritturaStorno->righe()->exists()) {
                DoubleEntryValidator::validateOrFail($scritturaStorno->id);
            }

            return $scritturaIncasso;
        });
    }
}
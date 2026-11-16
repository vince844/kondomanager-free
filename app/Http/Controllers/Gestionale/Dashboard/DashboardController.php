<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Dashboard;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\BudgetCoverageService;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Services\PianoRateQuoteService;
use App\Services\Riparto\VoceDiSpesaNegativa;
use App\Services\Dashboard\WidgetManager;
use App\Services\Dashboard\Widgets\TreasuryGuardianWidget;
use App\Services\Dashboard\Widgets\ControlliPostImportWidget;
use App\Services\Dashboard\Widgets\CreditiDaCompensareWidget;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use HasCondomini, HasEsercizio;

    public function __invoke(Condominio $condominio, BudgetCoverageService $coverageService, SpesaPerVoceService $spesaPerVoce, WidgetManager $widgetManager, TreasuryGuardianWidget $treasuryWidget, CreditiDaCompensareWidget $creditiWidget, ControlliPostImportWidget $controlliWidget, PianoRateQuoteService $pianoRateQuoteService): Response
    {
        $widgetManager->registerMany([$treasuryWidget, $creditiWidget, $controlliWidget]);

        $esercizio = $this->getEsercizioCorrente($condominio);
        // La copertura del bilancio, la parte più lenta della pagina: calcolata solo quando la pagina chiede una delle sue tre prop. Un caricamento parziale
        // che non le chiede (una scheda dell'Inbox, lo scorrimento, la finestra «Emetti le rate», le lettere) non la rifà.
        $calcoloCopertura = null;
        $coperturaDelBilancio = function () use (&$calcoloCopertura, $esercizio, $condominio, $coverageService, $spesaPerVoce, $pianoRateQuoteService) {
            if ($calcoloCopertura !== null) {
                return $calcoloCopertura;
            }
            $copertura = null;
            $pianiDisallineati = [];
            $fattureScoperte = [];

            if ($esercizio) {
                $esercizio->load('gestioni');
                $totPrev = 0; 
                $totSpese = 0;
                $totEntrate = 0;
                $totPian = 0; 
                $totVirtualeGlobale = 0;
                $totBudgetPuro = 0;
                $vociScoperte = [];

                // --- 1. Speso reale dell'esercizio (SOLO SPESE COMUNI) ---
                // Letto dal libro giornale: include anche regolazioni immediate e
                // fatture pregresse, invisibili alla vecchia query su righe_fattura.
                $fatturatoMap = $spesaPerVoce->perEsercizio($esercizio);

                // --- NUOVO: Recupero spese ad personam totali ---
                // Nelle righe_fattura il false in MySQL spesso è salvato come 0.
                $addebitiPersonali = (int) DB::table('righe_fattura')
                    ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                    ->where('fatture_passive.esercizio_id', $esercizio->id)
                    ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                    ->whereNotNull('righe_fattura.immobile_id') // Solo le spese private
                    ->where(function($q) {
                        $q->where('righe_fattura.is_rateizzata', false)
                          ->orWhere('righe_fattura.is_rateizzata', 0)
                          ->orWhereNull('righe_fattura.is_rateizzata'); 
                    })
                    ->sum(DB::raw('righe_fattura.importo_imponibile + righe_fattura.importo_iva'));

                // --- STEP 7: LISTA FATTURE SCOPERTE (Sopravvenienze + Ad Personam) ---
                $fattureScoperteRaw = FatturaPassiva::with([
                        'fornitore', 
                        'coperture',
                        'righe' => function ($q) {
                            $q->with(['immobile', 'conto'])
                            ->where(function($sq) {
                                $sq->where('is_rateizzata', false)
                                    ->orWhere('is_rateizzata', 0)
                                    ->orWhereNull('is_rateizzata'); // FIX NULL
                            })
                            ->where(function ($inner) {
                                $inner->where('is_sopravvenienza', true)
                                        ->orWhereNotNull('immobile_id');
                            });
                        }
                    ])
                    ->where('esercizio_id', $esercizio->id)
                    // Coda 165 (1.11.0-beta.36): fatture, non note — come il carrello.
                    ->where('tipo_documento', 'fattura')
                    ->where('stato_approvazione', '!=', 'contestata')
                    ->where('stato_pagamento', '!=', 'stornata')
                    ->where(function ($query) {
                        $query->whereHas('righe', function ($q) {
                            $q->where(function($sq) {
                                $sq->where('is_rateizzata', false)
                                    ->orWhere('is_rateizzata', 0)
                                    ->orWhereNull('is_rateizzata'); // FIX NULL
                            })
                            ->where(function ($inner) {
                                $inner->where('is_sopravvenienza', true)
                                        ->orWhereNotNull('immobile_id');
                            });
                        })
                        ->orWhere(function ($qPregresso) {
                            $qPregresso->where('is_pregresso', 1)
                                    ->whereHas('coperture', function ($qCop) {
                                        $qCop->where('tipo_copertura', 'sopravvenienza');
                                    });
                        });
                    })
                    ->get();

                $gestionePrincipaleId = $esercizio->gestioni->where('attiva', true)->first()?->id;

                // Coda 165: la fattura rettificata da una nota del fornitore collegata è in sospeso solo per il suo netto, e
                // quella annullata per intero non lo è più. La stessa regola del carrello (`NettoNoteCollegate`).
                $netti = \App\Services\Gestionale\NettoNoteCollegate::perFatture($fattureScoperteRaw->pluck('id')->all());

                foreach ($fattureScoperteRaw as $fattura) {
                    $datiExtra = is_string($fattura->dati_extra) 
                        ? json_decode($fattura->dati_extra, true) 
                        : (array) $fattura->dati_extra;
                    
                    $strategia = $datiExtra['override_budget']['strategia_rientro'] ?? 
                                $datiExtra['log_legale_sopravvenienza']['strategia_rientro'] ?? 
                                'nessuna';

                    $totaleFatturaCents = 0;
                    $righeMappate = [];

                    foreach ($fattura->righe as $riga) {
                        $importoRigaCents = (int) round(($riga->importo_imponibile + $riga->importo_iva));
                        $totaleFatturaCents += $importoRigaCents;

                        $righeMappate[] = [
                            'id'          => 'r_' . $riga->id,
                            'tipo'        => $riga->immobile_id ? 'ad_personam' : 'comune',
                            'importo'     => $importoRigaCents, 
                            'descrizione' => $riga->immobile_id 
                                ? "Addebito personale (Art. 63)" 
                                : "Parte comune (millesimale)",
                            'dettaglio'   => $riga->immobile_id
                                // `etichetta`: ripiega sul nome quando l'interno non c'è (beta.58).
                        ? $riga->immobile->etichetta
                                : ($riga->conto ? "Voce: {$riga->conto->nome}" : "Imprevisto")
                        ];
                    }

                    // Righe virtuali per sopravvenienze pregresse
                    if ($fattura->is_pregresso && $fattura->coperture->isNotEmpty()) {
                        foreach ($fattura->coperture as $copertura) {
                            if ($copertura->tipo_copertura === 'sopravvenienza') {
                                $importoCopCents = (int) $copertura->importo;
                                $totaleFatturaCents += $importoCopCents;

                                $righeMappate[] = [
                                    'id'          => 'c_' . $copertura->id,
                                    'tipo'        => 'comune',
                                    'importo'     => $importoCopCents,
                                    'descrizione' => "Integrazione Debito Storico",
                                    'dettaglio'   => "Eccedenza da fattura anno precedente"
                                ];
                            }
                        }
                    }

                    if (isset($netti[$fattura->id]) && $totaleFatturaCents > $netti[$fattura->id]['netto']) {
                        $note = $netti[$fattura->id]['note'];
                        $righeMappate[] = [
                            'id'          => 'n_' . $fattura->id,
                            'tipo'        => 'nota',
                            'importo'     => $netti[$fattura->id]['netto'] - $totaleFatturaCents,
                            'descrizione' => count($note) === 1 ? 'Nota di credito collegata' : 'Note di credito collegate',
                            'dettaglio'   => 'n. ' . implode(', n. ', array_column($note, 'numero')),
                        ];
                        $totaleFatturaCents = $netti[$fattura->id]['netto'];
                    }

                    if ($totaleFatturaCents > 0) {
                        $fattureScoperte[] = [
                            'id'             => $fattura->id,
                            'numero'         => $fattura->numero_documento,
                            'fornitore'      => $fattura->fornitore->ragione_sociale ?? 'Sconosciuto',
                            'totale_scoperto' => $totaleFatturaCents,
                            'gestione_id'    => $gestionePrincipaleId,
                            'strategia'      => $strategia,
                            'righe'          => $righeMappate,
                        ];
                    }
                }
                // ----------------------------------------------------------------------

                // --- 2. Recupero di TUTTE le strategie di sforo (SOLO SPESE COMUNI) ---
                $fattureSforo = DB::table('righe_fattura')
                    ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                    ->where('fatture_passive.esercizio_id', $esercizio->id)
                    ->where('fatture_passive.stato_approvazione', 'sforo_motivato')
                    ->whereNull('righe_fattura.immobile_id') // Esclude le spese ad personam
                    ->select(
                        'righe_fattura.conto_id', 
                        'fatture_passive.dati_extra', 
                        'righe_fattura.importo_imponibile', 
                        'righe_fattura.importo_iva'
                    )
                    ->get();

                $coperturaVirtualeMap = [];
                $strategieMap = [];

                foreach ($fattureSforo as $row) {
                    $datiExtra = is_string($row->dati_extra) ? json_decode($row->dati_extra, true) : (array) $row->dati_extra;
                    $strat = $datiExtra['override_budget']['strategia_rientro'] ?? 'conguaglio_fine_anno'; 
                    $importoRiga = abs((int)$row->importo_imponibile + (int)$row->importo_iva);

                    if (in_array($strat, ['conguaglio_fine_anno', 'fondo_riserva'])) {
                        $coperturaVirtualeMap[(int)$row->conto_id] = ($coperturaVirtualeMap[(int)$row->conto_id] ?? 0) + $importoRiga;
                    }
                    
                    if (!isset($strategieMap[(int)$row->conto_id]) || $strategieMap[(int)$row->conto_id] !== 'rata_integrativa') {
                        $strategieMap[(int)$row->conto_id] = $strat;
                    }
                }

                foreach ($esercizio->gestioni as $gestione) {
                    $report = $coverageService->analyze($gestione, $fatturatoMap, $coperturaVirtualeMap);

                    if (($report['status'] ?? '') === 'empty') {
                        continue;
                    }
                    
                    $fabbisognoRealeGestione = 0;
                    $budgetPuroGestione = 0;
                    $virtualeGestione = 0; 

                    foreach ($report['items'] as $item) {
                        if (!($item['is_leaf'] ?? false)) continue;

                        $budgetTeorico = $item['budget_teorico'];
                        $spesoReale = $fatturatoMap[$item['id']] ?? 0;

                        // Le voci di entrata si sottraggono: il fabbisogno da ripartire è il netto, come lo distribuisce il motore.
                        $segno = $item['segno'];
                        $fabbisognoVoce = max($budgetTeorico, $spesoReale);

                        $fabbisognoRealeGestione += $segno * $fabbisognoVoce;
                        if ($segno > 0) {
                            $totSpese += $fabbisognoVoce;
                        } else {
                            $totEntrate += $fabbisognoVoce;
                        }
                        $budgetPuroGestione += $segno * $budgetTeorico;
                        $virtualeGestione += $segno * ($item['copertura_virtuale'] ?? 0);
                    }

                    $totPrev += $fabbisognoRealeGestione;
                    $totBudgetPuro += $budgetPuroGestione;
                    $totPian += $report['totali']['pianificato'];
                    $totVirtualeGlobale += $virtualeGestione; 

                    // --- 3. Analisi Deficit Pura ---
                    // Una voce di spesa scritta con il meno resta fra le scoperte (il calcolo la addebita in positivo), ma la creazione non la propone:
                    // la scheda dice la sua nota, che nomina i piani che la addebitano o dice di correggerla, e non porta alla creazione.
                    $idDelleNegative = collect($report['items'])->filter(fn ($i) => $i['spesa_negativa'] ?? false)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $pianiDelleNegative = VoceDiSpesaNegativa::pianiPerVoce($idDelleNegative, (int) $gestione->id);
                    $bloccoDelleNegative = Conto::pianiCheBloccanoLaVoce($idDelleNegative);
                    $negativeAlTerzoLivello = VoceDiSpesaNegativa::alTerzoLivello($idDelleNegative);
                    foreach ($report['items'] as $item) {
                        if (!($item['is_leaf'] ?? false)) continue;

                        $fabbisognoReale = max($item['budget'], ($fatturatoMap[$item['id']] ?? 0));
                        $pianificato = (int) ($item['pianificato'] ?? 0);
                        $deficitRispettoRate = $fabbisognoReale - $pianificato;
                        
                        if ($deficitRispettoRate > 100) { 

                            $stratScelta = $strategieMap[$item['id']] ?? null;

                            $tipoStrategia = 'nessuna';
                            if ($stratScelta === 'rata_integrativa') {
                                $tipoStrategia = 'rata_integrativa'; 
                            } elseif ($stratScelta === 'fondo_riserva') {
                                $tipoStrategia = 'fondo_riserva';    
                            } elseif ($stratScelta === 'conguaglio_fine_anno') {
                                $tipoStrategia = 'conguaglio';       
                            }

                            $spesaNegativa = (bool) ($item['spesa_negativa'] ?? false);
                            $vociScoperte[] = [
                                'id'          => $item['id'],
                                'nome'        => $item['nome'],
                                'importo'     => $deficitRispettoRate, 
                                'gestione'    => $gestione->nome,
                                'gestione_id' => $gestione->id,
                                'is_sforo'    => isset($fatturatoMap[$item['id']]) && $fatturatoMap[$item['id']] > $item['budget_teorico'],
                                'strategia'   => $tipoStrategia,
                                'spesa_negativa' => $spesaNegativa,
                                // L'importo scritto della voce è il suo valore assoluto col meno: `budget_teorico` lo conta in positivo.
                                'nota'        => $spesaNegativa
                                    ? VoceDiSpesaNegativa::nota((string) $item['nome'], -(int) $item['budget_teorico'], $pianiDelleNegative[(int) $item['id']] ?? [], $bloccoDelleNegative[(int) $item['id']] ?? [], in_array((int) $item['id'], $negativeAlTerzoLivello, true))
                                    : null,
                            ];

                        }
                    }

                    // Le quote non si caricano: il verdetto le somma nel database (`PianoRateQuoteService::totalePuroGeneratoCents()`). Caricate come modelli, a
                    // 3.000 persone × 12 rate erano 36.000 quote e 125 MB per una cifra (Coda 520).
                    $pianiRate = PianoRate::where('gestione_id', $gestione->id)
                        ->with([
                            'capitoli' => function($q) {
                                $q->select('conti.id', 'conti.importo', 'conti.tipo');
                            }, 
                            'fattureStraordinarie', 
                            'rate',
                        ]) 
                        ->get();
                    
                    // Il verdetto vive in `PianoRateQuoteService` dall'11/08/2026, e non più qui:
                    // la stessa domanda la fa anche la pagina del piano, e finché ognuna se la
                    // calcolava per conto proprio le due rispondevano in modo diverso — metodi
                    // diversi e tolleranze diverse (zero qui, € 2,00 di là). Vedi `eDisallineato()`.
                    // Coda 414: un piano in «quota aperta» (una quota non assegnata accettata, e il piano non si è mosso da allora) non è
                    // nell'elenco: «Ricalcola» non la assegna a nessuno. `eDisallineato()` vuol dire il terzo stato.
                    foreach ($pianiRate as $piano) {
                        if ($pianoRateQuoteService->eDisallineato($piano)) {
                            $pianiDisallineati[] = [
                                'id' => $piano->id,
                                'nome' => $piano->nome,
                                'gestione' => $gestione->nome,
                                'delta' => $pianoRateQuoteService->totaleAttesoCents($piano)
                                         - $pianoRateQuoteService->totalePuroGeneratoCents($piano),
                            ];
                        }
                    }
                }

                // 1. Calcolo Fatturato Reale Condiviso
                // Senza gli incassi da terzi: l'incasso dell'antenna non è una spesa da sottrarre alle uscite; tutto il resto conta come prima.
                $totFatturatoCondiviso = array_sum($spesaPerVoce->perTipo($fatturatoMap, $esercizio)['spese']);

                // 2. Il delta è purissimo (Totale Fabbisogno - Rate Pianificate)
                $deltaReale = $totPrev - ($totPian + $totVirtualeGlobale);

                $copertura = [
                    'spese'              => $totSpese,
                    'entrate'            => $totEntrate,
                    'da_ripartire'       => $totPrev,
                    'pianificato'        => $totPian, 
                    'virtuale'           => $totVirtualeGlobale, 
                    'addebiti_personali' => $addebitiPersonali,
                    // Uscite totali verso i fornitori (vero debito di oggi!)
                    'uscite_totali'      => $totFatturatoCondiviso + $addebitiPersonali, 
                    'delta'              => $deltaReale,
                    'scoperto'           => max(0, $deltaReale),
                    'percentuale'        => $totPrev > 0 
                                                ? round((($totPian + $totVirtualeGlobale) / $totPrev) * 100, 1) 
                                                : 0,
                    'is_completo'        => abs($deltaReale) <= 500,
                    'has_sforo'          => $totPrev > $totBudgetPuro,
                    'orfani'             => $vociScoperte,
                    'scoperto_count'     => collect($vociScoperte)
                                                ->whereNotIn('strategia', ['conguaglio', 'fondo_riserva'])
                                                ->count(),
                    // True se nell'esercizio esiste almeno un piano rate con quota orfana
                    // documentata dall'admin (nota_scoperti non nulla). Usato dal widget
                    // per mostrare lo stato "QUOTA APERTA" invece del generico allarme.
                    'has_scoperti_documentati' => $esercizio
                        ? PianoRate::whereIn('gestione_id', $esercizio->gestioni->pluck('id'))
                            ->whereNotNull('nota_scoperti')
                            ->exists()
                        : false,
                ];
            }

            return $calcoloCopertura = [$copertura, $pianiDisallineati, $fattureScoperte];
        };

        // I compiti aperti del condominio, con le regole dell'Action inbox, una sola per le due Inbox (`ElencoDellInbox`): un compito in attesa di «Ricordamelo»
        // non c'è, né una scadenza privata; l'ordine (gli scaduti, le segnalazioni aperte, i bonifici da verificare, gli altri), la regola di «scaduto» e i gruppi
        // di `FasceDeiCompiti` (un compito di oggi non è scaduto; le segnalazioni e i bonifici segnalati non scadono), la prossima azione del tipo di
        // `TipiDeiCompiti` al posto di «Risolvi», la priorità della segnalazione, il titolo senza il condominio ripetuto, le risposte. In più le chiavi di prima
        // della riga del condominio: `action_url`, `status` («expired» solo per gli scaduti), `days_pending`.
        // I compiti delle segnalazioni sono quelli che chi apre la pagina può aprire, con la regola della pagina delle segnalazioni.
        // Le schede «Tutte», «Scadute», «In arrivo» (`?inbox=scaduti`, `?inbox=in_arrivo`) filtrano l'elenco con la regola di `FasceDeiCompiti`, come le
        // schede dell'Action inbox; le segnalazioni e i bonifici, che non scadono, stanno solo in «Tutte». I numeri delle schede e il cartellino valgono per
        // tutte le schede: si contano senza la data.
        $elenco = new \App\Services\Gestionale\Inbox\ElencoDellInbox(\App\Services\Gestionale\Inbox\FasceDeiCompiti::oggi());
        $schedaInbox = in_array(request()->query('inbox'), ['scaduti', 'in_arrivo'], true) ? request()->query('inbox') : 'tutti';
        $filtriInbox = \App\Services\Gestionale\Inbox\ElencoDellInbox::filtri(['condominio_id' => $condominio->id, 'quando' => $schedaInbox === 'tutti' ? null : $schedaInbox],
            request()->user());
        $schede = null;
        $schedeInbox = function () use (&$schede, $elenco, $filtriInbox) {
            return $schede ??= \App\Services\Gestionale\Inbox\ElencoDellInbox::schede($elenco->perFascia($filtriInbox));
        };
        $inboxTasks = $elenco->pagina($filtriInbox)
            ->through(fn (array $riga) => $riga + [
                'action_url'   => $riga['context']['action_url'] ?? null,
                'status'       => $riga['gruppo'] === 'scaduti' ? 'expired' : 'scheduled',
                'days_pending' => $riga['gruppo'] === 'scaduti' ? -$riga['giorni'] : 0,
            ]);

        // I widget (la tesoreria, i crediti da compensare, i controlli dopo l'import) si calcolano quando la pagina li chiede: un caricamento parziale che non
        // li chiede (lo scorrimento dell'Inbox) non rifà la tesoreria.
        $widgets = fn () => $widgetManager->getPayloads($condominio->id);
        // Il riquadro dei sei mesi dopo i widget, in fila con la tesoreria: ognuno con la sua lettura, e la memoria della prima liberata prima della seconda
        // (misurato: il picco in fila è quello della tesoreria, con la lettura condivisa è più alto). Calcolato a ogni apertura, mai scritto; i suoi compiti
        // arrivano all'Inbox in una prop loro. Le due prop leggono un calcolo solo, e un caricamento parziale che non le chiede (lo scorrimento dell'Inbox)
        // non lo fa.
        $calcolo = null;
        $seiMesi = function () use (&$calcolo, $condominio) {
            if ($calcolo === null) {
                gc_collect_cycles();
                $calcolo = app(SeiMesiPerAgire::class)->perCondominio($condominio);
            }

            return $calcolo;
        };

        return Inertia::render('gestionale/dashboard/Dashboard', [
            'condominio'        => $condominio, 
            'condomini'         => $this->getCondomini(),
            'esercizio'         => $esercizio, 
            'esercizi'          => $condominio->esercizi, 
            'copertura'         => fn () => $coperturaDelBilancio()[0],
            'fattureScoperte'   => fn () => $coperturaDelBilancio()[2],
            'pianiDisallineati' => fn () => $coperturaDelBilancio()[1],
            'widgets'           => $widgets,
            'inboxTasks'        => Inertia::scroll($inboxTasks),
            // Quanti compiti dell'Inbox sono scaduti, con la regola di `FasceDeiCompiti`: il cartellino è rosso solo con uno scaduto.
            'inboxScaduti'      => fn () => $schedeInbox()['scaduti'],
            // Il numero di ogni scheda («Tutte», «Scadute», «In arrivo»), e la scheda scelta.
            'inboxSchede'       => fn () => $schedeInbox(),
            'inboxScheda'       => $schedaInbox,
            'seiMesi'           => fn () => $seiMesi(),
            'compitiSeiMesi'    => fn () => $seiMesi()['compiti'],
            // La finestra «Emetti le rate» del cassetto della tesoreria: i piani con rate non ancora emesse e le loro rate, solo quando la finestra li chiede con
            // un caricamento parziale; mai con la pagina.
            'emissioneRapida'   => Inertia::optional(fn () => app(\App\Services\Treasury\FinestraDiEmissione::class)->perCondominio($condominio)),
            // Il giorno dell'amministratore, per «Ricordamelo» sui compiti dell'agenda (da domani): non quello del browser.
            'oggi'              => DateHelper::oggiUtente(),
        ]);
    }
}
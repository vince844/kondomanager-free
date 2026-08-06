<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Helpers\MoneyHelper; 
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\RataQuote; 
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SituazioneDebitoriaController extends Controller
{
    use HasEsercizio;

    public function __invoke(Request $request, Condominio $condominio): JsonResponse
    {
        // 1. Base Query
        $query = RataQuote::query()
            ->whereHas('rata', function($q) use ($condominio) {
                $q->whereHas('pianoRate', fn($p) => $p->where('condominio_id', $condominio->id));
            });

        // 2. Filtro Logico: Rate non ancora pagate del tutto o a credito.
        // Include anche le quote STRAPAGATE (importo_pagato > importo): sono
        // credito disponibile e devono nettare il residuo della rata, altrimenti
        // la UI mostra un debito inesistente (rischio doppio addebito).
        $query->where(function($q) {
            $q->whereRaw('importo > importo_pagato')
              ->orWhereRaw('importo_pagato > importo')
              ->orWhere('importo', '<', 0);
        });

        // 3. Filtri Contestuali
        if ($request->has('immobile_id') && $request->immobile_id) {
            $query->where('immobile_id', $request->immobile_id);
        } elseif ($request->has('anagrafica_id') && $request->anagrafica_id) {
            $query->where('anagrafica_id', $request->anagrafica_id);
        } else {
            return response()->json(['rate' => []]);
        }

        // 4. Esecuzione
        $rawQuotes = $query->with(['rata.pianoRate.gestione', 'immobile', 'rata', 'anagrafica'])
            ->orderBy('data_scadenza', 'asc') 
            ->get();

        // 5. AGGREGAZIONE PER RATA PADRE
        $groupedRate = $rawQuotes->groupBy('rata_id')->map(function ($gruppoQuotes) use ($condominio) {
            
            $first = $gruppoQuotes->first();
            
            // Calcoli Matematici sui Centesimi (Interi)
            $importoTotale = $gruppoQuotes->sum('importo');
            $importoPagato = $gruppoQuotes->sum('importo_pagato');
            $residuoNetto = ($importoTotale - $importoPagato);

            // Se il residuo netto totale è 0, controlliamo se è una "Compensazione Mista"
            if (abs($residuoNetto) < 1) {
                $hasDebiti = $gruppoQuotes->contains(fn($q) => ($q->importo - $q->importo_pagato) > 0);
                $hasCrediti = $gruppoQuotes->contains(fn($q) => ($q->importo - $q->importo_pagato) < 0);
                
                // Se NON contiene sia debiti che crediti (cioè è solo una rata vuota/pagata), allora ignorala
                if (!($hasDebiti && $hasCrediti)) {
                    return null;
                }
            }

            // Prepariamo dati per il fallback (Legacy V1.7)
            $pianoRate = $first->rata->pianoRate;
            $metodoDist = $pianoRate->metodo_distribuzione ?? 'prima_rata'; 
            $numeroRata = $first->rata->numero_rata;
            $totaleRatePiano = $pianoRate->numero_rate;

            $esercizioId = null;

            // --- MAPPATURA DETTAGLIO QUOTE PER IL FRONTEND VUE ---
            $dettaglioTooltip = $gruppoQuotes->map(function($q) use ($condominio, &$esercizioId, $metodoDist, $numeroRata, $totaleRatePiano, $first) {
                
                $residuoNettoQuota = ($q->importo - $q->importo_pagato); 
                $unita = $q->immobile ? "Int. {$q->immobile->interno}" : 'Generico';

                // 1. RECUPERO RUOLO DALLA TUA TABELLA PIVOT
                $ruoloIniziale = 'P'; // Default fallback
                if ($q->anagrafica_id && $q->immobile_id) {
                    $relazione = DB::table('anagrafica_immobile')
                        ->where('anagrafica_id', $q->anagrafica_id)
                        ->where('immobile_id', $q->immobile_id)
                        ->where('attivo', true)
                        ->first();
                        
                    if ($relazione) {
                        // Prende la prima lettera: 'proprietario' -> 'P', 'inquilino' -> 'I', 'usufruttuario' -> 'U'
                        $ruoloIniziale = strtoupper(substr($relazione->tipologia, 0, 1));
                    }
                }
                
                $componenteSaldo = 0;
                $componenteSpesa = 0;

                // 1. Lettura JSON (V1.9) - SICURA SUL CAST ARRAY DI LARAVEL
                $regole = $q->regole_calcolo;

                if (!empty($regole)) {
                    // Forziamo in array per sicurezza, nel caso il cast sia stato object
                    $jsonArr = json_decode(json_encode($regole), true);
                    
                    $componenteSaldo = $jsonArr['importi']['saldo_usato'] ?? ($jsonArr['audit']['saldo_usato'] ?? 0);
                    $componenteSpesa = $jsonArr['importi']['quota_pura_gestione'] ?? ($jsonArr['audit']['quota_pura'] ?? 0);

                } 
                // 2. Fallback Legacy (V1.7)
                else {
                    // ... [Codice di fallback rimasto identico per compatibilità] ...
                    if (!$esercizioId) {
                        if ($first->data_scadenza) {
                            $esercizioId = DB::table('esercizi')
                                ->where('condominio_id', $condominio->id)
                                ->whereDate('data_inizio', '<=', $first->data_scadenza)
                                ->whereDate('data_fine', '>=', $first->data_scadenza)
                                ->value('id');
                        }
                        if (!$esercizioId) {
                            $esercizioCorrente = $this->getEsercizioCorrente($condominio);
                            $esercizioId = $esercizioCorrente ? $esercizioCorrente->id : null;
                        }
                    }

                    $saldoInizialeTrovato = 0;
                    if ($q->anagrafica_id && $esercizioId) {
                        $saldoInizialeTrovato = DB::table('saldi')
                            ->where('condominio_id', $condominio->id)
                            ->where('esercizio_id', $esercizioId)
                            ->where('anagrafica_id', $q->anagrafica_id)
                            ->sum('saldo_iniziale'); 
                    }

                    $applicareSaldoQui = ($q->id === $first->id);

                    if ($applicareSaldoQui) {
                        if ($metodoDist === 'prima_rata' && $numeroRata == 1) {
                            $componenteSaldo = $saldoInizialeTrovato;
                        } elseif ($metodoDist === 'tutte_rate' && $totaleRatePiano > 0) {
                            $componenteSaldo = intval($saldoInizialeTrovato / $totaleRatePiano);
                        }
                    }
                    $componenteSpesa = $residuoNettoQuota - $componenteSaldo;
                }

                // Ritorniamo i dati formattati per la UI e per il motore composable Vue
                return [
                    'unita'             => $unita,
                    'anagrafica'        => $q->anagrafica ? $q->anagrafica->nome : 'Generico',
                    // Serve all'interfaccia per sapere DI CHI è il credito di questa quota.
                    // Cercando per immobile il gruppo raccoglie le quote di tutti i
                    // comproprietari (vedi il filtro a :39-45), quindi una riga a saldo misto
                    // può contenere il credito di una persona e il debito di un'altra: senza
                    // questo id la pagina offrirebbe il credito altrui a chi sta incassando.
                    'anagrafica_id'     => $q->anagrafica_id,
                    'ruolo'             => $ruoloIniziale, // INIETTIAMO IL RUOLO [P, I, U]
                    'residuo'           => MoneyHelper::fromCents($residuoNettoQuota), 
                    'residuo_originale' => MoneyHelper::fromCents($residuoNettoQuota), 
                    'is_credito'        => $residuoNettoQuota < 0,
                    'componente_saldo'  => MoneyHelper::fromCents($componenteSaldo), 
                    'componente_spesa'  => MoneyHelper::fromCents($componenteSpesa)  
                ];
            })->values();

            // COMPRESSORE NOMI E STRINGA COMPLETA
            $nomiUnici = $gruppoQuotes->map(function($q) {
                return $q->anagrafica ? $q->anagrafica->nome : null;
            })->filter()->unique()->values();

            // 1. Stringa Completa per il Tooltip (Es: "Rossi Mario, Bianchi Anna, Erede 3, Erede 4...")
            $intestatariCompleti = $nomiUnici->join(', ');

            // 2. Stringa Compressa per non rompere la UI
            if ($nomiUnici->count() > 2) {
                $intestatariCoinvolti = $nomiUnici->take(2)->join(', ') . ' (+ altri ' . ($nomiUnici->count() - 2) . ')';
            } else {
                $intestatariCoinvolti = $nomiUnici->join(' & ');
            }

            $unitaCoinvolte = $gruppoQuotes->map(function($q) {
                return $q->immobile ? "Int. {$q->immobile->interno} ({$q->immobile->nome})" : null;
            })->filter()->unique()->join(', ');

            $isEmitted = $gruppoQuotes->contains(fn($q) => !is_null($q->scrittura_contabile_id));

            // ID Rata sicuro
            $rataId = (int) $first->rata_id;

            // Cerchiamo l'evento specifico del condòmino per questa rata
            $eventoRata = Evento::where('meta->type', 'scadenza_rata_condomino')
                ->where(function($q) use ($rataId) {
                    $q->where('meta->context->rata_id', $rataId)
                      ->orWhere('meta->context->rata_id', (string) $rataId);
                })
                ->first();

            // LOGICA DI PUBBLICAZIONE:
            // 1. Se non c'è l'evento, la consideriamo pubblicata (fallback)
            // 2. Se c'è l'evento, leggiamo il flag 'is_published' dentro il meta
            // 3. Verifichiamo anche che la visibilità non sia 'hidden'
            $isPublished = true;
            if ($eventoRata) {
                $meta = $eventoRata->meta;
                $isPublished = (isset($meta['is_published']) && $meta['is_published'] === true) 
                               && $eventoRata->visibility !== 'hidden';
            }

            return [
                'id'              => $first->id,
                'rata_padre_id'   => $first->rata_id,
                'descrizione'     => $first->rata->descrizione ?? ("Rata n." . ($first->rata->numero_rata ?? '-')),
                'scadenza_human'  => $first->data_scadenza ? Carbon::parse($first->data_scadenza)->format('d/m/Y') : 'N/D',
                'importo_totale'  => MoneyHelper::fromCents($importoTotale),
                'residuo'         => MoneyHelper::fromCents($residuoNetto),
                'gestione'        => $first->rata->pianoRate->gestione->nome ?? 'Generica',
                'gestione_id'     => $first->rata->pianoRate->gestione_id,
                'unita'           => $unitaCoinvolte ?: 'Generico',
                'intestatario'    => $intestatariCoinvolti ?: 'N/D',          // La scritta corta
                'intestatari_full'=> $intestatariCompleti ?: 'N/D',           // La lista completa per il tooltip
                'tipologia'       => 'Aggregato',
                'da_pagare'       => 0,     
                'selezionata'     => false, 
                'scaduta'         => $first->data_scadenza && Carbon::parse($first->data_scadenza)->isPast(),
                'is_credito'      => $residuoNetto < 0,
                'is_emitted'      => $isEmitted,
                'is_published'    => $isPublished,
                'dettaglio_quote' => $dettaglioTooltip 
            ];
        })
        ->filter()
        ->values();

        return response()->json(['rate' => $groupedRate]);
    }
}
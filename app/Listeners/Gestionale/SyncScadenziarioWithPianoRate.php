<?php

namespace App\Listeners\Gestionale;

use App\Enums\CategoriaEventoEnum;
use App\Enums\StatoPianoRate;
use App\Enums\VisibilityStatus; 
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\Saldo; 
use App\Services\Gestionale\InboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncScadenziarioWithPianoRate implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PianoRateStatusUpdated $event): void
    {
        Log::info("Listener avviato per Piano Rate ID: {$event->pianoRate->id} - Stato: {$event->newStatus->value}");

        if ($event->newStatus === StatoPianoRate::APPROVATO) {
            $this->createEvents($event->pianoRate, $event->condominio, $event->esercizio, $event->user);
        } elseif ($event->newStatus === StatoPianoRate::BOZZA) {
            $this->deleteEvents($event->pianoRate, $event->user);
        }
    }

    private function createEvents($pianoRate, $condominio, $esercizio, $user)
    {
        Log::info("Inizio creazione eventi...");

        $pianoRate->loadMissing('gestione');
        $nomeGestione = $pianoRate->gestione->nome ?? 'Gestione';

        // 1. RECUPERO MASIVO DEI SALDI INIZIALI
        // Creiamo una mappa: [anagrafica_id => saldo_importo_centesimi]
        // Assumiamo che il saldo sia unico per anagrafica in questo condominio/esercizio
        $saldiMap = Saldo::where('esercizio_id', $esercizio->id)
            ->where('condominio_id', $condominio->id)
            ->selectRaw('anagrafica_id, SUM(saldo_iniziale) as totale')
            ->groupBy('anagrafica_id')
            ->pluck('totale', 'anagrafica_id')
            ->toArray();

        DB::transaction(function () use ($pianoRate, $condominio, $esercizio, $user, $nomeGestione, $saldiMap) {

            $catAdmin = CategoriaEvento::firstOrCreate(
                ['name' => CategoriaEventoEnum::SCADENZE_AMMINISTRATIVE->value],
                ['description' => 'Auto']
            );
            $catPublic = CategoriaEvento::firstOrCreate(
                ['name' => CategoriaEventoEnum::SCADENZE_RATE_CONDOMINIALI->value],
                ['description' => 'Auto']
            );

            $rate = $pianoRate->rate()
                ->with(['rateQuote.anagrafica', 'rateQuote.immobile']) 
                ->get(); 

            Log::info("Trovate " . $rate->count() . " rate da processare.");

            // Ordiniamo le rate per data per identificare la prima cronologica
            $rate = $rate->sortBy('data_scadenza');
            $isPrimaRataAssoluta = true; // Flag per capire se siamo nel primo loop

            foreach ($rate as $index => $rata) {
                
                // Determiniamo se questa è la rata n.1 logica (quella che si accolla il saldo)
                // Usiamo l'indice del loop o il numero rata
                $isRataUno = ($rata->numero_rata == 1);

                // --- 1. EVENTO ADMIN (Invariato) ---
                $dataPromemoria = $rata->data_scadenza->copy()->subDays(7)->setTime(9, 0);
                $urlEmissione = route('admin.gestionale.esercizi.piani-rate.show', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoRate'  => $pianoRate->id
                ]);

                $eventoAdmin = Evento::firstOrCreate(
                    ['title' => "Emettere rata {$rata->numero_rata} - {$condominio->nome}", 'start_time' => $dataPromemoria],
                    [
                        'created_by'  => $user->id,
                        'description' => "Ricordati di emettere le rate per il condominio {$condominio->nome}.",
                        'end_time'    => $dataPromemoria->copy()->addHour(),
                        'category_id' => $catAdmin->id,
                        'visibility'  => VisibilityStatus::HIDDEN->value, 
                        'is_approved' => true,
                        'meta' => [
                            'type' => 'emissione_rata',
                            'is_emitted' => false,
                            'requires_action' => true, 
                            'gestione' => $nomeGestione,
                            'condominio_nome' => $condominio->nome,
                            'totale_rata' => $rata->importo_totale,
                            'numero_rata' => $rata->numero_rata,
                            'action_url' => $urlEmissione,
                            'context' => ['piano_rate_id' => $pianoRate->id, 'rata_id' => $rata->id],
                        ],
                    ]
                );
                $eventoAdmin->condomini()->syncWithoutDetaching([$condominio->id]);

                // --- 1-BIS. EVENTO ADMIN CHECK (Invariato) ---
                $dataCheck = $rata->data_scadenza->copy()->addDays(4)->setTime(9, 0); 
                $urlIncassi = route('admin.gestionale.movimenti-rate.create', ['condominio' => $condominio->id]);
                $eventoCheck = Evento::firstOrCreate(
                    ['title' => "Verifica incassi - Rata {$rata->numero_rata} ({$condominio->nome})", 'start_time' => $dataCheck],
                    [
                        'created_by' => $user->id,
                        'end_time' => $dataCheck->copy()->addHour(),
                        'description' => "Controlla l'estratto conto per verificare gli incassi relativi alla rata n. {$rata->numero_rata}.",
                        'category_id' => $catAdmin->id, 
                        'visibility' => VisibilityStatus::HIDDEN->value,
                        'is_approved' => true,
                        'meta' => [
                            'type' => 'controllo_incassi',
                            'requires_action' => true,
                            'condominio_nome' => $condominio->nome,
                            'numero_rata' => $rata->numero_rata,
                            'gestione' => $nomeGestione,
                            'totale_rata' => $rata->importo_totale,
                            'action_url' => $urlIncassi,
                            'context' => ['piano_rate_id' => $pianoRate->id, 'rata_id' => $rata->id],
                        ],
                    ]
                );
                $eventoCheck->condomini()->syncWithoutDetaching([$condominio->id]);

                // --- 2. EVENTI CONDÒMINI (CON LOGICA SALDO DINAMICA) ---
                $quotePerAnagrafica = $rata->rateQuote->groupBy('anagrafica_id');

                foreach ($quotePerAnagrafica as $anagraficaId => $quote) {
                    $anagrafica = $quote->first()->anagrafica;
                    if (!$anagrafica) continue;

                    // Check esistenza
                    $esiste = Evento::where('start_time', $rata->data_scadenza->copy()->setTime(0, 0))
                        ->whereJsonContains('meta->context->rata_id', $rata->id)
                        ->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $anagraficaId))
                        ->exists();

                    if ($esiste) continue;

                    $importoVal = $quote->sum('importo'); // Importo NETTO della rata (quello nel DB)
                    
                    // --- LOGICA SCONTRINO (MATEMATICA INVERSA) ---
                    // Recuperiamo il saldo dal DB (tabella saldi)
                    $saldoInizialeTotale = isset($saldiMap[$anagraficaId]) ? (int)$saldiMap[$anagraficaId] : 0;
                    
                    // Decidiamo se applicare il saldo a QUESTA rata
                    // Regola: Il saldo si visualizza solo sulla Rata 1
                    $saldoApplicatoQui = 0;
                    if ($isRataUno && $saldoInizialeTotale != 0) {
                        $saldoApplicatoQui = $saldoInizialeTotale;
                    }

                    // Costruzione Dettaglio Quote con Audit
                    $dettaglioQuote = $quote->map(function($q, $key) use ($saldoApplicatoQui) {
                        $immobile = $q->immobile;
                        $desc = $immobile ? "Int. {$immobile->interno} ({$immobile->nome})" : "Unità";
                        
                        // Trucco: Attribuiamo tutto il saldo alla PRIMA riga della rata
                        // così lo scontrino appare una volta sola e i conti tornano.
                        $saldoRiga = ($key === 0) ? $saldoApplicatoQui : 0;
                        
                        // Calcoliamo la "Quota Pura" inversa
                        // Se ImportoNetto = QuotaPura + Saldo
                        // Allora QuotaPura = ImportoNetto - Saldo
                        $quotaPura = $q->importo - $saldoRiga;

                        return [
                            'descrizione' => $desc,
                            'importo' => $q->importo, // Questo rimane il netto reale da pagare (o credito)
                            'audit' => [
                                'quota_pura' => $quotaPura,
                                'saldo_usato' => $saldoRiga,
                            ]
                        ];
                    })->values()->toArray();
                    // ---------------------------------------------

                    // Logica Messaggi (Invariata)
                    $saldoPregresso = \App\Models\Gestionale\RataQuote::where('anagrafica_id', $anagraficaId)
                        ->whereHas('rata', function($q) use ($rata, $pianoRate) {
                            $q->where('piano_rate_id', $pianoRate->id)
                              ->where('data_scadenza', '<', $rata->data_scadenza);
                        })
                        ->sum('importo');

                    $saldoAttuale = $saldoPregresso + $importoVal;

                    if ($importoVal < 0) {
                        $descUser = "Gentile {$anagrafica->nome}, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n\nNon è richiesto alcun pagamento: l'importo verrà utilizzato automaticamente per compensare le rate successive.";
                    } elseif ($saldoAttuale < -0.01) {
                        $descUser = "Gentile {$anagrafica->nome}, è in scadenza la rata n. {$rata->numero_rata}.\n\nGrazie al tuo credito pregresso, questa rata risulta attualmente COPERTA e non richiede alcun versamento.\n\nVerifica sempre il saldo aggiornato nella tua area riservata.";
                    } else {
                        $descUser = "Gentile {$anagrafica->nome}, ti ricordiamo la scadenza della rata condominiale n. {$rata->numero_rata}.\n\nTi preghiamo di effettuare il pagamento entro la data indicata. Dopo aver effettuato il versamento, potrai segnalarlo all'amministratore tornando su questo evento.";
                        if ($saldoPregresso < -0.01) {
                            $descUser .= "\n\n(Nota: Una parte dell'importo è stata compensata dal tuo credito residuo. Verifica l'importo esatto da versare nella dashboard).";
                        }
                    }

                    if (!empty($rata->note)) $descUser .= "\n\nNote: {$rata->note}";

                    $eventoUser = Evento::create([
                        'title'       => "Scadenza rata {$rata->numero_rata} - {$pianoRate->nome}",
                        'start_time'  => $rata->data_scadenza->copy()->setTime(0, 0),
                        'end_time'    => $rata->data_scadenza->copy()->setTime(23, 59),
                        'created_by'  => $user->id,
                        'description' => $descUser,
                        'category_id' => $catPublic->id,
                        'visibility'  => VisibilityStatus::PRIVATE->value,
                        'is_approved' => true,
                        'timezone'    => config('app.timezone'),
                        'meta'        => [
                            'type'              => 'scadenza_rata_condomino',
                            'is_emitted'        => false, 
                            'requires_action'   => false, 
                            'status'            => 'pending',
                            'importo_originale' => $importoVal,
                            'importo_pagato'    => 0,
                            'importo_restante'  => $importoVal,
                            'dettaglio_quote'   => $dettaglioQuote, 
                            'gestione'          => $nomeGestione,
                            'condominio_nome'   => $condominio->nome,
                            'numero_rata'       => $rata->numero_rata,
                            'piano_nome'        => $pianoRate->nome,
                            'context' => [
                                'piano_rate_id' => $pianoRate->id,
                                'rata_id'       => $rata->id
                            ],
                        ],
                    ]);

                    $eventoUser->anagrafiche()->attach($anagraficaId);
                    $eventoUser->condomini()->attach($condominio->id);
                }
            }
        }); 

        // CACHE BUSTER INTELLIGENTE (Fix Multi-Admin)
        InboxService::clearAdminCache();
        
        Log::info("Listener: Eventi creati con successo.");
    }

    private function deleteEvents($pianoRate, $user)
    {
        Log::info("Cancellazione eventi...");
        Evento::whereJsonContains('meta->context->piano_rate_id', $pianoRate->id)->delete();

        // CACHE BUSTER INTELLIGENTE (Fix Multi-Admin)
        InboxService::clearAdminCache();
    }
}
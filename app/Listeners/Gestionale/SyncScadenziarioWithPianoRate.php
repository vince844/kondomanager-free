<?php

namespace App\Listeners\Gestionale;

use App\Enums\CategoriaEventoEnum;
use App\Enums\StatoPianoRate;
use App\Enums\VisibilityStatus; 
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Enums\EventoTipo;
use App\Services\Gestionale\InboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Gestionale\PianoRate;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;

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

    private function createEvents(PianoRate $pianoRate, Condominio $condominio, Esercizio $esercizio, User $user)
    {
        Log::info("Inizio creazione eventi...");

        $pianoRate->loadMissing('gestione');
        $nomeGestione = $pianoRate->gestione->nome ?? 'Gestione';

        DB::transaction(function () use ($pianoRate, $condominio, $esercizio, $user, $nomeGestione) {

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
                ->get()
                ->sortBy('data_scadenza');

            foreach ($rate as $rata) {

                // --- 1. EVENTO ADMIN ---
                $dataPromemoria = $rata->data_scadenza->copy()->subDays(7)->setTime(9, 0);
                $urlEmissione = route('admin.gestionale.esercizi.piani-rate.show', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoRate'  => $pianoRate->id
                ]);

                // Evita duplicati usando where
                $esisteAdmin = Evento::where('tipo', EventoTipo::EMISSIONE_RATA->value)
                    ->whereJsonContains('meta->context->rata_id', $rata->id)
                    ->exists();

                if (!$esisteAdmin) {
                    InboxService::createTask(
                        tipo: EventoTipo::EMISSIONE_RATA,
                        title: "Emettere rata {$rata->numero_rata} - {$condominio->nome}",
                        description: "Ricordati di emettere le rate per il condominio {$condominio->nome}.",
                        scadenza: $dataPromemoria,
                        createdByUserId: $user->id,
                        condominioId: $condominio->id,
                        context: ['piano_rate_id' => $pianoRate->id, 'rata_id' => $rata->id],
                        actionUrl: $urlEmissione,
                        extraMeta: [
                            'is_emitted' => false,
                            'gestione' => $nomeGestione,
                            'condominio_nome' => $condominio->nome,
                            'totale_rata' => $rata->importo_totale,
                            'numero_rata' => $rata->numero_rata,
                        ]
                    );
                }

                // --- 1-BIS. EVENTO ADMIN CHECK ---
                $dataCheck = $rata->data_scadenza->copy()->addDays(4)->setTime(9, 0); 
                $urlIncassi = route('admin.gestionale.movimenti-rate.create', ['condominio' => $condominio->id]);
                $esisteCheck = Evento::where('tipo', EventoTipo::CONTROLLO_INCASSI->value)
                    ->whereJsonContains('meta->context->rata_id', $rata->id)
                    ->exists();

                if (!$esisteCheck) {
                    InboxService::createTask(
                        tipo: EventoTipo::CONTROLLO_INCASSI,
                        title: "Verifica incassi - Rata {$rata->numero_rata} ({$condominio->nome})",
                        description: "Controlla l'estratto conto per verificare gli incassi relativi alla rata n. {$rata->numero_rata}.",
                        scadenza: $dataCheck,
                        createdByUserId: $user->id,
                        condominioId: $condominio->id,
                        context: ['piano_rate_id' => $pianoRate->id, 'rata_id' => $rata->id],
                        actionUrl: $urlIncassi,
                        extraMeta: [
                            'condominio_nome' => $condominio->nome,
                            'numero_rata' => $rata->numero_rata,
                            'gestione' => $nomeGestione,
                            'totale_rata' => $rata->importo_totale,
                        ]
                    );
                }

                // --- 2. EVENTI CONDÒMINI (CALCOLO BLINDATO V1.9) ---
                $quotePerAnagrafica = $rata->rateQuote->groupBy('anagrafica_id');

                // PRE-CALCOLO: Cerchiamo eventuali crediti puri sulla Rata 0 per queste anagrafiche.
                // Questa query viene eseguita solo se NON stiamo processando la rata 0 stessa 
                // E SOLO SE il piano rate è configurato esplicitamente per usare la rata zero.
                $creditiRataZero = [];
                if ($rata->numero_rata > 0 && $pianoRate->metodo_distribuzione === 'rata_zero') {
                    
                    $rateZero = $pianoRate->rate()->where('numero_rata', 0)->first();
                    
                    if ($rateZero) {
                        $quoteRataZero = $rateZero->rateQuote()->whereIn('anagrafica_id', $quotePerAnagrafica->keys())->get();
                        foreach ($quoteRataZero->groupBy('anagrafica_id') as $anagId => $quote0) {
                            $totaleQuota0 = $quote0->sum('importo');
                            // Se la Rata 0 è un credito (negativo), lo salviamo in positivo per il frontend
                            if ($totaleQuota0 < 0) {
                                $creditiRataZero[$anagId] = abs($totaleQuota0); 
                            }
                        }
                    }
                }

                foreach ($quotePerAnagrafica as $anagraficaId => $quote) {
                    $anagrafica = $quote->first()->anagrafica;
                    if (!$anagrafica) continue;

                    $esiste = Evento::where('start_time', $rata->data_scadenza->copy()->setTime(0, 0))
                        ->whereJsonContains('meta->context->rata_id', $rata->id)
                        ->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $anagraficaId))
                        ->exists();

                    if ($esiste) continue;

                    $importoVal = 0; 
                    
                    $dettaglioQuote = $quote->map(function($q) use (&$importoVal) {
                        $immobile = $q->immobile;
                        $desc = $immobile ? "Int. {$immobile->interno} ({$immobile->nome})" : "Unità";
                        
                        $componenteSpesa = $q->importo;
                        $componenteSaldo = 0;
                        $totaleCalcolato = $q->importo;

                        // FIX: regole_calcolo è già un array grazie al cast nel modello
                        $meta = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;

                        if (is_array($meta)) {
                            $componenteSpesa = $meta['importi']['quota_pura_gestione'] ?? $meta['audit']['quota_pura'] ?? $q->importo;
                            $componenteSaldo = $meta['importi']['saldo_usato'] ?? $meta['audit']['saldo_usato'] ?? 0;
                            $totaleCalcolato = $meta['importi']['totale_calcolato'] ?? ($componenteSpesa + $componenteSaldo);
                        }

                        $importoVal += $totaleCalcolato;

                        return [
                            'descrizione' => $desc,
                            'importo' => $totaleCalcolato,
                            'componente_spesa' => $componenteSpesa,
                            'componente_saldo' => $componenteSaldo,
                        ];
                    })->values()->toArray();

                    if ($importoVal < 0) {
                        $descUser = "Gentile {$anagrafica->nome}, questa voce rappresenta un credito a tuo favore registrato nella rata n. {$rata->numero_rata}.\n\nNon è richiesto alcun pagamento: l'importo verrà utilizzato automaticamente per compensare le rate successive.";
                    } else {
                        $descUser = "Gentile {$anagrafica->nome}, ti ricordiamo la scadenza della rata condominiale n. {$rata->numero_rata}.\n\nVerifica il dettaglio quote e il netto da versare nello scontrino qui sotto. Dopo aver effettuato il versamento, potrai segnalarlo all'amministratore cliccando sul pulsante corrispondente.";
                    }

                    if (!empty($rata->note)) $descUser .= "\n\nNote: {$rata->note}";

                    // ESTIAMO IL CREDITO SE ESISTE (In centesimi, come il resto degli importi)
                    $creditoRataZeroApplicabile = $creditiRataZero[$anagraficaId] ?? 0;

                    $eventoUser = Evento::create([
                        'title'       => $rata->numero_rata == 0 ? "Saldo Iniziale - {$pianoRate->nome}" : "Scadenza rata {$rata->numero_rata} - {$pianoRate->nome}",
                        'start_time'  => $rata->data_scadenza->copy()->setTime(0, 0),
                        'end_time'    => $rata->data_scadenza->copy()->setTime(23, 59),
                        'created_by'  => $user->id,
                        'description' => $descUser,
                        'category_id' => $catPublic->id,
                        'visibility'  => VisibilityStatus::PRIVATE->value,
                        'is_approved' => true,
                        'timezone'    => config('app.timezone'),
                        'meta'        => [
                            'type'              => EventoTipo::SCADENZA_RATA_CONDOMINO->value,
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
                            'credito_rata_zero' => $creditoRataZeroApplicabile, // NUOVO CAMPO WALLET
                            'context' => [
                                'piano_rate_id' => $pianoRate->id,
                                'rata_id'       => $rata->id
                            ],
                        ],
                        'tipo' => EventoTipo::SCADENZA_RATA_CONDOMINO,
                    ]);

                    $eventoUser->anagrafiche()->attach($anagraficaId);
                    $eventoUser->condomini()->attach($condominio->id);
                }
            }
        }); 

        InboxService::clearAdminCache();
        Log::info("Listener: Eventi creati con successo.");
    }

    private function deleteEvents(PianoRate $pianoRate, User $user)
    {
        Log::info("Cancellazione eventi...");
        Evento::whereJsonContains('meta->context->piano_rate_id', $pianoRate->id)->delete();
        InboxService::clearAdminCache();
    }
}
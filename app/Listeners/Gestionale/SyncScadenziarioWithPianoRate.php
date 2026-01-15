<?php

namespace App\Listeners\Gestionale;

use App\Enums\CategoriaEventoEnum;
use App\Enums\StatoPianoRate;
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

class SyncScadenziarioWithPianoRate implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PianoRateStatusUpdated $event): void
    {
        if ($event->newStatus === StatoPianoRate::APPROVATO) {
            $this->createEvents($event->pianoRate, $event->condominio, $event->esercizio, $event->user);
        } elseif ($event->newStatus === StatoPianoRate::BOZZA) {
            $this->deleteEvents($event->pianoRate);
        }
    }

    private function createEvents($pianoRate, $condominio, $esercizio, $user)
    {
        Log::info("Listener: Creazione eventi per Piano {$pianoRate->id}");

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

            $pianoRate->rate()
                ->with('rateQuote.anagrafica')
                ->lazyById(50) 
                ->each(function ($rata) use ($condominio, $esercizio, $user, $pianoRate, $catAdmin, $catPublic, $nomeGestione) {

                    // --- 1. EVENTO ADMIN ---
                    $dataPromemoria = $rata->data_scadenza->copy()->subDays(7)->setTime(9, 0);
                    $totaleRata = $rata->importo_totale;
                    $numAnagrafiche = $rata->rateQuote->unique('anagrafica_id')->count();

                    $titoloAdmin = "Emettere rata {$rata->numero_rata} - {$condominio->nome}";

                    // DESCRIZIONE PULITA: Solo il messaggio d'azione
                    $descAdmin = "Ricordati di emettere le ricevute per questa rata entro la scadenza. " .
                                 "Tutte le anagrafiche coinvolte riceveranno notifica dell'avvenuta emissione.";

                    $urlEmissione = route('admin.gestionale.esercizi.piani-rate.show', [
                        'condominio' => $condominio->id,
                        'esercizio'  => $esercizio->id,
                        'pianoRate'  => $pianoRate->id
                    ]);

                    $eventoAdmin = Evento::firstOrCreate(
                        [
                            'title'      => $titoloAdmin,
                            'start_time' => $dataPromemoria,
                            'created_by' => $user->id,
                        ],
                        [
                            'description' => $descAdmin,
                            'end_time'    => $dataPromemoria->copy()->addHour(),
                            'category_id' => $catAdmin->id,
                            'visibility'  => 'private', 
                            'is_approved' => true,
                            'meta'        => [
                                'type' => 'emissione_rata',
                                'requires_action' => true, 
                                'context' => [
                                    'piano_rate_id' => $pianoRate->id,
                                    'rata_id'       => $rata->id
                                ],
                                'gestione' => $nomeGestione,
                                'condominio_nome' => $condominio->nome,
                                'totale_rata' => $totaleRata,
                                'anagrafiche_count' => $numAnagrafiche,
                                'scadenza_reale' => $rata->data_scadenza->toDateString(),
                                'numero_rata' => $rata->numero_rata,
                                'piano_nome' => $pianoRate->nome,
                                'action_url' => $urlEmissione
                            ],
                        ]
                    );
                    
                    if ($user->anagrafica_id) $eventoAdmin->anagrafiche()->syncWithoutDetaching([$user->anagrafica_id]);
                    $eventoAdmin->condomini()->syncWithoutDetaching([$condominio->id]);

                    // --- 2. EVENTI CONDÒMINI ---
                    $quotePerAnagrafica = $rata->rateQuote->groupBy('anagrafica_id');

                    foreach ($quotePerAnagrafica as $anagraficaId => $quote) {
                        $anagrafica = $quote->first()->anagrafica;
                        if (!$anagrafica) continue;

                        $esiste = Evento::query()
                            ->whereJsonContains('meta->context->rata_id', $rata->id)
                            ->whereJsonContains('meta->type', 'scadenza_rata_condomino')
                            ->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $anagraficaId))
                            ->exists();

                        if ($esiste) continue;

                        $importoVal = $quote->sum('importo');
                        
                        $titoloUser = "Scadenza rata {$rata->numero_rata} - {$pianoRate->nome}";
                        $dataScadenza = $rata->data_scadenza->copy()->setTime(0, 0);

                        // DESCRIZIONE PULITA: Saluto + messaggio (le note vanno dopo se ci sono)
                        $descUser = "Gentile {$anagrafica->nome}, ti ricordiamo la scadenza della rata condominiale. " .
                                    "Effettua il pagamento entro la data indicata per evitare solleciti.";
                        
                        // Note separate (solo se presenti)
                        if (!empty($rata->note)) {
                            $descUser .= "\n\nNote aggiuntive: {$rata->note}";
                        }

                        $eventoUser = Evento::create([
                            'title'       => $titoloUser,
                            'start_time'  => $dataScadenza,
                            'created_by'  => $user->id,
                            'description' => $descUser,
                            'end_time'    => $dataScadenza->copy()->setTime(23, 59),
                            'category_id' => $catPublic->id,
                            'visibility'  => 'private',
                            'is_approved' => true,
                            'timezone'    => config('app.timezone'),
                            'meta'        => [
                                'type' => 'scadenza_rata_condomino',
                                'requires_action' => false, 
                                'context' => [
                                    'piano_rate_id' => $pianoRate->id,
                                    'rata_id'       => $rata->id
                                ],
                                'status' => 'pending',
                                'importo_originale' => $importoVal,
                                'importo_pagato' => 0,
                                'importo_restante' => $importoVal,
                                'gestione' => $nomeGestione,
                                'condominio_nome' => $condominio->nome,
                                'numero_rata' => $rata->numero_rata,
                                'piano_nome' => $pianoRate->nome
                            ],
                        ]);

                        $eventoUser->anagrafiche()->syncWithoutDetaching([$anagraficaId]);
                        $eventoUser->condomini()->syncWithoutDetaching([$condominio->id]);
                    }
                });
        });
    }

    private function deleteEvents($pianoRate)
    {
        Log::info("Listener: Cancellazione batch eventi per Piano {$pianoRate->id}");
        Evento::whereJsonContains('meta->context->piano_rate_id', $pianoRate->id)->delete();
    }
}
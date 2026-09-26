<?php

namespace App\Listeners\Gestionale;

use App\Enums\CategoriaEventoEnum;
use App\Enums\StatoPianoRate;
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Enums\EventoTipo;
use App\Services\Gestionale\EventiRataCondomino;
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
                            // Stessa formula che il portale usa quando serve l'evento, presa
                            // dallo stesso posto: due copie divergono alla prima modifica.
                            // Questo resta comunque uno **snapshot** — il valore che il condòmino
                            // legge lo ricalcola `EventoResource` al momento, perché qui non si
                            // torna mai a riscrivere un evento già creato.
                            $creditoResiduo = \App\Services\Gestionale\CreditoService::nettoSpendibile($quote0);

                            if ($creditoResiduo > 0) {
                                $creditiRataZero[$anagId] = $creditoResiduo;
                            }
                        }
                    }
                }

                foreach ($quotePerAnagrafica as $anagraficaId => $quote) {
                    $anagrafica = $quote->first()->anagrafica;
                    if (!$anagrafica) continue;

                    // L'evento si riconosce dalla RATA e dalla PERSONA, non dalla data.
                    //
                    // Qui c'era anche un filtro su `start_time`: finché le scadenze non si
                    // spostano è indifferente, perché la data dell'evento coincide sempre con
                    // quella della rata. Dal momento in cui una scadenza si può cambiare —
                    // ed è ciò che questa versione introduce — quel filtro rende invisibile
                    // l'evento che già esiste, e il listener ne crea un secondo per la stessa
                    // rata e la stessa persona. Il `rata_id` la identifica già da solo.
                    $esiste = Evento::whereJsonContains('meta->context->rata_id', $rata->id)
                        ->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $anagraficaId))
                        ->exists();

                    if ($esiste) continue;

                    // La costruzione del promemoria vive in `EventiRataCondomino` (B3a, 1.11.0-beta.34): la usa anche
                    // il passaggio che fa passare le bozze a chi entra, e due copie divergerebbero alla prima modifica.
                    app(EventiRataCondomino::class)->crea(
                        $pianoRate, $rata, $anagrafica, $quote, $condominio, $user->id, $nomeGestione, $catPublic->id,
                        // ESTIAMO IL CREDITO SE ESISTE (In centesimi, come il resto degli importi)
                        $creditiRataZero[$anagraficaId] ?? 0,
                    );
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
<?php

namespace App\Http\Controllers\Eventi\Utenti;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\User; 
use App\Enums\VisibilityStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache; 
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class PaymentReportingController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Evento $evento)
    {
        $this->authorize('view', $evento);

        $currentStatus = $evento->meta['status'] ?? 'pending';

        // Blocchi di sicurezza
        if ($currentStatus === 'paid') return back()->with('error', 'Già pagata.');
        if ($currentStatus === 'reported') return back()->with('info', 'Già segnalato.');

        DB::transaction(function () use ($request, $evento) {
            
            // 1. Rileva intento credito dal frontend
            $intentUsaCredito = $request->boolean('intent_usa_credito');
            // Nota: I valori dal frontend arrivano in centesimi
            $creditoRichiestoCentesimi = $intentUsaCredito ? (int) $request->input('credito_richiesto', 0) : 0;

            // 2. Aggiorna stato evento utente (RESETTA RIFIUTO)
            $meta = $evento->meta;
            $meta['status'] = 'reported'; 
            $meta['reported_at'] = now()->toIso8601String();
            
            if ($intentUsaCredito) {
                $meta['intent_usa_credito'] = true;
                $meta['credito_richiesto'] = $creditoRichiestoCentesimi;
            }

            // PULIZIA: Se era stato rifiutato, rimuoviamo la motivazione
            if (isset($meta['rejection_reason'])) {
                unset($meta['rejection_reason']);
                unset($meta['rejected_at']);
            }

            $evento->update(['meta' => $meta]);

            // 3. Prepara dati e calcoli per il Task Admin
            $anagrafica = $evento->anagrafiche->first();
            $nomeAnagrafica = $anagrafica ? $anagrafica->nome : 'Condòmino';
            $condominioId = $evento->condomini->first()?->id;
            
            $totaleRataCentesimi = $meta['importo_restante'] ?? 0;
            
            // La matematica pura: Bonifico atteso = Totale Rata - Credito prelevato
            $importoBancaCentesimi = max(0, $totaleRataCentesimi - $creditoRichiestoCentesimi);
            
            $importoBancaEuro = $importoBancaCentesimi / 100;
            $creditoEuro = $creditoRichiestoCentesimi / 100;
            
            $importoFormat = number_format($importoBancaEuro, 2, ',', '.');
            $creditoFormat = number_format($creditoEuro, 2, ',', '.');

            // 4. Costruisci il messaggio perfetto per la Inbox
            if ($intentUsaCredito) {
                if ($importoBancaCentesimi > 0) {
                    // Scenario A: Credito parziale + Bonifico differenza
                    $descrizioneTask = "Il condòmino {$nomeAnagrafica} ha richiesto di usare {$creditoFormat}€ del suo salvadanaio e ha segnalato di aver pagato la differenza di {$importoFormat}€ in banca.\n\nVerifica l'estratto conto e registra l'incasso compensando il credito.";
                } else {
                    // Scenario B: Credito capiente (Nessun bonifico)
                    $descrizioneTask = "Il condòmino {$nomeAnagrafica} ha richiesto di usare {$creditoFormat}€ del suo salvadanaio per saldare interamente questa rata.\n\nNessun incasso bancario previsto (0,00€). Clicca per confermare la compensazione.";
                }
            } else {
                // Scenario C: Pagamento standard
                $descrizioneTask = "Il condòmino {$nomeAnagrafica} ha segnalato di aver pagato l'intero importo di {$importoFormat}€.\n\nVerifica l'estratto conto bancario e registra l'incasso.";
            }

            // 5. Crea Task Admin
            $adminEvent = Evento::create([
                'title'       => "Verifica Incasso: {$evento->title}",
                'description' => $descrizioneTask,
                'start_time'  => now(),
                'end_time'    => now()->addHour(),
                'created_by'  => Auth::id(),
                'category_id' => $evento->category_id,
                'visibility'  => VisibilityStatus::HIDDEN->value, 
                'is_approved' => true,
                'meta'        => [
                    'type'            => 'verifica_pagamento',
                    'requires_action' => true,
                    'intent_usa_credito' => $intentUsaCredito,
                    'context'         => [
                        'related_event_id' => $evento->id,
                        'rata_id'          => $meta['context']['rata_id'] ?? null,
                        'piano_rate_id'    => $meta['context']['piano_rate_id'] ?? null,
                        'anagrafica_id'    => $anagrafica?->id
                    ],
                    'condominio_nome'    => $meta['condominio_nome'] ?? '',
                    'importo_dichiarato' => $importoBancaCentesimi, // Quello atteso in banca
                    'action_url'         => null 
                ]
            ]);

            if ($condominioId) $adminEvent->condomini()->attach($condominioId);
            if ($anagrafica) $adminEvent->anagrafiche()->attach($anagrafica->id);

            // 6. Genera Link Operativo per aprire IncassoRateNew.vue
            if ($condominioId) {
                $actionUrlParams = [
                    'condominio'            => $condominioId,
                    'prefill_rata_id'       => $meta['context']['rata_id'] ?? null,
                    'prefill_anagrafica_id' => $anagrafica?->id,
                    'prefill_importo'       => $importoBancaEuro,
                    'prefill_descrizione'   => $intentUsaCredito ? "Compensazione rata e saldo" : "Saldo rata condominiale",
                    'related_task_id'       => $adminEvent->id 
                ];

                // Passiamo l'intento via URL così Vue sa cosa fare!
                if ($intentUsaCredito) {
                    $actionUrlParams['intent_usa_credito'] = 'true';
                }

                $adminMeta = $adminEvent->meta;
                $adminMeta['action_url'] = route('admin.gestionale.movimenti-rate.create', $actionUrlParams);
                $adminEvent->update(['meta' => $adminMeta]);
            }

            // 7. CACHE BUSTER INTELLIGENTE
            $admins = User::role([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])->get();
            foreach ($admins as $admin) {
                Cache::forget('inbox_count_' . $admin->id);
            }

        });

        return back()->with('success', 'Segnalazione inviata con successo.');
    }
}
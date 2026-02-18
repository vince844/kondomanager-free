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

        DB::transaction(function () use ($evento) {
            
            // 1. Aggiorna stato evento utente (RESETTA RIFIUTO)
            $meta = $evento->meta;
            $meta['status'] = 'reported'; 
            $meta['reported_at'] = now()->toIso8601String();
            
            // PULIZIA: Se era stato rifiutato, rimuoviamo la motivazione
            if (isset($meta['rejection_reason'])) {
                unset($meta['rejection_reason']);
                unset($meta['rejected_at']);
            }

            $evento->update(['meta' => $meta]);

            // 2. Prepara dati per il Task Admin
            $anagrafica = $evento->anagrafiche->first();
            $nomeAnagrafica = $anagrafica ? $anagrafica->nome : 'Condòmino';
            
            $importoEuro = ($meta['importo_restante'] ?? 0) / 100;
            $importoFormat = number_format($importoEuro, 2, ',', '.');
            
            $condominioId = $evento->condomini->first()?->id;

            // 3. Crea Task Admin
            // Usiamo 'start_time' => now() così il Middleware lo pesca subito 
            $adminEvent = Evento::create([
                'title'       => "Verifica Incasso: {$evento->title}",
                'description' => "Il condòmino {$nomeAnagrafica} ha segnalato di aver pagato {$importoFormat}€.\n" .
                                 "Verifica l'estratto conto bancario e registra l'incasso.",
                'start_time'  => now(),
                'end_time'    => now()->addHour(),
                'created_by'  => Auth::id(),
                'category_id' => $evento->category_id,
                // HIDDEN ('hidden') è diverso da PRIVATE ('private'), quindi il Middleware lo mostrerà!
                'visibility'  => VisibilityStatus::HIDDEN->value, 
                'is_approved' => true,
                'meta'        => [
                    'type'            => 'verifica_pagamento',
                    'requires_action' => true,
                    'context'         => [
                        'related_event_id' => $evento->id,
                        'rata_id'          => $meta['context']['rata_id'] ?? null,
                        'piano_rate_id'    => $meta['context']['piano_rate_id'] ?? null,
                        'anagrafica_id'    => $anagrafica?->id
                    ],
                    'condominio_nome'    => $meta['condominio_nome'] ?? '',
                    'importo_dichiarato' => $meta['importo_restante'] ?? 0,
                    'action_url'         => null // Lo riempiamo al punto 4
                ]
            ]);

            if ($condominioId) {
                $adminEvent->condomini()->attach($condominioId);
            }
            if ($anagrafica) {
                $adminEvent->anagrafiche()->attach($anagrafica->id);
            }

            // 4. Genera Link e aggiorna
            if ($condominioId) {
                $actionUrl = route('admin.gestionale.movimenti-rate.create', [
                    'condominio'            => $condominioId,
                    'prefill_rata_id'       => $meta['context']['rata_id'] ?? null,
                    'prefill_anagrafica_id' => $anagrafica?->id,
                    'prefill_importo'       => $importoEuro,
                    'prefill_descrizione'   => "Saldo rata condominiale (Segnalazione utente)",
                    'related_task_id'       => $adminEvent->id 
                ]);

                $adminMeta = $adminEvent->meta;
                $adminMeta['action_url'] = $actionUrl;
                $adminEvent->update(['meta' => $adminMeta]);
            }

            // 5. CACHE BUSTER INTELLIGENTE (Fix Multi-Admin)
            // Invece di pulire la cache del condòmino, puliamo quella di chi DEVE VEDERE la notifica.
            
            // Opzione A: Se usi Spatie Laravel-Permission (consigliato)
            // Recupera tutti gli utenti che hanno ruoli amministrativi
            $admins = User::role([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])->get();
            
            foreach ($admins as $admin) {
                // Rimuove la cache del conteggio per ogni amministratore trovato
                Cache::forget('inbox_count_' . $admin->id);
            }

        });

        return back()->with('success', 'Segnalazione inviata con successo.');
    }
}
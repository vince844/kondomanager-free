<?php

namespace App\Http\Controllers\Eventi\Utenti;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Enums\VisibilityStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class PaymentReportingController extends Controller
{
    use AuthorizesRequests;

    /**
     * Gestisce la segnalazione di pagamento da parte del condòmino.
     */
    public function __invoke(Request $request, Evento $evento)
    {
        // 1. Sicurezza: L'utente deve poter vedere l'evento (tramite Policy)
        $this->authorize('view', $evento);

        // 2. Validazioni stato
        $currentStatus = $evento->meta['status'] ?? 'pending';

        if ($currentStatus === 'paid') {
            return back()->with('error', 'Questa rata risulta già pagata.');
        }

        if ($currentStatus === 'reported') {
            return back()->with('info', 'Hai già segnalato questo pagamento. Attendi la verifica.');
        }

        // 3. Esecuzione
        DB::transaction(function () use ($evento) {
            
            // A. Aggiorna l'evento del Condòmino
            $meta = $evento->meta;
            $meta['status'] = 'reported'; // Diventa "In verifica"
            $meta['reported_at'] = now()->toIso8601String();
            $evento->update(['meta' => $meta]);

            // Dati per l'evento Admin
            $anagrafica = $evento->anagrafiche->first();
            $nomeAnagrafica = $anagrafica ? $anagrafica->nome : 'Condòmino sconosciuto';
            $importo = number_format(($meta['importo_restante'] ?? 0) / 100, 2, ',', '.');
            $condominioId = $evento->condomini->first()?->id;

            // B. Crea il task per l'Amministratore (Action Inbox)
            $adminEvent = Evento::create([
                'title'       => "Verifica Incasso: {$evento->title}",
                'description' => "Il condòmino {$nomeAnagrafica} ha segnalato di aver pagato {$importo}€.\n" .
                                 "Verifica l'estratto conto bancario e registra l'incasso.",
                'start_time'  => now(), // Subito in cima alla lista
                'end_time'    => now()->addHour(),
                'created_by'  => Auth::id(),
                'category_id' => $evento->category_id,
                'visibility'  => VisibilityStatus::HIDDEN->value, // VISIBILE SOLO AD ADMIN
                'is_approved' => true,
                'meta'        => [
                    'type'            => 'verifica_pagamento',
                    'requires_action' => true,
                    'context'         => [
                        'related_event_id' => $evento->id, // ID evento utente
                        'rata_id'          => $meta['context']['rata_id'] ?? null,
                        'piano_rate_id'    => $meta['context']['piano_rate_id'] ?? null,
                        'anagrafica_id'    => $anagrafica?->id
                    ],
                    'condominio_nome'    => $meta['condominio_nome'] ?? '',
                    'importo_dichiarato' => $meta['importo_restante'] ?? 0,
                    'action_url'         => null // Futuro link a "Registra Incasso"
                ]
            ]);

            // Colleghiamo il condominio anche al task admin
            if ($condominioId) {
                $adminEvent->condomini()->attach($condominioId);
            }
        });

        return back()->with('success', 'Segnalazione inviata con successo.');
    }
}
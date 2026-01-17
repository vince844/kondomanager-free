<?php

namespace App\Http\Controllers\Eventi\Utenti;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\Condominio;
use App\Enums\VisibilityStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class PaymentReportingController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Evento $evento)
    {
        $this->authorize('view', $evento);

        $currentStatus = $evento->meta['status'] ?? 'pending';

        if ($currentStatus === 'paid') return back()->with('error', 'Già pagata.');
        if ($currentStatus === 'reported') return back()->with('info', 'Già segnalato.');

        DB::transaction(function () use ($evento) {
            
            // 1. Aggiorna stato evento utente
            $meta = $evento->meta;
            $meta['status'] = 'reported'; 
            $meta['reported_at'] = now()->toIso8601String();
            $evento->update(['meta' => $meta]);

            // 2. Prepara dati
            $anagrafica = $evento->anagrafiche->first();
            $nomeAnagrafica = $anagrafica ? $anagrafica->nome : 'Condòmino';
            
            // Importo in Euro (es. 33.29)
            $importoEuro = ($meta['importo_restante'] ?? 0) / 100;
            $importoFormat = number_format($importoEuro, 2, ',', '.');
            
            $condominioId = $evento->condomini->first()?->id;
            
            // 3. Genera Link "Registra Incasso"
            $actionUrl = null;

            if ($condominioId) {
                // Generiamo la rotta per la creazione dell'incasso.
                // Passiamo i dati come parametri GET (Query String) per precompilare il form.
                $actionUrl = route('admin.gestionale.movimenti-rate.create', [
                    'condominio' => $condominioId,
                    // Parametri aggiuntivi che finiranno nell'URL come ?prefill_rata_id=...
                    'prefill_rata_id'       => $meta['context']['rata_id'] ?? null,
                    'prefill_anagrafica_id' => $anagrafica?->id,
                    'prefill_importo'       => $importoEuro,
                    'prefill_descrizione'   => "Saldo rata condominiale (Segnalazione utente)"
                ]);
            }

            // 4. Crea Task Admin
            $adminEvent = Evento::create([
                'title'       => "Verifica Incasso: {$evento->title}",
                'description' => "Il condòmino {$nomeAnagrafica} ha segnalato di aver pagato {$importoFormat}€.\n" .
                                 "Verifica l'estratto conto bancario e registra l'incasso.",
                'start_time'  => now(),
                'end_time'    => now()->addHour(),
                'created_by'  => Auth::id(),
                'category_id' => $evento->category_id,
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
                    
                    // URL FUNZIONANTE
                    'action_url'         => $actionUrl 
                ]
            ]);

            if ($condominioId) {
                $adminEvent->condomini()->attach($condominioId);
            }
        });

        return back()->with('success', 'Segnalazione inviata con successo.');
    }
}
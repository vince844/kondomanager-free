<?php

namespace App\Http\Controllers\Impostazioni;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\User;
use App\Services\Documenti\SpazioDocumenti;
use App\Support\FunzioniInstallazione;
use App\Support\PersistenzaStorage;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Questa installazione» (1.11.0-beta.33): cosa può fare e quanto ha usato, in numeri neutri.
 * Condomini su tetto, spazio su tetto (solo se c'è un tetto: senza, un totale è una curiosità —
 * roadmap, Coda 55), le funzioni accese, dove stanno i documenti. Nessuna parola «piano» se
 * `GESTIONE_PIANO_URL` manca: chi si autoospita vede i suoi fatti e basta.
 */
class InstallazioneController extends Controller
{
    public function __invoke(SpazioDocumenti $spazio): Response
    {
        Gate::allowIf(
            fn (User $user) => $user->hasPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value),
            __('errors.403_message')
        );

        $limiteSpazio = $spazio->limiteByte();

        return Inertia::render('impostazioni/impostazioniInstallazione', [
            'versione' => (string) config('app.version'),
            'condomini' => Condominio::query()->where('is_demo', false)->count(),
            'condomini_dimostrativi' => Condominio::query()->where('is_demo', true)->count(),
            'limite_condomini' => (int) config('kondomanager.limite_condomini', 0),
            // Lo spazio si calcola solo se c'è un tetto: senza, la riga non compare e la query non si fa.
            'spazio_usato_byte' => $limiteSpazio !== null ? $spazio->usatoByte() : null,
            'limite_spazio_byte' => $limiteSpazio,
            'funzioni' => FunzioniInstallazione::mappa(),
            'documenti_stato' => PersistenzaStorage::statoDocumenti(),
            'gestione_piano_url' => config('kondomanager.gestione_piano_url') ?: null,
        ]);
    }
}

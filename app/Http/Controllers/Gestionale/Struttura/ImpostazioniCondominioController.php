<?php

namespace App\Http\Controllers\Gestionale\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\ImpostazioniCondominio;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le impostazioni del condominio, nella scheda «Impostazioni» dell'area «Condominio»: le scelte che valgono solo per questo condominio, una riga per condominio
 * ({@see ImpostazioniCondominio}). Oggi la sezione «Il recupero dei crediti»: che cosa succede dopo una lettera quando scadono rate nuove, e se il programma
 * suggerisce la prossima azione. Aprire la pagina non scrive niente (senza la riga valgono le scelte di serie); le salva chi modifica il condominio
 * (`CondominioPolicy::update`, «Modifica condomini»), gli altri le vedono.
 */
class ImpostazioniCondominioController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    public const FRASE_SALITA = 'Scegli che cosa succede dopo una lettera, quando scadono rate nuove.';
    public const FRASE_AZIONE = 'Scegli chi sceglie la prossima azione.';

    public function index(Request $request, Condominio $condominio): Response
    {
        return Inertia::render('gestionale/struttura/ImpostazioniCondominio', [
            'condominio' => new CondominioResource($condominio),
            'condomini' => $this->getCondomini(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'impostazioni' => ImpostazioniCondominio::delCondominio((int) $condominio->id)->perLaPagina(),
            'puo_modificare' => Gate::allows('update', $condominio),
        ]);
    }

    public function update(Request $request, Condominio $condominio): RedirectResponse
    {
        Gate::authorize('update', $condominio);

        $dati = $request->validate([
            'recupero_salita' => ['required', Rule::in(ImpostazioniCondominio::SALITE)],
            'recupero_suggerisce_azione' => ['required', 'boolean'],
        ], [
            'recupero_salita.required' => self::FRASE_SALITA, 'recupero_salita.in' => self::FRASE_SALITA,
            'recupero_suggerisce_azione.required' => self::FRASE_AZIONE, 'recupero_suggerisce_azione.boolean' => self::FRASE_AZIONE,
        ]);
        ImpostazioniCondominio::updateOrCreate(['condominio_id' => $condominio->id], [
            'recupero_salita' => $dati['recupero_salita'],
            'recupero_suggerisce_azione' => (bool) $dati['recupero_suggerisce_azione'],
            'aggiornate_da' => $request->user()?->id,
        ]);

        return back()->with($this->flashSuccess('Impostazioni salvate: valgono solo per ' . $condominio->nome . '.'));
    }
}

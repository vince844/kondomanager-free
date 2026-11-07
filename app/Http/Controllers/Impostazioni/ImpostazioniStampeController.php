<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Settings\PrintSettings;
use App\Http\Requests\Settings\UpdatePrintSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use App\Services\Documenti\ArchivioDocumenti;
use App\Traits\HandleFlashMessages;
use Inertia\Inertia;
use Inertia\Response;

class ImpostazioniStampeController extends Controller
{
    use HandleFlashMessages;

    /**
     * Show the print settings form.
     */
    public function index(PrintSettings $settings): Response
    {
        Gate::authorize('manage', $settings);

        $archivio = app(ArchivioDocumenti::class);

        return Inertia::render('impostazioni/impostazioniStampe', [
            'nota_legale_stampe' => (string) $settings->nota_legale_stampe,
            'firma_stampe_url'   => $settings->firma_stampe_path ? $archivio->urlPubblico($settings->firma_stampe_path) : null,
            // Il limite lo decide il server, non noi: il testo d'aiuto scriveva «max 2MB» a mano in
            // quattro lingue, e su uno spazio web che ne accetta uno prometteva il doppio.
            'limiteFirma'        => \App\Support\LimiteCaricamento::etichetta(2.0),
            // Lo studio che scrive le lettere ai condòmini.
            'studio'             => [
                'nome'      => (string) $settings->studio_nome,
                'indirizzo' => (string) $settings->studio_indirizzo,
                'email'     => (string) $settings->studio_email,
                'pec'       => (string) $settings->studio_pec,
                'telefono'  => (string) $settings->studio_telefono,
                'comune'    => (string) $settings->studio_comune,
            ],
        ]);
    }

    /**
     * Update the print settings.
     */
    public function store(UpdatePrintSettingsRequest $request, PrintSettings $settings): RedirectResponse
    {
        Gate::authorize('manage', $settings);

        try {
            $validated = $request->validated();

            $settings->nota_legale_stampe = $validated['nota_legale_stampe'] ?? null;
            // Un campo che la richiesta non porta resta com'era: la pagina li manda tutti, ma una richiesta di prima di questi campi no.
            foreach (['studio_nome', 'studio_indirizzo', 'studio_email', 'studio_pec', 'studio_telefono', 'studio_comune'] as $campo) {
                if ($request->has($campo)) {
                    $valore = trim((string) ($validated[$campo] ?? ''));
                    $settings->{$campo} = $valore === '' ? null : $valore;
                }
            }
            
            $archivio = app(ArchivioDocumenti::class);

            if ($request->hasFile('firma_stampe')) {
                $archivio->eliminaPubblico($settings->firma_stampe_path);
                $path = $archivio->salvaPubblico($request->file('firma_stampe'), 'settings/signatures');
                $settings->firma_stampe_path = $path;
            } elseif (!empty($validated['delete_firma_stampe']) && $settings->firma_stampe_path) {
                $archivio->eliminaPubblico($settings->firma_stampe_path);
                $settings->firma_stampe_path = null;
            }
            
            $settings->save();
        } catch (\Exception $e) {
            return redirect()->back()->with(
                $this->flashError(__('impostazioni.error_save_general_settings'))
            );
        }

        return redirect()->back()->with(
            $this->flashSuccess(__('impostazioni.success_save_print_settings'))
        );
    }
}

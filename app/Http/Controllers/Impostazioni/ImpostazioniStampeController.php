<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Settings\PrintSettings;
use App\Http\Requests\Settings\UpdatePrintSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return Inertia::render('impostazioni/impostazioniStampe', [
            'nota_legale_stampe' => (string) $settings->nota_legale_stampe,
            'firma_stampe_url'   => $settings->firma_stampe_path ? $disk->url($settings->firma_stampe_path) : null,
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
            
            if ($request->hasFile('firma_stampe')) {
                if ($settings->firma_stampe_path) {
                    Storage::disk('public')->delete($settings->firma_stampe_path);
                }
                $path = $request->file('firma_stampe')->store('settings/signatures', 'public');
                $settings->firma_stampe_path = $path;
            } elseif (!empty($validated['delete_firma_stampe']) && $settings->firma_stampe_path) {
                Storage::disk('public')->delete($settings->firma_stampe_path);
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

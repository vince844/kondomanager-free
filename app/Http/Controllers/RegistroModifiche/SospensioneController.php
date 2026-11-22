<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\RegistroModifiche;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\RegistroModifiche\AccessoAiCasiSpeciali;
use App\Services\RegistroModifiche\SospensioneDellaCancellazione;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «Sospendi la cancellazione» del registro per un condominio (decisione 550): lo stato per il componente `SospensioneDellaCancellazione.vue`, la
 * sospensione e la sua fine, con il motivo obbligatorio, solo dell'amministratore (la rotta ha il ruolo; qui lo si ricontrolla). Ogni gesto è una riga del
 * registro del condominio.
 */
class SospensioneController extends Controller
{
    public function mostra(Request $request, Condominio $condominio): JsonResponse
    {
        abort_unless(AccessoAiCasiSpeciali::puoLeggere($request->user()), 403);

        return response()->json(SospensioneDellaCancellazione::perIlComponente($condominio, AccessoAiCasiSpeciali::amministratore($request->user())));
    }

    public function sospendi(Request $request, Condominio $condominio): JsonResponse
    {
        abort_unless(AccessoAiCasiSpeciali::amministratore($request->user()), 403);
        $motivo = $this->motivo($request, 'Scrivi perché sospendi la cancellazione: per esempio «causa Rossi, tribunale di Napoli».');

        $stato = SospensioneDellaCancellazione::stato($condominio);
        if ($stato !== null) {
            throw ValidationException::withMessages(['motivo' => "La cancellazione è già sospesa dal {$stato['dal']}."]);
        }
        SospensioneDellaCancellazione::sospendi($condominio, $motivo);

        return response()->json(SospensioneDellaCancellazione::perIlComponente($condominio, true));
    }

    public function togli(Request $request, Condominio $condominio): JsonResponse
    {
        abort_unless(AccessoAiCasiSpeciali::amministratore($request->user()), 403);
        $motivo = $this->motivo($request, 'Scrivi perché togli la sospensione: per esempio «causa chiusa con la sentenza del …».');

        if (SospensioneDellaCancellazione::stato($condominio) === null) {
            throw ValidationException::withMessages(['motivo' => 'La cancellazione di questo condominio non è sospesa.']);
        }
        SospensioneDellaCancellazione::togli($condominio, $motivo);

        return response()->json(SospensioneDellaCancellazione::perIlComponente($condominio, true));
    }

    /** Le sospensioni attive dei condomini eliminati, per il registro dello studio: solo l'amministratore. */
    public function eliminati(Request $request): JsonResponse
    {
        abort_unless(AccessoAiCasiSpeciali::amministratore($request->user()), 403);

        return response()->json(['sospensioni' => array_values(array_filter(SospensioneDellaCancellazione::attive(), fn ($s) => $s['eliminato']))]);
    }

    /** Toglie la sospensione di un condominio per numero (anche eliminato, che non si lega più alla rotta), con il motivo e la riga del registro. */
    public function togliPerNumero(Request $request, int $condominio_id): JsonResponse
    {
        abort_unless(AccessoAiCasiSpeciali::amministratore($request->user()), 403);
        $motivo = $this->motivo($request, 'Scrivi perché togli la sospensione: per esempio «causa chiusa con la sentenza del …».');

        SospensioneDellaCancellazione::togliPerNumero($condominio_id, $motivo);

        return $this->eliminati($request);
    }

    private function motivo(Request $request, string $messaggio): string
    {
        $dati = $request->validate(['motivo' => ['required', 'string', 'max:1000']], ['motivo.required' => $messaggio], ['motivo' => 'motivo']);

        return trim((string) $dati['motivo']);
    }
}

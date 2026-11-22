<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Convocazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\Convocazione\ConsegnaRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Un invio della convocazione (§4.4 del progetto della tappa 2), o della trasmissione del verbale con le stesse regole: «Registra la consegna» con l'esito, il
 * giorno e la prova (la correzione con il perché),
 * «Allega la prova», la ricevuta PEC letta dal file (decisione 577), lo scaricamento della prova e del PDF conservato. L'invio è dell'assemblea dell'indirizzo ({@see assicuraLInvio()}); la prova di una
 * convocazione non si toglie.
 */
class InviiDellaConvocazioneController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private readonly ConsegneDellaConvocazione $consegne) {}

    public function consegna(ConsegnaRequest $request, Condominio $condominio, Assemblea $assemblea, RegistroInvio $invio): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $this->assicuraLInvio($assemblea, $invio);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $dati = $request->validated();
        $this->consegne->registra($assemblea, $invio, $dati['esito'], $dati['giorno'], $dati['ora'] ?? null, $request->file('prova'), $request->user(),
            $dati['perche'] ?? null);

        return back()->with($this->flashSuccess('Esito registrato: è anche una riga del registro delle modifiche dell\'assemblea.'));
    }

    public function prova(Request $request, Condominio $condominio, Assemblea $assemblea, RegistroInvio $invio): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $this->assicuraLInvio($assemblea, $invio);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $request->validate([
            'prova' => ['required', 'file', 'extensions:' . implode(',', RegistroLettere::ESTENSIONI_PROVA), 'max:' . LimiteCaricamento::regolaMax(RegistroLettere::MB_PROVA),
                \App\Support\FileComeIlNome::regola()],
        ], ['prova.extensions' => 'La prova è un PDF, un\'immagine (JPG o PNG), un messaggio .eml o un file firmato .p7m.']);
        try {
            $this->consegne->allegaProva($assemblea, $invio, $request->file('prova'), $request->user());
        } catch (\App\Exceptions\Gestionale\LetteraNonEmettibileException $e) {
            return back()->withErrors(['prova' => $e->getMessage()]);
        }

        return back()->with($this->flashSuccess('Prova allegata. La prova di una convocazione non si toglie.'));
    }

    /**
     * La ricevuta PEC trascinata sulla riga (decisione 577): il programma legge il giorno e l'ora della consegna e archivia il file sulla riga. Se non è la
     * ricevuta di consegna di questa PEC, la frase dice perché e niente si scrive.
     */
    public function ricevuta(Request $request, Condominio $condominio, Assemblea $assemblea, RegistroInvio $invio): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $this->assicuraLInvio($assemblea, $invio);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $request->validate([
            'ricevuta' => ['required', 'file', 'extensions:eml', 'max:' . LimiteCaricamento::regolaMax(RegistroLettere::MB_PROVA)],
            'perche' => ['nullable', 'string', 'max:500'],
        ], ['ricevuta.extensions' => 'La ricevuta del gestore è un file .eml: salvala dalla casella PEC così com\'è.', 'ricevuta.required' => 'Scegli la ricevuta.']);
        $esito = $this->consegne->registraLaRicevuta($assemblea, $invio, $request->file('ricevuta'), $request->user(), $request->input('perche'));

        return back()->with($this->flashSuccess('Ricevuta letta: consegnata il ' . $esito->ricevuta_il?->format('d/m/Y') . ' alle '
            . (string) $esito->esito_il?->format('H:i') . '. Il file resta sulla riga.'));
    }

    public function scaricaProva(Request $request, Condominio $condominio, Assemblea $assemblea, RegistroInvio $invio, string|int $documento)
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $this->assicuraLInvio($assemblea, $invio);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $doc = Documento::query()->whereKey((int) $documento)->where('documentable_type', RegistroInvio::class)->where('documentable_id', $invio->id)->first();
        abort_if($doc === null || ! app(ArchivioDocumenti::class)->esiste($doc->path), 404);

        return app(ArchivioDocumenti::class)->scarica($doc->path, $doc->nomeDiScaricamento());
    }

    public function pdf(Request $request, Condominio $condominio, Assemblea $assemblea, RegistroInvio $invio)
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $this->assicuraLInvio($assemblea, $invio);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $doc = isset($invio->dati['documento_id']) ? Documento::query()->whereKey((int) $invio->dati['documento_id'])->where('documentable_type', RegistroInvio::class)
            ->where('documentable_id', $invio->id)->first() : null;
        abort_if($doc === null || ! app(ArchivioDocumenti::class)->esiste($doc->path), 404);

        return app(ArchivioDocumenti::class)->scarica($doc->path, $doc->nomeDiScaricamento());
    }

    /** L'invio è della convocazione (o della trasmissione del verbale) di questa assemblea, nel suo condominio: altrimenti 404. */
    private function assicuraLInvio(Assemblea $assemblea, RegistroInvio $invio): void
    {
        abort_unless((int) $invio->condominio_id === (int) $assemblea->condominio_id && $invio->documentabile_type === $assemblea->getMorphClass()
            && (int) $invio->documentabile_id === (int) $assemblea->id && in_array($invio->tipo, RegistroInvio::TIPI_DELL_ASSEMBLEA, true), 404);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Gestionale\RendicontoVersione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\VersioniDelRendiconto;
use App\Support\FileComeIlNome;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Le versioni del rendiconto dalla Sintesi ({@see VersioniDelRendiconto}): «Ferma per l'assemblea», «Conserva le parti una per una», «Carica il PDF che
 * hai mandato», «Registra l'approvazione», «Elimina la versione» (solo fermata e mai approvata), e lo scaricamento dei documenti di una versione (il PDF
 * conservato, le sue parti, il verbale).
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello, mai fra quelle del portale: il fascicolo porta i dati di tutti i condòmini. Registra
 * l'approvazione chi entra nel pannello, come chi approva un piano di rate: è scrivere un fatto dell'assemblea. L'esercizio di un altro condominio fa
 * 404 per tutta la richiesta (`scopeBindings()`), con la guardia in ogni metodo; una versione o un documento che non sono di questo esercizio fanno 404.
 */
class RendicontoVersioniController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private readonly VersioniDelRendiconto $versioni) {}

    /** «Ferma per l'assemblea»: le parti scelte del fascicolo, con il dettaglio del riepilogo e la colonna delle lettere se scelti. */
    public function store(Request $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $dati = $request->validate([
            'parti' => ['nullable', 'array'],
            'parti.*' => ['string', Rule::in(FascicoloDelRendiconto::PARTI)],
            'dettaglio' => ['nullable', 'boolean'],
            'lettere' => ['nullable', 'boolean'],
            // «Per quale assemblea»: quella in preparazione con il punto del rendiconto di questo esercizio, o nessuna.
            'assemblea_id' => ['nullable', 'integer'],
        ]);
        $assemblea = empty($dati['assemblea_id']) ? null : \App\Services\Assemblee\SchedaDelCondominio::risolvi($condominio, 'assemblea', (int) $dati['assemblea_id']);
        // L'assemblea scelta si controlla prima: se non si può legare, la versione non nasce.
        if ($assemblea !== null) {
            \App\Services\Assemblee\Convocazione\RendicontoPerLAssemblea::puntoPer($assemblea, (int) $esercizio->id, (int) $condominio->id, $request->user());
        }
        $versione = $this->versioni->ferma($condominio, $esercizio, $dati['parti'] ?? [], (bool) ($dati['dettaglio'] ?? false), (bool) ($dati['lettere'] ?? false),
            $request->user());
        if ($assemblea !== null) {
            \App\Services\Assemblee\Convocazione\RendicontoPerLAssemblea::allega($assemblea, $versione, $request->user());
        }

        // Le cifre fermate che non tornano si dicono subito: il PDF è conservato com'è, e l'amministratore lo sa prima dell'assemblea.
        $nonTornano = VersioniDelRendiconto::cifreCheNonTornano([(int) $versione->id])[(int) $versione->id] ?? null;
        if ($nonTornano !== null) {
            return back()->with($this->flashWarning('Versione '.$versione->numero.' fermata per l\'assemblea. '.$nonTornano));
        }

        return back()->with(($versione->pdf['documenti'] ?? []) === []
            ? $this->flashWarning('Versione '.$versione->numero.' fermata con le cifre di oggi. '.VersioniDelRendiconto::FRASE_SENZA_PDF)
            : $this->flashSuccess('Versione '.$versione->numero.' fermata per l\'assemblea: le cifre e il PDF del fascicolo sono conservati.'));
    }

    /** «Conserva le parti una per una»: una parte per richiesta, con la data della versione. */
    public function parte(Request $request, Condominio $condominio, Esercizio $esercizio, string $versione): RedirectResponse
    {
        $v = $this->assicuraVersione($condominio, $esercizio, $versione);
        $dati = $request->validate(['parte' => ['required', 'string', Rule::in(FascicoloDelRendiconto::PARTI)]]);
        $troppo = $this->versioni->conservaParte($v, $dati['parte'], $request->user());

        return back()->with($troppo === null
            ? $this->flashSuccess('«'.FascicoloDelRendiconto::NOMI[$dati['parte']].'» conservata nella versione '.$v->numero.'.')
            : $this->flashError($troppo));
    }

    /** «Carica il PDF che hai mandato». */
    public function carica(Request $request, Condominio $condominio, Esercizio $esercizio, string $versione): RedirectResponse
    {
        $v = $this->assicuraVersione($condominio, $esercizio, $versione);
        // Un file nel gestionale: chiede «Crea documenti archivio», come «Allega» della fattura.
        Gate::authorize('create', Documento::class);
        $request->validate([
            'pdf' => ['required', 'file', 'extensions:pdf', 'max:'.LimiteCaricamento::regolaMax(50.0), FileComeIlNome::regola()],
        ], ['pdf.required' => 'Scegli il PDF che hai mandato ai condòmini.']);
        $this->versioni->caricaPdf($v, $request->file('pdf'), $request->user());

        return back()->with($this->flashSuccess('PDF caricato nella versione '.$v->numero.', con la sua impronta.'));
    }

    /** «Registra l'approvazione»: la data della delibera, il verbale, se l'assemblea ha approvato anche lo stato di riparto (sì o no, da dire). */
    public function approva(Request $request, Condominio $condominio, Esercizio $esercizio, string $versione): RedirectResponse
    {
        $v = $this->assicuraVersione($condominio, $esercizio, $versione);
        // Registrare l'approvazione resta di chi entra nel pannello; caricare il verbale chiede anche «Crea documenti archivio».
        if ($request->hasFile('verbale')) {
            Gate::authorize('create', Documento::class);
        }
        $dati = $request->validate([
            'data_delibera' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.DateHelper::oggiUtente()],
            'numero_verbale' => ['nullable', 'string', 'max:50'],
            'riparto_approvato' => ['required', Rule::in(['0', '1', 0, 1, true, false], true)],
            'nota_approvazione' => ['nullable', 'string', 'max:2000'],
            'verbale' => ['nullable', 'file', 'extensions:pdf,jpg,jpeg,png', 'max:'.LimiteCaricamento::regolaMax(10.0), FileComeIlNome::regola()],
        ], [
            'data_delibera.required' => 'Scrivi la data dell\'assemblea che ha approvato il rendiconto.',
            'data_delibera.before_or_equal' => 'La data della delibera non può essere dopo oggi.',
            'riparto_approvato.required' => 'Di\' se l\'assemblea ha approvato anche lo stato di riparto: sì o no, lo dice il verbale.',
            'riparto_approvato.in' => 'Di\' se l\'assemblea ha approvato anche lo stato di riparto: sì o no, lo dice il verbale.',
        ]);
        if ($v->stato !== RendicontoVersione::PRESENTATA) {
            return back()->with($this->flashError('La versione '.$v->numero.' non è presentata: si registra l\'approvazione di una versione presentata.'));
        }
        $dati['riparto_approvato'] = filter_var($dati['riparto_approvato'], FILTER_VALIDATE_BOOLEAN);
        $this->versioni->approva($v, $dati, $request->file('verbale'), $request->user());

        return back()->with($this->flashSuccess('Approvazione registrata sulla versione '.$v->numero.'. Lo stato dell\'esercizio non cambia.'));
    }

    /** «Elimina la versione»: una versione fermata e mai approvata, con il suo PDF e i suoi file. Le approvate e le sostituite restano. */
    public function destroy(Condominio $condominio, Esercizio $esercizio, string $versione): RedirectResponse
    {
        $v = $this->assicuraVersione($condominio, $esercizio, $versione);
        $rifiuto = $this->versioni->elimina($v);

        return back()->with($rifiuto === null
            ? $this->flashSuccess('Versione '.$v->numero.' eliminata, con il suo PDF. Le schede del rendiconto non cambiano.')
            : $this->flashError($rifiuto));
    }

    /**
     * Un documento della versione: il PDF conservato, una sua parte, quello caricato, il verbale. Il verbale approvato dall'assemblea (il seguito delle
     * delibere) è il documento del verbale sottoscritto, già fra i documenti dell'assemblea: la versione lo indica con `verbale_documento_id`, e si apre da qui
     * con il permesso di chi vede il rendiconto.
     */
    public function documento(Condominio $condominio, Esercizio $esercizio, string $versione, string $documento, ArchivioDocumenti $archivio)
    {
        $v = $this->assicuraVersione($condominio, $esercizio, $versione);
        $d = Documento::query()->whereKey((int) $documento)->where(fn ($q) => $q->where(fn ($w) => $w->where('documentable_type', RendicontoVersione::class)
            ->where('documentable_id', $v->id))->when($v->verbale_documento_id !== null, fn ($w) => $w->orWhere('id', (int) $v->verbale_documento_id)))->first();
        abort_if($d === null || ! $archivio->esiste($d->path), 404);

        return $archivio->apri($d->path, $d->nomeDiScaricamento(), (string) $d->mime_type);
    }

    private function assicuraVersione(Condominio $condominio, Esercizio $esercizio, string $versione): RendicontoVersione
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        return RendicontoVersione::where('condominio_id', $condominio->id)->where('esercizio_id', $esercizio->id)->findOrFail((int) $versione);
    }
}

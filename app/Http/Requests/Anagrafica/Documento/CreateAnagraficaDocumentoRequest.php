<?php

namespace App\Http\Requests\Anagrafica\Documento;

use App\Support\LimiteCaricamento;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;

/**
 * Il caricamento di un documento sulla scheda di un'anagrafica.
 *
 * Gemella di `Fornitore\Documento\CreateFornitoreDocumentoRequest`, con due differenze dichiarate:
 *
 * - **la descrizione è facoltativa.** Sul fornitore è obbligatoria; qui il caso normale è archiviare
 *   la copia di un documento d'identità, dove il nome dice già tutto e la descrizione è un campo da
 *   riempire per forza. Un campo obbligatorio che non serve si riempie con «-», e allora tanto vale
 *   non chiederlo;
 * - **il limite del file lo dichiara il server**, come ovunque: `LimiteCaricamento` legge
 *   `upload_max_filesize` e `post_max_size`, così la regola non può promettere più di quanto la
 *   macchina accetti.
 */
class CreateAnagraficaDocumentoRequest extends FormRequest
{
    /**
     * Il permesso prima delle regole (Coda 402): il caricamento di un documento della persona chiede la `update` di
     * `AnagraficaPolicy` sulla persona della rotta, come il suo modulo di modifica. Chi non la ha riceve il rifiuto
     * con la sua frase e nessun esito delle regole. `AnagraficaDocumentoController` la chiede di nuovo.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('anagrafica'));
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'is_published' => ['required', 'boolean'],
            'is_approved'  => ['required', 'boolean'],

            // ⚠️ Solo PDF, come l'archivio e come il fornitore. Non è una limitazione di questa
            // schermata: allargarla qui vorrebbe dire avere un tipo di file che l'archivio non sa
            // mostrare, e un documento che si carica e non si apre è peggio di uno che non si
            // carica.
            'file'         => ['required', 'file', 'mimes:pdf', 'max:'.LimiteCaricamento::regolaMax()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_published' => $this->boolean('is_published'),
            'is_approved'  => $this->boolean('is_approved'),
        ]);
    }

    public function attributes(): array
    {
        return [
            'name'        => 'nome del documento',
            'description' => 'descrizione',
            'file'        => 'file',
        ];
    }

    /** Chi carica è chi è collegato: non si prende dal modulo, dove sarebbe falsificabile. */
    public function autore(): int
    {
        return Auth::id();
    }
}

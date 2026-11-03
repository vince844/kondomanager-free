<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Support\LimiteCaricamento;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Allega un documento a una fattura già registrata (Coda 102, 1.11.0-beta.12).
 *
 * L'autorizzazione vera è nel controller (`Gate::authorize('create', Documento::class)`),
 * come per `download()` sullo stesso modello: qui `authorize()` resta `true`, coerente
 * con StoreFatturaRequest e UpdateFatturaRequest.
 */
class StoreFatturaDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ⚠️ `extensions:`, non `mimes:` — trovato dalla revisione avversariale della
            // beta.12 sulla fixture .p7m vera del progetto (tests/Fixtures/fatturapa/).
            // `mimes:` guarda il contenuto (`finfo`): una busta CAdES è ASN.1 generico,
            // `finfo` la vede `application/octet-stream` e `guessExtension()` restituisce
            // `'bin'` — MAI `p7m`, qualunque file .p7m reale. Il tetto che il tracciato
            // FatturaPA promette di poter allegare (docs/lettura_xml_fatture_passive.md)
            // era quindi irraggiungibile al 100% dei tentativi. `extensions:` si fida del
            // nome del file: più debole in astratto, ma qui il file va su un disco
            // *privato* con nome casuale (`hashName()`) e non è mai eseguito né servito
            // se non da `response()->download()` — nessuna strada nuova si apre.
            // Stesso tetto di dimensione di prima di questa beta.
            'file' => ['required', 'file', 'extensions:pdf,xml,p7m,jpg,jpeg,png', 'max:'.LimiteCaricamento::regolaMax(10.0)],
        ];
    }
}

<?php

namespace App\Http\Requests\Gestionale\Esercizio;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method bool merge(string $key)
 * @method bool input(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class UpdateEsercizioRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome'                => 'required|string|max:255', 
            'descrizione'         => 'required|string|max:255',
            'data_inizio'         => 'required|date',
            'data_fine'           => 'required|date|after_or_equal:data_inizio',
            'stato'               => 'required|string|in:aperto,chiuso',
            'note'                => 'sometimes|nullable|string',
        ];
    }

    /**
     * Stessa invariante di CreateEsercizioRequest: riaprire un esercizio chiuso
     * mentre ne esiste un altro aperto renderebbe ambiguo l'esercizio corrente.
     *
     * Due precisazioni imparate dalla review:
     *  - il controllo scatta solo sulla TRANSIZIONE chiuso → aperto. Il form rimanda
     *    sempre `stato`, quindi su un esercizio già aperto il vincolo bloccherebbe
     *    perfino la rinomina — e su un DB con due esercizi aperti (ripristino di un
     *    backup anteriore a questa versione) renderebbe entrambi immodificabili;
     *  - il condominio si legge dall'ESERCIZIO, non dalla rotta: le rotte annidate
     *    non hanno scopeBindings, quindi il condominio di rotta può non essere quello
     *    a cui l'esercizio appartiene.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $esercizio = $this->route('esercizio');

            if ($this->input('stato') !== 'aperto') {
                return;
            }

            // Già aperto e resta aperto: nessuna transizione, nessun vincolo.
            if ($esercizio instanceof \App\Models\Esercizio && $esercizio->stato === 'aperto') {
                return;
            }

            $condominioId = $esercizio instanceof \App\Models\Esercizio
                ? $esercizio->condominio_id
                : $this->route('condominio')?->id;

            $altroAperto = \App\Models\Esercizio::where('condominio_id', $condominioId)
                ->where('stato', 'aperto')
                ->when($esercizio?->id, fn ($q) => $q->where('id', '!=', $esercizio->id))
                ->first();

            if ($altroAperto) {
                $validator->errors()->add(
                    'stato',
                    "Esiste già un esercizio aperto per questo condominio («{$altroAperto->nome}»). "
                    .'Chiudilo prima di riaprire questo.'
                );
            }
        });
    }
}

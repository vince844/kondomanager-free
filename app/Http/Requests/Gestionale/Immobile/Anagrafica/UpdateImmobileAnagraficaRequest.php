<?php

namespace App\Http\Requests\Gestionale\Immobile\Anagrafica;

use App\Enums\RuoloAnagraficaImmobile;
use App\Traits\ValidatesImmobileAnagraficaPivot;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method bool filled(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 * @method string|null input(string $key, mixed $default = null)
 */
class UpdateImmobileAnagraficaRequest extends FormRequest
{

    use ValidatesImmobileAnagraficaPivot;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `min:0` e `max:100` mancavano in entrambe le Request, ed è la porta da cui sono
            // entrate le anomalie di A7 (`subentro_e_competenza_temporale.md`): sul database
            // reale ci sono unità con somma quote 200 e una riga a quota 0.
            //
            // ⚠️ **Il limite non è la somma, ed è deliberato.** La somma per ruolo la presidia
            // già `ValidatesImmobileAnagraficaPivot`; qui si ferma solo il valore assurdo sulla
            // singola riga. Una quota di 50 su un titolare unico resta accettata, perché
            // `pivot.quota` è un **peso relativo fra soggetti dello stesso ruolo** e non una
            // riduzione della quota dell'unità: chi la scrive pensando «metà» ottiene comunque
            // l'addebito intero, ma il dato non è di per sé sbagliato ed è l'amministratore a
            // doverlo decidere.
            'quota'           => 'required|numeric|min:0|max:100',
            'note'            => 'sometimes|nullable|string',
            'anagrafica_id'   => ['required','integer', Rule::exists('anagrafiche', 'id')],
            // Vedi la nota gemella in `CreateImmobileAnagraficaRequest`.
            'tipologia'       => ['required', Rule::in(RuoloAnagraficaImmobile::values())],
            'data_inizio'     => 'required|date',
            // ⚠️ `after_or_equal:data_inizio` c'era nella Request di creazione e **non** qui: in
            // modifica si salvava una data di fine anteriore alla data di inizio. Un periodo di
            // titolarità che finisce prima di cominciare non è un dato brutto, è un dato che il
            // filtro temporale della 1.11 non saprà interpretare — e a quel punto sarà già in
            // banca dati.
            'data_fine'       => 'sometimes|nullable|date|after_or_equal:data_inizio',
        ];
    }

   public function withValidator($validator)
    {
        $this->withPivotValidator($validator);
    }

    protected function prepareForValidation()
    {
        if ($this->filled('data_inizio')) {
            $this->merge([
                'data_inizio' => Carbon::parse($this->input('data_inizio'))->toDateString(),
            ]);
        }

        $this->merge([
            'data_fine' => $this->filled('data_fine')
                ? Carbon::parse($this->input('data_fine'))->toDateString()
                : null,
        ]);
    }
}

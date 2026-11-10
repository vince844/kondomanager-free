<?php

namespace App\Http\Requests\Allegati;

use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImpostazioniAllegatiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $elemento = $this->route('segnalazione') ?? $this->route('comunicazione');

        if (! $user || ! ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione)) {
            return false;
        }

        return RegoleAllegati::puoImpostare($user, $elemento);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'allegati_accesso'  => ['required', 'string', Rule::in(RegoleAllegati::ACCESSI)],
            'allegati_moderati' => ['required', 'boolean'],
        ];
    }
}


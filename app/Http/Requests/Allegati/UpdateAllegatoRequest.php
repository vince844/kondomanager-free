<?php

namespace App\Http\Requests\Allegati;

use App\Models\Documento;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAllegatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $allegato = $this->route('allegato');

        if (! $user || ! $allegato instanceof Documento) {
            return false;
        }

        $elemento = RegoleAllegati::elementoDellAllegato($allegato);
        abort_if($elemento === null, 404);

        return RegoleAllegati::puoModificare($user, $allegato, $elemento);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
        ];
    }
}


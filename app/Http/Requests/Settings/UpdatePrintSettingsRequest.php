<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\LimiteCaricamento;

class UpdatePrintSettingsRequest extends FormRequest
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
            'nota_legale_stampe'         => 'nullable|string',
            // ⚠️ Via `svg`: `image` e `mimes` sono in **AND**, e `validateImage()` di Laravel non
            // ammette l'SVG senza il parametro `allow_svg`. La regola dichiarava quindi ammesso un
            // formato che scartava subito dopo, con un messaggio che non spiegava niente. Il
            // comportamento non cambia per nessuno — l'SVG è sempre stato respinto — mentre
            // abilitarlo sarebbe una capacità nuova (mPDF renderebbe un XML caricato dall'utente
            // dentro ogni stampa), da decidere per conto suo e non di sfuggita.
            //
            // Il tetto resta 2 MB, che è il suo: una firma è un rettangolo stampato a 180×80 punti.
            // Quel che cambia è che su un server più stretto la regola non ne promette più due.
            'firma_stampe'               => 'nullable|image|mimes:jpeg,png,jpg|max:'.LimiteCaricamento::regolaMax(2.0),
            'delete_firma_stampe'        => 'nullable|boolean',
        ];
    }
}

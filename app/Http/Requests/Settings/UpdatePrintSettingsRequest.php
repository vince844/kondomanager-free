<?php

namespace App\Http\Requests\Settings;

use App\Services\PDF\ImmagineDiStampa;
use App\Settings\PrintSettings;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use App\Support\LimiteCaricamento;

class UpdatePrintSettingsRequest extends FormRequest
{
    /**
     * Il permesso prima dei dati: chi non gestisce le impostazioni ha il 403, non il rifiuto di un campo (e il logo e la firma non si aprono). Il
     * controller lo chiede di nuovo.
     */
    public function authorize(): Response
    {
        return Gate::inspect('manage', app(PrintSettings::class));
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
            // `bail`: al primo controllo che non passa si ferma, e il rifiuto dice una frase sola.
            'firma_stampe'               => 'nullable|bail|image|mimes:jpeg,png,jpg|max:'.LimiteCaricamento::regolaMax(2.0),
            'delete_firma_stampe'        => 'nullable|boolean',
            // Lo studio che scrive le lettere ai condòmini: in testa alla lettera, accanto al condominio. Facoltativi.
            'studio_nome'                => 'nullable|string|max:150',
            'studio_indirizzo'           => 'nullable|string|max:255',
            'studio_email'               => 'nullable|email|max:150',
            'studio_pec'                 => 'nullable|email|max:150',
            'studio_telefono'            => 'nullable|string|max:50',
            'studio_comune'              => 'nullable|string|max:100',
            // In testa alle stampe con gli altri dati dello studio. Il codice fiscale di una persona può avere lettere al posto delle cifre
            // (l'omocodia): 16 lettere e cifre; quello di una società sono 11 cifre. Arriva senza spazi e in maiuscolo (`prepareForValidation`).
            'studio_partita_iva'         => 'nullable|string|regex:/^[0-9]{11}$/',
            // In una lista, non in una stringa: la barra verticale della regola si leggerebbe come separatore delle regole.
            'studio_codice_fiscale'      => ['nullable', 'string', 'regex:/^([0-9]{11}|[A-Z0-9]{16})$/'],
            'studio_sito'                => 'nullable|string|max:150|not_regex:/\s/',
            // Il logo come la firma: niente SVG, 2 MB o il limite del server; la misura in pixel la controlla `after()`.
            'logo_stampe'                => 'nullable|bail|image|mimes:jpeg,png,jpg|max:'.LimiteCaricamento::regolaMax(2.0),
            'delete_logo_stampe'         => 'nullable|boolean',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $limite = LimiteCaricamento::etichetta(2.0);

        return [
            // Un GIF, un WEBP, un SVG, un file che non è un'immagine: per tutti la stessa frase, che dice che cosa caricare.
            'logo_stampe.image'           => 'Il logo va caricato come PNG o JPG.',
            'logo_stampe.mimes'           => 'Il logo va caricato come PNG o JPG.',
            'logo_stampe.max'             => "Il logo può essere grande al massimo {$limite}.",
            'logo_stampe.uploaded'        => "Il logo può essere grande al massimo {$limite}.",
            'firma_stampe.image'          => 'La firma va caricata come PNG o JPG.',
            'firma_stampe.mimes'          => 'La firma va caricata come PNG o JPG.',
            'firma_stampe.max'            => "La firma può essere grande al massimo {$limite}.",
            'firma_stampe.uploaded'       => "La firma può essere grande al massimo {$limite}.",
            'studio_partita_iva.regex'    => 'La partita IVA ha 11 cifre.',
            'studio_codice_fiscale.regex' => 'Il codice fiscale ha 11 cifre, o 16 lettere e cifre.',
            'studio_sito.max'             => 'Il sito può avere al massimo 150 caratteri.',
            'studio_sito.not_regex'       => 'Il sito si scrive senza spazi.',
        ];
    }

    /** Il codice fiscale e la partita IVA dello studio senza spazi, il codice fiscale in maiuscolo; il sito senza gli spazi ai lati. */
    protected function prepareForValidation(): void
    {
        $pulito = [];
        foreach (['studio_partita_iva', 'studio_codice_fiscale'] as $campo) {
            if (is_string($this->input($campo))) {
                $pulito[$campo] = (string) preg_replace('/\s+/u', '', $this->input($campo));
            }
        }
        if (isset($pulito['studio_codice_fiscale'])) {
            $pulito['studio_codice_fiscale'] = mb_strtoupper($pulito['studio_codice_fiscale']);
        }
        if (is_string($this->input('studio_sito'))) {
            $pulito['studio_sito'] = trim($this->input('studio_sito'));
        }
        $this->merge($pulito);
    }

    /**
     * Il logo e la firma, prima di aprirli: la misura dall'intestazione del file, contro il tetto di 4 milioni di pixel ({@see ImmagineDiStampa}).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (['logo_stampe', 'firma_stampe'] as $campo) {
                $file = $this->file($campo);
                if ($file instanceof UploadedFile && $file->isValid() && ! $validator->errors()->has($campo)
                    && ($rifiuto = ImmagineDiStampa::rifiuto((string) $file->getRealPath())) !== null) {
                    $validator->errors()->add($campo, $rifiuto);
                }
            }
        }];
    }
}

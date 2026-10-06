<?php

namespace App\Http\Requests\Gestionale\Saldi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Valida l'aggiornamento di un saldo iniziale.
 *
 * Controlli:
 *   - saldo_iniziale obbligatorio e intero (centesimi)
 *   - gestione_id obbligatorio e appartenente al condominio
 *   - il saldo non deve essere già applicato a un piano rate (muro contabile)
 *   - il saldo deve appartenere al condominio della route (ownership)
 */
class UpdateSaldoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'saldo_iniziale' => ['required', 'integer'],
            'gestione_id'    => ['required', 'integer', 'exists:gestioni,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $saldo      = $this->route('saldo');
                $condominio = $this->route('condominio');

                // Ownership: il saldo deve appartenere a questo condominio
                if ($saldo->condominio_id !== $condominio->id) {
                    $validator->errors()->add(
                        'saldo',
                        'Il saldo non appartiene a questo condominio.'
                    );
                    return;
                }

                // B2 (inv. 19): la coppia di conguaglio di un passaggio somma zero per costruzione, e non si
                // tocca a metà. Si corregge dal passaggio, o con un saldo manuale a parte.
                if ($saldo->dellArretrato()) {
                    $validator->errors()->add('saldo', \App\Models\Saldo::FRASE_ARRETRATO);
                    return;
                }
                // Decisione 67 (3): un saldo da cui una successione ha calcolato l'arretrato agli eredi.
                if (($frase = $saldo->fraseFonteDellArretrato()) !== null) {
                    $validator->errors()->add('saldo', $frase);
                    return;
                }
                if ($saldo->subentro_id !== null && $saldo->subentro?->arretratoAgliEredi()) {
                    $validator->errors()->add('saldo', \App\Models\Saldo::FRASE_CONGUAGLIO_CON_ARRETRATO);
                    return;
                }
                if ($saldo->subentro_id !== null) {
                    $validator->errors()->add('saldo', 'Questa riga è una delle due del conguaglio di un passaggio di titolarità (credito a chi esce, debito a chi entra, somma zero): non si modifica da sola. Se le parti hanno regolato diversamente, annulla il conguaglio dallo storico dell\'unità («Passaggi registrati»): toglie le due righe insieme, con la tua nota; se un piano le ha già emesse, resta il saldo manuale di segno opposto.');
                    return;
                }

                // Muro contabile: la soglia è l'EMISSIONE, non la generazione.
                // Finché il piano non è emesso né incassato resta interamente
                // riscrivibile — «Ricalcola» lo dimostra — quindi il saldo che
                // lo alimenta si può ancora correggere.
                if ($saldo->eBloccato()) {
                    $piano = $saldo->pianoRate;

                    $validator->errors()->add(
                        'saldo',
                        // Rilievo V9 del giro di verifica della .42: la ragione vera del fermo e il suo rimedio. Un piano fermo solo
                        // per il conguaglio di un passaggio non ha emissioni da annullare.
                        $piano
                            ? "Non puoi modificare questo saldo: è incluso nel piano rate «{$piano->nome}», che "
                                . ($piano->fraseDelFermo() ?? 'non si riscrive più') . '. '
                                . (($rimedi = $piano->rimediDelFermo()) !== [] ? 'Per correggerlo: ' . implode('; ', $rimedi) . '.' : '')
                            : 'Non puoi modificare un saldo già incluso in un piano rate emesso.'
                    );
                    return;
                }

                // La gestione deve appartenere al condominio
                $gestioneAppartiene = $condominio->gestioni()
                    ->where('id', $this->input('gestione_id'))
                    ->exists();

                if (!$gestioneAppartiene) {
                    $validator->errors()->add(
                        'gestione_id',
                        'La gestione selezionata non appartiene a questo condominio.'
                    );
                }
            }
        ];
    }

    public function messages(): array
    {
        return [
            'saldo_iniziale.required' => 'Inserisci un importo.',
            'saldo_iniziale.integer'  => 'L\'importo deve essere un numero intero (centesimi).',
            'gestione_id.required'    => 'Seleziona una gestione.',
            'gestione_id.exists'      => 'La gestione selezionata non esiste.',
        ];
    }
}

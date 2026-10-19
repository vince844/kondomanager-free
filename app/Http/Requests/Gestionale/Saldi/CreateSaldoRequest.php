<?php

namespace App\Http\Requests\Gestionale\Saldi;

use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Traits\HasEsercizio;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Valida e blocca i duplicati per entrambi i tipi di saldo:
 *
 * Personale: un solo saldo per (esercizio, gestione, immobile, anagrafica), senza contare quelli scritti da un passaggio né quelli legati a un passaggio
 * Solidale:  un solo saldo per (esercizio, gestione, immobile) con anagrafica_id NULL, con la stessa eccezione
 *
 * Con `passaggio_id` (1.11.0-beta.53, Coda 244) la riga è legata a un passaggio di titolarità: la parte di una fermata che il programma non
 * sposta, quindi mai di € 0,00. Non occupa il posto, e il posto non si guarda; si controlla invece che il passaggio sia un passaggio padre
 * di questo condominio, non annullato, con il registro, la cui famiglia comprenda l'unità; che la persona abbia o abbia avuto una riga su
 * quell'unità; che la gestione sia fra quelle attive del condominio. Il doppione delle righe legate lo controlla
 * `SaldoInizialeController::store`, dentro la transazione.
 */
class CreateSaldoRequest extends FormRequest
{
    use HasEsercizio; 

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'anagrafica_id'  => ['nullable', 'integer', 'exists:anagrafiche,id', 'required_with:passaggio_id'],
            'immobile_id'    => ['required', 'integer', 'exists:immobili,id'],
            'gestione_id'    => ['required', 'integer', 'exists:gestioni,id'],
            'saldo_iniziale' => ['required', 'integer'],
            'passaggio_id'   => ['nullable', 'integer'],
        ];
    }

    /**
     * Validazione after: controlla unicità in base al tipo di saldo.
     * Chiamata dopo che le rules() di base sono passate.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return; // Non proseguire se ci sono già errori
                }

                $condominio = $this->route('condominio');
                
                // 2. Usiamo il metodo del Trait passandogli il condominio
                $esercizio = $this->getEsercizioCorrente($condominio);

                if (!$esercizio) {
                    $validator->errors()->add('error', 'Nessun esercizio aperto per questo condominio.');
                    return;
                }

                $anagraficaId = $this->input('anagrafica_id');
                $gestioneId   = $this->input('gestione_id');
                $immobileId   = $this->input('immobile_id');

                if ($this->filled('passaggio_id')) {
                    // Una riga legata a zero non sposta nessuna parte: si rifiuta sull'importo. Senza legame resta come prima.
                    if ((int) $this->input('saldo_iniziale') === 0) {
                        $validator->errors()->add('saldo_iniziale', 'Un saldo legato a un passaggio di € 0,00 non sposta niente: scrivi l\'importo della parte.');

                        return;
                    }
                    // La riga legata a un passaggio non occupa il posto: il posto non si guarda.
                    $this->controllaIlPassaggio($validator, $condominio, (int) $this->input('passaggio_id'), (int) $anagraficaId, (int) $immobileId, (int) $gestioneId);

                    return;
                }

                // B2 (S5): le righe del conguaglio di un passaggio (`subentro_id`) non occupano lo slot —
                // dopo un subentro l'entrante deve poter registrare un suo pregresso sulla stessa gestione. Dalla
                // 1.11.0-beta.53 nemmeno le righe legate a un passaggio (`passaggio_id`): il posto conta un saldo per
                // persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio.
                $query = Saldo::where('esercizio_id', $esercizio->id)
                    ->where('gestione_id', $gestioneId)
                    ->where('immobile_id', $immobileId)
                    ->whereNull('subentro_id')
                    ->whereNull('passaggio_id');

                if (is_null($anagraficaId)) {
                    // Saldo solidale: max uno per (esercizio, gestione, immobile)
                    $esiste = (clone $query)->whereNull('anagrafica_id')->exists();

                    if ($esiste) {
                        $validator->errors()->add(
                            'anagrafica_id',
                            'Esiste già un saldo solidale per questo immobile in questa gestione. Modifica quello esistente.'
                        );
                    }
                } else {
                    // Saldo personale: max uno per (esercizio, gestione, immobile, anagrafica)
                    $esiste = (clone $query)->where('anagrafica_id', $anagraficaId)->exists();

                    if ($esiste) {
                        $validator->errors()->add(
                            'anagrafica_id',
                            'Esiste già un saldo per questa anagrafica in questa gestione.'
                        );
                    }
                }
            }
        ];
    }

    /** Il passaggio padre a cui la riga si lega, la sua unità, la persona e la gestione: ogni rifiuto dice il campo e la ragione. */
    private function controllaIlPassaggio(Validator $validator, $condominio, int $passaggioId, int $anagraficaId, int $immobileId, int $gestioneId): void
    {
        $passaggio = Subentro::query()->whereKey($passaggioId)->where('condominio_id', $condominio->id)->whereNull('subentro_padre_id')->first();
        if ($passaggio === null) {
            $validator->errors()->add('passaggio_id', self::FRASE_PASSAGGIO_ALTRUI);

            return;
        }
        if (! is_array($passaggio->registro)) {
            // Un passaggio senza registro non si annulla dal programma (`AnnullaPassaggioAction::motivoBlocco`): una riga legata a lui non si toglierebbe.
            $validator->errors()->add('passaggio_id', 'Questo passaggio è stato registrato prima della 1.11.0-beta.37 e non si annulla dal programma: un saldo per lui si scrive senza legame.');

            return;
        }
        $unita = Subentro::conAnnullati()->where(fn ($q) => $q->whereKey($passaggio->id)->orWhere('subentro_padre_id', $passaggio->id))
            ->pluck('immobile_id')->map(fn ($id) => (int) $id)->all();
        if (! in_array($immobileId, $unita, true)) {
            $validator->errors()->add('passaggio_id', 'Il passaggio scelto non riguarda questa unità.');

            return;
        }
        // Una persona dell'unità: chi ha o ha avuto una riga di titolarità lì, in qualunque periodo (la stessa lista che il modale offre).
        if (! DB::table('anagrafica_immobile')->where('anagrafica_id', $anagraficaId)->where('immobile_id', $immobileId)->exists()) {
            $validator->errors()->add('anagrafica_id', 'Questa persona non ha mai avuto una riga su questa unità: un saldo legato a un passaggio è di una delle sue persone.');

            return;
        }
        if (! Gestione::whereKey($gestioneId)->where('condominio_id', $condominio->id)->where('attiva', true)->exists()) {
            $validator->errors()->add('gestione_id', 'La gestione scelta non è fra quelle attive di questo condominio.');
        }
    }

    /** Il passaggio scelto non c'è fra quelli a cui legare: di un altro condominio, annullato, o il passaggio di una pertinenza. */
    public const FRASE_PASSAGGIO_ALTRUI = 'Il passaggio scelto non è di questo condominio, o è stato annullato.';

    public function messages(): array
    {
        return [
            'anagrafica_id.required_with' => 'Un saldo legato a un passaggio è di una persona: scegli chi.',
            'immobile_id.required' => 'Seleziona un immobile.',
            'gestione_id.required' => 'Seleziona una gestione.',
            'saldo_iniziale.required' => 'Inserisci un importo.',
            'saldo_iniziale.integer'  => 'L\'importo deve essere un numero intero (centesimi).',
            'anagrafica_id.exists'    => 'L\'anagrafica selezionata non esiste.',
        ];
    }
}
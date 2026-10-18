<?php

namespace App\Http\Requests\Gestionale\PianoConto\Conto;

use App\Helpers\MoneyHelper;
use Illuminate\Validation\Validator;

/**
 * L'importo di una voce si scrive senza il segno: il segno lo decide il tipo (una spesa si addebita, un'entrata si accredita).
 *
 * Il motore di ripartizione applica il valore assoluto alla voce secondo il suo tipo, quindi un importo negativo non rovescia niente: una spesa
 * a −€ 240,00 si addebita come € 240,00 mentre la distinta delle spese la somma con il suo segno, e i due documenti si contraddicono. I tre punti
 * d'ingresso (creazione, modifica, importo che il piano forza sulla voce) rifiutano il meno con queste frasi, che stanno qui per dirlo una volta sola.
 */
final class ImportoSenzaSegno
{
    public const PER_UNA_SPESA = 'Una voce di spesa non può avere un importo negativo. Se è un provento, per esempio l\'affitto di un\'antenna, scegli «Entrata» e scrivi l\'importo senza il segno.';

    public const PER_UN_ENTRATA = 'L\'importo di una voce di entrata si scrive senza il segno: è il tipo «Entrata» a ridurre quanto si chiede ai condòmini.';

    public const PER_IL_PIANO = 'L\'importo di una voce nel piano non può essere negativo. Scrivi l\'importo senza il segno: lo decide il tipo della voce.';

    /** Il testo del rifiuto per il tipo che la richiesta manda. */
    public static function rifiutoPerIlTipo(mixed $tipo): string
    {
        return $tipo === 'entrata' ? self::PER_UN_ENTRATA : self::PER_UNA_SPESA;
    }

    /** L'importo scritto è un numero negativo. Una lista o un valore che non è testo non lo è: lo rifiutano già le regole del campo. */
    public static function eNegativo(mixed $importo): bool
    {
        return (is_string($importo) || is_int($importo) || is_float($importo)) && MoneyHelper::toCents($importo) < 0;
    }

    /** Aggiunge il rifiuto sul campo, se il campo non ha già un errore. */
    public static function rifiuta(Validator $validator, string $campo, string $messaggio): void
    {
        if (! $validator->errors()->has($campo)) {
            $validator->errors()->add($campo, $messaggio);
        }
    }
}

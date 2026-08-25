<?php

namespace App\Services\Import\Controlli\Verificatori;

use App\Models\ImportBatch;
use App\Models\Saldo;
use App\Services\Import\Controlli\EsitoControllo;
use App\Services\Import\Controlli\VerificatoreControllo;
use Illuminate\Support\Facades\DB;

/**
 * I saldi importati sono intestati a chi su quell'unità è davvero titolare?
 *
 * Il riparto e l'elenco unità sono due stampe di due momenti diversi, e in mezzo può esserci una
 * compravendita. Il saldo entra comunque — l'art. 63 disp. att. c.c. lo lega all'unità prima che
 * alla persona — ma va riletto **prima** di trasformarlo in una rata.
 *
 * ## Perché esiste lo stato «superato»
 *
 * Quando il saldo è già stato assorbito da un piano rate emesso, la finestra utile si è chiusa:
 * non lo si può più cambiare da lì. Dire «risolto» sarebbe falso — nessuno l'ha controllato — e
 * dire «aperto» manderebbe l'amministratore su una pagina dove non può più fare niente. La riga
 * resta, dice la verità, e diventa spuntabile a mano.
 */
final class TitolaritaDeiSaldi implements VerificatoreControllo
{
    public function esegui(ImportBatch $batch, array $idPerTipo): EsitoControllo
    {
        $id = $idPerTipo[Saldo::class] ?? [];

        if ($id === []) {
            return EsitoControllo::risolto('Nessun saldo importato da questo lotto.');
        }

        // I saldi solidali (`anagrafica_id` a NULL) sono per costruzione dell'unità e non di una
        // persona: la domanda non li riguarda.
        $saldi = Saldo::whereIn('id', $id)->whereNotNull('anagrafica_id')->get();

        $incoerenti = $saldi->filter(fn (Saldo $s) => ! DB::table('anagrafica_immobile')
            ->where('anagrafica_id', $s->anagrafica_id)
            ->where('immobile_id', $s->immobile_id)
            ->exists());

        if ($incoerenti->isEmpty()) {
            return EsitoControllo::risolto('Ogni saldo importato è intestato a un titolare di quell\'unità.');
        }

        $frase = sprintf(
            '%d %s ancora intestat%s a chi su quell\'unità non risulta titolare.',
            $incoerenti->count(),
            $incoerenti->count() === 1 ? 'saldo' : 'saldi',
            $incoerenti->count() === 1 ? 'o' : 'i',
        );

        if ($incoerenti->every(fn (Saldo $s) => $s->eBloccato())) {
            return EsitoControllo::superato($incoerenti->count(), $frase
                .' Sono già dentro un piano rate emesso: da qui non si cambiano più.');
        }

        return EsitoControllo::aperto($incoerenti->count(), $frase);
    }
}

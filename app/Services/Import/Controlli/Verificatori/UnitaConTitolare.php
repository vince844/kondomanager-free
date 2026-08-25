<?php

namespace App\Services\Import\Controlli\Verificatori;

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\ImportBatch;
use App\Models\Immobile;
use App\Services\Import\Controlli\EsitoControllo;
use App\Services\Import\Controlli\VerificatoreControllo;
use Illuminate\Support\Facades\DB;

/**
 * Le unità importate hanno qualcuno a cui intestare le rate?
 *
 * Un'unità senza titolare non riceve rate, non compare in morosità e non entra in nessun
 * riparto. È la voce che l'anteprima mostra per prima in rosso, ed è anche quella che si chiude
 * da sola nel momento in cui l'amministratore assegna l'ultimo proprietario.
 *
 * ## «Titolare» significa diritto reale, non «una riga qualsiasi nella pivot»
 *
 * Il conteggio guardava ogni riga di `anagrafica_immobile`, **inquilino compreso**. Un'unità
 * con il solo conduttore risultava così a posto — verde — mentre per il motore di riparto è
 * invisibile: `CalcoloQuoteService::distribuisciSuTabelle()` esaurisce la cascata e mette il
 * peso fra gli scoperti (nessuno paga quella quota), e `GenerateSaldiAction::risolviTitolari()`
 * solleva `SaldoSolidaleSenzaTitolareException` bloccando l'emissione del piano rate.
 *
 * Era quindi il difetto peggiore che questa lista possa avere: un verde falso su un problema
 * che ricompare più tardi, come eccezione, lontano dall'importazione che l'ha causato. La
 * regola sta in un posto solo dalla beta.43 — `RuoloAnagraficaImmobile::titolariDiDirittoReale()`
 * — e qui bastava usarla.
 */
final class UnitaConTitolare implements VerificatoreControllo
{
    public function esegui(ImportBatch $batch, array $idPerTipo): EsitoControllo
    {
        $id = $idPerTipo[Immobile::class] ?? [];

        if ($id === []) {
            return EsitoControllo::risolto('Nessuna unità importata da questo lotto.');
        }

        $conTitolare = DB::table('anagrafica_immobile')
            ->whereIn('immobile_id', $id)
            ->where('attivo', true)
            ->whereIn('tipologia', array_column(RuoloAnagraficaImmobile::titolariDiDirittoReale(), 'value'))
            ->distinct()
            ->count('immobile_id');

        $senza = count($id) - $conTitolare;

        if ($senza <= 0) {
            return EsitoControllo::risolto(
                sprintf('Tutte le %d unità importate hanno un titolare di diritto reale.', count($id)),
            );
        }

        return EsitoControllo::aperto($senza, sprintf(
            '%d %s su %d ancora senza un titolare di diritto reale: non riceveranno rate. '
            .'Un inquilino non basta — verso il condominio risponde chi ha la proprietà, '
            .'la nuda proprietà o l\'usufrutto.',
            $senza,
            $senza === 1 ? 'unità' : 'unità',
            count($id),
        ));
    }
}

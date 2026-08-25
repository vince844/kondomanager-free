<?php

namespace App\Actions\Cassa;

use App\Enums\EsitoAperturaCassa;
use App\Enums\TipoMovimentoContabile;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Porta a giornale il saldo di apertura di una cassa.
 *
 *   DARE  conto della cassa            (attivo: la liquidità entra)
 *   AVERE Fondo Passate Gestioni       (contropartita patrimoniale)
 *
 * e azzera la colonna `casse.saldo_iniziale` nella STESSA transazione, così il
 * valore non può mai essere contato due volte. La formula di lettura del saldo
 * (`SaldoCassaService`) non cambia: `colonna + movimenti` resta corretta sia
 * prima (colonna piena, nessuna scrittura) sia dopo (colonna a zero, scrittura
 * a giornale). Vedi docs/fondo_accantonato_e_quadratura_sp.md §1.
 *
 * Senza questa scrittura il saldo entrerebbe come pura attività priva di
 * contropartita, e lo Stato Patrimoniale non chiuderebbe ("buco A").
 *
 * SICUREZZA — in ogni caso dubbio NON forza nulla e restituisce false: la cassa
 * resta con il saldo in colonna, che è comunque corretto. Meglio non registrata
 * che registrata male.
 *
 * Nota: la migrazione di backfill (2026_07_24_090100) replica volutamente questa
 * logica invece di richiamare questa classe — una migrazione deve restare
 * congelata nel tempo e non dipendere da codice applicativo che evolverà.
 */
class RegistraAperturaCassaAction
{
    /**
     * Restituisce un esito **tipizzato** e non un booleano: dalla beta.45 questa azione è
     * esposta dietro un pulsante, e i cinque modi in cui poteva non fare niente andavano
     * distinti. Due di essi — importo zero e apertura già presente — non sono nemmeno
     * errori: sono «non c'è niente da fare».
     *
     * Il sesto esito possibile resta un'eccezione: se la partita doppia non quadra è un
     * guasto, non uno stato da raccontare all'utente.
     */
    public function execute(Cassa $cassa): EsitoAperturaCassa
    {
        $importo = (int) $cassa->saldo_iniziale;

        if ($importo === 0) {
            return EsitoAperturaCassa::IMPORTO_ZERO;
        }

        if (! $cassa->conto_contabile_id) {
            return EsitoAperturaCassa::CONTO_MANCANTE;
        }

        if ($this->haGiaApertura($cassa)) {
            return EsitoAperturaCassa::GIA_REGISTRATA;
        }

        // L'apertura precede tutto: si aggancia al primo esercizio del condominio.
        $esercizio = Esercizio::where('condominio_id', $cassa->condominio_id)
            ->orderBy('data_inizio')
            ->first();

        $contropartita = ContoContabile::where('condominio_id', $cassa->condominio_id)
            ->where('ruolo', 'passate_gestioni')
            ->whereNull('deleted_at')
            ->first();

        // Due mancanze diverse con due rimedi diversi: prima erano un ramo solo, e il
        // messaggio non poteva dire quale dei due mancasse.
        if (! $esercizio || ! $contropartita) {
            Log::warning('Apertura cassa non registrata: esercizio o contropartita mancante.', [
                'cassa_id'      => $cassa->id,
                'condominio_id' => $cassa->condominio_id,
                'ha_esercizio'  => (bool) $esercizio,
                'ha_conto'      => (bool) $contropartita,
            ]);

            return $esercizio
                ? EsitoAperturaCassa::CONTROPARTITA_MANCANTE
                : EsitoAperturaCassa::ESERCIZIO_MANCANTE;
        }

        DB::transaction(function () use ($cassa, $esercizio, $contropartita, $importo) {
            $scrittura = ScritturaContabile::create([
                'condominio_id'      => $cassa->condominio_id,
                // NULL per scelta: l'apertura di una cassa è un fatto di condominio,
                // non di gestione (design doc §10 D7).
                'gestione_id'        => null,
                'esercizio_id'       => $esercizio->id,
                'data_registrazione' => $esercizio->data_inizio,
                'data_competenza'    => $esercizio->data_inizio,
                'causale'            => mb_substr('Saldo di apertura — '.$cassa->nome, 0, 255),
                'tipo_movimento'     => TipoMovimentoContabile::APERTURA,
                'stato'              => 'registrata',
                'created_by'         => auth()->id(),
            ]);

            // Un saldo negativo (conto scoperto) resta bilanciato invertendo i versi,
            // senza mai scrivere importi negativi a giornale.
            $importoAssoluto = abs($importo);
            $versoCassa  = $importo >= 0 ? 'dare' : 'avere';
            $versoContro = $importo >= 0 ? 'avere' : 'dare';

            $scrittura->righe()->create([
                'conto_contabile_id' => $cassa->conto_contabile_id,
                'cassa_id'           => $cassa->id,
                'tipo_riga'          => $versoCassa,
                'importo'            => $importoAssoluto,
                'note'               => 'Saldo di apertura',
            ]);

            $scrittura->righe()->create([
                'conto_contabile_id' => $contropartita->id,
                'cassa_id'           => null,
                'tipo_riga'          => $versoContro,
                'importo'            => $importoAssoluto,
                'note'               => 'Contropartita saldo di apertura',
            ]);

            DoubleEntryValidator::validateOrFail($scrittura->id);

            // Il dato si SPOSTA dalla colonna al giornale: mai in due posti insieme.
            $cassa->forceFill(['saldo_iniziale' => 0])->save();
        });

        return EsitoAperturaCassa::REGISTRATA;
    }

    private function haGiaApertura(Cassa $cassa): bool
    {
        return DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
            ->where('rs.conto_contabile_id', $cassa->conto_contabile_id)
            ->where('sc.tipo_movimento', TipoMovimentoContabile::APERTURA->value)
            ->whereNull('sc.deleted_at')
            ->exists();
    }
}

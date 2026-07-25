<?php

namespace App\Actions\Cassa;

use App\Enums\TipoMovimentoContabile;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Support\Facades\DB;

/**
 * Porta a giornale un "già versato" (beta.26) che l'amministratore dichiara
 * ancora fermo, per intero o in parte, su una cassa/fondo del condominio.
 *
 *   DARE  conto della cassa selezionata   (attivo: la liquidità entra)
 *   AVERE Fondo Passate Gestioni          (contropartita patrimoniale)
 *
 * Stessa forma contabile di RegistraAperturaCassaAction, ma con due differenze
 * deliberate:
 *
 *   1. Non legge né tocca `casse.saldo_iniziale`: l'importo arriva da parametro
 *      (la somma del "già versato" registrato per quella voce), non dalla
 *      colonna. Non è un saldo di APERTURA — è un accantonamento che si
 *      aggiunge a una cassa che può avere già movimenti.
 *   2. `tipo_movimento = ACCANTONAMENTO`, non APERTURA: le guardie
 *      Cassa::hasAperturaRegistrata()/RegistraAperturaCassaAction::haGiaApertura()
 *      cercano solo APERTURA, quindi restano innocue e non bloccano né vengono
 *      bloccate da questa scrittura. Erano tipo dichiarato ma mai istanziato
 *      prima di questa azione (docs/fondo_accantonato_e_quadratura_sp.md D8-bis).
 *
 * Senza questa scrittura il "già versato" dichiarato come "ancora in cassa"
 * resterebbe un dato di solo riparto, senza contropartita in liquidità reale —
 * verificato con un test: la cassa restava a saldo zero anche con centinaia di
 * euro di già-versato registrati.
 *
 * SICUREZZA — nessun caso dubbio viene forzato: importo zero o mancanze nei
 * dati di contesto (esercizio, contropartita) restituiscono false senza
 * scrivere nulla, sullo stesso principio di RegistraAperturaCassaAction.
 */
class RegistraContributoInCassaAction
{
    public function execute(Cassa $cassa, int $importoCents, string $causale): bool
    {
        if ($importoCents <= 0 || ! $cassa->conto_contabile_id) {
            return false;
        }

        $esercizio = Esercizio::where('condominio_id', $cassa->condominio_id)
            ->where('stato', 'aperto')
            ->orderByDesc('data_inizio')
            ->first()
            ?? Esercizio::where('condominio_id', $cassa->condominio_id)
                ->orderByDesc('data_inizio')
                ->first();

        $contropartita = ContoContabile::where('condominio_id', $cassa->condominio_id)
            ->where('ruolo', 'passate_gestioni')
            ->whereNull('deleted_at')
            ->first();

        if (! $esercizio || ! $contropartita) {
            return false;
        }

        DB::transaction(function () use ($cassa, $esercizio, $contropartita, $importoCents, $causale) {
            $scrittura = ScritturaContabile::create([
                'condominio_id'      => $cassa->condominio_id,
                'gestione_id'        => null,
                'esercizio_id'       => $esercizio->id,
                'data_registrazione' => now()->format('Y-m-d'),
                'data_competenza'    => now()->format('Y-m-d'),
                'causale'            => mb_substr($causale, 0, 255),
                'tipo_movimento'     => TipoMovimentoContabile::ACCANTONAMENTO,
                'stato'              => 'registrata',
                'created_by'         => auth()->id(),
            ]);

            $scrittura->righe()->create([
                'conto_contabile_id' => $cassa->conto_contabile_id,
                'cassa_id'           => $cassa->id,
                'tipo_riga'          => 'dare',
                'importo'            => $importoCents,
                'note'               => 'Già versato — soldi ancora in cassa',
            ]);

            $scrittura->righe()->create([
                'conto_contabile_id' => $contropartita->id,
                'cassa_id'           => null,
                'tipo_riga'          => 'avere',
                'importo'            => $importoCents,
                'note'               => 'Contropartita accantonamento già versato',
            ]);

            DoubleEntryValidator::validateOrFail($scrittura->id);
        });

        return true;
    }
}

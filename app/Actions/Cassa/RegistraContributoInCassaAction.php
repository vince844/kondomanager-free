<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Actions\Cassa;

use App\Enums\TipoMovimentoContabile;
use App\Helpers\DateHelper;
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
 * Chi salva il già versato non ignora più il false (Coda 754 (9)): legge prima
 * `motivoDelRifiuto()` e, se l'accredito non si può scrivere, non salva le righe.
 */
class RegistraContributoInCassaAction
{
    /** Coda 754 (9), la regola 76.3: il conto di sistema che manca, detto a chi salva il già versato. */
    public const SENZA_PASSATE_GESTIONI = 'Il già versato non è stato salvato: manca il conto di sistema «Fondo Passate Gestioni» (2301), che serve per '
        . 'accreditare i soldi sulla cassa. Va ripristinato da chi gestisce l\'installazione.';

    public const SENZA_ESERCIZIO = 'Il già versato non è stato salvato: il condominio non ha un esercizio in cui scrivere l\'accredito della cassa. '
        . 'Crea prima l\'esercizio, poi salva di nuovo.';

    public const CASSA_SENZA_CONTO = 'Il già versato non è stato salvato: la cassa scelta non ha un conto in contabilità su cui accreditare i soldi. '
        . 'Scegli un\'altra cassa.';

    /**
     * Perché l'accredito su questa cassa non si potrebbe scrivere, o `null` se si può (Coda 754 (9)). Le stesse condizioni di `execute`, lette una volta
     * sola: chi salva il già versato le guarda prima di scrivere le righe, così una riga «registrata in cassa» non resta mai senza la sua scrittura.
     */
    public static function motivoDelRifiuto(Cassa $cassa): ?string
    {
        if (! $cassa->conto_contabile_id) {
            return self::CASSA_SENZA_CONTO;
        }
        if (! self::esercizioPer($cassa)) {
            return self::SENZA_ESERCIZIO;
        }

        return self::contropartitaPer($cassa) ? null : self::SENZA_PASSATE_GESTIONI;
    }

    public function execute(Cassa $cassa, int $importoCents, string $causale): bool
    {
        if ($importoCents <= 0 || ! $cassa->conto_contabile_id) {
            return false;
        }

        $esercizio = self::esercizioPer($cassa);
        $contropartita = self::contropartitaPer($cassa);

        if (! $esercizio || ! $contropartita) {
            return false;
        }

        // Il giorno è quello dell'amministratore, non il giorno UTC del server: alle 00:30 di Roma del 01/01 `now()` è ancora il 31/12.
        $oggi = DateHelper::oggiUtente();

        DB::transaction(function () use ($cassa, $esercizio, $contropartita, $importoCents, $causale, $oggi) {
            $scrittura = ScritturaContabile::create([
                'condominio_id'      => $cassa->condominio_id,
                'gestione_id'        => null,
                'esercizio_id'       => $esercizio->id,
                'data_registrazione' => $oggi,
                'data_competenza'    => $oggi,
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

    /** L'esercizio dell'accredito: l'ultimo aperto, altrimenti l'ultimo del condominio. */
    private static function esercizioPer(Cassa $cassa): ?Esercizio
    {
        return Esercizio::where('condominio_id', $cassa->condominio_id)
            ->where('stato', 'aperto')
            ->orderByDesc('data_inizio')
            ->first()
            ?? Esercizio::where('condominio_id', $cassa->condominio_id)
                ->orderByDesc('data_inizio')
                ->first();
    }

    /** La contropartita: il conto di sistema «Fondo Passate Gestioni». */
    private static function contropartitaPer(Cassa $cassa): ?ContoContabile
    {
        return ContoContabile::where('condominio_id', $cassa->condominio_id)
            ->where('ruolo', 'passate_gestioni')
            ->whereNull('deleted_at')
            ->first();
    }
}

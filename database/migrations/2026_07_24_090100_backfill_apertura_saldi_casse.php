<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Porta a giornale il saldo di apertura delle casse esistenti (1.9.x → 1.10).
 *
 * Fino alla 1.9.x il saldo di apertura di una cassa viveva SOLO in una colonna
 * (`casse.saldo_iniziale`), senza alcuna scrittura di contropartita: entrava come
 * pura attività, lasciando lo Stato Patrimoniale sbilanciato di quell'importo
 * (vedi docs/fondo_accantonato_e_quadratura_sp.md §1, "buco A").
 *
 * Questa migrazione, per ogni cassa con saldo di apertura diverso da zero:
 *   1. crea la scrittura di apertura   DARE cassa / AVERE Fondo Passate Gestioni
 *   2. azzera la colonna `saldo_iniziale`
 * entrambe nella STESSA transazione.
 *
 * RETROCOMPATIBILITÀ — il saldo mostrato non cambia mai, né durante né dopo:
 *   prima : colonna 100.000 + movimenti 0       = 100.000
 *   dopo  : colonna 0       + movimenti 100.000 = 100.000
 * La formula di lettura (`SaldoCassaService`) resta identica: si sposta il DATO,
 * non il calcolo. Per questo un'installazione che non ha ancora eseguito le
 * migrazioni continua a mostrare i saldi corretti.
 *
 * SICUREZZA — ogni caso dubbio viene SALTATO, non forzato: una cassa saltata resta
 * nello stato 1.9.x, che è comunque corretto. Nessuno scenario in cui questa
 * migrazione può falsare un saldo.
 *
 * Idempotente: una cassa che ha già la sua scrittura di apertura viene ignorata.
 */
return new class extends Migration
{
    public function up(): void
    {
        $casse = DB::table('casse')
            ->whereNotNull('conto_contabile_id')
            ->where('saldo_iniziale', '!=', 0)
            ->get();

        $creati = 0;
        $saltati = 0;

        foreach ($casse as $cassa) {
            // Idempotenza: apertura già a giornale per questa cassa?
            $giaAperta = DB::table('righe_scritture as rs')
                ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
                ->where('rs.conto_contabile_id', $cassa->conto_contabile_id)
                ->where('sc.tipo_movimento', 'apertura')
                ->whereNull('sc.deleted_at')
                ->exists();

            if ($giaAperta) {
                $saltati++;
                continue;
            }

            // L'apertura precede tutto: si aggancia al primo esercizio del condominio.
            $esercizio = DB::table('esercizi')
                ->where('condominio_id', $cassa->condominio_id)
                ->orderBy('data_inizio')
                ->first();

            // Contropartita patrimoniale: il denaro presente all'avvio è residuo
            // delle gestioni precedenti a Kondomanager.
            $contropartita = DB::table('conti_contabili')
                ->where('condominio_id', $cassa->condominio_id)
                ->where('ruolo', 'passate_gestioni')
                ->whereNull('deleted_at')
                ->value('id');

            // Nessun esercizio o nessuna contropartita: si salta, la cassa resta
            // nello stato 1.9.x (saldo comunque corretto). Meglio non migrata che
            // migrata male.
            if (! $esercizio || ! $contropartita) {
                Log::warning('Backfill apertura cassa saltato: esercizio o contropartita mancante.', [
                    'cassa_id'      => $cassa->id,
                    'condominio_id' => $cassa->condominio_id,
                    'ha_esercizio'  => (bool) $esercizio,
                    'ha_conto'      => (bool) $contropartita,
                ]);
                $saltati++;
                continue;
            }

            $importo = (int) $cassa->saldo_iniziale;

            DB::transaction(function () use ($cassa, $esercizio, $contropartita, $importo) {
                $scritturaId = DB::table('scritture_contabili')->insertGetId([
                    'condominio_id'      => $cassa->condominio_id,
                    // NULL per scelta: l'apertura di una cassa è un fatto di condominio,
                    // non di gestione (design doc §10 D7).
                    'gestione_id'        => null,
                    'esercizio_id'       => $esercizio->id,
                    'data_registrazione' => $esercizio->data_inizio,
                    'data_competenza'    => $esercizio->data_inizio,
                    'numero_protocollo'  => 'AP-'.str_pad((string) $cassa->id, 6, '0', STR_PAD_LEFT),
                    'causale'            => 'Saldo di apertura — '.$cassa->nome,
                    'descrizione'        => 'Scrittura generata automaticamente in fase di aggiornamento '
                                            .'per portare a giornale il saldo di apertura della cassa.',
                    'tipo_movimento'     => 'apertura',
                    'stato'              => 'registrata',
                    'idempotency_key'    => (string) Str::uuid(),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                // Un saldo di apertura negativo (scoperto di conto) resta bilanciato
                // invertendo i versi, non forzando importi negativi a giornale.
                $versoCassa   = $importo >= 0 ? 'dare' : 'avere';
                $versoContro  = $importo >= 0 ? 'avere' : 'dare';
                $importoAssoluto = abs($importo);

                DB::table('righe_scritture')->insert([
                    [
                        'scrittura_id'       => $scritturaId,
                        'conto_contabile_id' => $cassa->conto_contabile_id,
                        'cassa_id'           => $cassa->id,
                        'tipo_riga'          => $versoCassa,
                        'importo'            => $importoAssoluto,
                        'note'               => 'Saldo di apertura',
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ],
                    [
                        'scrittura_id'       => $scritturaId,
                        'conto_contabile_id' => $contropartita,
                        'cassa_id'           => null,
                        'tipo_riga'          => $versoContro,
                        'importo'            => $importoAssoluto,
                        'note'               => 'Contropartita saldo di apertura',
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ],
                ]);

                // Il dato si SPOSTA: la colonna si azzera nella stessa transazione,
                // così non può mai essere contato due volte.
                DB::table('casse')->where('id', $cassa->id)->update([
                    'saldo_iniziale' => 0,
                    'updated_at'     => now(),
                ]);
            });

            $creati++;
        }

        Log::info('Backfill aperture casse completato.', [
            'aperture_create' => $creati,
            'casse_saltate'   => $saltati,
        ]);
    }

    public function down(): void
    {
        // Riporta il saldo dalla scrittura di apertura alla colonna, poi rimuove
        // la scrittura: l'inverso esatto di up(), sempre a saldo invariato.
        $aperture = DB::table('scritture_contabili')
            ->where('tipo_movimento', 'apertura')
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($aperture as $scritturaId) {
            $rigaCassa = DB::table('righe_scritture')
                ->where('scrittura_id', $scritturaId)
                ->whereNotNull('cassa_id')
                ->first();

            if (! $rigaCassa) {
                continue;
            }

            DB::transaction(function () use ($scritturaId, $rigaCassa) {
                $importo = $rigaCassa->tipo_riga === 'dare'
                    ? (int) $rigaCassa->importo
                    : -(int) $rigaCassa->importo;

                DB::table('casse')->where('id', $rigaCassa->cassa_id)->update([
                    'saldo_iniziale' => $importo,
                    'updated_at'     => now(),
                ]);

                DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->delete();
                DB::table('scritture_contabili')->where('id', $scritturaId)->delete();
            });
        }
    }
};

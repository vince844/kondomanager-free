<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Tabella;
use App\Services\RegistroModifiche\GestiDelRegistro;
use Illuminate\Support\Facades\DB;

/**
 * Una voce nuova del piano dei conti (o un capitolo): il corpo che stava nel controllore delle voci (`ContoController::store`), in un servizio che chiamano la
 * pagina del piano dei conti e «Crea il piano straordinario» del seguito delle delibere. Le regole sono quelle di prima:
 *
 * - **l'importo arriva in centesimi**: lo converte una volta sola chi riceve la richiesta (`MoneyHelper::toCents()` nel controllore); un capitolo ha zero;
 * - il mastro dalla natura della voce ({@see contoContabile()});
 * - una voce di spesa ha la sua tabella millesimale del condominio e le tre percentuali, che fanno 100; altrimenti si ferma con la frase di prima;
 * - la riga delle tabelle della voce nel registro delle modifiche (gesto 7);
 * - la transazione è di chi chiama.
 */
final class CreazioneDellaVoce
{
    /**
     * @param array<string, mixed> $dati i campi validati di `CreateContoRequest`, con `importo` già in centesimi
     *
     * @throws \Exception senza la tabella del condominio, o con le percentuali che non fanno 100
     */
    public static function crea(Condominio $condominio, PianoConto $pianoConto, array $dati): Conto
    {
        // Gesto 7 del registro delle modifiche, voci e tabelle: le tabelle della voce prima, per il campo «Tabelle millesimali».
        $tabelleDellaVocePrima = [];
        $isCapitolo = $dati['isCapitolo'] ?? false;
        $isSottoConto = $dati['isSottoConto'] ?? false;
        $richiedeGiaVersato = $dati['richiedeGiaVersato'] ?? false;

        // Creazione del record principale del Conto
        $nuovoConto = Conto::create([
            'piano_conto_id'        => $pianoConto->id,
            'parent_id'             => $isSottoConto ? ($dati['parent_id'] ?? null) : null,
            // Fatto esplicito scelto ORA dall'amministratore, persistito subito: non va più indovinato da importo/parent_id in un secondo momento (bug «voce
            // a zero perde la tabella millesimale», migrazione add_is_capitolo_to_conti_table).
            'is_capitolo'           => $isCapitolo,
            // Filtra l'elenco «Già versato» (beta.27): un capitolo non versa nulla direttamente (beta.22), resta sempre false.
            'richiede_gia_versato'  => $isCapitolo ? false : $richiedeGiaVersato,
            'conto_contabile_id'    => self::contoContabile((int) $condominio->id, $dati['tipo_spesa'] ?? 'standard', (string) $dati['tipo']),
            'codice'                => $dati['codice'] ?? null,
            'nome'                  => $dati['nome'],
            'descrizione'           => $dati['descrizione'] ?? null,
            'tipo'                  => $dati['tipo'],
            'tipo_spesa'            => $dati['tipo_spesa'] ?? 'standard',
            'importo'               => $isCapitolo ? 0 : (int) ($dati['importo'] ?? 0),
            'default_fornitore_id'  => $dati['default_fornitore_id'] ?? null,
            'note'                  => $dati['note'] ?? null,
            'attivo'                => true,
        ]);

        // Se NON è un capitolo (quindi è una voce di spesa effettiva), gestiamo le tabelle millesimali
        if (! $isCapitolo) {
            // 1. Validazione e recupero della tabella millesimale associata
            $tabella = Tabella::where('id', $dati['tabella_millesimale_id'] ?? null)->where('condominio_id', $condominio->id)->first();
            if (! $tabella) {
                throw new \Exception('Tabella millesimale non trovata');
            }

            // 2. Associazione della tabella al conto (pivot)
            $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
                'conto_id' => $nuovoConto->id,
                'tabella_id' => $tabella->id,
                'coefficiente' => 100.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Preparazione delle ripartizioni (proprietario, inquilino, usufruttuario)
            $ripartizioni = [
                ['soggetto' => 'proprietario', 'percentuale' => $dati['percentuale_proprietario'] ?? 0],
                ['soggetto' => 'inquilino', 'percentuale' => $dati['percentuale_inquilino'] ?? 0],
                ['soggetto' => 'usufruttuario', 'percentuale' => $dati['percentuale_usufruttuario'] ?? 0],
            ];

            // Controllo integrità: la somma deve essere 100%
            if (array_sum(array_column($ripartizioni, 'percentuale')) != 100) {
                throw new \Exception('La somma delle percentuali deve essere 100%');
            }

            // 4. Inserimento delle singole quote di ripartizione
            foreach ($ripartizioni as $rip) {
                if ($rip['percentuale'] > 0) {
                    DB::table('conto_tabella_ripartizioni')->insert([
                        'conto_tabella_millesimale_id' => $contoTabellaId,
                        'soggetto' => $rip['soggetto'],
                        'percentuale' => $rip['percentuale'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        GestiDelRegistro::scriviTabelleDellaVoce($nuovoConto, $tabelleDellaVocePrima);

        return $nuovoConto;
    }

    /**
     * Il mastro della voce. Coda 680 (decisione 493 (15)): una voce di entrata nel mastro delle entrate ({@see VociDiEntrataNelMastro::mastro()}), che nasce
     * alla prima richiesta; fino alla 1.12.0-beta.11 finiva in «Costi per Servizi». Una voce di entrata che c'era già, modificata, tiene il suo mastro: lo
     * corregge l'amministratore dal controllo del piano dei conti, mai il salvataggio della voce. Una voce di spesa come prima, dal tipo di spesa.
     */
    public static function contoContabile(int $condominioId, string $tipoSpesa, ?string $tipo = null, ?Conto $esistente = null): ?int
    {
        if (VociDiEntrataNelMastro::diEntrata($tipo)) {
            if ($esistente !== null && VociDiEntrataNelMastro::diEntrata((string) $esistente->getOriginal('tipo')) && $esistente->conto_contabile_id !== null) {
                return (int) $esistente->conto_contabile_id;
            }

            return (int) VociDiEntrataNelMastro::mastro($condominioId)->id;
        }
        $ruolo = match ($tipoSpesa) {
            'professionista' => 'compensi_professionisti',
            default          => 'costi_servizi',
        };

        return ContoContabile::where('condominio_id', $condominioId)->where('ruolo', $ruolo)->value('id');
    }
}

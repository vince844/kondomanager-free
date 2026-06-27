<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Data migration — nessuna modifica DDL.
     *
     * Problema che risolve:
     * ensureDefaultConti() in CondominioService ha un early return se il conto
     * `crediti_condomini` esiste già. Tutti i condomini creati prima di v1.9.1
     * non ricevono i 3 nuovi conti necessari al modulo Pagamento Fatture:
     *   - spese_bancarie  (6003) — commissioni su bonifici ai fornitori
     *   - iva_acquisti    (1201) — preparazione Reverse Charge v1.12
     *   - iva_vendite     (2203) — preparazione Reverse Charge v1.12
     *
     * Idempotenza: il check è su (condominio_id, ruolo) — se il conto esiste
     * già (es. migration eseguita due volte), viene silenziosamente saltato.
     *
     * Sicurezza su dati live:
     * - Usa DB::table() direttamente, mai Eloquent models (stabilità migration)
     * - Usa lazy() + cursore DB: nessun caricamento in RAM di tutti i record
     * - set_time_limit(0): necessario su Windows/hosting condiviso (max_execution_time
     *   default = 60s, insufficiente se i condomini sono molti)
     * - Un eventuale errore su un singolo condominio logga e continua (non blocca gli altri)
     * - down() è no-op: non cancelliamo mai conti in rollback
     *   (potrebbero avere scritture collegate nel momento in cui si fa rollback)
     */
    public function up(): void
    {
        // Critico su Windows / hosting condiviso: questa migration può girare
        // dentro una richiesta HTTP (Artisan::call in SystemUpgradeController).
        // max_execution_time = 60s di default — insufficiente per molti condomini.
        set_time_limit(0);

        $condominii = DB::table('condomini')->select('id')->pluck('id');

        if ($condominii->isEmpty()) {
            Log::info('Seed conti v1.9.1: nessun condominio trovato, nessuna azione necessaria.');
            return;
        }

        $creati   = 0;
        $saltati  = 0;
        $errori   = 0;

        foreach ($condominii as $condominioId) {
            try {
                $result = $this->seedContiPerCondominio((int) $condominioId);
                $creati  += $result['creati'];
                $saltati += $result['saltati'];
            } catch (\Throwable $e) {
                $errori++;
                Log::error("Seed conti v1.9.1: errore su condominio {$condominioId}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Seed conti v1.9.1: completato.', [
            'condomini_processati' => $condominii->count(),
            'conti_creati'         => $creati,
            'conti_saltati'        => $saltati,
            'errori'               => $errori,
        ]);
    }

    /**
     * Rollback intenzionalmente no-op.
     *
     * Non cancelliamo i conti in down() per due motivi:
     * 1. Potrebbero avere scritture contabili collegate create durante v1.9.1.
     * 2. La logica "crea se manca" è sicura da lasciare in place.
     * Se si vuole rimuovere un conto specifico, farlo manualmente sul DB
     * dopo aver verificato l'assenza di scritture collegate.
     */
    public function down(): void
    {
        Log::info('Seed conti v1.9.1: down() — nessuna azione (i conti creati vengono mantenuti per sicurezza).');
    }

    /**
     * Crea i 3 conti mancanti per un singolo condominio.
     *
     * @return array{creati: int, saltati: int}
     */
    private function seedContiPerCondominio(int $condominioId): array
    {
        $creati  = 0;
        $saltati = 0;
        $adesso  = now()->toDateTimeString();

        // Recupera i nodi root patrimoniali: necessari come parent_id per
        // iva_acquisti (figlio di ATTIVO) e iva_vendite (figlio di PASSIVO).
        // Li cerchiamo per codice, non per ruolo, perché i root non hanno ruolo.
        $attivoRoot = DB::table('conti_contabili')
            ->where('condominio_id', $condominioId)
            ->where('codice', '1000')
            ->whereNull('deleted_at')
            ->value('id');

        $passivoRoot = DB::table('conti_contabili')
            ->where('condominio_id', $condominioId)
            ->where('codice', '2000')
            ->whereNull('deleted_at')
            ->value('id');

        if (!$attivoRoot || !$passivoRoot) {
            Log::warning("Seed conti v1.9.1: condominio {$condominioId} privo dei nodi root 1000/2000 — skip.", [
                'condominio_id' => $condominioId,
                'attivo_root'   => $attivoRoot,
                'passivo_root'  => $passivoRoot,
            ]);
            return ['creati' => 0, 'saltati' => 0];
        }

        // Definizione dei 3 conti da creare.
        // parent_id = null per spese_bancarie (conto economico, come 6001/6002).
        // parent_id = attivoRoot per iva_acquisti (patrimoniale attivo).
        // parent_id = passivoRoot per iva_vendite (patrimoniale passivo).
        $contiDaCreare = [
            [
                'ruolo'       => 'spese_bancarie',
                'codice'      => '6003',
                'nome'        => 'Spese Bancarie',
                'descrizione' => 'Commissioni su bonifici, spese tenuta conto e oneri bancari',
                'tipo'        => 'costo',
                'categoria'   => 'costi',
                'parent_id'   => null,
            ],
            [
                // Non usato in v1.9.1. Preparato per v1.12 Fase B (Reverse Charge N6.x).
                // Nel RC: DARE IVA Acquisti (credito verso erario) / AVERE IVA Vendite.
                'ruolo'       => 'iva_acquisti',
                'codice'      => '1201',
                'nome'        => 'IVA Acquisti',
                'descrizione' => 'IVA a credito su acquisti (Reverse Charge — attivo da v1.12)',
                'tipo'        => 'attivo',
                'categoria'   => 'crediti',
                'parent_id'   => $attivoRoot,
            ],
            [
                // Non usato in v1.9.1. Preparato per v1.12 Fase B (Reverse Charge N6.x).
                'ruolo'       => 'iva_vendite',
                'codice'      => '2203',
                'nome'        => 'IVA Vendite',
                'descrizione' => 'IVA a debito (Reverse Charge — attivo da v1.12)',
                'tipo'        => 'passivo',
                'categoria'   => 'debiti',
                'parent_id'   => $passivoRoot,
            ],
        ];

        foreach ($contiDaCreare as $conto) {
            // Check idempotenza su (condominio_id, ruolo) — il ruolo è l'identificatore
            // funzionale usato da tutti i service per trovare il conto corretto.
            $esiste = DB::table('conti_contabili')
                ->where('condominio_id', $condominioId)
                ->where('ruolo', $conto['ruolo'])
                ->whereNull('deleted_at')
                ->exists();

            if ($esiste) {
                $saltati++;
                continue;
            }

            DB::table('conti_contabili')->insert([
                'condominio_id' => $condominioId,
                'parent_id'     => $conto['parent_id'],
                'ruolo'         => $conto['ruolo'],
                'codice'        => $conto['codice'],
                'nome'          => $conto['nome'],
                'descrizione'   => $conto['descrizione'],
                'tipo'          => $conto['tipo'],       // ENUM: valori validi
                'categoria'     => $conto['categoria'],  // ENUM: valori validi
                'di_sistema'    => 1,
                'attivo'        => 1,
                'livello'       => 1,
                'ordine'        => 0,
                'created_at'    => $adesso,
                'updated_at'    => $adesso,
                // deleted_at non inserito → NULL by default
            ]);

            $creati++;

            Log::info("Seed conti v1.9.1: creato '{$conto['ruolo']}' per condominio {$condominioId}.");
        }

        return ['creati' => $creati, 'saltati' => $saltati];
    }
};
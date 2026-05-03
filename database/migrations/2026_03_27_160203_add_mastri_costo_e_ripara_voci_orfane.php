<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Condominio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\Conto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Critico su Windows / hosting condiviso: questa migration gira dentro
        // una richiesta HTTP (Artisan::call in SystemUpgradeController).
        // max_execution_time = 60s di default — insufficiente per condomini grandi.
        set_time_limit(0);

        // lazy() usa un cursor DB invece di caricare tutti i record in RAM
        Condominio::query()->select('id')->lazy()->each(function (Condominio $condominio) {

            // ── Mastro "Costi per Servizi" ────────────────────────────────────
            $costiServizi = ContoContabile::firstOrCreate(
                [
                    'condominio_id' => $condominio->id,
                    'ruolo'         => 'costi_servizi',
                ],
                [
                    'parent_id'   => null,
                    'codice'      => '6001',
                    'nome'        => 'Costi per Servizi',
                    'descrizione' => 'Manutenzioni, pulizie, utenze e servizi generali',
                    'tipo'        => 'costo',
                    'categoria'   => 'costi',
                    'di_sistema'  => true,
                    'attivo'      => true,
                    'livello'     => 1,
                ]
            );

            // ── Mastro "Compensi Professionisti" ──────────────────────────────
            $compensiProf = ContoContabile::firstOrCreate(
                [
                    'condominio_id' => $condominio->id,
                    'ruolo'         => 'compensi_professionisti',
                ],
                [
                    'parent_id'   => null,
                    'codice'      => '6002',
                    'nome'        => 'Compensi Professionisti',
                    'descrizione' => 'Amministratore, avvocati, tecnici e consulenti',
                    'tipo'        => 'costo',
                    'categoria'   => 'costi',
                    'di_sistema'  => true,
                    'attivo'      => true,
                    'livello'     => 1,
                ]
            );

            // ── Riparazione voci orfane ───────────────────────────────────────
            // SOSTITUISCE: Conto::whereHas('pianoConto', fn($q) => ...)
            // whereHas() genera una subquery EXISTS correlata che MySQL non ottimizza
            // con gli indici FK → full scan su ogni iterazione del loop.
            // DB::join() usa gli indici FK esistenti (conti.piano_conto_id → piani_conti.id)
            // ed è 10x più veloce su tabelle medie.

            // A. Voci di tipo "professionista" → mastro compensi
            DB::table('conti')
                ->join('piani_conti', 'conti.piano_conto_id', '=', 'piani_conti.id')
                ->where('piani_conti.condominio_id', $condominio->id)
                ->whereNull('conti.conto_contabile_id')
                ->where('conti.tipo', 'spesa')
                ->where('conti.tipo_spesa', 'professionista')
                ->update(['conti.conto_contabile_id' => $compensiProf->id]);

            // B. Tutte le altre voci di spesa standard → mastro costi servizi
            DB::table('conti')
                ->join('piani_conti', 'conti.piano_conto_id', '=', 'piani_conti.id')
                ->where('piani_conti.condominio_id', $condominio->id)
                ->whereNull('conti.conto_contabile_id')
                ->where('conti.tipo', 'spesa')
                ->where(function ($q) {
                    $q->where('conti.tipo_spesa', '!=', 'professionista')
                      ->orWhereNull('conti.tipo_spesa');
                })
                ->update(['conti.conto_contabile_id' => $costiServizi->id]);

            Log::info("Migrazione mastri costo completata per condominio ID: {$condominio->id}");
        });
    }

    public function down(): void
    {
        // Scollega prima i conti, poi elimina i mastri
        DB::table('conti')
            ->whereIn('conto_contabile_id', function ($q) {
                $q->select('id')
                  ->from('conti_contabili')
                  ->whereIn('ruolo', ['costi_servizi', 'compensi_professionisti']);
            })
            ->update(['conto_contabile_id' => null]);

        ContoContabile::whereIn('ruolo', ['costi_servizi', 'compensi_professionisti'])->delete();
    }
};
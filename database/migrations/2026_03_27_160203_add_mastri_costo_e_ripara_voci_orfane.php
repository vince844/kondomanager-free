<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Condominio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\Conto;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Recuperiamo tutti i condomini presenti a database
        $condomini = Condominio::all();

        foreach ($condomini as $condominio) {
            
            // 2. Creiamo il Mastro "Costi per Servizi"
            $costiServizi = ContoContabile::firstOrCreate(
                [
                    'condominio_id' => $condominio->id, 
                    'ruolo'         => 'costi_servizi'
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
                    'livello'     => 1
                ]
            );

            // 3. Creiamo il Mastro "Compensi Professionisti"
            $compensiProf = ContoContabile::firstOrCreate(
                [
                    'condominio_id' => $condominio->id, 
                    'ruolo'         => 'compensi_professionisti'
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
                    'livello'     => 1
                ]
            );

            // 4. Ripariamo i dati vecchi: Associamo le voci orfane ai rispettivi mastri
            
            // A. Assegniamo le voci di tipo "professionista"
            Conto::whereHas('pianoConto', function($q) use ($condominio) {
                    $q->where('condominio_id', $condominio->id);
                 })
                 ->whereNull('conto_contabile_id')
                 ->where('tipo', 'spesa')
                 ->where('tipo_spesa', 'professionista')
                 ->update(['conto_contabile_id' => $compensiProf->id]);

            // B. Assegniamo tutte le altre voci di spesa standard
            Conto::whereHas('pianoConto', function($q) use ($condominio) {
                    $q->where('condominio_id', $condominio->id);
                 })
                 ->whereNull('conto_contabile_id')
                 ->where('tipo', 'spesa')
                 ->where(function($q) {
                     $q->where('tipo_spesa', '!=', 'professionista')
                       ->orWhereNull('tipo_spesa');
                 })
                 ->update(['conto_contabile_id' => $costiServizi->id]);
                 
            Log::info("Migrazione Mastri Costo completata per condominio ID: {$condominio->id}");
        }
    }

    public function down(): void
    {
        // Se facciamo rollback, scolleghiamo i conti e rimuoviamo i mastri
        Conto::whereHas('contoContabile', function($q) {
            $q->whereIn('ruolo', ['costi_servizi', 'compensi_professionisti']);
        })->update(['conto_contabile_id' => null]);

        ContoContabile::whereIn('ruolo', ['costi_servizi', 'compensi_professionisti'])->delete();
    }
};
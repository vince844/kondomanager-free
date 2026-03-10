<?php

namespace App\Services;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gestisce la logica di business principale per la creazione e l'inizializzazione
 * di un nuovo Condominio, inclusa la configurazione dell'esercizio fiscale,
 * della gestione base e del piano dei conti di sistema.
 */
class CondominioService
{
    /**
     * Crea un nuovo condominio e inizializza tutta la sua struttura contabile di base.
     * * Il processo è avvolto in una transazione: in caso di errore, nulla viene salvato.
     * Vengono generati automaticamente: l'Esercizio corrente, la Gestione Ordinaria
     * e il Piano dei Conti standard (incluso il Fondo Passate Gestioni).
     *
     * @param array $condominioData Dati validati per la creazione del condominio.
     * @return Condominio L'istanza del condominio appena creato.
     * @throws \Exception Se la transazione sul database fallisce.
     */
    public function createCondominioWithEsercizio(array $condominioData): Condominio
    {
        return DB::transaction(function () use ($condominioData) {

            // 1. Crea Condominio
            $condominio = Condominio::create($condominioData);
            
            // 2. Crea Esercizio
            $esercizio = $this->createEsercizioForCondominio($condominio);
            
            // 3. Crea Gestione Ordinaria e la collega all'Esercizio
            $this->createDefaultGestione($condominio, $esercizio);
            
            // 4. Crea Conti (incluso il nuovo Fondo Passate Gestioni)
            $this->ensureDefaultConti($condominio); 
            
            return $condominio;
        });
    }

    /**
     * Recupera l'Esercizio per l'anno in corso, creandolo se non esiste.
     * * Imposta le date dall'inizio alla fine dell'anno solare corrente
     * e lo marca con lo stato 'aperto'.
     *
     * @param Condominio $condominio Il condominio di riferimento.
     * @return Esercizio L'esercizio per l'anno corrente.
     */
    public function createEsercizioForCondominio(Condominio $condominio): Esercizio
    {
        $currentYear = now()->year;
        $existing = $condominio->esercizi()
            ->whereYear('data_inizio', $currentYear)
            ->whereYear('data_fine', $currentYear)
            ->first();

        if ($existing) return $existing;

        return Esercizio::create([
            'condominio_id' => $condominio->id,
            'nome'          => "Esercizio anno {$currentYear}",
            'descrizione'   => "Esercizio anno {$currentYear}",
            'data_inizio'   => now()->startOfYear(),
            'data_fine'     => now()->endOfYear(),
            'stato'         => 'aperto',
            'note'          => 'Esercizio creato automaticamente.',
        ]);
    }

    /**
     * Crea la Gestione Ordinaria di base e la aggancia all'Esercizio indicato.
     * * Questo "cassetto" è obbligatorio nel nuovo sistema Wallet (V 1.9+) 
     * per permettere l'inserimento dei Saldi Iniziali e della Rata 0
     * subito dopo la creazione del condominio.
     *
     * @param Condominio $condominio Il condominio di riferimento.
     * @param Esercizio $esercizio L'esercizio a cui collegare la gestione.
     * @return void
     */
    public function createDefaultGestione(Condominio $condominio, Esercizio $esercizio): void
    {
        // Controlliamo che non esista già per evitare duplicati
        $gestione = $condominio->gestioni()->where('tipo', 'ordinaria')->first();

        if (!$gestione) {
            $gestione = $condominio->gestioni()->create([
                'nome'        => 'Gestione ordinaria',
                'tipo'        => 'ordinaria',
                'attiva'      => true,
                'data_inizio' => $esercizio->data_inizio,
                'data_fine'   => $esercizio->data_fine,
            ]);

            Log::info("Gestione ordinaria creata per '{$condominio->nome}'");
        }

        // Assicuriamoci che questa gestione sia agganciata all'esercizio appena creato.
        // Se usi una tabella pivot (esercizio_gestione), usa attach():
        if (!$gestione->esercizi()->where('esercizio_id', $esercizio->id)->exists()) {
            $gestione->esercizi()->attach($esercizio->id);
            Log::info("Gestione ordinaria agganciata all'Esercizio ID {$esercizio->id}");
        }
    }

    /**
     * Inizializza il Piano dei Conti (struttura Patrimoniale) di base per il condominio.
     * * Crea le radici ATTIVO e PASSIVO, oltre ai conti di sistema fondamentali
     * (Cassa, Crediti, Debiti, Anticipi, Gestione Rate e Fondo Passate Gestioni)
     * rispettando scrupolosamente gli ENUM del database.
     *
     * @param Condominio $condominio Il condominio a cui generare i conti.
     * @return void
     */
    public function ensureDefaultConti(Condominio $condominio): void
    {
        // Controllo se esiste già il conto "Crediti v/Condomini"
        if (ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->exists()) {
            return;
        }

        Log::info("Inizializzazione Piano Conti (Schema Strict) per '{$condominio->nome}'...");

        // 1. RADICE: ATTIVO (parent_id = null)
        $attivoRoot = ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'codice' => '1000'],
            [
                'parent_id'   => null,
                'nome'        => 'ATTIVO',
                'descrizione' => 'Sezione patrimoniale delle attività (liquidità e crediti)',
                'tipo'        => 'attivo',      
                'categoria'   => 'liquidita',   
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 0
            ]
        );

        // 2. RADICE: PASSIVO (parent_id = null)
        $passivoRoot = ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'codice' => '2000'],
            [
                'parent_id'   => null,
                'nome'        => 'PASSIVO',
                'descrizione' => 'Sezione patrimoniale delle passività (debiti e fondi)',
                'tipo'        => 'passivo',     
                'categoria'   => 'fondi',       
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 0
            ]
        );

        // --- SOTTOCONTI DI SISTEMA ---

        // A. Crediti verso Condomini (Attività > Crediti)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'crediti_condomini'],
            [
                'parent_id'   => $attivoRoot->id,
                'codice'      => '1101',
                'nome'        => 'Crediti verso Condomini',
                'descrizione' => 'Crediti vantati dal condominio per rate emesse e non ancora pagate',
                'tipo'        => 'attivo',
                'categoria'   => 'crediti',     
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // B. Cassa Contanti (Attività > Liquidità)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'cassa'],
            [
                'parent_id'   => $attivoRoot->id,
                'codice'      => '1001',
                'nome'        => 'Cassa Contanti',
                'descrizione' => 'Fondo liquidità in contanti a disposizione del condominio',
                'tipo'        => 'attivo',
                'categoria'   => 'liquidita',   
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // C. Anticipi da Condomini (Passività > Debiti)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'anticipi_condomini'],
            [
                'parent_id'   => $passivoRoot->id,
                'codice'      => '2101',
                'nome'        => 'Anticipi da Condomini',
                'descrizione' => 'Somme versate in eccedenza dai condòmini (crediti a loro favore)',
                'tipo'        => 'passivo',
                'categoria'   => 'debiti',      
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );
        
        // D. Debiti v/Fornitori (Passività > Debiti)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'debiti_fornitori'],
            [
                'parent_id'   => $passivoRoot->id,
                'codice'      => '2201',
                'nome'        => 'Debiti v/Fornitori',
                'descrizione' => 'Debiti verso i fornitori per fatture registrate e non ancora saldate',
                'tipo'        => 'passivo',
                'categoria'   => 'debiti',      
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // E. Gestione Rate (Passività > Fondi/Ricavi)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'gestione_rate'],
            [
                'parent_id'   => $passivoRoot->id, 
                'codice'      => '3001',
                'nome'        => 'Gestione Rate',
                'descrizione' => 'Contropartita contabile per l\'emissione delle rate ai condòmini',
                'tipo'        => 'passivo',     
                'categoria'   => 'fondi',       
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // F. Fondo Passate Gestioni (Passività > Fondi)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'passate_gestioni'],
            [
                'parent_id'   => $passivoRoot->id,
                'codice'      => '2301', 
                'nome'        => 'Fondo Passate Gestioni',
                'descrizione' => 'Fondo per debiti e spese di competenza di esercizi precedenti',
                'tipo'        => 'passivo',
                'categoria'   => 'fondi',
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        Log::info("Piano Conti creato correttamente per '{$condominio->nome}'");
    }
}
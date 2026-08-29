<?php

namespace App\Services;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\ContoContabile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Enums\ContoContabileCategoria;
use App\Enums\ContoContabileTipo; 

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

        // Invariante "al più un esercizio aperto per condominio": va rispettata anche
        // qui, non solo nelle FormRequest. Questo è il percorso non-HTTP (creazione
        // condominio, installer, seeder) e creava sempre 'aperto' senza guardare se ce
        // ne fosse già uno: bastava quello per rendere nondeterministico
        // getEsercizioCorrente() e, con lui, ogni sigillo per-esercizio.
        $giaAperto = $condominio->esercizi()->where('stato', 'aperto')->first();

        if ($giaAperto) {
            return $giaAperto;
        }

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
    /**
     * La gestione predefinita del condominio, agganciata a quell'esercizio.
     *
     * ⚠️ **Il tipo è un parametro dalla beta.3 della 1.11, e serve all'importazione.** In Danea
     * un esercizio straordinario è una riga di `TCONDOMINI` con `STRAORDINARIO = 1` e un periodo
     * proprio; da noi lo stesso concetto è una **gestione straordinaria**, che è il contenitore
     * che esiste per quello. Senza il parametro l'importazione poteva solo creare l'ordinaria, e
     * il pregresso di uno straordinario ci finiva dentro senza che niente lo segnalasse.
     *
     * I chiamanti che non lo passano non cambiano comportamento: il predefinito resta `ordinaria`.
     *
     * Le date sono quelle dell'esercizio salvo indicazione diversa: una gestione straordinaria ha
     * un periodo suo — un fondo lavori può durare più di un anno — ed è la ragione per cui
     * `gestioni` ha `data_inizio` e `data_fine` proprie e un legame molti-a-molti con gli esercizi.
     *
     * @return Gestione la gestione, così chi la crea può dire a valle quale ha usato
     */
    public function createDefaultGestione(
        Condominio $condominio,
        Esercizio $esercizio,
        string $tipo = 'ordinaria',
        $dataInizio = null,
        $dataFine = null,
    ): Gestione {
        // Controlliamo che non esista già per evitare duplicati
        $gestione = $condominio->gestioni()->where('tipo', $tipo)->first();

        if (!$gestione) {
            $gestione = $condominio->gestioni()->create([
                'nome'        => 'Gestione '.$tipo,
                'tipo'        => $tipo,
                'attiva'      => true,
                'data_inizio' => $dataInizio ?? $esercizio->data_inizio,
                'data_fine'   => $dataFine ?? $esercizio->data_fine,
            ]);

            Log::info("Gestione {$tipo} creata per '{$condominio->nome}'");
        }

        // Assicuriamoci che questa gestione sia agganciata all'esercizio appena creato.
        if (!$gestione->esercizi()->where('esercizio_id', $esercizio->id)->exists()) {
            $gestione->esercizi()->attach($esercizio->id);
            Log::info("Gestione {$tipo} agganciata all'Esercizio ID {$esercizio->id}");
        }

        return $gestione;
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
                'tipo'        => ContoContabileTipo::ATTIVO->value,      
                'categoria'   => ContoContabileCategoria::LIQUIDITA->value,   
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
                'tipo'        => ContoContabileTipo::PASSIVO->value,     
                'categoria'   => ContoContabileCategoria::FONDI->value,       
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
                'tipo'        => ContoContabileTipo::ATTIVO->value,
                'categoria'   => ContoContabileCategoria::CREDITI->value,     
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
                'tipo'        => ContoContabileTipo::ATTIVO->value,
                'categoria'   => ContoContabileCategoria::LIQUIDITA->value,   
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
                'tipo'        => ContoContabileTipo::PASSIVO->value,
                'categoria'   => ContoContabileCategoria::DEBITI->value,      
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
                'tipo'        => ContoContabileTipo::PASSIVO->value,
                'categoria'   => ContoContabileCategoria::DEBITI->value,      
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // D-Bis. Debiti v/Erario per Ritenute (Passività > Debiti)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'debiti_erario_ritenute'],
            [
                'parent_id'   => $passivoRoot->id,
                'codice'      => '2202',
                'nome'        => 'Debiti v/Erario per Ritenute',
                'descrizione' => 'Trattenute operate da versare allo Stato tramite F24',
                'tipo'        => ContoContabileTipo::PASSIVO->value,
                'categoria'   => ContoContabileCategoria::DEBITI->value,      
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
                'tipo'        => ContoContabileTipo::PASSIVO->value,     
                'categoria'   => ContoContabileCategoria::FONDI->value,       
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
                'tipo'        => ContoContabileTipo::PASSIVO->value,
                'categoria'   => ContoContabileCategoria::FONDI->value,
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // G. Sopravvenienze Passive (Conto Economico > Costi Straordinari)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'sopravvenienze_passive'],
            [
                'parent_id'   => null, // Nessun parent patrimoniale, è un conto economico
                'codice'      => '4001', 
                'nome'        => 'Sopravvenienze Passive',
                'descrizione' => 'Costi relativi a esercizi precedenti non contabilizzati (Art. 1130-bis c.c.)',
                'tipo'        => ContoContabileTipo::COSTO->value, 
                'categoria'   => ContoContabileCategoria::COSTI->value,
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // H. Costi per Servizi (Conto Economico > Costi Operativi)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'costi_servizi'],
            [
                'parent_id'   => null,
                'codice'      => '6001',
                'nome'        => 'Costi per Servizi',
                'descrizione' => 'Manutenzioni, pulizie, utenze e servizi generali del condominio',
                'tipo'        => ContoContabileTipo::COSTO->value,
                'categoria'   => ContoContabileCategoria::COSTI->value,
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1,
            ]
        );

        // I. Compensi Professionisti (Conto Economico > Costi Operativi)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'compensi_professionisti'],
            [
                'parent_id'   => null,
                'codice'      => '6002',
                'nome'        => 'Compensi Professionisti',
                'descrizione' => 'Amministratore, avvocati, tecnici e consulenti',
                'tipo'        => ContoContabileTipo::COSTO->value,
                'categoria'   => ContoContabileCategoria::COSTI->value,
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1,
            ]
        );

        // J. Spese Bancarie (Conto Economico > Costi Operativi)
        //    Usato in v1.9.1: commissioni sui bonifici ai fornitori.
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'spese_bancarie'],
            [
                'parent_id'   => null,
                'codice'      => '6003',
                'nome'        => 'Spese Bancarie',
                'descrizione' => 'Commissioni su bonifici, spese tenuta conto e oneri bancari',
                'tipo'        => ContoContabileTipo::COSTO->value,
                'categoria'   => ContoContabileCategoria::COSTI->value,
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1,
            ]
        );
 
        // K. IVA Acquisti (Attivo > Crediti)
        //    Non usato in v1.9.1. Preparato per v1.12 Fase B (Reverse Charge N6.x).
        //    Nel RC: DARE IVA Acquisti (credito verso erario) / AVERE IVA Vendite.
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'iva_acquisti'],
            [
                'parent_id'   => $attivoRoot->id,
                'codice'      => '1201',
                'nome'        => 'IVA Acquisti',
                'descrizione' => 'IVA a credito su acquisti (usato per Reverse Charge in v1.12)',
                'tipo'        => ContoContabileTipo::ATTIVO->value,
                'categoria'   => ContoContabileCategoria::CREDITI->value,
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1,
            ]
        );
 
        // L. IVA Vendite (Passivo > Debiti)
        //    Non usato in v1.9.1. Preparato per v1.12 Fase B (Reverse Charge N6.x).
        //    Nel RC: DARE IVA Acquisti / AVERE IVA Vendite (debito verso erario).
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'iva_vendite'],
            [
                'parent_id'   => $passivoRoot->id,
                'codice'      => '2203',
                'nome'        => 'IVA Vendite',
                'descrizione' => 'IVA a debito (usato per Reverse Charge in v1.12)',
                'tipo'        => ContoContabileTipo::PASSIVO->value,
                'categoria'   => ContoContabileCategoria::DEBITI->value,
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1,
            ]
        );

        Log::info("Piano Conti creato correttamente per '{$condominio->nome}'");
    }
}
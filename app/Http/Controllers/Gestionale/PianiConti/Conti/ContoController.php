<?php

namespace App\Http\Controllers\Gestionale\PianiConti\Conti;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoConto\Conto\CreateContoRequest;
use App\Http\Requests\Gestionale\PianoConto\Conto\UpdateContoRequest;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Tabella;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException; 

/**
 * Controller per la gestione delle voci di spesa/entrata (Conti) all'interno del piano dei conti.
 * Gestisce la creazione, l'aggiornamento e l'eliminazione dei conti, inclusa la logica
 * complessa per capitoli, sottoconti e blocchi contabili.
 */
class ContoController extends Controller
{
    use HandleFlashMessages;

    /**
     * Crea una nuova voce di spesa o capitolo nel piano dei conti.
     */
    public function store(CreateContoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): RedirectResponse
    {
        try {
            DB::beginTransaction();
            $data = $request->validated();
            
            // Estrazione sicura dei flag booleani (previene errori se la chiave non esiste nel payload)
            $isCapitolo = $data['isCapitolo'] ?? false;
            $isSottoConto = $data['isSottoConto'] ?? false;
            $richiedeGiaVersato = $data['richiedeGiaVersato'] ?? false;

            // Creazione del record principale del Conto
            $nuovoConto = Conto::create([
                'piano_conto_id'        => $pianoConto->id,
                'parent_id'             => $isSottoConto ? ($data['parent_id'] ?? null) : null,
                // Fatto esplicito scelto ORA dall'amministratore, persistito subito:
                // non va più indovinato da importo/parent_id in un secondo momento
                // (bug "voce a zero perde la tabella millesimale", migrazione
                // add_is_capitolo_to_conti_table).
                'is_capitolo'           => $isCapitolo,
                // Filtra l'elenco "Già versato" (beta.27): senza flag esplicito
                // ogni voce di spesa vi compariva, confuso su un piano dei conti
                // con decine di voci. Un capitolo non versa nulla direttamente
                // (beta.22): non ha senso per lui, resta sempre false.
                'richiede_gia_versato'  => $isCapitolo ? false : $richiedeGiaVersato,
                'conto_contabile_id'    => $this->resolveContoContabileId($condominio->id, $data['tipo_spesa'] ?? 'standard'),
                'codice'                => $data['codice'] ?? null,
                'nome'                  => $data['nome'],
                'descrizione'           => $data['descrizione'] ?? null,
                'tipo'                  => $data['tipo'],
                'tipo_spesa'            => $data['tipo_spesa'] ?? 'standard',
                'importo'               => $isCapitolo ? 0 : MoneyHelper::toCents($data['importo'] ?? 0),
                'default_fornitore_id'  => $data['default_fornitore_id'] ?? null,
                'note'                  => $data['note'] ?? null,
                'attivo'                => true,
            ]);

            // Se NON è un capitolo (quindi è una voce di spesa effettiva), gestiamo le tabelle millesimali
            if (!$isCapitolo) {
                // 1. Validazione e recupero della tabella millesimale associata
                $tabella = Tabella::where('id', $data['tabella_millesimale_id'])
                                  ->where('condominio_id', $condominio->id)
                                  ->first();
                if (!$tabella) throw new \Exception('Tabella millesimale non trovata');

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
                    ['soggetto' => 'proprietario', 'percentuale' => $data['percentuale_proprietario'] ?? 0],
                    ['soggetto' => 'inquilino', 'percentuale' => $data['percentuale_inquilino'] ?? 0],
                    ['soggetto' => 'usufruttuario', 'percentuale' => $data['percentuale_usufruttuario'] ?? 0]
                ];

                // Controllo integrità: la somma deve essere 100%
                if (array_sum(array_column($ripartizioni, 'percentuale')) != 100) {
                    throw new \Exception("La somma delle percentuali deve essere 100%");
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
            
            DB::commit();
            return to_route('admin.gestionale.esercizi.piani-conti.show', [$condominio->id, $esercizio->id, $pianoConto->id])
                ->with($this->flashSuccess(__('gestionale.success_create_conto')));
                
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with($this->flashError($e->getMessage()));
        }
    }

    /**
     * Aggiorna una voce di spesa esistente, applicando i controlli di sicurezza contabile (Accounting Core).
     */
    public function update(UpdateContoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {
        try {
            DB::beginTransaction();
            $data = $request->validated();
            
            // Estrazione sicura
            $isCapitolo = $data['isCapitolo'] ?? false;
            $isSottoConto = $data['isSottoConto'] ?? false;
            $richiedeGiaVersato = $data['richiedeGiaVersato'] ?? false;
            $nuovoImporto = $isCapitolo ? 0 : MoneyHelper::toCents($data['importo'] ?? 0);

            // FIX bug "voce a zero perde la tabella millesimale": lo stato
            // PERSISTITO prima di questo update è l'unica fonte di verità su
            // se questo conto era già un capitolo. Va letto ORA, prima che
            // $conto->update() più sotto lo sovrascriva.
            $eraGiaCapitolo = (bool) $conto->is_capitolo;

            // Guard strutturale: un capitolo con sottoconti non può diventare una voce normale
            if ($isCapitolo === false && $conto->sottoconti()->exists()) {
                throw ValidationException::withMessages([
                    'isCapitolo' => 'Cannot convert to expense: this chapter has subaccounts attached. Please remove or reassign them first.'
                ]);
            }

            // CONTROLLI DI SICUREZZA: Scattano solo se l'utente tenta di modificare l'importo di una spesa
            if (!$isCapitolo && $nuovoImporto != $conto->importo) {
                
                // 1. HARD LOCK: Blocco totale se esistono rate già approvate, emesse o chiuse.
                $hasHardLock = $conto->pianiRate()->whereIn('stato', ['approvato', 'emesso', 'chiuso'])->exists() ||
                               ($conto->parent && $conto->parent->pianiRate()->whereIn('stato', ['approvato', 'emesso', 'chiuso'])->exists());

                if ($hasHardLock) {
                    // Lanciamo un'eccezione di validazione così Inertia la mostra in rosso sotto il campo "importo"
                    throw ValidationException::withMessages([
                        'importo' => "Modifica inibita: l'importo è bloccato da rate già approvate o emesse."
                    ]);
                }

                // 2. SOFT LOCK (Blocco Elastico): Impedisce di scendere sotto la soglia già impegnata.
                // Usiamo il pattern di Fallback: se l'importo in pivot è NULL, usiamo l'intero importo del conto.
                $impegnato = DB::table('piano_rate_capitoli')
                    ->where('conto_id', $conto->id)
                    ->get()
                    ->sum(function ($row) use ($conto) {
                        return $row->importo !== null ? (int) $row->importo : $conto->importo;
                    });

                if ($nuovoImporto < $impegnato) {
                    $giaPianificato = number_format($impegnato / 100, 2, ',', '.');
                    throw ValidationException::withMessages([
                        'importo' => "L'importo minimo consentito è € $giaPianificato (già impegnato nei piani rate)."
                    ]);
                }

            }

            // Procediamo con l'aggiornamento dei dati
            $conto->update([
                'parent_id'             => $isSottoConto ? ($data['parent_id'] ?? null) : null,
                'is_capitolo'           => $isCapitolo,
                // Un capitolo non versa nulla direttamente (beta.22): non ha senso
                // per lui, resta sempre false a prescindere da cosa arriva dal form.
                'richiede_gia_versato'  => $isCapitolo ? false : $richiedeGiaVersato,
                'conto_contabile_id'    => $this->resolveContoContabileId($condominio->id, $data['tipo_spesa'] ?? 'standard'),
                'codice'                => $data['codice'] ?? null,
                'nome'                  => $data['nome'],
                'descrizione'           => $data['descrizione'] ?? null,
                'tipo'                  => $data['tipo'],
                'tipo_spesa'            => $data['tipo_spesa'] ?? 'standard',
                'importo'               => $nuovoImporto,
                'default_fornitore_id'  => $data['default_fornitore_id'] ?? null,
                'note'                  => $data['note'] ?? null,
            ]);

            // Coerenza dati contabili:
            // - Capitolo => nessuna tabella/ripartizione associata
            // - Voce spesa => almeno una tabella e relative ripartizioni
            //
            // FIX bug "voce a zero perde la tabella millesimale": il ramo
            // distruttivo scatta SOLO su una transizione vera (non era
            // capitolo, ora lo diventa) — mai su un resave a parità di stato,
            // che prima cancellava tabella/ripartizioni di una voce reale
            // solo perché isCapitolo veniva (ri)calcolato male a monte.
            /*
             * Quante tabelle millesimali sono collegate a questa voce, **prima** di toccarla.
             *
             * Serve al ramo qui sotto: la scheda di modifica ne conosce una sola, quindi su una
             * voce che ne ha più d'una non è in grado di rappresentare ciò che c'è.
             */
            $tabelleCollegate = DB::table('conto_tabella_millesimale')
                ->where('conto_id', $conto->id)
                ->count();

            if ($isCapitolo && ! $eraGiaCapitolo) {
                $contoTabellaIds = DB::table('conto_tabella_millesimale')
                    ->where('conto_id', $conto->id)
                    ->pluck('id');

                if ($contoTabellaIds->isNotEmpty()) {
                    // Difesa in profondità: c'è REALMENTE qualcosa da perdere.
                    // Non basta che qualcuno (un frontend, una chiamata diretta)
                    // dichiari isCapitolo=true — se questo elimina dati veri,
                    // serve una conferma esplicita e distinta dal solo booleano.
                    // Mai un'eliminazione silenziosa dedotta da una transizione.
                    if (empty($data['confermaConversioneCapitolo'])) {
                        throw ValidationException::withMessages([
                            'isCapitolo' => "Questa voce ha una tabella millesimale e delle ripartizioni collegate: trasformarla in capitolo le eliminerebbe. Conferma esplicitamente per procedere.",
                        ]);
                    }

                    DB::table('conto_tabella_ripartizioni')
                        ->whereIn('conto_tabella_millesimale_id', $contoTabellaIds)
                        ->delete();

                    DB::table('conto_tabella_millesimale')
                        ->where('conto_id', $conto->id)
                        ->delete();
                }
            } elseif (! $isCapitolo && $tabelleCollegate <= 1) {
                /*
                 * ⛔ **Una voce ripartita su PIÙ tabelle non si tocca da qui.** Corretto nella beta.68.
                 *
                 * `ModalModificaConto.vue` legge `tabelle_millesimali[0]` e manda quella: la scheda
                 * conosce **una tabella sola**. Fin qui è un limite. Il difetto era che il blocco
                 * qui sotto — «pulizia delle vecchie tabelle orfane» — cancellava **tutte** le
                 * altre associazioni e le loro ripartizioni: aprire la scheda per correggere un
                 * importo bastava a dimezzare la ripartizione, senza chiedere e senza dire niente.
                 *
                 * Il caso non è esotico, è quello di scuola: **art. 1126 c.c.**, lastrico solare
                 * diviso fra chi ne ha l'uso esclusivo (un terzo) e tutti gli altri (due terzi).
                 * Lo stesso vale per l'art. 1124 sulle scale e per l'ascensore.
                 *
                 * ⚠️ **È lo stesso principio della guardia qui sopra**, sul ramo gemello della
                 * conversione in capitolo: *«mai un'eliminazione silenziosa dedotta da una
                 * transizione»*. Là era già applicato, qui no.
                 *
                 * ⚠️ **La correzione è una rimozione, e si ferma qui di proposito.** Insegnare a
                 * questa scheda a gestire N tabelle è una schermata nuova, cioè una funzione: va
                 * nella 1.11. Quello che non poteva restare è che una schermata distruggesse un
                 * dato che non sa nemmeno mostrare. Nome, importo, note e gerarchia si salvano lo
                 * stesso — è solo la parte che la scheda non sa rappresentare a non venire toccata.
                 *
                 * Nel frattempo una voce su più tabelle si modifica dalla sezione delle tabelle
                 * collegate, che sa farlo da sempre — e la scheda lo dice.
                 *
                 * Il presidio è `tests/Feature/Gestionale/CapitoloSuPiuTabelleNonSiPerdeTest.php`.
                 */
                $tabella = Tabella::query()
                    ->where('id', $data['tabella_millesimale_id'])
                    ->where('condominio_id', $condominio->id)
                    ->first();

                if (!$tabella) {
                    throw ValidationException::withMessages([
                        'tabella_millesimale_id' => 'La tabella millesimale selezionata non è valida'
                    ]);
                }

                // --- INIZIO FIX: PULIZIA DELLE VECCHIE TABELLE ORFANE ---
                $vecchieAssociazioni = DB::table('conto_tabella_millesimale')
                    ->where('conto_id', $conto->id)
                    ->where('tabella_id', '!=', $tabella->id)
                    ->pluck('id');

                if ($vecchieAssociazioni->isNotEmpty()) {
                    // Elimina prima i figli (ripartizioni)
                    DB::table('conto_tabella_ripartizioni')
                        ->whereIn('conto_tabella_millesimale_id', $vecchieAssociazioni)
                        ->delete();
                    
                    // Poi elimina i padri orfani (l'associazione alla vecchia tabella)
                    DB::table('conto_tabella_millesimale')
                        ->whereIn('id', $vecchieAssociazioni)
                        ->delete();
                }
                // --- FINE FIX ---

                $contoTabellaId = DB::table('conto_tabella_millesimale')
                    ->where('conto_id', $conto->id)
                    ->where('tabella_id', $tabella->id)
                    ->value('id');

                if (!$contoTabellaId) {
                    $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
                        'conto_id' => $conto->id,
                        'tabella_id' => $tabella->id,
                        'coefficiente' => 100.00,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('conto_tabella_ripartizioni')
                    ->where('conto_tabella_millesimale_id', $contoTabellaId)
                    ->delete();

                $ripartizioni = [
                    ['soggetto' => 'proprietario', 'percentuale' => $data['percentuale_proprietario'] ?? 0],
                    ['soggetto' => 'inquilino', 'percentuale' => $data['percentuale_inquilino'] ?? 0],
                    ['soggetto' => 'usufruttuario', 'percentuale' => $data['percentuale_usufruttuario'] ?? 0],
                ];

                /*
                 * ⚠️ **Questo controllo mancava, ed era l'anello 3 della catena delle proporzioni.**
                 * Aggiunto nella beta.69.
                 *
                 * Le porte che scrivono `conto_tabella_ripartizioni` sono quattro: `store()` qui
                 * sopra (riga 94), `AssociaTabellaController`, `AggiornaTabellaController` e
                 * `FatturaPassivaService` — che usa una strategia diversa ma altrettanto chiusa,
                 * ripiegando su «proprietario 100» invece di rifiutare. **Solo `update()` scriveva
                 * senza guardare.**
                 *
                 * Cosa costava, misurato con una sonda il 22/08/2026: ripartizioni che dichiarano il
                 * 60% della quota di un'unità facevano addebitare il **100%**, perché la
                 * rinormalizzazione finale del motore assorbiva il resto. Il totale del piano
                 * restava perfettamente uguale al preventivo, quindi nessun controllo contabile
                 * aveva niente da segnalare.
                 *
                 * ⚠️ È la **quarta** volta che una guardia esiste in `store()` e non in `update()`
                 * su questo progetto — dopo il riparto manuale, la capienza del conto in modifica
                 * pagamento e le notifiche della beta.64. Il presidio strutturale è
                 * `tests/Feature/System/RipartizioniValidateSuOgniPortaTest.php`.
                 *
                 * Il motore è stato chiuso lo stesso (`CalcoloQuoteService`, anello 3): questa
                 * riga serve a fermare l'errore **dove lo si commette**, con un messaggio che dice
                 * cosa fare, invece di scoprirlo generando il piano.
                 */
                if (array_sum(array_column($ripartizioni, 'percentuale')) != 100) {
                    throw new \Exception("La somma delle percentuali deve essere 100%");
                }

                foreach ($ripartizioni as $rip) {
                    if ((float) $rip['percentuale'] > 0) {
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

            DB::commit();
            return to_route('admin.gestionale.esercizi.piani-conti.show', [$condominio->id, $esercizio->id, $pianoConto->id])
                ->with($this->flashSuccess(__('gestionale.success_update_conto')));
                
        } catch (ValidationException $e) {
            // Se è un errore di validazione (i nostri blocchi), facciamo il rollback e lo rilanciamo 
            // affinché Laravel/Inertia lo gestisca normalmente verso il frontend.
            DB::rollBack();
            throw $e;
            
        } catch (\Exception $e) {
            // Per errori generici o di database
            DB::rollBack();
            return back()->with($this->flashError($e->getMessage()));
        }
    }

    /**
     * Elimina un conto, previo controllo dei vincoli strutturali.
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {
        // Prevenzione 1: Non puoi eliminare un capitolo che ha dei sottoconti agganciati
        if ($conto->sottoconti()->exists()) {
            return back()->with($this->flashError(__('gestionale.error_conto_has_sottoconti')));
        }
        
        // Prevenzione 2: Non puoi eliminare un conto se è agganciato a piani rate in stato bloccante
        // (bozza non blocca l'eliminazione)
        $statiBloccanti = ['approvato', 'emesso', 'chiuso'];

        // CORRETTO — il conto direttamente in un piano bloccante
        $lockDiretto = $conto->pianiRate()
            ->whereIn('stato', $statiBloccanti)
            ->exists();

        // Il padre ha un piano bloccante E questo conto è incluso in quel piano
        $lockIndiretto = false;
        if ($conto->parent) {
            $lockIndiretto = $conto->parent->pianiRate()
                ->whereIn('stato', $statiBloccanti)
                ->whereHas('capitoli', fn($q) => $q->where('conto_id', $conto->id))
                ->exists();
        }

        $lock = $lockDiretto || $lockIndiretto;

        if ($lock) {
            return back()->with($this->flashError(__('gestionale.error_conto_locked_by_rate_plan')));
        }

        try {
            DB::beginTransaction();
            // Pulizia delle relazioni (tabella pivot) prima di eliminare il conto
            $conto->tabelle()->detach(); 
            $conto->delete();
            
            DB::commit();
            return to_route('admin.gestionale.esercizi.piani-conti.show', [$condominio->id, $esercizio->id, $pianoConto->id])
                ->with($this->flashSuccess(__('gestionale.success_delete_conto')));
                
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with($this->flashError($e->getMessage()));
        }
    }

    private function resolveContoContabileId(int $condominioId, string $tipoSpesa): ?int
    {
        $ruolo = match($tipoSpesa) {
            'professionista' => 'compensi_professionisti',
            default          => 'costi_servizi',
        };

        return ContoContabile::where('condominio_id', $condominioId)
            ->where('ruolo', $ruolo)
            ->value('id');
    }
}

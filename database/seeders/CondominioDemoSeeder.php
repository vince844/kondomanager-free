<?php

namespace Database\Seeders;

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Enums\TipoCassa;
use App\Models\Anagrafica;
use App\Models\Gestionale\Cassa;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Services\CondominioService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * # Il condominio dimostrativo: un percorso completo, non una collezione di funzioni
 *
 * ## A cosa serve
 *
 * Chi installa KondoManager per la prima volta si trova davanti un programma vuoto, e da un
 * programma vuoto non si capisce cosa sa fare. Questo seeder costruisce **un condominio finito**:
 * struttura, millesimi, preventivo, piano rate, incassi, fatture, pagamenti, fondi. Serve a chi
 * comincia per capire dove vuole arrivare, e serve alla demo pubblica.
 *
 * ## ⚠️ La regola che rende questo file diverso da un seeder qualunque
 *
 * **Passa dai service veri, mai da `DB::table()->insert()` sui dati contabili.** Le fatture le
 * registra `FatturaPassivaService`, il piano rate lo genera `GeneratePianoRateAction`, gli incassi
 * `StoreIncassoRateAction`, i giroconti `RegistraGirocontoAction`.
 *
 * Non è pignoleria. Un seeder che scrive a mano produce **stati che il prodotto non sa produrre** —
 * è esattamente il difetto trovato nella beta.66, dove nove file di test costruivano un esercizio
 * senza attaccargli la gestione, uno stato impossibile che nessuno vedeva. Una demo così mostra un
 * KondoManager che non esiste, e i primi a sbatterci sono le persone che si vogliono convincere.
 *
 * Passando dai service, invece:
 *
 * - non può produrre uno stato impossibile, perché usa le stesse porte dell'amministratore;
 * - **si aggiorna da solo** quando i service cambiano, invece di invecchiare in silenzio;
 * - ed è la prova di integrazione più larga che il progetto abbia: un giro completo dall'inizio
 *   alla fine, che nessun test copre per intero.
 *
 * ## Il perimetro, dichiarato
 *
 * ⚠️ **Non copre «tutto quello che il programma sa fare», e non deve.** Con centotrentanove
 * migrazioni di superficie quell'obiettivo non finisce mai, e un seeder che insegue tutto è un
 * seeder che nessuno aggiorna. Copre **il percorso di un condominio**, che è ciò che un
 * amministratore deve vedere per capire — e che per costruzione tocca il motore da capo a fondo:
 *
 *  1. struttura e unità, con proprietari, un inquilino e due comproprietari;
 *  2. tabelle millesimali, **compreso l'art. 1126 c.c.** — una spesa su due tabelle, un terzo a chi
 *     ha l'uso esclusivo del lastrico e due terzi a tutti gli altri;
 *  3. esercizio, gestione ordinaria e casse (conto corrente più un fondo);
 *  4. preventivo con capitoli e voci, una delle quali ripartita fra proprietario e inquilino;
 *  5. piano rate generato dal motore vero;
 *  6. incassi: uno completo, uno parziale che lascia una morosità;
 *  7. fatture fornitore: una con ritenuta d'acconto e una nota di credito;
 *  8. il pagamento che **compensa** la nota di credito riducendo il bonifico (beta.67);
 *  9. un giroconto dal conto corrente al fondo;
 * 10. una fattura **fuori budget**, che resta in attesa di ratifica e si vede nel piano dei conti;
 * 11. uno **storno**: un pagamento annullato con la sua scrittura inversa, perché in contabilità
 *     non si cancella, si rettifica — ed è il principio su cui è costruito tutto il gestionale;
 * 12. lo **scadenzario F24** già calcolato, con la ritenuta pronta da versare;
 * 13. una **sopravvenienza**: una spesa imprevista che il preventivo non conteneva, che si crea la
 *     propria voce e chiede rate nuove — è la sezione gialla del piano dei conti.
 *
 * Il rendiconto non si semina: è una vista, e con questi movimenti si popola da sé.
 *
 * ## Due cose escluse apposta, e il perché
 *
 * ⚠️ **Il «già versato» è escluso**, benché sia la funzione documentata nella beta.70 e fosse
 * stato messo qui una prima volta. Provato e misurato il 23/08/2026: il motore sottrae gli acconti
 * mentre calcola (`CalcoloQuoteService::nettingGiaVersato`), ma il pivot `piano_rate_capitoli` —
 * che la porta vera riempie con `importo_suggerito`, cioè `max(conto->importo, spesoReale)` di
 * `BudgetCoverageService:38`, **senza sottrarre le coperture** — resta al lordo. Il cruscotto
 * confronta i due (`PianoRateQuoteService::totaleAttesoCents` contro `totalePuroGeneratoCents`) e
 * su questa demo la differenza era € 12.480,00 dichiarati contro € 10.980,00 generati: allarme
 * rosso «hai modificato il preventivo, ricalcola» su un piano perfettamente corretto. È lo stesso
 * allarme per cui il pivot era già stato sistemato una volta, e mettere il netto a mano nel pivot
 * significherebbe scrivere uno stato che la porta vera non produce — cioè rompere la regola qui
 * sopra. Rientra quando quella discordanza è risolta nel prodotto, non prima.
 *
 * ⚠️ **I saldi iniziali sono esclusi apposta**, e non per dimenticanza. Un saldo si blocca quando
 * un piano rate lo rivendica (`SaldoInizialeController:101`), e qui il piano è già emesso: o i
 * saldi nascono col lucchetto — mostrando la funzione già morta, senza matita né cestino — oppure
 * restano fuori dal piano, e allora il piano non torna con i pregressi dichiarati. Entrambe le
 * strade insegnano qualcosa di falso, e una demo che insegna il falso è peggio di una che tace.
 *
 * ## Come si aggiorna
 *
 * Quando una beta aggiunge una funzione che un amministratore deve **vedere per capire**, si
 * aggiunge un passo qui. Quando ne aggiunge una di dettaglio, non si aggiunge niente: il perimetro
 * qui sopra è la difesa contro la crescita infinita, e va difeso.
 */
class CondominioDemoSeeder extends Seeder
{
    private Condominio $condominio;
    private Esercizio $esercizio;
    private $gestione;
    private array $unita = [];
    private array $persone = [];
    private array $tabelle = [];
    private array $casse = [];
    private ?PianoConto $pianoConto = null;
    private array $voci = [];

    /** Il progressivo di questa demo: entra nel codice, nel nome e nei numeri di documento. */
    private int $progressivo = 1;

    /** Le cose andate storte, per dirle invece di nasconderle. */
    public array $avvisi = [];

    public function run(): void
    {
        $this->creaCondominio();
        $this->creaUnitaEPersone();
        $this->creaTabelleMillesimali();
        $this->creaCasse();
        $this->creaPreventivo();
        $this->generaPianoRate();

        foreach (['registraIncassi', 'registraFatture', 'registraFatturaInSforo',
                  'registraSopravvenienza', 'registraGiroconto', 'stornaUnPagamento',
                  'generaScadenzarioF24', 'riempiInbox'] as $passo) {
            try {
                $this->{$passo}();
            } catch (\Throwable $e) {
                // ⚠️ Un passo che fallisce non deve far saltare tutto il condominio: quello che è
                // già stato costruito è utile lo stesso, e l'avviso dice cosa manca invece di
                // lasciare una demo monca senza spiegazione.
                $this->avvisi[] = $passo.': '.$e->getMessage();
            }
        }
    }

    public function condominio(): Condominio
    {
        return $this->condominio;
    }

    // ── 1. Il condominio ─────────────────────────────────────────────────────

    private function creaCondominio(): void
    {
        // Il primo numero libero: si contano i codici già usati, non i condomini dimostrativi
        // esistenti — uno rimosso non deve far riusare il suo codice se qualcosa è rimasto.
        $progressivo = 1;
        while (Condominio::where('codice_identificativo', 'DEMO-'.$progressivo)->exists()) {
            $progressivo++;
        }
        $this->progressivo = $progressivo;

        // Dal service, non da `Condominio::create()`: è il service che semina i sedici conti
        // contabili, l'esercizio e la gestione ordinaria collegata.
        $this->condominio = app(CondominioService::class)->createCondominioWithEsercizio([
            // ⚠️ **Numerazione progressiva, non un timestamp.** Codice, nome ed email sono unici a
            // database, e due demo create nello stesso secondo collidevano — provato. Un progressivo
            // regge, e soprattutto si legge: «Condominio Dimostrativo 2» dice a un amministratore
            // cos'ha davanti, «DEMO-260823063527» no.
            'codice_identificativo' => 'DEMO-'.$progressivo,
            // ⚠️ Obbligatorio, non decorativo: `UpdateCondominioRequest:38` lo pretende
            // `required`. Senza, chi apriva il condominio dimostrativo, cambiava il numero
            // di piani e salvava si prendeva un errore su un campo che non aveva toccato.
            // Compare anche nell'intestazione di tutte le stampe e sulla delega F24, che lo
            // eredita da qui. Undici cifre come un codice fiscale di condominio vero, e
            // derivato dal progressivo perché la colonna è UNIQUE.
            'codice_fiscale'        => '900'.str_pad((string) $progressivo, 8, '0', STR_PAD_LEFT),
            'nome'                  => 'Condominio Dimostrativo'.($progressivo > 1 ? ' '.$progressivo : ''),
            'indirizzo'             => 'Via Giuseppe Verdi 12',
            'comune'                => 'Milano',
            'provincia'             => 'MI',
            'cap'                   => '20121',
            'email'                 => 'demo-'.$progressivo.'@kondomanager.test',
            'is_demo'               => true,
            'anno_costruzione'      => 1978,
            'numero_piani'          => 4,
            'note'                  => "Condominio di esempio creato da KondoManager.\n"
                                     . "Serve a mostrare un percorso completo: millesimi, preventivo, piano rate, "
                                     . "incassi, fatture e fondi. Puoi modificarlo o eliminarlo quando vuoi.",
        ]);

        $this->esercizio = $this->condominio->esercizi()->latest('id')->firstOrFail();
        $this->gestione  = $this->esercizio->gestioni()->firstOrFail();
    }

    // ── 2. Unità e persone ───────────────────────────────────────────────────

    private function creaUnitaEPersone(): void
    {
        // ⚠️ Per **nome**, non la prima che capita: `value('id')` senza `where` restituiva
        // «Ufficio» (id 1), e le quattro unità comparivano nell'elenco col badge UFFICIO
        // accanto ad «Attico». Se il vocabolario fosse vuoto si resta sul primo id
        // disponibile, perché un badge sbagliato è meno grave di un seeder che non parte.
        $tipologia = DB::table('tipologie_immobili')->where('nome', 'Abitazione')->value('id')
            ?? DB::table('tipologie_immobili')->value('id');

        $definizioni = [
            ['codice' => 'INT-1', 'nome' => 'Interno 1', 'interno' => '1', 'piano' => 'Piano terra'],
            ['codice' => 'INT-2', 'nome' => 'Interno 2', 'interno' => '2', 'piano' => 'Primo piano'],
            ['codice' => 'INT-3', 'nome' => 'Interno 3', 'interno' => '3', 'piano' => 'Secondo piano'],
            ['codice' => 'ATT',   'nome' => 'Attico',    'interno' => '4', 'piano' => 'Terzo piano'],
        ];

        foreach ($definizioni as $d) {
            $this->unita[$d['codice']] = Immobile::create([
                'condominio_id'   => $this->condominio->id,
                'tipologia_id'    => $tipologia,
                'codice_immobile' => $d['codice'],
                'nome'            => $d['nome'],
                'interno'         => $d['interno'],
                'piano'           => $d['piano'],
            ]);
        }

        // Le persone: tre proprietari, due comproprietari sull'interno 3, un inquilino sull'1.
        // ⚠️ `anagrafiche` non ha una colonna `cognome`: il nome completo sta tutto in `nome`.
        // E `user_id` resta **null**: queste sono persone dell'anagrafica, non utenti del
        // programma — un seeder che creasse sei utenze vere aggiungerebbe sei password da
        // gestire e sei caselle di posta a cui il programma proverebbe a scrivere.
        // ⚠️ **Niente `Anagrafica::factory()` qui.** `fakerphp/faker` è in `require-dev`:
        // il pacchetto distribuito si costruisce con `--no-dev` e Faker non c'è (verificato
        // su km_v1.10.0-beta.32.zip: zero occorrenze di `vendor/fakerphp`, mentre le
        // factory viaggiano regolarmente). `$this->faker` sarebbe **null** e la prima riga
        // di `AnagraficaFactory::definition()` morirebbe: il pulsante funzionava solo sulle
        // macchine di sviluppo. I valori qui sono scritti a mano e derivati dal progressivo,
        // perché email, PEC, codice fiscale e numero documento hanno un indice UNIQUE e la
        // seconda demo collideva con la prima.
        $n = $this->progressivo;

        foreach ([
            'rossi'    => ['Mario Rossi',     'mario.rossi',     'RSSMRA70A01F205X', '1970-01-01', 'Milano'],
            'bianchi'  => ['Anna Bianchi',    'anna.bianchi',    'BNCNNA75E41F205Y', '1975-05-01', 'Milano'],
            'verdi'    => ['Luca Verdi',      'luca.verdi',      'VRDLCU82H15F205Z', '1982-06-15', 'Milano'],
            'verdi2'   => ['Sara Verdi',      'sara.verdi',      'VRDSRA85T50F205W', '1985-12-10', 'Milano'],
            'esposito' => ['Giulia Esposito', 'giulia.esposito', 'SPSGLI68D45F839K', '1968-04-05', 'Napoli'],
            'ferrari'  => ['Paolo Ferrari',   'paolo.ferrari',   'FRRPLA90L20F205J', '1990-07-20', 'Milano'],
        ] as $chiave => [$nomeCompleto, $slug, $cf, $nascita, $luogo]) {
            $this->persone[$chiave] = Anagrafica::create([
                'user_id'        => null,
                'nome'           => $nomeCompleto,
                'indirizzo'      => 'Via Giuseppe Verdi 12',
                'email'          => "{$slug}.demo{$n}@kondomanager.test",
                'pec'            => "{$slug}.demo{$n}@pec.kondomanager.test",
                'codice_fiscale' => substr($cf, 0, 14).str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                'telefono'       => '02 1234'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'luogo_nascita'  => $luogo,
                'data_nascita'   => $nascita,
            ]);
        }

        $titolarita = [
            ['INT-1', 'rossi',    'proprietario', 100],
            ['INT-1', 'ferrari',  'inquilino',    100],   // l'unità affittata
            ['INT-2', 'bianchi',  'proprietario', 100],
            ['INT-3', 'verdi',    'proprietario',  50],   // due comproprietari
            ['INT-3', 'verdi2',   'proprietario',  50],
            ['ATT',   'esposito', 'proprietario', 100],   // ha l'uso esclusivo del lastrico
        ];

        foreach ($titolarita as [$unita, $persona, $ruolo, $quota]) {
            DB::table('anagrafica_immobile')->insert([
                'anagrafica_id' => $this->persone[$persona]->id,
                'immobile_id'   => $this->unita[$unita]->id,
                'tipologia'     => $ruolo,
                'quota'         => $quota,
                'attivo'        => true,
                'data_inizio'   => now()->subYears(3),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // ⚠️ **Non basta `anagrafica_immobile`.** Assegnare un'unità rende la persona
            // condòmina dello stabile, e quel fatto vive nel pivot `anagrafica_condominio`:
            // è quello che leggono la rubrica, i selettori dei destinatari di comunicazioni,
            // eventi e documenti — e soprattutto `StoreIncassoRateRequest:18`, che senza
            // pivot **rifiuta il pagante**. È la stessa riga che scrive la porta vera
            // (`ImmobileAnagraficaController:148`) dalla beta.48, coda ⑫; qui mancava, e il
            // seeder ricostruiva a mano proprio lo stato che quella beta aveva chiuso.
            $this->persone[$persona]->condomini()->syncWithoutDetaching([$this->condominio->id]);
        }
    }

    // ── 3. Tabelle millesimali, compreso l'art. 1126 ─────────────────────────

    private function creaTabelleMillesimali(): void
    {
        $definizioni = [
            'proprieta' => [
                'nome'  => 'Proprietà generale',
                'tipo'  => 'standard',
                'quote' => ['INT-1' => 240, 'INT-2' => 240, 'INT-3' => 240, 'ATT' => 280],
            ],
            // ⚠️ Le due tabelle dell'art. 1126 c.c.: la spesa del lastrico si divide in un terzo a
            // chi ne ha l'uso esclusivo e due terzi a tutti gli altri. Sono due platee diverse,
            // ed è il motivo per cui una voce di spesa può essere collegata a due tabelle.
            'lastrico_uso' => [
                'nome'  => 'Lastrico — uso esclusivo (art. 1126)',
                'tipo'  => 'lastrico',
                'quote' => ['ATT' => 1000],
            ],
            'lastrico_tutti' => [
                'nome'  => 'Lastrico — copertura (art. 1126)',
                'tipo'  => 'lastrico',
                'quote' => ['INT-1' => 333, 'INT-2' => 333, 'INT-3' => 334],
            ],
        ];

        foreach ($definizioni as $chiave => $d) {
            $tabella = Tabella::create([
                'condominio_id' => $this->condominio->id,
                'nome'          => $d['nome'],
                'tipo'          => $d['tipo'],
                'quota'         => 'millesimi',
                'attiva'        => true,
            ]);

            foreach ($d['quote'] as $codice => $valore) {
                DB::table('quote_tabella')->insert([
                    'tabella_id'  => $tabella->id,
                    'immobile_id' => $this->unita[$codice]->id,
                    'valore'      => $valore,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            $this->tabelle[$chiave] = $tabella;
        }
    }

    // ── 4. Le casse: un conto corrente e un fondo ────────────────────────────

    private function creaCasse(): void
    {
        // ⚠️ **Da `CreateCassaAction`, non da `Cassa::create()`.** Una cassa non è solo una riga:
        // le serve un conto contabile, e l'azione lo crea insieme. Scrivendo a mano si otterrebbe
        // una cassa senza conto — che è precisamente uno degli stati che il prodotto non sa
        // produrre e che un seeder scritto a mano produce senza accorgersene.
        $crea = app(\App\Actions\Cassa\CreateCassaAction::class);

        $this->casse['banca'] = $crea->execute($this->condominio, [
            'nome'           => 'Conto corrente condominiale',
            'tipo'           => TipoCassa::BANCA->value,
            'saldo_iniziale' => '0,00',
            'istituto'       => 'Banca di esempio',
            'iban'           => 'IT60X0542811101000000123456',
            'intestatario'   => 'Condominio Dimostrativo',
            'predefinito'    => true,
        ]);

        // ⚠️ Un fondo è una **partizione** dello stesso conto corrente, non un secondo conto in
        // banca: è la convenzione del progetto, ed è il motivo per cui i fondi si alimentano con
        // un giroconto e non con un versamento.
        $this->casse['fondo'] = $crea->execute($this->condominio, [
            'nome'           => 'Fondo lavori straordinari',
            'tipo'           => TipoCassa::FONDO->value,
            'saldo_iniziale' => '0,00',
        ]);
    }

    // ── 5. Il preventivo ─────────────────────────────────────────────────────

    private function creaPreventivo(): void
    {
        $this->pianoConto = PianoConto::create([
            'condominio_id' => $this->condominio->id,
            'gestione_id'   => $this->gestione->id,
            'nome'          => 'Preventivo '.now()->year,
        ]);

        $capitolo = Conto::create([
            'piano_conto_id' => $this->pianoConto->id,
            'parent_id'      => null,
            'is_capitolo'    => true,
            'nome'           => 'Spese generali',
            'tipo'           => 'spesa',
            'importo'        => 0,
        ]);

        $voci = [
            // [chiave, nome, centesimi, tabella => coefficiente, ripartizione per ruolo, conto di costo]
            ['amministratore', 'Compenso amministratore', 180000, ['proprieta' => 100], ['proprietario' => 100], 'compensi_professionisti'],
            ['pulizie',        'Pulizia scale',            96000, ['proprieta' => 100], ['proprietario' => 50, 'inquilino' => 50], 'costi_servizi'],
            ['ascensore',      'Manutenzione ascensore',   72000, ['proprieta' => 100], ['proprietario' => 100], 'costi_servizi'],
        ];

        foreach ($voci as [$chiave, $nome, $importo, $tabelle, $ripartizioni, $ruolo]) {
            $this->voci[$chiave] = $this->creaVoce($capitolo, $nome, $importo, $tabelle, $ripartizioni, $ruolo);
        }

        // ⚠️ La voce che mostra l'art. 1126: **due tabelle su una sola spesa**, un terzo e due terzi.
        $this->voci['lastrico'] = $this->creaVoce(
            null,
            'Rifacimento lastrico solare',
            900000,
            ['lastrico_uso' => 33.33, 'lastrico_tutti' => 66.67],
            ['proprietario' => 100],
        );
    }

    private function creaVoce(?Conto $capitolo, string $nome, int $importoCents, array $tabelle, array $ripartizioni, string $ruoloContabile = 'costi_servizi'): Conto
    {
        $conto = Conto::create([
            'piano_conto_id' => $this->pianoConto->id,
            'parent_id'      => $capitolo?->id,
            'is_capitolo'    => false,
            'nome'           => $nome,
            'tipo'           => 'spesa',
            'natura_spesa'   => 'ordinaria',
            'importo'        => $importoCents,
            // ⚠️ **L'ancoraggio in partita doppia, e senza non si può fatturare.** È il conto di
            // costo su cui la fattura scriverà il DARE. `ContoController` lo risolve dal ruolo
            // (`costi_servizi`, oppure `compensi_professionisti` per un professionista): senza,
            // registrare una fattura su questa voce fallisce con «Integrità compromessa».
            // Scoperto costruendo questo seeder — un `Conto::create()` a mano non lo mette.
            'conto_contabile_id' => \App\Models\Gestionale\ContoContabile::where('condominio_id', $this->condominio->id)
                ->where('ruolo', $ruoloContabile)
                ->value('id'),
        ]);

        foreach ($tabelle as $chiave => $coefficiente) {
            $associazioneId = DB::table('conto_tabella_millesimale')->insertGetId([
                'conto_id'     => $conto->id,
                'tabella_id'   => $this->tabelle[$chiave]->id,
                'coefficiente' => $coefficiente,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            foreach ($ripartizioni as $soggetto => $percentuale) {
                DB::table('conto_tabella_ripartizioni')->insert([
                    'conto_tabella_millesimale_id' => $associazioneId,
                    'soggetto'                     => $soggetto,
                    'percentuale'                  => $percentuale,
                    'created_at'                   => now(),
                    'updated_at'                   => now(),
                ]);
            }
        }

        return $conto;
    }

    // ── 6. Il piano rate, generato dal motore vero ───────────────────────────

    private function generaPianoRate(): void
    {
        $piano = PianoRate::create([
            'gestione_id'   => $this->gestione->id,
            'condominio_id' => $this->condominio->id,
            'nome'          => 'Piano rate '.now()->year,
            'stato'         => 'bozza',
            'tipo'          => 'ordinario',
            'numero_rate'   => 2,
        ]);

        /*
         * ⚠️ **Il piano deve dichiarare quali voci copre, e senza il cruscotto lo dà per rotto.**
         *
         * Un piano rate non copre «tutto il preventivo»: copre un elenco esplicito di voci, con
         * l'importo che ciascuna finanzia (pivot `piano_rate_capitoli`). Il cruscotto confronta la
         * somma dichiarata lì con quello che il piano ha davvero generato, e se differiscono
         * mostra in rosso «hai modificato il preventivo, ricalcola».
         *
         * Il seeder generava le rate senza collegare le voci: totale atteso **zero**, totale
         * generato € 12.480,00, delta −12.480,00. Il condominio dimostrativo si apriva con un
         * allarme rosso e le coperture allo 0% — cioè mostrava un programma che sembra rotto
         * proprio a chi lo sta guardando per la prima volta.
         *
         * ⚠️ **Trovato solo guardando a video.** Nessun avviso del seeder, nessun test rosso: i dati
         * erano tutti corretti presi uno per uno, ed è la lettura d'insieme a essere sbagliata.
         */
        $piano->capitoli()->sync(
            collect($this->voci)
                ->mapWithKeys(fn (Conto $voce) => [$voce->id => ['importo' => $voce->importo]])
                ->all()
        );

        app(GeneratePianoRateAction::class)->execute($piano, accettaScoperti: false);

        $piano->update(['stato' => \App\Enums\StatoPianoRate::APPROVATO->value]);
        $this->pianoRate = $piano->refresh();

        /*
         * ⚠️ **L'emissione non è un cambio di stato, ed è l'errore che avevo commesso.**
         *
         * La prima stesura faceva `$piano->rate()->update(['stato' => 'emessa'])`. Le rate
         * *risultavano* emesse e gli incassi funzionavano, ma **la scrittura contabile non
         * esisteva**: emettere una rata registra in partita doppia il credito verso i condòmini
         * contro il conto della gestione, aggiorna gli eventi in agenda e valida la quadratura.
         *
         * Cioè avevo prodotto esattamente lo stato che il prodotto non sa produrre — il peccato da
         * cui l'intera regola di questo seeder esiste per difendersi. **Se n'è accorto Vincenzo
         * guardando la pagina del piano rate**, non una prova automatica: a database lo stato
         * diceva «emessa» e sembrava tutto a posto.
         *
         * Si chiama quindi la porta vera. `EmissioneRateController::store()` prende un `Request`
         * semplice — non un FormRequest — quindi è richiamabile senza artifici.
         */
        $richiesta = \Illuminate\Http\Request::create('', 'POST', [
            'rate_ids'        => $piano->rate()->pluck('id')->all(),
            'data_emissione'  => now()->subDays(35)->format('Y-m-d'),
            // ⚠️ Niente notifiche: il condominio dimostrativo non deve scrivere a nessuno. Le
            // anagrafiche non hanno un'utenza, ma la regola vale a prescindere — un seeder che
            // manda posta è un seeder che si fa notare nel modo sbagliato.
            'invia_notifiche' => false,
        ]);
        $richiesta->setUserResolver(fn () => \Illuminate\Support\Facades\Auth::user());

        app(\App\Http\Controllers\Gestionale\PianiRate\EmissioneRateController::class)
            ->store($richiesta, $this->condominio, $this->pianoRate);
    }

    private ?PianoRate $pianoRate = null;

    // ── 7-9. I passi che possono fallire ─────────────────────────────────────

    /**
     * Due incassi, e sono scelti: uno saldato e uno a metà.
     *
     * ⚠️ **La morosità è il punto.** Un condominio in cui hanno pagato tutti non mostra niente di
     * ciò che serve a un amministratore: il residuo per persona, il sollecito, l'estratto conto che
     * non torna a zero. Il condominio dimostrativo deve avere qualcuno indietro con i pagamenti,
     * altrimenti mostra un mondo che non esiste.
     */
    private function registraIncassi(): void
    {
        $incassa = app(\App\Actions\Gestionale\Movimenti\StoreIncassoRateAction::class);
        $primaRata = $this->pianoRate->rate()->orderBy('id')->firstOrFail();

        $quotePerPersona = \App\Models\Gestionale\RataQuote::where('rata_id', $primaRata->id)
            ->get()
            ->groupBy('anagrafica_id');

        // Rossi salda per intero, Bianchi versa la metà: resta a debito e compare fra i morosi.
        $piani = [
            ['persona' => 'rossi',   'frazione' => 1.0,  'descrizione' => 'Prima rata — saldo'],
            ['persona' => 'bianchi', 'frazione' => 0.5,  'descrizione' => 'Prima rata — acconto'],
        ];

        foreach ($piani as $p) {
            $quote = $quotePerPersona->get($this->persone[$p['persona']]->id);

            if (! $quote || $quote->isEmpty()) {
                $this->avvisi[] = "incassi: nessuna quota per {$p['persona']}";
                continue;
            }

            $dettaglio = [];
            $totale = 0.0;

            foreach ($quote as $quota) {
                $importo = round(((int) $quota->importo * $p['frazione']) / 100, 2);
                if ($importo <= 0) {
                    continue;
                }
                $dettaglio[] = ['rata_id' => $quota->id, 'importo' => $importo];
                $totale += $importo;
            }

            if ($dettaglio === []) {
                continue;
            }

            $incassa->execute([
                'pagante_id'          => $this->persone[$p['persona']]->id,
                'cassa_id'            => $this->casse['banca']->id,
                'gestione_id'         => $this->gestione->id,
                'data_pagamento'      => now()->subDays(20)->format('Y-m-d'),
                'importo_totale'      => round($totale, 2),
                'descrizione'         => $p['descrizione'],
                'eccedenza'           => 0,
                'dettaglio_pagamenti' => $dettaglio,
            ], $this->condominio, $this->esercizio);
        }
    }

    /**
     * Due documenti dal fornitore, e un pagamento che li compensa.
     *
     * ⚠️ **La nota di credito non è un ornamento.** È la funzione rimessa in piedi nella beta.67 —
     * fino a lì premere «usa le note di credito» finiva sempre in errore — ed è anche il caso in cui
     * si vede che dalla banca esce **meno** di quanto si chiude di debito. Un amministratore che non
     * l'ha mai vista non sa che il programma la sa fare.
     */
    private function registraFatture(): void
    {
        // ⚠️ Un fornitore solo per tutte le demo, non uno per ciascuna: i fornitori sono una
        // rubrica condivisa fra i condomini, e crearne uno nuovo a ogni prova riempirebbe l'elenco
        // di doppioni. La rimozione lo porta via quando non ha più fatture.
        $fornitore = \App\Models\Fornitore::firstOrCreate(
            ['ragione_sociale' => 'Impresa Manutenzioni Demo s.r.l.'],
            [
                'soggetto_ritenuta'          => true,
                'perc_imponibile_ritenuta'   => 100,
                'perc_ritenuta'              => 4,
                'giorni_scadenza'            => 30,
                'modalita_pagamento_default' => 'bonifico',
            ],
        );

        $service = app(\App\Services\Gestionale\FatturaPassivaService::class);

        $comune = [
            'fornitore_id'   => $fornitore->id,
            'esercizio_id'   => $this->esercizio->id,
            'gestione_id'    => $this->gestione->id,
            'data_documento' => now()->subDays(45)->format('Y-m-d'),
            'data_scadenza'  => now()->subDays(15)->format('Y-m-d'),
            // ⚠️ `FatturaPassivaService` legge questa chiave **senza valore di ripiego**, perché la
            // richiesta vera la garantisce. Ometterla produceva un avviso PHP e una modalità vuota
            // sulla fattura: è il genere di cosa che un seeder scritto a mano non scopre mai, e che
            // passando dalle porte vere salta fuori al primo giro.
            'modalita_pagamento' => 'bonifico',
        ];

        $fattura = $service->registraFattura(array_merge($comune, [
            'tipo_documento'   => 'fattura',
            // ⚠️ **Il numero porta il progressivo della demo, non un timestamp.**
            // `fatture_passive` ha un vincolo di unicità su fornitore + numero + data, e il
            // fornitore è condiviso fra le demo: due create nello stesso **secondo** collidevano,
            // e collidevano in silenzio, perché questo passo è protetto da un `try` che avvisa
            // invece di far saltare tutto il condominio. Provato, non dedotto.
            'numero_documento' => 'FT-2026-'.$this->progressivo,
            'righe'            => [[
                'descrizione'        => 'Manutenzione ascensore — canone annuo',
                'importo_imponibile' => 600,
                'aliquota_iva'       => 22,
                'conto_id'           => $this->voci['ascensore']->id,
                'is_sopravvenienza'  => false,
            ]],
        ]), $this->condominio->id);

        $nota = $service->registraFattura(array_merge($comune, [
            'tipo_documento'   => 'nota_credito',
            'numero_documento' => 'NC-2026-'.$this->progressivo,
            'data_documento'   => now()->subDays(20)->format('Y-m-d'),
            'righe'            => [[
                'descrizione'        => 'Storno per intervento non eseguito',
                'importo_imponibile' => 150,
                'aliquota_iva'       => 22,
                'conto_id'           => $this->voci['ascensore']->id,
                'is_sopravvenienza'  => false,
            ]],
        ]), $this->condominio->id);

        $creditoNota = abs((int) $nota->netto_a_pagare);
        $residuoFattura = (int) $fattura->residuo;
        $compensato = min($creditoNota, $residuoFattura);
        $perCassa = $residuoFattura - $compensato;

        $allocazioni = [];
        if ($perCassa > 0) {
            $allocazioni[] = ['fattura_id' => $fattura->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => $perCassa];
        }
        $allocazioni[] = ['fattura_id' => $fattura->id, 'tipo' => 'compensazione', 'importo_allocato_cents' => $compensato];
        $allocazioni[] = ['fattura_id' => $nota->id,    'tipo' => 'compensazione', 'importo_allocato_cents' => $compensato];

        app(\App\Services\Gestionale\PagamentoFornitoreService::class)->registraPagamento([
            'fornitore_id'                  => $fornitore->id,
            'condominio_id'                 => $this->condominio->id,
            'esercizio_id'                  => $this->esercizio->id,
            'conto_corrente_id'             => $this->casse['banca']->conto_contabile_id,
            'data_pagamento'                => now()->subDays(10)->format('Y-m-d'),
            'metodo_pagamento'              => 'bonifico',
            'iban_beneficiario'             => 'IT60X0542811101000000123456',
            'importo_commissioni_cents'     => 0,
            'bonifico_parlante'             => false,
            'allow_overdraft'               => true,
            'iban_confermato_manualmente'   => true,
            'conferma_duplicato_verificato' => true,
            'allocazioni'                   => $allocazioni,
        ]);
    }

    /**
     * Il giroconto che alimenta il fondo lavori.
     *
     * Mostra la cosa che più spesso viene fraintesa: **un fondo non è un secondo conto in banca**,
     * è una partizione dello stesso conto corrente. Il denaro non esce dal condominio, cambia
     * destinazione.
     */
    private function registraGiroconto(): void
    {
        app(\App\Actions\Gestionale\Movimenti\RegistraGirocontoAction::class)->execute([
            'cassa_origine_id'      => $this->casse['banca']->id,
            'cassa_destinazione_id' => $this->casse['fondo']->id,
            'importo'               => 1500.00,
            'data_operazione'       => now()->subDays(5)->format('Y-m-d'),
            'causale'               => 'Accantonamento per il rifacimento del lastrico',
            'gestione_id'           => $this->gestione->id,
            'esercizio_id'          => $this->esercizio->id,
        ], $this->condominio, $this->esercizio);
    }

    /**
     * Tre attività nell'Inbox operativa.
     *
     * ## Perché l'Inbox vuota era il difetto più grosso della demo
     *
     * ⚠️ **Su richiesta di Vincenzo** — *«perché non riempiamo l'activity inbox? quella è una chicca
     * di KondoManager»*. Aveva ragione, e per una ragione che va oltre l'estetica: l'Inbox è il
     * posto in cui il programma dice all'amministratore **cosa deve fare oggi**, ed è la differenza
     * fra un archivio contabile e un assistente. Una demo che la mostra vuota mostra il primo.
     *
     * ## Le tre sono scelte, non riempitivo
     *
     * Ognuna nasce da qualcosa che nel condominio dimostrativo **c'è davvero**, e insieme coprono i
     * tre motivi per cui l'Inbox si accende: una scadenza di legge, un ritardo di un condòmino, una
     * decisione da prendere. Un'attività che non corrisponde a niente sarebbe peggio di nessuna: chi
     * ci clicca sopra arriverebbe su una pagina che non spiega niente.
     */
    private function riempiInbox(): void
    {
        $autore = \Illuminate\Support\Facades\Auth::id()
            ?? \App\Models\User::query()->value('id');

        if (! $autore) {
            $this->avvisi[] = 'inbox: nessun utente a cui attribuire le attività';

            return;
        }

        $morosi = \Illuminate\Support\Facades\DB::table('rate_quote as rq')
            ->join('rate as r', 'r.id', '=', 'rq.rata_id')
            ->join('anagrafiche as a', 'a.id', '=', 'rq.anagrafica_id')
            ->where('r.piano_rate_id', $this->pianoRate->id)
            ->whereColumn('rq.importo_pagato', '<', 'rq.importo')
            ->select('a.nome', \Illuminate\Support\Facades\DB::raw('SUM(rq.importo - rq.importo_pagato) as residuo'))
            ->groupBy('a.nome')
            ->orderByDesc('residuo')
            ->get();

        $totaleMoroso = (int) $morosi->sum('residuo');

        // 1. La ritenuta d'acconto da versare: una scadenza di legge, con una data.
        \App\Services\Gestionale\InboxService::createTask(
            tipo: \App\Enums\EventoTipo::F24,
            title: 'Versare la ritenuta d\'acconto del mese',
            description: 'La fattura dell\'impresa di manutenzione ha una ritenuta del 4% trattenuta al '
                . 'fornitore. Va versata con il modello F24 entro il giorno 16 del mese successivo al pagamento.',
            scadenza: now()->addDays(6),
            createdByUserId: $autore,
            condominioId: $this->condominio->id,
            // ⚠️ **Senza `actionUrl` il pulsante «Risolvi» non compare**, e l'attività diventa una
            // nota che si può solo spuntare. Il cruscotto lo mostra solo se il collegamento c'è:
            // un'attività senza destinazione dice cosa fare ma non dove — cioè metà del lavoro.
            actionUrl: route('admin.gestionale.f24.index', ['condominio' => $this->condominio->id]),
            priorita: 'alta',
        );

        // 2. La morosità: nasce dall'incasso parziale che il seeder registra apposta.
        if ($totaleMoroso > 0) {
            $dettaglio = $morosi->take(3)
                ->map(fn ($m) => $m->nome.' € '.number_format($m->residuo / 100, 2, ',', '.'))
                ->implode(' · ');

            \App\Services\Gestionale\InboxService::createTask(
                tipo: \App\Enums\EventoTipo::CONTROLLO_INCASSI,
                title: 'Sollecitare le rate non ancora versate',
                // ⚠️ «Il piano», non «la prima rata»: gli incassi toccano solo la prima, ma il
                // residuo che si legge qui comprende anche la seconda, non ancora scaduta. Dire
                // «la prima rata» con il totale del piano sarebbe una frase falsa su un numero
                // vero — la forma di errore più difficile da smentire per chi legge.
                description: 'Il piano rate risulta incassato solo in parte. Residuo complessivo € '
                    . number_format($totaleMoroso / 100, 2, ',', '.').', fra rate scadute e a scadere. '
                    . 'I tre importi maggiori: '.$dettaglio,
                scadenza: now()->addDays(10),
                createdByUserId: $autore,
                condominioId: $this->condominio->id,
                actionUrl: route('admin.gestionale.movimenti-rate.index', ['condominio' => $this->condominio->id]),
            );
        }

        // 3. Il fondo lavori: una decisione da prendere, non un adempimento.
        \App\Services\Gestionale\InboxService::createTask(
            tipo: \App\Enums\EventoTipo::SCADENZA,
            title: 'Decidere quando avviare il rifacimento del lastrico',
            description: 'Il fondo lavori ha € 1.500,00 accantonati a fronte di una spesa preventivata '
                . 'di € 9.000,00. Prima di affidare l\'incarico serve la delibera assembleare e la '
                . 'copertura del resto.',
            scadenza: now()->addDays(30),
            createdByUserId: $autore,
            condominioId: $this->condominio->id,
            actionUrl: route('admin.gestionale.casse.index', ['condominio' => $this->condominio->id]),
        );
    }

    /**
     * Una fattura che sfora il budget della sua voce.
     *
     * ⚠️ **Su richiesta di Vincenzo**, e mostra una cosa che nessun altro passo mostra: cosa succede
     * quando la spesa reale supera il preventivo. La fattura resta **in attesa di ratifica** —
     * l'art. 1135 c.c. vuole una delibera o una motivazione d'urgenza — e nel piano dei conti la
     * voce si accende con il suo avviso.
     *
     * È il caso più comune della vita vera di un amministratore, ed era l'unico pezzo del cruscotto
     * che nel condominio dimostrativo restava spento.
     */
    private function registraFatturaInSforo(): void
    {
        $fornitore = \App\Models\Fornitore::firstOrCreate(
            ['ragione_sociale' => 'Idraulica Urgenze Demo s.n.c.'],
            ['soggetto_ritenuta' => false, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico'],
        );

        app(\App\Services\Gestionale\FatturaPassivaService::class)->registraFattura([
            'fornitore_id'       => $fornitore->id,
            'esercizio_id'       => $this->esercizio->id,
            'gestione_id'        => $this->gestione->id,
            'tipo_documento'     => 'fattura',
            'numero_documento'   => 'FT-URG-'.$this->progressivo,
            'data_documento'     => now()->subDays(8)->format('Y-m-d'),
            'data_scadenza'      => now()->addDays(22)->format('Y-m-d'),
            'modalita_pagamento' => 'bonifico',
            // ⚠️ L'importo supera di proposito il preventivo della voce «Manutenzione ascensore»
            // (€ 720,00): è ciò che fa scattare lo stato «in attesa di ratifica».
            'righe' => [[
                'descrizione'        => 'Riparazione urgente colonna montante',
                'importo_imponibile' => 1400,
                'aliquota_iva'       => 22,
                'conto_id'           => $this->voci['ascensore']->id,
                'is_sopravvenienza'  => false,
            ]],
            'dati_extra' => [
                'override_budget' => [
                    'motivo' => 'Intervento urgente per perdita in colonna montante: '
                              . 'autorizzato dall\'amministratore ai sensi dell\'art. 1135 c.c., '
                              . 'da ratificare nella prossima assemblea.',
                ],
            ],
        ], $this->condominio->id);
    }

    /**
     * Uno storno, e non è un dettaglio tecnico.
     *
     * ⚠️ **In contabilità non si cancella: si rettifica.** Un pagamento sbagliato non sparisce —
     * si registra una scrittura uguale e contraria che lo annulla, e restano visibili tutte e due.
     * È il principio su cui è costruito tutto il gestionale, e finché il condominio dimostrativo non
     * ne mostrava uno, chi lo guardava non aveva modo di scoprirlo.
     *
     * ⚠️ **Lo storno ha una fattura sua, e la prima stesura sbagliava.** Avevo pensato di stornare
     * il pagamento della fattura in sforo: non si può, e il seeder me l'ha detto al primo giro —
     * *«le seguenti fatture non sono ancora approvate»*. È il comportamento **giusto**: una spesa
     * fuori budget non si paga finché l'assemblea non l'ha ratificata. Quindi la fattura in sforo
     * resta da ratificare (che è quello che deve mostrare) e lo storno lavora su una spesa
     * ordinaria.
     *
     * Non si storna nemmeno il pagamento con la nota di credito: quello è l'esempio della
     * compensazione, e deve restare intatto.
     */
    private function stornaUnPagamento(): void
    {
        $fornitore = \App\Models\Fornitore::firstOrCreate(
            ['ragione_sociale' => 'Pulizie Aurora Demo s.r.l.'],
            ['soggetto_ritenuta' => false, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico'],
        );

        $service = app(\App\Services\Gestionale\PagamentoFornitoreService::class);

        $urgente = app(\App\Services\Gestionale\FatturaPassivaService::class)->registraFattura([
            'fornitore_id'       => $fornitore->id,
            'esercizio_id'       => $this->esercizio->id,
            'gestione_id'        => $this->gestione->id,
            'tipo_documento'     => 'fattura',
            'numero_documento'   => 'FT-PUL-'.$this->progressivo,
            'data_documento'     => now()->subDays(30)->format('Y-m-d'),
            'data_scadenza'      => now()->format('Y-m-d'),
            'modalita_pagamento' => 'bonifico',
            'righe' => [[
                'descrizione'        => 'Pulizia scale — primo trimestre',
                'importo_imponibile' => 240,
                'aliquota_iva'       => 22,
                'conto_id'           => $this->voci['pulizie']->id,
                'is_sopravvenienza'  => false,
            ]],
        ], $this->condominio->id);

        // Prima lo si paga — non si può stornare quello che non è mai stato registrato.
        $pagamento = $service->registraPagamento([
            'fornitore_id'                  => $urgente->fornitore_id,
            'condominio_id'                 => $this->condominio->id,
            'esercizio_id'                  => $this->esercizio->id,
            'conto_corrente_id'             => $this->casse['banca']->conto_contabile_id,
            'data_pagamento'                => now()->subDays(4)->format('Y-m-d'),
            'metodo_pagamento'              => 'bonifico',
            'iban_beneficiario'             => 'IT60X0542811101000000123456',
            'importo_commissioni_cents'     => 0,
            'bonifico_parlante'             => false,
            'allow_overdraft'               => true,
            'iban_confermato_manualmente'   => true,
            'conferma_duplicato_verificato' => true,
            'allocazioni' => [[
                'fattura_id'             => $urgente->id,
                'tipo'                   => 'pagamento',
                'importo_allocato_cents' => (int) $urgente->residuo,
            ]],
        ]);

        // …e poi lo si annulla, con il motivo: è quello che resta agli atti.
        $service->stornaPagamento(
            $pagamento,
            'Bonifico disposto due volte per errore: il secondo è stato revocato dalla banca.',
            $this->esercizio->id,
        );
    }

    /**
     * Calcola lo scadenzario delle ritenute.
     *
     * ⚠️ **Senza questo la pagina F24 del condominio dimostrativo era vuota**, con un pulsante
     * arancione «Aggiorna scadenze» e l'avviso «1 ritenuta operata non è ancora in una delega».
     * Coerente — quel pulsante esiste apposta — ma per una demo è il risultato sbagliato: l'attività
     * in Inbox dice «versa la ritenuta», si preme «Risolvi», e si arriva su una schermata che dice
     * di non avere niente da versare.
     *
     * Trovato da Vincenzo guardando la pagina, non da nessuna prova.
     */
    private function generaScadenzarioF24(): void
    {
        app(\App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action::class)
            ->esegui($this->condominio, $this->esercizio->id);
    }

    /**
     * Una sopravvenienza: la spesa che il preventivo non conteneva.
     *
     * ⚠️ **Su richiesta di Vincenzo**, ed è la voce che accende la **sezione gialla** del piano dei
     * conti — che nel condominio dimostrativo restava spenta.
     *
     * È un caso diverso dallo sforo, e la differenza conta: lo **sforo** è una voce che esisteva a
     * preventivo e che la spesa reale ha superato; la **sopravvenienza** è una spesa che a
     * preventivo non c'era affatto — un guasto, un danno, un obbligo arrivato dopo. Non supera un
     * budget: **non ne ha uno**, e per essere pagata servono rate nuove.
     *
     * L'art. 1130-bis c.c. le tratta a parte proprio per questo, e il programma le raccoglie in un
     * capitolo generato da sé invece di sporcare il preventivo deliberato.
     */
    private function registraSopravvenienza(): void
    {
        $fornitore = \App\Models\Fornitore::firstOrCreate(
            ['ragione_sociale' => 'Vetreria Rapida Demo s.r.l.'],
            ['soggetto_ritenuta' => false, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico'],
        );

        app(\App\Services\Gestionale\FatturaPassivaService::class)->registraFattura([
            'fornitore_id'       => $fornitore->id,
            'esercizio_id'       => $this->esercizio->id,
            'gestione_id'        => $this->gestione->id,
            'tipo_documento'     => 'fattura',
            'numero_documento'   => 'FT-SOP-'.$this->progressivo,
            'data_documento'     => now()->subDays(12)->format('Y-m-d'),
            'data_scadenza'      => now()->addDays(18)->format('Y-m-d'),
            'modalita_pagamento' => 'bonifico',
            'righe' => [[
                'descrizione'        => 'Sostituzione vetrata dell\'androne dopo atto vandalico',
                'importo_imponibile' => 1500,
                'aliquota_iva'       => 22,
                // ⚠️ Nessun `conto_id`: è il punto. La voce **non esiste** a preventivo, e il
                // programma la crea da sé sotto un capitolo tecnico, a partire dal log qui sotto.
                'is_sopravvenienza'  => true,
            ]],
            'dati_extra' => [
                'log_legale_sopravvenienza' => [
                    'nome_voce'           => 'Sostituzione vetrata androne',
                    // ⚠️ `conti.origine_decisionale` è un enum a **due** valori:
                    // `gestione_corrente` e `delibera_assembleare`. Un terzo valore viene troncato
                    // in silenzio da MySQL — scoperto al primo giro, perché il seeder passa dalla
                    // porta vera e la porta ha detto di no.
                    'origine_decisionale' => 'gestione_corrente',
                    'tipo_ripartizione'   => 'millesimale',
                    // ⚠️ **Obbligatoria quando la ripartizione è millesimale**:
                    // `StoreFatturaRequest:90` la impone con `required_if`. Qui il seeder
                    // chiama il service e non passa dalla FormRequest, quindi la regola non
                    // scattava e `FatturaPassivaService:969` scartava la tabella in silenzio
                    // (`! empty(...)`). Risultato: il conto della sopravvenienza nasceva con
                    // **zero** tabelle collegate, `CalcoloQuoteService` registrava l'importo
                    // come scoperto e non produceva quote — e il pulsante «Finanzia Spesa»
                    // che il cruscotto mette accanto alla sezione gialla portava a un errore.
                    'tabella_millesimale_id' => $this->tabelle['proprieta']->id,
                    'motivazione_sforo'   => 'Vetrata dell\'androne infranta durante la notte: '
                                           . 'sostituzione disposta d\'urgenza per la sicurezza '
                                           . 'dell\'accesso, da ratificare in assemblea.',
                ],
            ],
        ], $this->condominio->id);
    }
}

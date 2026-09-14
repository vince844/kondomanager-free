<?php

namespace Database\Seeders;

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Enums\TipoCassa;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CondominioService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Il condominio di prova delle stampe di riparto.
 *
 * ## Da dove viene
 *
 * Un amministratore ha messo a confronto, sullo stesso condominio, la stampa del riparto per
 * tabella di KondoManager e quella del programma che usava prima: «bello da vedere quello di KM
 * ma solo da lontano… prova a stamparlo su carta e capisci subito». Ne abbiamo tenuto la **forma**,
 * che è quella più comune di un condominio vero e più larga di qualunque fixture che avevamo:
 * **38 unità** (17 appartamenti, 17 box, 4 cantine) per **48 righe** soggetto × unità, con **sei
 * tabelle** — acqua a consumo in metri cubi, acqua a quote fisse, una generale ridotta, generale,
 * interrato, scale — e un preventivo intorno ai € 18.000. Fino a qui la stampa era stata misurata
 * su quattro unità e tre tabelle.
 *
 * ⚠️ **Tutto il resto è costruito.** Nomi, sede, numerazione delle unità, millesimi, consumi,
 * importi e chi possiede o abita cosa sono inventati qui, con la stessa distribuzione di un
 * condominio vero (sei appartamenti e quattro box affittati, proprietari con più unità, una
 * coppia registrata con un nome solo, un ente fra gli inquilini): un file con dati veri non entra
 * in un repository, per nessuna ragione, e un esempio si costruisce, non si preleva. I nomi
 * ripetuti su più righe servono a vedere se la stampa regge.
 *
 * ## A cosa serve
 *
 * È la fixture con cui si misura la **leggibilità** delle stampe (pagine, formato, corpo del
 * carattere) e con cui si tengono ferme le loro garanzie numeriche su una forma vera. Per la
 * verifica a video si semina nel database di sviluppo con `php artisan db:seed --class=CondominioStampeSeeder`;
 * porta `is_demo = true`, quindi si rimuove con `CreaCondominioDimostrativoAction::rimuovi()` —
 * e, finché c'è, occupa il posto della demo: «Crea condominio dimostrativo» risponde che esiste
 * già. È l'effetto voluto del riuso di quella rimozione, non un difetto.
 *
 * Costruito sul modello di `CondominioDemoSeeder`: stesse porte del prodotto (il service per il
 * condominio, l'azione per la cassa, il motore vero per il piano rate), stesse ragioni.
 */
class CondominioStampeSeeder extends Seeder
{
    private Condominio $condominio;
    private Esercizio $esercizio;
    private $gestione;
    /** @var array<string, Immobile> */
    private array $unita = [];
    /** @var array<string, Anagrafica> */
    private array $persone = [];
    /** @var array<string, Tabella> */
    private array $tabelle = [];
    private ?PianoConto $pianoConto = null;
    /** @var array<string, Conto> */
    private array $voci = [];
    private ?PianoRate $pianoRate = null;
    private int $progressivo = 1;

    public function run(): void
    {
        $this->creaCondominio();
        $this->creaUnita();
        $this->creaPersoneETitolarita();
        $this->creaTabelle();
        $this->creaCassa();
        $this->creaPreventivo();
        $this->generaPianoRate();
    }

    /**
     * Undici cifre con la cifra di controllo (lo schema delle partite IVA): finto ma ben formato,
     * così una futura validazione del campo non lo rifiuta.
     */
    private static function codiceFiscaleNumerico(string $dieciCifre): string
    {
        $somma = 0;
        foreach (str_split($dieciCifre) as $i => $cifra) {
            $v = (int) $cifra;
            if ($i % 2 === 1) {
                $v *= 2;
                $v = $v > 9 ? $v - 9 : $v;
            }
            $somma += $v;
        }

        return $dieciCifre.((10 - $somma % 10) % 10);
    }

    public function condominio(): Condominio
    {
        return $this->condominio;
    }

    public function pianoRate(): PianoRate
    {
        return $this->pianoRate;
    }

    // ── 1. Il condominio ─────────────────────────────────────────────────────

    private function creaCondominio(): void
    {
        $progressivo = 1;
        while (Condominio::where('codice_identificativo', 'STAMPE-'.$progressivo)->exists()) {
            $progressivo++;
        }
        $this->progressivo = $progressivo;

        $this->condominio = app(CondominioService::class)->createCondominioWithEsercizio([
            'codice_identificativo' => 'STAMPE-'.$progressivo,
            'codice_fiscale'        => self::codiceFiscaleNumerico('910'.str_pad((string) $progressivo, 7, '0', STR_PAD_LEFT)),
            'nome'                  => 'Condominio Prova Stampe'.($progressivo > 1 ? ' '.$progressivo : ''),
            'indirizzo'             => 'Via dei Platani 12',
            'comune'                => 'Pesaro',
            'provincia'             => 'PU',
            'cap'                   => '61121',
            'email'                 => 'stampe-'.$progressivo.'@kondomanager.test',
            'is_demo'               => true,
            'anno_costruzione'      => 2004,
            'numero_piani'          => 5,
            'note'                  => "Condominio di prova per le stampe di riparto: 38 unità, 48 righe, sei tabelle.\n"
                                     . "Ha la forma di un condominio vero; nomi e numeri sono inventati.",
        ]);

        $this->esercizio = $this->condominio->esercizi()->latest('id')->firstOrFail();
        $this->gestione  = $this->esercizio->gestioni()->firstOrFail();
    }

    // ── 2. Le unità: 17 appartamenti, 17 box, 4 cantine ──────────────────────

    /**
     * Appartamenti AP01–AP17, box BO01–BO19 con due buchi (07 e 13: due box accorpati, come
     * capita), cantine CA01–CA04. I buchi non sono un vezzo: la numerazione dei nomi («Box 8»,
     * «Box 9», «Box 10») non è continua e la stampa, che ordina per interno e poi per nome in
     * ordine naturale (il codice è solo spareggio), deve reggerla.
     *
     * @return array<string, array{tipo: string, nome: string, interno: ?string, piano: string}>
     */
    private function definizioniUnita(): array
    {
        $unita = [];
        $piani = ['Piano terra', 'Primo piano', 'Primo piano', 'Secondo piano', 'Secondo piano', 'Terzo piano'];
        for ($i = 1; $i <= 17; $i++) {
            $codice = sprintf('AP%02d', $i);
            $unita[$codice] = ['tipo' => 'Abitazione', 'nome' => 'Appartamento '.$i, 'interno' => (string) $i, 'piano' => $piani[intdiv($i - 1, 3)] ?? 'Quarto piano'];
        }
        foreach ([1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12, 14, 15, 16, 17, 18, 19] as $n) {
            $unita[sprintf('BO%02d', $n)] = ['tipo' => 'Box', 'nome' => 'Box '.$n, 'interno' => null, 'piano' => 'Interrato'];
        }
        foreach ([1, 2, 3, 4] as $n) {
            $unita[sprintf('CA%02d', $n)] = ['tipo' => 'Cantina', 'nome' => 'Cantina '.$n, 'interno' => null, 'piano' => 'Interrato'];
        }

        return $unita;
    }

    private function creaUnita(): void
    {
        $tipologie = DB::table('tipologie_immobili')->pluck('id', 'nome');
        $ripiego   = $tipologie->first();

        foreach ($this->definizioniUnita() as $codice => $d) {
            $immobile = new Immobile([
                'condominio_id' => $this->condominio->id,
                'tipologia_id'  => $tipologie[$d['tipo']] ?? $ripiego,
                'nome'          => $d['nome'],
                'interno'       => $d['interno'],
                'piano'         => $d['piano'],
            ]);
            // ⚠️ `codice_immobile` non è fillable di proposito (`Immobile.php`: lo genera
            // `booted()` come `C<id>-0001`) e ha un indice UNIQUE **globale**, non per condominio:
            // si assegna come proprietà, così vale anche nei test dove i modelli sono guarded, e
            // porta il progressivo perché «AP01» nudo collide alla seconda semina.
            $immobile->codice_immobile = 'S'.$this->progressivo.'-'.$codice;
            $immobile->save();
            $this->unita[$codice] = $immobile;
        }
    }

    // ── 3. Le persone e chi possiede o abita cosa ─────────────────────────────

    /**
     * Ventuno persone per quarantotto righe. Sei appartamenti sono affittati (proprietario più
     * inquilino), quattro box pure; parecchi proprietari hanno l'appartamento, un box e una
     * cantina; quattro unità sono intestate a una coppia con un nome solo, come fa chi registra
     * «la famiglia» invece di due comproprietari; un inquilino è un ente e non una persona.
     *
     * @return array<string, string>
     */
    private function definizioniPersone(): array
    {
        return [
            'q01' => 'Serena Lombardi',
            'q02' => 'Tommaso Grandi',                 // inquilino di AP03 e BO04
            'q03' => 'Ilaria Bosco',                   // proprietaria di AP03 e BO04
            'q04' => 'Riccardo Bellini',               // inquilino di AP06 e BO09
            'q05' => 'Giuliana Bellini',               // proprietaria di AP05, AP06 e BO09
            'q06' => 'Martina Ricci',                  // appartamento, box e cantina
            'q07' => 'Luca Toscani',                   // inquilino di AP07 e BO05
            'q08' => 'Antonietta Pagliaro',            // proprietaria di AP07 e BO05
            'q09' => 'Stefano Ortolani',
            'q10' => 'Giada Rizzato',                  // inquilina di AP12
            'q11' => 'Ottavio e Lucia Mancuso',        // coppia con un nome solo: AP12, AP13 e BO15
            'q12' => 'Youssef Benali',                 // inquilino di AP14 e BO16
            'q13' => 'Ernesto Galli e Rosa Ferrante',  // proprietari di AP14 e BO16
            'q14' => 'Paolo e Michela Rinaldi',
            'q15' => 'Andrea Colombo',                 // un appartamento e due box
            'q16' => 'Cooperativa Prova Uno',          // inquilino di AP16: un ente, non una persona
            'q17' => 'Ornella e Maurizio Ferri',       // proprietari di AP16 e BO11
            'q18' => 'Simona Lazzari',                 // due appartamenti, due box, una cantina
            'q19' => 'Renato Bruni e Patrizia Serra',
            'q20' => 'Silvia Caputo',
            'q21' => 'Loredana Fabbri',                // due box in fondo alla rampa, fuori dalla generale ridotta
        ];
    }

    /**
     * [unità, persona, ruolo]. Un'unità con due righe ha un proprietario e un inquilino.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function titolarita(): array
    {
        return [
            ['AP01', 'q01', 'proprietario'],
            ['AP02', 'q06', 'proprietario'],
            ['AP03', 'q02', 'inquilino'], ['AP03', 'q03', 'proprietario'],
            ['AP04', 'q09', 'proprietario'],
            ['AP05', 'q05', 'proprietario'],
            ['AP06', 'q04', 'inquilino'], ['AP06', 'q05', 'proprietario'],
            ['AP07', 'q07', 'inquilino'], ['AP07', 'q08', 'proprietario'],
            ['AP08', 'q14', 'proprietario'],
            ['AP09', 'q15', 'proprietario'],
            ['AP10', 'q18', 'proprietario'],
            ['AP11', 'q18', 'proprietario'],
            ['AP12', 'q10', 'inquilino'], ['AP12', 'q11', 'proprietario'],
            ['AP13', 'q11', 'proprietario'],
            ['AP14', 'q12', 'inquilino'], ['AP14', 'q13', 'proprietario'],
            ['AP15', 'q19', 'proprietario'],
            ['AP16', 'q16', 'inquilino'], ['AP16', 'q17', 'proprietario'],
            ['AP17', 'q20', 'proprietario'],
            ['BO01', 'q01', 'proprietario'],
            ['BO02', 'q06', 'proprietario'],
            ['BO03', 'q09', 'proprietario'],
            ['BO04', 'q02', 'inquilino'], ['BO04', 'q03', 'proprietario'],
            ['BO05', 'q07', 'inquilino'], ['BO05', 'q08', 'proprietario'],
            ['BO06', 'q14', 'proprietario'],
            ['BO08', 'q15', 'proprietario'],
            ['BO09', 'q04', 'inquilino'], ['BO09', 'q05', 'proprietario'],
            ['BO10', 'q15', 'proprietario'],
            ['BO11', 'q17', 'proprietario'],
            ['BO12', 'q18', 'proprietario'],
            ['BO14', 'q18', 'proprietario'],
            ['BO15', 'q11', 'proprietario'],
            ['BO16', 'q12', 'inquilino'], ['BO16', 'q13', 'proprietario'],
            ['BO17', 'q20', 'proprietario'],
            ['BO18', 'q21', 'proprietario'],
            ['BO19', 'q21', 'proprietario'],
            ['CA01', 'q06', 'proprietario'],
            ['CA02', 'q18', 'proprietario'],
            ['CA03', 'q19', 'proprietario'],
            ['CA04', 'q20', 'proprietario'],
        ];
    }

    private function creaPersoneETitolarita(): void
    {
        $n = $this->progressivo;
        $i = 0;

        // ⚠️ Niente factory (Faker non c'è nel pacchetto distribuito: vedi CondominioDemoSeeder)
        // e niente colonne UNIQUE lasciate al caso: email, PEC e codice fiscale sono derivati
        // dalla chiave e dal progressivo, così due semine non collidono. Il codice fiscale delle
        // persone è volutamente **non valido** (prefisso STMP, codice catastale inesistente): non
        // deve poter essere scambiato per quello di qualcuno.
        foreach ($this->definizioniPersone() as $chiave => $nome) {
            $i++;
            $slug = 'stampe'.$n.'.'.$chiave;
            $this->persone[$chiave] = Anagrafica::create([
                'user_id'        => null,
                'nome'           => $nome,
                'indirizzo'      => 'Via dei Platani 12',
                'email'          => $slug.'@kondomanager.test',
                'pec'            => $slug.'@pec.kondomanager.test',
                'codice_fiscale' => 'STMP'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).str_pad((string) $i, 2, '0', STR_PAD_LEFT).'A01Z999'.chr(65 + ($i % 26)),
                'telefono'       => '0721 '.str_pad((string) ($n * 100 + $i), 6, '0', STR_PAD_LEFT),
                'luogo_nascita'  => 'Pesaro',
                'data_nascita'   => '1970-01-01',
            ]);
            // Agganciata al condominio qui, non solo quando compare in una titolarità: una persona
            // senza aggancio resterebbe orfana alla rimozione e farebbe fallire la risemina
            // sull'indice UNIQUE del codice fiscale.
            $this->persone[$chiave]->condomini()->syncWithoutDetaching([$this->condominio->id]);
        }

        foreach ($this->titolarita() as [$unita, $persona, $ruolo]) {
            DB::table('anagrafica_immobile')->insert([
                'anagrafica_id' => $this->persone[$persona]->id,
                'immobile_id'   => $this->unita[$unita]->id,
                'tipologia'     => $ruolo,
                'quota'         => 100,
                'attivo'        => true,
                'data_inizio'   => now()->subYears(3),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    // ── 4. Le sei tabelle ────────────────────────────────────────────────────

    /**
     * Valori costruiti con la forma di un condominio vero: i consumi d'acqua in metri cubi (1.590
     * in tutto, da 1 a 259), una quota fissa per appartamento (17), tre tabelle millesimali che
     * chiudono a mille (la generale ridotta, la generale, le scale) e una — l'interrato — che
     * **non** chiude a mille perché è la parte della generale che spetta a box e cantine (120,8):
     * capita in tanti condomìni, e la stampa deve reggerlo. La generale ridotta è la generale
     * senza gli ultimi due box, rinormalizzata a mille.
     *
     * @return array<string, array{nome: string, quota: string, valori: array<string, float>}>
     */
    private function definizioniTabelle(): array
    {
        $ap = fn (array $v) => array_combine(array_map(fn ($i) => sprintf('AP%02d', $i), range(1, 17)), $v);
        $box = ['BO01' => 8.4, 'BO02' => 6.3, 'BO03' => 5.4, 'BO04' => 7.8, 'BO05' => 5.9, 'BO06' => 5.9, 'BO08' => 5.3, 'BO09' => 6.4,
                'BO10' => 5.1, 'BO11' => 8.1, 'BO12' => 6.1, 'BO14' => 7.8, 'BO15' => 5.8, 'BO16' => 8.3, 'BO17' => 8.0, 'BO18' => 8.5,
                'BO19' => 8.4];
        $cantine = ['CA01' => 0.7, 'CA02' => 0.9, 'CA03' => 0.9, 'CA04' => 0.8];

        return [
            'acqua' => [
                'nome'   => 'Acqua — consumi',
                'quota'  => 'mtcubi',
                'valori' => $ap([2, 36, 1, 62, 56, 53, 124, 135, 97, 156, 43, 259, 32, 125, 76, 97, 236]),
            ],
            'acqua_fisso' => [
                'nome'   => 'Acqua — quota fissa',
                'quota'  => 'quote',
                'valori' => $ap(array_fill(0, 17, 1)),
            ],
            'escluse' => [
                'nome'   => 'Generale ridotta', // la generale senza gli ultimi due box
                'quota'  => 'millesimi',
                'valori' => $ap([34.4, 55.2, 28.8, 47.0, 51.9, 81.1, 38.8, 38.6, 63.0, 44.2, 68.4, 32.6, 66.8, 45.8, 42.9, 72.1, 83.0])
                          + ['BO01' => 8.5, 'BO02' => 6.4, 'BO03' => 5.5, 'BO04' => 7.9, 'BO05' => 6.0, 'BO06' => 6.0, 'BO08' => 5.4, 'BO09' => 6.5,
                             'BO10' => 5.2, 'BO11' => 8.2, 'BO12' => 6.2, 'BO14' => 7.9, 'BO15' => 5.9, 'BO16' => 8.4, 'BO17' => 8.1]
                          + $cantine,
            ],
            'generale' => [
                'nome'   => 'Generale',
                'quota'  => 'millesimi',
                'valori' => $ap([33.8, 54.3, 28.3, 46.2, 51.0, 79.7, 38.1, 37.9, 61.9, 43.5, 67.2, 32.0, 65.7, 45.0, 42.2, 70.9, 81.5]) + $box + $cantine,
            ],
            'interrato' => [
                'nome'   => 'Interrato',
                'quota'  => 'millesimi',
                'valori' => $box + $cantine,
            ],
            'scale' => [
                'nome'   => 'Scale',
                'quota'  => 'millesimi',
                'valori' => $ap([115.51, 55.38, 71.22, 90.95, 40.77, 20.09, 95.45, 75.78, 89.73, 64.49, 19.67, 36.34, 16.27, 43.88, 77.63, 64.92, 21.92]),
            ],
        ];
    }

    private function creaTabelle(): void
    {
        foreach ($this->definizioniTabelle() as $chiave => $d) {
            $tabella = Tabella::create([
                'condominio_id' => $this->condominio->id,
                'nome'          => $d['nome'],
                'tipo'          => 'standard',
                'quota'         => $d['quota'],
                'attiva'        => true,
            ]);

            foreach ($d['valori'] as $codice => $valore) {
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

    // ── 5. La cassa ──────────────────────────────────────────────────────────

    private function creaCassa(): void
    {
        app(\App\Actions\Cassa\CreateCassaAction::class)->execute($this->condominio, [
            'nome'           => 'Conto corrente condominiale',
            'tipo'           => TipoCassa::BANCA->value,
            'saldo_iniziale' => '0,00',
            'istituto'       => 'Banca di prova',
            'iban'           => 'IT07X0542811101000000654321', // cifre di controllo mod 97 corrette
            'intestatario'   => $this->condominio->nome,
            'predefinito'    => true,
        ]);
    }

    // ── 6. Il preventivo: una voce per tabella, € 18.350,00 in tutto ─────────

    /**
     * La ripartizione fra proprietario e inquilino è quella d'uso: l'acqua, le scale e
     * l'interrato sono dell'inquilino dove c'è; la generale ridotta due terzi a lui e un terzo
     * al proprietario; la generale quasi tutta al proprietario. Dove non c'è un inquilino, la
     * cascata dei ruoli porta tutto al proprietario. Gli importi sono in centesimi.
     */
    private function creaPreventivo(): void
    {
        $this->pianoConto = PianoConto::create([
            'condominio_id' => $this->condominio->id,
            'gestione_id'   => $this->gestione->id,
            'nome'          => 'Preventivo '.now()->year,
        ]);

        // Cinque capitoli radice: il riparto per capitolo aggrega sul capitolo di primo livello, e
        // con un capitolo solo uscirebbe a una colonna — la fixture deve esercitare anche quella
        // stampa. Cinque e non sei perché i centesimi che la cascata non assegna aggiungono la
        // colonna «Fuori riparto» (Code 77/78), e sette colonne fanno due blocchi. L'acqua ha due
        // voci su due tabelle diverse: è il capitolo a quota mista, quello che stampa «—».
        $voci = [
            ['acqua',       'Acqua',                'Acqua — consumi',                    235000, ['acqua'       => 100], ['inquilino' => 100]],
            ['acqua_fisso', 'Acqua',                'Acqua — quota fissa',                170000, ['acqua_fisso' => 100], ['inquilino' => 100]],
            ['escluse',     'Generale ridotta',     'Spese generali (senza box 18 e 19)',  80000, ['escluse'     => 100], ['inquilino' => 66.67, 'proprietario' => 33.33]],
            ['generale',    'Spese generali',       'Spese generali',                     615000, ['generale'    => 100], ['proprietario' => 86, 'inquilino' => 14]],
            ['interrato',   'Interrato',            'Interrato — box e cantine',           45000, ['interrato'   => 100], ['inquilino' => 100]],
            ['scale',       'Scale e ascensore',    'Scale e ascensore',                  690000, ['scale'       => 100], ['inquilino' => 100]],
        ];

        $contoCosti = ContoContabile::where('condominio_id', $this->condominio->id)
            ->where('ruolo', 'costi_servizi')
            ->value('id');

        $capitoli = [];
        foreach ($voci as [$chiave, $nomeCapitolo, $nome, $importo, $tabelle, $ripartizioni]) {
            $capitolo = $capitoli[$nomeCapitolo] ??= Conto::create([
                'piano_conto_id' => $this->pianoConto->id,
                'parent_id'      => null,
                'is_capitolo'    => true,
                'nome'           => $nomeCapitolo,
                'tipo'           => 'spesa',
                'importo'        => 0,
            ]);
            $conto = Conto::create([
                'piano_conto_id'     => $this->pianoConto->id,
                'parent_id'          => $capitolo->id,
                'is_capitolo'        => false,
                'nome'               => $nome,
                'tipo'               => 'spesa',
                'importo'            => $importo,
                'conto_contabile_id' => $contoCosti,
            ]);

            foreach ($tabelle as $tabella => $coefficiente) {
                $associazioneId = DB::table('conto_tabella_millesimale')->insertGetId([
                    'conto_id'     => $conto->id,
                    'tabella_id'   => $this->tabelle[$tabella]->id,
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

            $this->voci[$chiave] = $conto;
        }
    }

    // ── 7. Il piano rate, dal motore vero ────────────────────────────────────

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

        $piano->capitoli()->sync(
            collect($this->voci)
                ->mapWithKeys(fn (Conto $voce) => [$voce->id => ['importo' => $voce->importo]])
                ->all()
        );

        app(GeneratePianoRateAction::class)->execute($piano, accettaScoperti: false);

        $piano->update(['stato' => \App\Enums\StatoPianoRate::APPROVATO->value]);
        $this->pianoRate = $piano->refresh();
    }
}

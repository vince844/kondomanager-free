<?php

/**
 * Lo scenario condiviso dei test della 1.11.0-beta.51 sul piano rate e sulla nota (§6.2, §6.3, Coda 172).
 *
 * Costruisce un condominio in poche righe (unità, tabelle, gestioni, voci, piani, titolari) e fa le operazioni vere del programma:
 * i passaggi, l'annullamento, l'emissione e il ricalcolo passano dalle rotte HTTP da amministratore; la generazione del piano
 * chiama `GeneratePianoRateAction`, come i test esistenti (`ruScenario`, `spScenario`). È autosufficiente: `tests/Lab/` non è
 * tracciato nel repository e i test della suite non possono dipenderne.
 *
 * Non è un test: il nome non finisce in `Test.php` e Pest non lo raccoglie. Lo caricano con `require_once` i file
 * `Beta51PianoRate*Test.php`, così ognuno passa anche lanciato da solo.
 *
 * Tutto il denaro è in centesimi, positivo = debito. Le date sono stringhe `Y-m-d`.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Exceptions\Gestionale\DestinatariCambiatiException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/GestionaleTestHelpers.php';

final class Beta51Scenario
{
    public Condominio $c;
    public Esercizio $e;
    public User $admin;
    public ?Cassa $cassa = null;

    /** @var array<string, Immobile> */
    public array $unita = [];
    /** @var array<string, Anagrafica> */
    public array $persone = [];
    /** @var array<string, Tabella> */
    public array $tabelle = [];
    /** @var array<string, array{g: Gestione, tipo: string, pc: PianoConto}> */
    public array $gestioni = [];
    /** @var array<string, array{conto: Conto, gestione: string, importo: int}> */
    public array $voci = [];
    /** @var array<string, array{p: PianoRate, gestione: string, tipo: string}> */
    public array $piani = [];

    private int $n = 0;
    private static int $istanze = 0;
    private ?int $fornitoreId = null;

    private function __construct(public readonly TestCase $t)
    {
    }

    // --- Il costruttore --------------------------------------------------------------------------------------------------

    /** Un condominio vuoto con l'esercizio 2026 aperto e l'amministratore che opera. */
    public static function nuovo(TestCase $t): self
    {
        $s = new self($t);
        self::$istanze++;
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
        $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $ruolo->givePermissionTo($permesso);
        $s->admin = User::factory()->create();
        $s->admin->assignRole($ruolo);
        $s->c = Condominio::factory()->create(['nome' => 'Condominio beta 51 n. ' . self::$istanze]);
        $s->e = Esercizio::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);

        return $s;
    }

    /** Una tabella millesimale con le unità e i loro millesimi; le unità che non esistono ancora si creano. */
    public function tabella(string $nome, array $millesimi): self
    {
        $t = Tabella::create(['condominio_id' => $this->c->id, 'nome' => $nome, 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
        $this->tabelle[$nome] = $t;
        foreach ($millesimi as $u => $valore) {
            $this->unitaSeManca($u);
            DB::table('quote_tabella')->insert(['tabella_id' => $t->id, 'immobile_id' => $this->unita[$u]->id, 'valore' => (float) $valore, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $this;
    }

    /** Un'unità (appartamento) o una pertinenza (`$di` = l'unità principale). */
    public function unitaSeManca(string $nome, ?string $di = null): Immobile
    {
        if (! isset($this->unita[$nome])) {
            $this->unita[$nome] = Immobile::forceCreate([
                'condominio_id' => $this->c->id, 'codice_immobile' => "B51-{$this->c->id}-{$nome}",
                'nome' => $di === null ? "Interno {$nome}" : "Box {$nome}", 'descrizione' => '', 'interno' => $nome,
                'pertinenza_di_immobile_id' => $di === null ? null : $this->unitaSeManca($di)->id,
            ]);
        }

        return $this->unita[$nome];
    }

    /** La pertinenza (box) di un'unità: va dichiarata prima della tabella che le dà i millesimi. */
    public function pertinenza(string $box, string $di): self
    {
        $this->unitaSeManca($box, $di);

        return $this;
    }

    /** Una persona, creata alla prima volta che serve e agganciata al condominio. */
    public function persona(string $chi): Anagrafica
    {
        if (! isset($this->persone[$chi])) {
            $this->n++;
            $cf = strtoupper(substr(str_pad(preg_replace('/[^a-z]/i', '', $chi), 4, 'X'), 0, 4)) . 'BCQ' . str_pad((string) $this->c->id, 4, '0', STR_PAD_LEFT) . str_pad((string) $this->n, 5, '0', STR_PAD_LEFT);
            $this->persone[$chi] = Anagrafica::forceCreate(['nome' => ucfirst($chi), 'email' => "b51-{$chi}-{$this->c->id}@test.it", 'indirizzo' => 'Via della Prova 1', 'codice_fiscale' => $cf]);
            $this->persone[$chi]->condomini()->syncWithoutDetaching([$this->c->id]);
        }

        return $this->persone[$chi];
    }

    /** Un titolare iniziale, censito: proprietario (quota 100) o altro ruolo. */
    public function titolare(string $unita, string $chi, string $tipologia = 'proprietario', float $quota = 100, string $dal = '2019-01-01'): self
    {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $this->persona($chi)->id, 'immobile_id' => $this->unitaSeManca($unita)->id, 'tipologia' => $tipologia, 'quota' => $quota,
            'attivo' => true, 'data_inizio' => $dal, 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

        return $this;
    }

    /** Una gestione dell'esercizio, ordinaria o straordinaria, con il suo piano dei conti. */
    public function gestione(string $nome, string $tipo = 'ordinaria'): self
    {
        $g = Gestione::factory()->create(['condominio_id' => $this->c->id, 'nome' => $nome, 'tipo' => $tipo, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
        DB::table('esercizio_gestione')->insertOrIgnore(['esercizio_id' => $this->e->id, 'gestione_id' => $g->id, 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pc = PianoConto::create(['condominio_id' => $this->c->id, 'gestione_id' => $g->id, 'nome' => "Piano dei conti {$nome}"]);
        $this->gestioni[$nome] = ['g' => $g, 'tipo' => $tipo, 'pc' => $pc];

        return $this;
    }

    /** Una voce di spesa: importo in centesimi, tabelle con il coefficiente (somma 100), ruoli che la pagano con la percentuale (somma 100). */
    public function voce(string $gestione, string $nome, int $importo, array $tabelle, array $ripartizioni = ['proprietario' => 100]): self
    {
        $g = $this->gestioni[$gestione];
        $conto = Conto::create(['piano_conto_id' => $g['pc']->id, 'nome' => $nome, 'tipo' => 'spesa', 'natura_spesa' => $g['tipo'], 'importo' => $importo]);
        foreach ($tabelle as $tab => $coeff) {
            $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $this->tabelle[$tab]->id, 'coefficiente' => $coeff, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($ripartizioni as $soggetto => $perc) {
                DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $perc, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->voci[$nome] = ['conto' => $conto, 'gestione' => $gestione, 'importo' => $importo];

        return $this;
    }

    /**
     * Il preventivo: un piano ordinario sulle voci della gestione. Non lo genera. `$applicaSaldi` è il flag che la creazione scrive
     * secondo lo stato del Wallet di quel momento; con `$capitoli` il piano nomina le sue voci (si può togliere una voce).
     */
    public function pianoOrdinario(string $nome, string $gestione, int $numeroRate = 12, string $primaScadenza = '2026-01-05', string $metodo = 'prima_rata', bool $capitoli = false, bool $bozza = false, bool $applicaSaldi = true): self
    {
        $p = PianoRate::create([
            'gestione_id' => $this->gestioni[$gestione]['g']->id, 'condominio_id' => $this->c->id, 'esercizio_id' => $this->e->id, 'nome' => $nome, 'stato' => $bozza ? 'bozza' : 'approvato',
            'tipo' => 'ordinario', 'numero_rate' => $numeroRate, 'giorno_scadenza' => (int) substr($primaScadenza, 8, 2), 'data_prima_scadenza' => $primaScadenza,
            'metodo_distribuzione' => $metodo, 'applica_saldi' => $applicaSaldi,
        ]);
        if ($capitoli) {
            foreach (collect($this->voci)->filter(fn ($v) => $v['gestione'] === $gestione) as $voce) {
                $p->capitoli()->attach($voce['conto']->id, ['importo' => $voce['importo'], 'note' => 'Inclusione automatica orfani']);
            }
        }
        $this->piani[$nome] = ['p' => $p, 'gestione' => $gestione, 'tipo' => 'ordinario'];

        return $this;
    }

    /** Un piano straordinario da una fattura vera, con la data della delibera. Non lo genera. @param array<string, int> $righe */
    public function pianoStraordinario(string $nome, string $gestione, array $righe, string $delibera, int $numeroRate = 6, string $primaScadenza = '2026-04-05', string $metodo = 'prima_rata', bool $applicaSaldi = true): self
    {
        $this->fornitoreId ??= DB::table('fornitori')->insertGetId([
            'ragione_sociale' => 'Impresa della beta 51 Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
            'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $totale = array_sum($righe);
        $fattura = FatturaPassiva::create([
            'condominio_id' => $this->c->id, 'fornitore_id' => $this->fornitoreId, 'esercizio_id' => $this->e->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-B51-' . $this->c->id . '-' . count($this->piani),
            'data_documento' => $delibera, 'data_scadenza' => $delibera, 'is_pregresso' => false, 'importo_imponibile' => $totale, 'importo_iva' => 0, 'importo_ritenuta' => 0,
            'totale_documento' => $totale, 'netto_a_pagare' => $totale, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
        ]);
        foreach ($righe as $voce => $importo) {
            DB::table('righe_fattura')->insert([
                'fattura_passiva_id' => $fattura->id, 'conto_id' => $this->voci[$voce]['conto']->id, 'immobile_id' => null, 'descrizione' => $voce, 'aliquota_iva' => 0,
                'importo_imponibile' => $importo, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $p = PianoRate::create([
            'gestione_id' => $this->gestioni[$gestione]['g']->id, 'condominio_id' => $this->c->id, 'esercizio_id' => $this->e->id, 'nome' => $nome, 'stato' => 'approvato',
            'tipo' => 'straordinario', 'numero_rate' => $numeroRate, 'giorno_scadenza' => (int) substr($primaScadenza, 8, 2), 'data_prima_scadenza' => $primaScadenza,
            'metodo_distribuzione' => $metodo, 'applica_saldi' => $applicaSaldi, 'data_delibera_assemblea' => $delibera,
        ]);
        $p->fatture()->attach($fattura->id, ['importo_collegato' => $totale]);
        $this->piani[$nome] = ['p' => $p, 'gestione' => $gestione, 'tipo' => 'straordinario'];

        return $this;
    }

    /** Un saldo manuale (positivo = debito) di una persona su un'unità, nel Wallet della gestione (non applicato). Restituisce il saldo. */
    public function saldo(?string $chi, string $unita, string $gestione, int $importo, bool $applicato = false): Saldo
    {
        return Saldo::create(['esercizio_id' => $this->e->id, 'condominio_id' => $this->c->id, 'anagrafica_id' => $chi === null ? null : $this->persona($chi)->id, 'immobile_id' => $this->unita[$unita]->id,
            'gestione_id' => $this->gestioni[$gestione]['g']->id, 'saldo_iniziale' => $importo, 'origine' => 'manuale', 'is_applicato' => $applicato]);
    }

    // --- Le operazioni ---------------------------------------------------------------------------------------------------

    /** La generazione con il cancello (2) accettato. `$forza` è l'override dei saldi (null = quello che decide il piano). */
    public function genera(string $piano, ?bool $forza = true, string $nota = 'Letto: generazione del piano nella prova'): self
    {
        app(GeneratePianoRateAction::class)->execute($this->piani[$piano]['p'], forzaApplicazioneSaldi: $forza, accettaDestinatari: true, notaDestinatari: $nota, esercizio: $this->e);

        return $this;
    }

    /**
     * La generazione senza la presa d'atto: se il cancello (2) si apre, le voci che mostra; se non si apre, un array vuoto (e il
     * piano è stato generato).
     *
     * @return list<array<string, mixed>>
     */
    public function cancello(string $piano, ?bool $forza = null): array
    {
        try {
            app(GeneratePianoRateAction::class)->execute($this->piani[$piano]['p'], forzaApplicazioneSaldi: $forza, accettaDestinatari: false, esercizio: $this->e);
        } catch (DestinatariCambiatiException $ex) {
            return $ex->getCambiamenti();
        }

        return [];
    }

    /** La voce del defunto nel piano fra quelle del cancello, se c'è. */
    public static function voceDelDefunto(array $cambiamenti): ?array
    {
        return collect($cambiamenti)->firstWhere('motivo', 'defunto_nel_piano');
    }

    /** Il ricalcolo dalla rotta vera. Con `$accetta` falso manda la richiesta senza la presa d'atto del cancello. */
    public function ricalcola(string $piano, bool $accetta = true): TestResponse
    {
        return $this->t->actingAs($this->admin)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$this->c, $this->e, $this->piani[$piano]['p']]),
            $accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo del piano nella prova'] : []);
    }

    /** Emette dalla rotta vera le rate non ancora emesse con scadenza fino al giorno dato (approva il piano se è in bozza). */
    public function emetti(string $piano, string $fino): TestResponse
    {
        $rate = DB::table('rate')->where('piano_rate_id', $this->piani[$piano]['p']->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
        if ($this->piani[$piano]['p']->fresh()->stato->value === 'bozza') {
            $this->piani[$piano]['p']->update(['stato' => 'approvato', 'approvato_il' => now()]);
        }

        return $this->t->actingAs($this->admin)->post(route('admin.gestionale.piani-rate.emetti', [$this->c, $this->piani[$piano]['p']]), [
            'rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false,
        ]);
    }

    /**
     * Un passaggio dalla rotta vera, preceduto dalla sua anteprima. `$tipo`: `vendita`, `successione`, `costituzione`, `estinzione`, `inizio_locazione`.
     * Opzioni: `quota`, `pertinenze` (nomi delle unità), `eredi` / `arretrato` / `riferimento` / `legato` / `ruolo` (successione),
     * `ordinaria` (costituzione), `rinuncia` (la ragione della rinuncia alla coppia). Restituisce il passaggio padre; se il programma lo rifiuta, fa fallire il test con la ragione.
     */
    public function passaggio(string $tipo, string $unita, ?string $esce, ?string $entra, string $il, array $opzioni = []): Subentro
    {
        $dati = $this->modulo($tipo, $unita, $esce, $entra, $il, $opzioni);
        if (is_string($dati)) {
            throw new \RuntimeException('Passaggio non componibile: ' . $dati);
        }
        $ant = $this->t->actingAs($this->admin)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->c, $this->unita[$unita]]), $dati);
        if ($ant->status() !== 200) {
            throw new \RuntimeException('Anteprima rifiutata: ' . $this->errori($ant));
        }
        $anteprima = $ant->json();
        if ($anteprima['cancello']['richiesto'] ?? false) {
            $dati += ['ho_letto' => true, 'nota_cancello' => 'Letto: il pannello è stato controllato nella prova'];
        }
        if (($anteprima['ordinaria']['impronta'] ?? null) !== null) {
            $dati += ['ordinaria_impronta' => $anteprima['ordinaria']['impronta']];
        }
        $r = $this->t->actingAs($this->admin)->postJson(route('admin.gestionale.immobili.passaggi.store', [$this->c, $this->unita[$unita]]), $dati);
        if ($r->status() >= 400) {
            throw new \RuntimeException('Registrazione rifiutata: ' . $this->errori($r));
        }

        return Subentro::where('immobile_id', $this->unita[$unita]->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
    }

    /** L'annullamento dalla rotta vera dell'ultimo passaggio registrato sull'unità (o di quello dato). */
    public function annulla(string $unita, ?int $passaggio = null): TestResponse
    {
        $sub = Subentro::where('immobile_id', $this->unita[$unita]->id)->whereNull('subentro_padre_id')
            ->when($passaggio !== null, fn ($q) => $q->whereKey($passaggio))->latest('id')->firstOrFail();

        return $this->t->actingAs($this->admin)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$this->c, $this->unita[$unita], $sub]), ['nota_annullamento' => 'Registrato per prova: lo annullo']);
    }

    // --- Le letture ------------------------------------------------------------------------------------------------------

    /** Le somme delle quote del piano per persona, in centesimi; `$chi` limita l'elenco (e l'ordine). @return array<string, int> */
    public function somme(string $piano, ?array $chi = null): array
    {
        $r = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $this->piani[$piano]['p']->id)
            ->selectRaw('rate_quote.anagrafica_id as a, SUM(rate_quote.importo) as t')->groupBy('rate_quote.anagrafica_id')->pluck('t', 'a')->all();
        $out = [];
        foreach ($chi ?? array_keys($this->persone) as $nome) {
            $out[$nome] = (int) ($r[$this->persona($nome)->id] ?? 0);
        }

        return $out;
    }

    /** Quanto è andato a giornale a nome di una persona (le quote con la scrittura di emissione). */
    public function aGiornale(string $chi): int
    {
        return (int) DB::table('rate_quote')->where('anagrafica_id', $this->persona($chi)->id)->whereNotNull('scrittura_contabile_id')->sum('importo');
    }

    /** Il piano com'è adesso nel database. */
    public function piano(string $nome): PianoRate
    {
        return $this->piani[$nome]['p']->fresh();
    }

    /** I passaggi che il piano deve ancora seguire, come «id» (con «a» se annullato): per leggere lo stato senza la forma dell'oggetto. @return list<string> */
    public function daSeguire(string $piano): array
    {
        return array_map(fn (Subentro $x) => $x->id . ($x->annullato() ? 'a' : ''), $this->piano($piano)->passaggiDaSeguire());
    }

    /** I parametri `titolarita_alla` congelati nella prima quota del piano (`regole_calcolo.parametri`), o null. */
    public function titolaritaAlla(string $piano): mixed
    {
        $riga = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $this->piani[$piano]['p']->id)->orderBy('rate_quote.id')->first(['rate_quote.regole_calcolo']);

        return json_decode((string) $riga->regole_calcolo, true)['parametri']['titolarita_alla'] ?? null;
    }

    /** Una cassa per gli incassi e i rimborsi. */
    public function cassa(): Cassa
    {
        if ($this->cassa === null) {
            $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $this->c->id, 'ruolo' => 'conto_bancario', 'codice' => '1010.B51', 'nome' => 'Banca della prova',
                'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);
            $this->cassa = Cassa::create(['condominio_id' => $this->c->id, 'nome' => 'Banca della prova', 'tipo' => 'banca', 'conto_contabile_id' => $conto, 'saldo_iniziale' => 1000000, 'attiva' => true]);
        }

        return $this->cassa;
    }

    // --- Il modulo di un passaggio ---------------------------------------------------------------------------------------

    /** Il modulo di un passaggio, o la ragione per cui non si può comporre (nessuna riga di chi esce con quel ruolo). */
    public function modulo(string $tipo, string $unita, ?string $esce, ?string $entra, string $il, array $opzioni = []): array|string
    {
        $ruoloUscente = match ($tipo) {
            'inizio_locazione' => null,
            'estinzione' => 'usufruttuario',
            'successione' => $opzioni['ruolo'] ?? 'proprietario',
            default => 'proprietario',
        };
        $riga = null;
        if ($ruoloUscente !== null) {
            $riga = DB::table('anagrafica_immobile')->where('immobile_id', $this->unita[$unita]->id)->where('anagrafica_id', $this->persona($esce)->id)
                ->where('tipologia', $ruoloUscente)->where(fn ($q) => $q->whereNull('data_fine')->orWhere('data_fine', '>=', $il))->orderByDesc('id')->first();
            if ($riga === null) {
                return "{$esce} non ha una riga aperta da {$ruoloUscente} sull'unità {$unita}";
            }
        }
        $base = [
            'decorrenza' => $il, 'quota' => $opzioni['quota'] ?? ($riga !== null ? (float) $riga->quota : 100), 'copia_autentica' => false,
            'estremi_titolo' => 'atto della prova', 'pertinenze' => array_map(fn ($p) => $this->unita[$p]->id, $opzioni['pertinenze'] ?? []),
        ] + ($riga !== null ? ['riga_uscente_id' => (int) $riga->id] : []) + ($entra !== null ? ['anagrafica_entrante_id' => $this->persona($entra)->id] : []);
        // La rinuncia alla coppia proposta, con la ragione («Le parti hanno regolato il conguaglio fra loro»): vendita e usufrutto, dove la scelta è la casella.
        if (isset($opzioni['rinuncia'])) {
            $base += ['rinuncia_conguaglio' => true, 'nota_conguaglio' => $opzioni['rinuncia']];
        }
        if ($tipo === 'costituzione') {
            $base += ['ordinaria_dopo_atto' => $opzioni['ordinaria'] ?? Subentro::ORDINARIA_ALL_USUFRUTTUARIO, 'voci_da_tenere' => []];
        }

        return $base + match ($tipo) {
            'successione' => ['tipo' => 'successione', 'tipologia' => $ruoloUscente,
                'eredi' => array_map(fn ($chi, $q) => ['anagrafica_id' => $this->persona($chi)->id, 'quota' => $q], array_keys($opzioni['eredi'] ?? []), array_values($opzioni['eredi'] ?? [])),
                'arretrato' => $opzioni['arretrato'] ?? Subentro::ARRETRATO_AGLI_EREDI]
                + (isset($opzioni['riferimento']) ? ['erede_di_riferimento' => $this->persona($opzioni['riferimento'])->id] : [])
                + (($opzioni['legato'] ?? false) ? ['sottotipo' => Subentro::LEGATO] : [])
                + (($opzioni['arretrato'] ?? Subentro::ARRETRATO_AGLI_EREDI) === Subentro::ARRETRATO_AL_DEFUNTO ? ['conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO] : []),
            // L'estinzione dell'usufrutto: chi esce è l'usufruttuario, i nudi proprietari tornano pieni (nessuno entra).
            'estinzione' => ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'tipologia' => 'proprietario'],
            'vendita' => ['tipo' => 'vendita', 'tipologia' => 'proprietario'],
            'costituzione' => ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'tipologia' => 'usufruttuario'],
            'inizio_locazione' => ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'regime_contratto' => 'abitativo']
                + (isset($opzioni['fine_locazione']) ? ['data_fine_locazione' => $opzioni['fine_locazione']] : []),
        };
    }

    private function errori(TestResponse $r): string
    {
        $j = $r->json();
        $errori = collect($j['errors'] ?? [])->flatten()->all();

        return mb_substr($errori !== [] ? implode(' | ', $errori) : ($j['message'] ?? (string) $r->status()), 0, 400);
    }

    /** Centesimi in euro per i moduli che li vogliono così (incasso): «12345» → «123.45». */
    public static function euro(int $centesimi): string
    {
        return sprintf('%s%d.%02d', $centesimi < 0 ? '-' : '', intdiv(abs($centesimi), 100), abs($centesimi) % 100);
    }
}

<?php

/**
 * Le versioni del rendiconto per l'assemblea, sul caso di Via Verdi con le spese scritte con le fatture (così il prospetto della Sintesi le vede).
 *
 * Le cifre del caso, rifatte a mano (positivo = debito): preventivo € 4.000,00 in quattro rate tutte emesse, consuntivo Pulizie € 3.300,00 e
 * Manutenzioni € 900,00, un citofono di € 80,00 su U3 non ancora chiesto. Il conguaglio: Neri A 2.100,00 (500/1000 di 4.200,00) − B 2.000,00 =
 * +100,00; Bianchi (181 giorni su 365) 624,82 − 595,07 = +29,75; Rossi (184 giorni) 635,18 − 604,93 = +30,25; Verdi 840,00 + 80,00 − 800,00 = +120,00.
 * Il prospetto: costi € 4.200,00, quote registrate € 4.000,00, risultato −200,00.
 *
 * La versione si ferma il 01/03/2027 alle 10 di Roma: ogni parte del PDF dice quel giorno, anche quella conservata il 05/03/2027.
 */

use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Gestionale\NotaSintetica;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\User;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\VersioniDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__ . '/../Pdf/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    // Tutti i permessi esistono, come su un'installazione: la porta del portale li chiede per nome. Il ruolo del pannello ha i suoi.
    foreach (\App\Enums\Permission::cases() as $permesso) {
        Permission::findOrCreate($permesso->value, 'web');
    }
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
});

afterEach(function () {
    (new ReflectionProperty(FascicoloDelRendiconto::class, 'limiteTempo'))->setValue(null, null);
});

/**
 * La forma 1 delle cifre: le chiavi, una per riga, con «*» per gli elementi di un elenco. Sale il numero della forma quando cambia questo elenco: le
 * cifre di una versione si leggono con le chiavi della sua forma. Gli elenchi vuoti nel caso (le voci ferme, i periodi diversi, i giorni per ruolo
 * di chi ha un ruolo solo) hanno solo la loro chiave.
 */
const RVS_FORMA_1 = [
    'alla', 'calcolato_il', 'conguaglio', 'conguaglio.condominio', 'conguaglio.condominio.a', 'conguaglio.condominio.a_non_disponibili', 'conguaglio.condominio.b',
    'conguaglio.condominio.conguaglio', 'conguaglio.condominio.fuori_dalle_rate', 'conguaglio.condominio.non_attribuite', 'conguaglio.condominio.non_disponibili',
    'conguaglio.condominio.personali', 'conguaglio.condominio.posizione', 'conguaglio.condominio.saldo', 'conguaglio.da_altre_gestioni', 'conguaglio.gestioni',
    'conguaglio.gestioni.*', 'conguaglio.gestioni.*.gestione_id', 'conguaglio.gestioni.*.gia_versato_da_decidere', 'conguaglio.gestioni.*.gia_versato_unita',
    'conguaglio.gestioni.*.in_corso', 'conguaglio.gestioni.*.invariante', 'conguaglio.gestioni.*.non_attribuite', 'conguaglio.gestioni.*.non_calcolabile',
    'conguaglio.gestioni.*.nome', 'conguaglio.gestioni.*.opera_finita', 'conguaglio.gestioni.*.per_voce', 'conguaglio.gestioni.*.per_voce.*',
    'conguaglio.gestioni.*.per_voce.*.conto_id', 'conguaglio.gestioni.*.per_voce.*.non_attribuite', 'conguaglio.gestioni.*.per_voce.*.persone',
    'conguaglio.gestioni.*.per_voce.*.uscite', 'conguaglio.gestioni.*.per_voce.*.voci_ferme', 'conguaglio.gestioni.*.periodi_diversi',
    'conguaglio.gestioni.*.periodi_diversi_perche', 'conguaglio.gestioni.*.tipo', 'conguaglio.gestioni.*.totali', 'conguaglio.gestioni.*.totali.a',
    'conguaglio.gestioni.*.totali.b', 'conguaglio.gestioni.*.totali.conguaglio', 'conguaglio.gestioni.*.totali.fuori_dalle_rate', 'conguaglio.gestioni.*.totali.non_disponibili',
    'conguaglio.gestioni.*.totali.personali', 'conguaglio.gestioni.*.totali.posizione', 'conguaglio.gestioni.*.totali.saldo', 'conguaglio.gestioni.*.uscite',
    'conguaglio.gestioni.*.uscite.*', 'conguaglio.gestioni.*.uscite.*.id', 'conguaglio.gestioni.*.uscite.*.importo', 'conguaglio.gestioni.*.voci_ferme', 'conguaglio.righe',
    'conguaglio.righe.*', 'conguaglio.righe.*.a', 'conguaglio.righe.*.anagrafica_id', 'conguaglio.righe.*.b', 'conguaglio.righe.*.b.coppie', 'conguaglio.righe.*.b.di_cui_non_emesse',
    'conguaglio.righe.*.b.gia_versato_persona', 'conguaglio.righe.*.b.quote', 'conguaglio.righe.*.b.regolato', 'conguaglio.righe.*.b.totale', 'conguaglio.righe.*.conguaglio',
    'conguaglio.righe.*.fuori_dalle_rate', 'conguaglio.righe.*.gestione_id', 'conguaglio.righe.*.gia_versato_da_decidere', 'conguaglio.righe.*.gia_versato_da_decidere_perche',
    'conguaglio.righe.*.gia_versato_non_usato', 'conguaglio.righe.*.gia_versato_non_usato.questo_esercizio', 'conguaglio.righe.*.gia_versato_non_usato.tutti_gli_esercizi',
    'conguaglio.righe.*.giorni', 'conguaglio.righe.*.giorni_per_ruolo', 'conguaglio.righe.*.immobile_id', 'conguaglio.righe.*.nome', 'conguaglio.righe.*.non_disponibile',
    'conguaglio.righe.*.personali', 'conguaglio.righe.*.posizione', 'conguaglio.righe.*.saldo', 'conguaglio.righe.*.unita', 'controlli', 'controlli.*', 'controlli.*.destra',
    'controlli.*.esito', 'controlli.*.id', 'controlli.*.nome', 'controlli.*.sinistra', 'esito', 'esito.da_leggere', 'esito.non_calcolati', 'esito.non_tornano', 'esito.tornano',
    'finito', 'forma', 'intestazione', 'intestazione.condominio', 'intestazione.condominio.codice_fiscale', 'intestazione.condominio.id', 'intestazione.condominio.indirizzo',
    'intestazione.condominio.nome', 'intestazione.esercizio', 'intestazione.esercizio.al', 'intestazione.esercizio.dal', 'intestazione.esercizio.id', 'intestazione.esercizio.nome',
    'intestazione.studio', 'intestazione.studio.comune', 'intestazione.studio.email', 'intestazione.studio.indirizzo', 'intestazione.studio.nome', 'intestazione.studio.pec',
    'intestazione.studio.telefono', 'prospetto', 'prospetto.costi', 'prospetto.deliberato', 'prospetto.in_fondi', 'prospetto.preventivo', 'prospetto.quote_giornale',
    'prospetto.quote_non_pagate', 'prospetto.quote_pagate', 'prospetto.quote_piani', 'prospetto.risultato', 'registro', 'registro.*', 'registro.*.data', 'registro.*.entrata',
    'registro.*.impronta', 'registro.*.numero', 'registro.*.riga_id', 'registro.*.scrittura_id', 'registro.*.uscita', 'riepilogo', 'riepilogo.fuori_capitoli',
    'riepilogo.fuori_capitoli.commissioni_senza_voce', 'riepilogo.fuori_capitoli.contestate', 'riepilogo.fuori_capitoli.non_classificati', 'riepilogo.gestioni',
    'riepilogo.gestioni.*', 'riepilogo.gestioni.*.deliberato_stato', 'riepilogo.gestioni.*.gestione_id', 'riepilogo.gestioni.*.nome', 'riepilogo.gestioni.*.tipo',
    'riepilogo.gestioni.*.totale_entrate', 'riepilogo.gestioni.*.totale_entrate.deliberato', 'riepilogo.gestioni.*.totale_entrate.incassato',
    'riepilogo.gestioni.*.totale_entrate.preventivo', 'riepilogo.gestioni.*.totale_spese', 'riepilogo.gestioni.*.totale_spese.consuntivo',
    'riepilogo.gestioni.*.totale_spese.deliberato', 'riepilogo.gestioni.*.totale_spese.preventivo', 'riepilogo.gestioni.*.voci', 'riepilogo.gestioni.*.voci.*',
    'riepilogo.gestioni.*.voci.*.anno_prima', 'riepilogo.gestioni.*.voci.*.capitolo_id', 'riepilogo.gestioni.*.voci.*.consuntivo', 'riepilogo.gestioni.*.voci.*.deliberato',
    'riepilogo.gestioni.*.voci.*.incassato', 'riepilogo.gestioni.*.voci.*.nome', 'riepilogo.gestioni.*.voci.*.preventivo', 'riepilogo.gestioni.*.voci.*.scostamento',
    'riepilogo.gestioni.*.voci.*.scostamento_preventivo', 'riepilogo.gestioni.*.voci.*.tipo', 'riepilogo.gestioni.*.voci.*.voce_id', 'situazione', 'situazione.attivo',
    'situazione.costi', 'situazione.crediti_condomini', 'situazione.data', 'situazione.debiti_erario', 'situazione.debiti_fornitori', 'situazione.fondi', 'situazione.in_fondi',
    'situazione.liquidita', 'situazione.passivo', 'situazione.ricavi',
];

/** La forma 3: la forma 1 con l'impronta del giornale, in tutto, per registrazione («id:impronta:protocollo») e prima dell'esercizio. */
const RVS_FORMA_3 = [...RVS_FORMA_1, 'giornale', 'giornale.impronta', 'giornale.per_scrittura', 'giornale.per_scrittura.*', 'giornale.prima', 'giornale.scritture'];

/** Via Verdi: le rate e gli incassi, il passaggio di U2 con la coppia, le spese con le fatture su un conto di costo, il citofono di U3. */
function rvsViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');

    return $s;
}

/** Il giorno dell'amministratore, alle 10 di Roma. */
function rvsGiorno($test, string $giorno): void
{
    $test->travelTo(\Carbon\Carbon::parse($giorno.' 10:00', 'Europe/Rome'));
}

/** «Ferma per l'assemblea» dalla rotta della Sintesi; la versione nata. */
function rvsFerma($test, array $s, array $dati = []): RendicontoVersione
{
    $test->actingAs($test->user)->from(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))
        ->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s['c'], $s['e']]), $dati)->assertSessionHasNoErrors()->assertRedirect();

    return RendicontoVersione::where('esercizio_id', $s['e']->id)->latest('numero')->firstOrFail();
}

/** «Registra l'approvazione». */
function rvsApprova($test, array $s, RendicontoVersione $v, array $dati)
{
    return $test->actingAs($test->user)->from(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))
        ->post(route('admin.gestionale.esercizi.rendiconto.versioni.approva', [$s['c'], $s['e'], $v->id]), $dati);
}

/** Le chiavi di un array, una per riga, con «*» per gli elementi di un elenco (l'unione delle chiavi di tutti gli elementi). */
function rvsChiavi(array $a, string $prefisso = ''): array
{
    $chiavi = [];
    $lista = array_is_list($a) && $a !== [];
    foreach ($a as $k => $v) {
        $percorso = $prefisso === '' ? (string) ($lista ? '*' : $k) : $prefisso.'.'.($lista ? '*' : $k);
        $chiavi[$percorso] = true;
        if (is_array($v)) {
            foreach (rvsChiavi($v, $percorso) as $sotto) {
                $chiavi[$sotto] = true;
            }
        }
    }
    $chiavi = array_keys($chiavi);
    sort($chiavi);

    return $chiavi;
}

/** Le viste delle stampe scritte durante una richiesta, con la data di emissione che hanno ricevuto. */
function rvsVisteConLaData(): object
{
    $viste = new ArrayObject();
    View::composer(['pdf.gestionale.rendiconto_frontespizio', 'pdf.gestionale.rendiconto_riepilogo', 'pdf.gestionale.stato_patrimoniale',
        'pdf.gestionale.rendiconto_posizioni', 'pdf.gestionale.rendiconto_crediti', 'pdf.gestionale.registro_contabilita', 'pdf.gestionale.consuntivo_per_tabella',
        'pdf.gestionale.conguaglio_per_persona'], function ($v) use ($viste) {
            $dati = $v->getData();
            $viste[] = ['vista' => $v->getName().(isset($dati['parte']) ? ':'.$dati['parte'] : ''), 'data' => isset($dati['data_emissione_stampe'])
                ? $dati['data_emissione_stampe']->format('d/m/Y') : null, 'html' => null, 'dati' => $dati];
        });

    return $viste;
}

it('«Ferma per l\'assemblea» conserva le cifre della forma 1 con il conguaglio per persona, la nota com\'è, e il PDF del fascicolo con la sua impronta e la data della versione in ogni parte', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    NotaSintetica::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'contenuto' => ['altre' => ['testo' => 'La nota com\'è oggi.']]]);
    $viste = rvsVisteConLaData();

    $v = rvsFerma($this, $s);

    $conguaglio = collect($v->cifre['conguaglio']['righe'])->mapWithKeys(fn ($r) => [$r['nome'] => $r['conguaglio']])->sortKeys()->all();
    expect([$v->numero, $v->stato, $v->forma, $v->creata_da])->toBe([1, 'presentata', 3, $this->user->id])
        ->and($conguaglio)->toBe(['Bianchi' => 2975, 'Neri' => 10000, 'Rossi' => 3025, 'Verdi' => 12000])
        ->and(array_intersect_key($v->cifre['prospetto'], array_flip(['costi', 'quote_giornale', 'risultato'])))->toBe(['costi' => 420000, 'quote_giornale' => 400000, 'risultato' => -20000])
        ->and($v->cifre['alla'])->toBe('2026-12-31')
        ->and(VersioniDelRendiconto::emessaIl($v)->format('d/m/Y H:i'))->toBe('01/03/2027 10:00')
        ->and($v->nota['bozza']['parti']['altre']['testo'])->toBe('La nota com\'è oggi.')->and($v->nota['stampa']['vuota'])->toBeFalse()
        // Bianchi ha avuto U2 per 181 giorni, Rossi per 184: i giorni della riga del conguaglio.
        ->and(collect($v->cifre['conguaglio']['righe'])->mapWithKeys(fn ($r) => [$r['nome'] => $r['giorni']])->sortKeys()->all())
        ->toBe(['Bianchi' => 181, 'Neri' => null, 'Rossi' => 184, 'Verdi' => null]);

    // Il PDF del fascicolo: un documento della versione, sul disco, con l'impronta SHA-256 del file.
    expect($v->pdf['documenti'])->toHaveCount(1);
    $riga = $v->pdf['documenti'][0];
    $documento = Documento::findOrFail($riga['documento_id']);
    expect($riga['parte'])->toBe('fascicolo')
        ->and([$documento->documentable_type, $documento->documentable_id, (bool) $documento->is_published])->toBe([RendicontoVersione::class, $v->id, false])
        ->and($riga['impronta'])->toBe(hash('sha256', Storage::disk('local')->get($documento->path)))
        ->and(str_starts_with(Storage::disk('local')->get($documento->path), '%PDF'))->toBeTrue()
        ->and($v->pdf['parti'])->toBe(FascicoloDelRendiconto::PARTI);

    // La data di emissione della versione in ogni parte, il frontespizio compreso, nel piede e in «Stampato il».
    $date = collect($viste)->mapWithKeys(fn ($x) => [$x['vista'] => $x['data']])->all();
    expect(array_keys($date))->toContain('pdf.gestionale.rendiconto_frontespizio', 'pdf.gestionale.rendiconto_riepilogo', 'pdf.gestionale.stato_patrimoniale:situazione',
        'pdf.gestionale.consuntivo_per_tabella', 'pdf.gestionale.conguaglio_per_persona', 'pdf.gestionale.registro_contabilita')
        ->and(array_unique(array_values($date)))->toBe(['01/03/2027']);
});

it('il numero di forma: le chiavi delle cifre sono quelle scritte per la forma 3, con i giorni, i giorni per ruolo, le voci ferme e l\'impronta del giornale; per ogni voce uscite + voci ferme = persone + non attribuite', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    $v = rvsFerma($this, $s, ['parti' => ['riepilogo']]);
    $cifre = RendicontoVersione::findOrFail($v->id)->cifre;

    $forma3 = RVS_FORMA_3;
    sort($forma3);
    expect(RendicontoVersione::FORMA)->toBe(3)
        ->and($cifre['forma'])->toBe(RendicontoVersione::FORMA)
        ->and(rvsChiavi($cifre))->toBe($forma3)
        // L'impronta del giornale è quella del giornale di quel giorno.
        ->and($cifre['giornale']['impronta'])->toBe(\App\Services\Gestionale\ConfrontoDelRendiconto::giornale($s['e'])['impronta']);

    foreach ($cifre['conguaglio']['gestioni'] as $g) {
        expect($g['invariante'])->toBe([])
            ->and($g['gia_versato_unita'])->toBe(0);
        foreach ($g['per_voce'] as $t) {
            expect($t['uscite'] + $t['voci_ferme'])->toBe($t['persone'] + $t['non_attribuite']);
        }
    }
    // Pulizie € 3.300,00 e Manutenzioni € 900,00, divise fra le persone; nessuna ferma, nessuna non attribuita.
    expect(collect($cifre['conguaglio']['gestioni'][0]['per_voce'])->map(fn ($t) => [$t['uscite'], $t['voci_ferme'], $t['persone'], $t['non_attribuite']])->all())
        ->toBe([[330000, 0, 330000, 0], [90000, 0, 90000, 0]]);
});

it('l\'uguaglianza delle voci dice quale voce non torna, e con il già versato dell\'unità si guarda sulla gestione', function () {
    $voci = [['conto_id' => 7, 'uscite' => 1000, 'voci_ferme' => 0, 'persone' => 999, 'non_attribuite' => 0],
        ['conto_id' => 8, 'uscite' => 500, 'voci_ferme' => 200, 'persone' => 500, 'non_attribuite' => 200]];

    expect(\App\Services\Gestionale\CifreDelRendiconto::invariante($voci, 0))->toBe([7])
        ->and(\App\Services\Gestionale\CifreDelRendiconto::invariante($voci, 1))->toBe([])
        ->and(\App\Services\Gestionale\CifreDelRendiconto::invariante($voci, 2))->toBe(['*']);
});

it('il fascicolo oltre il tetto: la versione nasce con le cifre e senza PDF; le parti si conservano una per una, ognuna con la sua impronta e la data della versione', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    // 9 s di limite, 4,5 s di stima: meno della base del fascicolo, che non parte.
    (new ReflectionProperty(FascicoloDelRendiconto::class, 'limiteTempo'))->setValue(null, 9);
    $v = rvsFerma($this, $s, ['parti' => ['riepilogo', 'situazione', 'conguaglio']]);
    (new ReflectionProperty(FascicoloDelRendiconto::class, 'limiteTempo'))->setValue(null, null);

    expect($v->pdf['documenti'])->toBe([])
        ->and($v->pdf['troppo'])->toStartWith('Con il tempo che il server concede a una stampa il fascicolo non si può fare')
        ->and($v->cifre['conguaglio']['condominio']['conguaglio'])->toBe(28000)
        ->and(session('message')['message'] ?? '')->toBe('Versione 1 fermata con le cifre di oggi. '.VersioniDelRendiconto::FRASE_SENZA_PDF)
        ->and(VersioniDelRendiconto::elenco($s['e'])[0]['troppo'])->toBe(VersioniDelRendiconto::FRASE_SENZA_PDF);

    // Quattro giorni dopo: ogni parte con la data della versione, non con quella di oggi.
    rvsGiorno($this, '2027-03-05');
    $viste = rvsVisteConLaData();
    foreach (['riepilogo', 'situazione', 'conguaglio'] as $parte) {
        $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))
            ->post(route('admin.gestionale.esercizi.rendiconto.versioni.parte', [$s['c'], $s['e'], $v->id]), ['parte' => $parte])->assertSessionHasNoErrors()->assertRedirect();
    }
    $v->refresh();

    expect(array_column($v->pdf['documenti'], 'parte'))->toBe(['riepilogo', 'situazione', 'conguaglio']);
    foreach ($v->pdf['documenti'] as $riga) {
        $documento = Documento::findOrFail($riga['documento_id']);
        expect($riga['impronta'])->toBe(hash('sha256', Storage::disk('local')->get($documento->path)))
            ->and($documento->documentable_id)->toBe($v->id);
    }
    expect(count(array_unique(array_column($v->pdf['documenti'], 'impronta'))))->toBe(3)
        ->and(array_unique(array_column((array) $viste->getArrayCopy(), 'data')))->toBe(['01/03/2027']);
    // Il testo della parte: «Stampato il 01/03/2027», mai il giorno in cui è stata conservata.
    $riepilogo = collect($viste)->firstWhere('vista', 'pdf.gestionale.rendiconto_riepilogo');
    $html = view('pdf.gestionale.rendiconto_riepilogo', $riepilogo['dati'])->render();
    expect($html)->toContain('01/03/2027')->not->toContain('05/03/2027')
        ->and(VersioniDelRendiconto::elenco($s['e'])[0]['troppo'])->toBeNull();
});

it('«Carica il PDF che hai mandato»: il file com\'è, con la sua impronta, fra i documenti della versione', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    (new ReflectionProperty(FascicoloDelRendiconto::class, 'limiteTempo'))->setValue(null, 9);
    $v = rvsFerma($this, $s, ['parti' => ['riepilogo']]);
    (new ReflectionProperty(FascicoloDelRendiconto::class, 'limiteTempo'))->setValue(null, null);
    $file = UploadedFile::fake()->createWithContent('Rendiconto mandato.pdf', "%PDF-1.4\nil fascicolo mandato con la convocazione\n%%EOF");

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.rendiconto.versioni.carica', [$s['c'], $s['e'], $v->id]), ['pdf' => $file])
        ->assertSessionHasNoErrors()->assertRedirect();
    $riga = $v->fresh()->pdf['documenti'][0];

    expect($riga['parte'])->toBe('caricato')
        ->and($riga['impronta'])->toBe(hash('sha256', "%PDF-1.4\nil fascicolo mandato con la convocazione\n%%EOF"))
        ->and(Documento::findOrFail($riga['documento_id'])->name)->toBe('Rendiconto mandato.pdf');
});

it('l\'approvazione: la data, il verbale come documento, «anche lo stato di riparto» da dire sì o no; la seconda approvazione fa sostituita la prima', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    $prima = rvsFerma($this, $s, ['parti' => ['riepilogo']]);
    rvsGiorno($this, '2027-03-15');

    // Senza la risposta sullo stato di riparto non si registra: non c'è una risposta già scelta.
    rvsApprova($this, $s, $prima, ['data_delibera' => '2027-03-15', 'numero_verbale' => '2'])->assertSessionHasErrors('riparto_approvato');
    rvsApprova($this, $s, $prima, ['data_delibera' => '2027-03-16', 'numero_verbale' => '2', 'riparto_approvato' => '1'])->assertSessionHasErrors('data_delibera');
    expect($prima->fresh()->stato)->toBe('presentata');

    rvsApprova($this, $s, $prima, ['data_delibera' => '2027-03-15', 'numero_verbale' => '2', 'riparto_approvato' => '1', 'nota_approvazione' => 'Approvato all\'unanimità.',
        'verbale' => UploadedFile::fake()->createWithContent('verbale 2.pdf', "%PDF-1.4\nverbale\n%%EOF")])->assertSessionHasNoErrors();
    $prima->refresh();
    $verbale = Documento::findOrFail($prima->verbale_documento_id);
    expect([$prima->stato, $prima->data_delibera->format('Y-m-d'), $prima->numero_verbale, $prima->riparto_approvato, $prima->nota_approvazione, $prima->approvata_da])
        ->toBe(['approvata', '2027-03-15', '2', true, 'Approvato all\'unanimità.', $this->user->id])
        ->and([$verbale->documentable_type, $verbale->documentable_id, $verbale->name])->toBe([RendicontoVersione::class, $prima->id, 'verbale 2.pdf'])
        ->and(Esercizio::find($s['e']->id)->stato)->toBe('aperto');

    // Una versione approvata non si approva di nuovo.
    rvsApprova($this, $s, $prima, ['data_delibera' => '2027-03-15', 'riparto_approvato' => '0'])->assertSessionHasNoErrors();
    expect(session('message')['message'] ?? '')->toBe('La versione 1 non è presentata: si registra l\'approvazione di una versione presentata.');

    // L'assemblea che corregge: una versione nuova, approvata con «riparto: no»; la prima resta, sostituita.
    $seconda = rvsFerma($this, $s, ['parti' => ['riepilogo']]);
    rvsApprova($this, $s, $seconda, ['data_delibera' => '2027-03-15', 'numero_verbale' => '3', 'riparto_approvato' => '0'])->assertSessionHasNoErrors();
    expect([$prima->fresh()->stato, $seconda->fresh()->stato, $seconda->fresh()->riparto_approvato, $seconda->numero])->toBe(['sostituita', 'approvata', false, 2])
        ->and(RendicontoVersione::where('esercizio_id', $s['e']->id)->count())->toBe(2);
});

it('un esercizio con un rendiconto conservato non si elimina, e il messaggio lo dice', function () {
    $s = cpcViaVerdi($this);
    $chiuso = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $v = RendicontoVersione::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $chiuso->id, 'numero' => 1, 'stato' => 'approvata', 'forma' => 1, 'cifre' => [],
        'calcolato_il' => '2026-03-01 09:00:00', 'data_delibera' => '2026-03-15']);

    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.destroy', [$s['c'], $chiuso]))->assertRedirect();

    expect(Esercizio::find($chiuso->id))->not->toBeNull()
        ->and(RendicontoVersione::find($v->id))->not->toBeNull()
        ->and(session('message')['message'] ?? '')->toContain('Contiene: la versione 1 del rendiconto, approvata il 15/03/2026: resta, ed è nella Sintesi del rendiconto.');
});

it('la nota sintetica scritta trattiene l\'esercizio come il rendiconto conservato: la cascata la porterebbe via', function () {
    $s = cpcViaVerdi($this);
    $chiuso = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $nota = NotaSintetica::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $chiuso->id, 'contenuto' => ['altre' => ['testo' => 'I contratti in essere.']]]);

    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.destroy', [$s['c'], $chiuso]))->assertRedirect();

    expect(Esercizio::find($chiuso->id))->not->toBeNull()
        ->and(NotaSintetica::find($nota->id))->not->toBeNull()
        ->and(session('message')['message'] ?? '')->toContain('Contiene: la nota sintetica del rendiconto, scritta.');
});

it('lo stato in testa a ogni scheda: «Bozza», poi «Fermato per l\'assemblea il …», poi «Approvato il … (verbale n. …)»', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    $schede = ['esercizi.rendiconto.sintesi', 'esercizi.rendiconto.riepilogo', 'esercizi.stato-patrimoniale.index', 'esercizi.rendiconto.cassa', 'esercizi.rendiconto.condomini',
        'esercizi.rendiconto.conguaglio', 'esercizi.rendiconto.controlli'];
    $frasi = fn () => collect($schede)->mapWithKeys(fn ($r) => [$r => $this->actingAs($this->user)->get(route('admin.gestionale.'.$r, [$s['c'], $s['e']]))->assertOk()
        ->viewData('page')['props']['stato_rendiconto']['frase'] ?? null])->unique()->values()->all();

    expect($frasi())->toBe(['Bozza: le cifre cambiano a ogni registrazione.']);

    $v = rvsFerma($this, $s, ['parti' => ['riepilogo']]);
    // Il giorno in cui il rendiconto è stato fermato: il programma non sa quando è stato presentato all'assemblea.
    expect($frasi())->toBe(['Fermato per l\'assemblea il 01/03/2027.']);

    rvsGiorno($this, '2027-03-15');
    rvsApprova($this, $s, $v, ['data_delibera' => '2027-03-15', 'numero_verbale' => '2', 'riparto_approvato' => '1'])->assertSessionHasNoErrors();
    expect($frasi())->toBe(['Approvato il 15/03/2027 (verbale n. 2).']);

    // La Sintesi elenca le versioni, senza le cifre.
    $sintesi = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))->viewData('page')['props'];
    expect(array_map(fn ($x) => [$x['numero'], $x['stato'], $x['data_delibera'], $x['riparto_approvato']], $sintesi['versioni']))->toBe([[1, 'approvata', '2027-03-15', true]])
        ->and(array_key_exists('cifre', $sintesi['versioni'][0]))->toBeFalse();
});

it('le versioni e i loro PDF stanno dietro il pannello e nel loro condominio; il PDF non entra nell\'archivio dei condòmini', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    $v = rvsFerma($this, $s, ['parti' => ['riepilogo']]);
    $documento = $v->pdf['documenti'][0]['documento_id'];
    $altro = cpcViaVerdi($this);
    rvsGiorno($this, '2027-03-01');

    // Il PDF si apre dal pannello.
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.versioni.documento', [$s['c'], $s['e'], $v->id, $documento]))->assertOk();
    // Un altro condominio, un altro esercizio, un altro documento: 404.
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.versioni.documento', [$altro['c'], $s['e'], $v->id, $documento]))->assertNotFound();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.versioni.documento', [$altro['c'], $altro['e'], $v->id, $documento]))->assertNotFound();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.rendiconto.versioni.approva', [$altro['c'], $altro['e'], $v->id]),
        ['data_delibera' => '2027-03-01', 'riparto_approvato' => '1'])->assertNotFound();
    $estraneo = Documento::create(['name' => 'Altro', 'description' => 'Altro', 'path' => 'documenti/altro.pdf', 'created_by' => $this->user->id]);
    Storage::disk('local')->put('documenti/altro.pdf', '%PDF');
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.versioni.documento', [$s['c'], $s['e'], $v->id, $estraneo->id]))->assertNotFound();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$altro['c'], $s['e']]))->assertNotFound();

    // Un condòmino con un utente: fuori dal pannello non ferma, non scarica, e il PDF non è fra i documenti del suo archivio.
    $condomino = User::factory()->create();
    $s['persone']['Neri']->update(['user_id' => $condomino->id]);
    $this->actingAs($condomino)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s['c'], $s['e']]))->assertForbidden();
    $this->actingAs($condomino)->get(route('admin.gestionale.esercizi.rendiconto.versioni.documento', [$s['c'], $s['e'], $v->id, $documento]))->assertForbidden();
    expect(RendicontoVersione::where('esercizio_id', $s['e']->id)->count())->toBe(1);
    expect($this->actingAs($condomino)->get(route('user.documenti.download', $documento))->status())->toBeIn([403, 404])
        ->and(Documento::whereNull('documentable_type')->where('id', $documento)->exists())->toBeFalse();
});

/** Ferma oltre il tetto: la versione nasce senza PDF, con le parti scelte. */
function rvsFermaSenzaPdf($test, array $s, array $parti = []): RendicontoVersione
{
    (new ReflectionProperty(FascicoloDelRendiconto::class, 'limiteTempo'))->setValue(null, 9);
    try {
        return rvsFerma($test, $s, $parti === [] ? [] : ['parti' => $parti]);
    } finally {
        (new ReflectionProperty(FascicoloDelRendiconto::class, 'limiteTempo'))->setValue(null, null);
    }
}

/** «Conserva» una parte dalla rotta della Sintesi. */
function rvsConserva($test, array $s, RendicontoVersione $v, string $parte)
{
    return $test->actingAs($test->user)->from(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))
        ->post(route('admin.gestionale.esercizi.rendiconto.versioni.parte', [$s['c'], $s['e'], $v->id]), ['parte' => $parte])->assertSessionHasNoErrors()->assertRedirect();
}

/** Il testo del PDF conservato di una parte, letto con `pdftotext`, come lo legge chi lo apre. */
function rvsTestoDelPdf(RendicontoVersione $v, string $parte): string
{
    $riga = collect($v->fresh()->pdf['documenti'])->firstWhere('parte', $parte);
    $file = tempnam(sys_get_temp_dir(), 'rvs').'.pdf';
    file_put_contents($file, Storage::disk('local')->get(Documento::findOrFail($riga['documento_id'])->path));
    $testo = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return $testo;
}

it('una parte da conservare dopo una registrazione nuova non si conserva: la frase dice che cosa è cambiato, e nessun file nasce', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    $v = rvsFermaSenzaPdf($this, $s, ['riepilogo', 'situazione', 'conguaglio']);
    $file = count(Storage::disk('local')->allFiles());

    // Quattro giorni dopo, nel 2026 ancora aperto, una fattura di Manutenzioni di € 60,00: le spese 4.200,00 → 4.260,00, Manutenzioni 900,00 → 960,00, e
    // il conguaglio di tutte e quattro le persone (il 30 % di U2 diviso per giorni fra Bianchi e Rossi).
    rvsGiorno($this, '2027-03-05');
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 6000, []);
    rvsConserva($this, $s, $v, 'riepilogo');

    expect(session('message')['type'] ?? null)->toBe('error')
        ->and(session('message')['message'] ?? '')->toBe('Dal 01/03/2027, il giorno della versione 1, sono cambiati i conti: nel giornale 1 registrazione nuova; le spese dell\'esercizio erano €'."\u{00A0}".'4.200,00, '
            .'oggi sono €'."\u{00A0}".'4.260,00; Manutenzioni era €'."\u{00A0}".'900,00, oggi è €'."\u{00A0}".'960,00; i debiti verso i fornitori erano €'."\u{00A0}".'4.280,00, oggi sono €'
            ."\u{00A0}".'4.340,00; il conguaglio è cambiato per 4 persone su 4. Una parte conservata oggi avrebbe la '
            .'data della versione e le cifre di oggi, e non si conserva: ferma una versione nuova, o carica il PDF che hai mandato.')
        ->and($v->fresh()->pdf['documenti'])->toBe([])
        ->and(count(Storage::disk('local')->allFiles()))->toBe($file);
});

it('la nota cambiata dopo la versione non ferma la parte «nota» e non entra nel suo PDF: si conserva la nota di quel giorno', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    NotaSintetica::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'contenuto' => \App\Services\Gestionale\NotaSinteticaDelRendiconto::normalizza([
        'fatti' => ['G1' => ['stato' => 'tenuto']], 'parti' => ['altre' => ['testo' => 'Il testo di allora, presentato.']]])]);
    $v = rvsFermaSenzaPdf($this, $s, ['nota', 'riepilogo']);
    expect(json_encode($v->nota['stampa'], JSON_UNESCAPED_UNICODE))->toContain('Il testo di allora, presentato.');
    // Quattro giorni dopo la nota cambia, senza nessuna cifra: il fatto tolto, un altro testo.
    rvsGiorno($this, '2027-03-05');
    NotaSintetica::where('esercizio_id', $s['e']->id)->update(['contenuto' => json_encode(\App\Services\Gestionale\NotaSinteticaDelRendiconto::normalizza([
        'fatti' => ['G1' => ['stato' => 'tolto']], 'parti' => ['altre' => ['testo' => 'Il testo di dopo, mai presentato.']]]))]);

    rvsConserva($this, $s, $v, 'nota');

    $testo = preg_replace('/\s+/u', ' ', rvsTestoDelPdf($v, 'nota'));
    expect(session('message')['type'] ?? null)->not->toBe('error')
        ->and($testo)->toContain('Il testo di allora, presentato.')->toContain('Le spese dell\'esercizio sono')
        ->not->toContain('Il testo di dopo')
        // Il frontespizio dice che la nota c'è: quella della versione.
        ->not->toContain(\App\Services\Gestionale\NotaSinteticaDelRendiconto::FRASE_SENZA_NOTA);
    // Una parte diversa dalla nota, con la nota cambiata, si conserva anch'essa: il cambiamento della nota non è un cambiamento dei conti.
    rvsConserva($this, $s, $v, 'riepilogo');
    expect(session('message')['type'] ?? null)->not->toBe('error')->and($v->fresh()->pdf['documenti'])->toHaveCount(2);
});

it('le differenze fra le cifre fermate e quelle di oggi: nessuna senza cambiamenti, al di là dell\'ora del calcolo e dello studio', function () {
    $cifre = ['calcolato_il' => '2027-03-01T10:00:00+01:00', 'intestazione' => ['studio' => ['nome' => 'A']], 'alla' => '2026-12-31', 'prospetto' => ['costi' => 100],
        'esito' => ['tornano' => 14], 'registro' => [['riga_id' => 1, 'impronta' => 'x']], 'conguaglio' => ['righe' => []]];
    $oggi = ['calcolato_il' => '2027-03-05T10:00:00+01:00', 'intestazione' => ['studio' => ['nome' => 'B']]] + $cifre;

    expect(VersioniDelRendiconto::differenze($cifre, $oggi))->toBe([])
        ->and(VersioniDelRendiconto::differenze($cifre, ['registro' => [['riga_id' => 1, 'impronta' => 'x'], ['riga_id' => 2, 'impronta' => 'y']]] + $oggi))
        ->toBe(['1 riga del registro di contabilità è nuova o cambiata'])
        ->and(VersioniDelRendiconto::differenze($cifre, ['esito' => ['tornano' => 13]] + $oggi))->toBe(['l\'esito dei controlli è cambiato'])
        ->and(VersioniDelRendiconto::differenze($cifre, ['alla' => '2027-03-05'] + $oggi))->toBe(['il giorno delle cifre era il 31/12/2026, oggi è il 05/03/2027'])
        // L'intestazione non è una cifra: il condominio con un altro nome non è un cambiamento dei conti.
        ->and(VersioniDelRendiconto::differenze($cifre, ['intestazione' => ['studio' => ['nome' => 'B'], 'condominio' => ['nome' => 'Via Nuova']]] + $oggi))->toBe([])
        ->and(VersioniDelRendiconto::differenze($cifre, ['prospetto' => ['costi' => 100, 'altro' => 1]] + $oggi))->toBe(['altre cifre del rendiconto'])
        // Una parte che la versione non ha (una forma più vecchia) non si confronta: decide la forma più vecchia delle due.
        ->and(VersioniDelRendiconto::differenze($cifre, ['situazione' => ['liquidita' => 100]] + $oggi))->toBe([]);
});

it('ogni parte conservata un altro giorno dice nel suo PDF la data della versione, e il calcolo del conguaglio della versione', function (bool $studio) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    if ($studio) {
        Storage::fake('public');
        cdsStudioPieno(true);
    }
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    $v = rvsFermaSenzaPdf($this, $s);
    rvsGiorno($this, '2027-03-05');

    $stampato = [];
    $calcolato = [];
    foreach (FascicoloDelRendiconto::PARTI as $parte) {
        rvsConserva($this, $s, $v, $parte);
        expect(session('message')['type'] ?? null)->toBe('success');
        $testo = rvsTestoDelPdf($v, $parte);
        preg_match_all('/(?:[Ss]tampato il|Documento emesso il) (\d\d\/\d\d\/\d{4})/', $testo, $m);
        $stampato[$parte] = array_values(array_unique($m[1]));
        preg_match_all('/calcolato il (\d\d\/\d\d\/\d{4})/', $testo, $m);
        $calcolato[$parte] = array_values(array_unique($m[1]));
        expect($testo)->not->toContain('05/03/2027');
    }

    // Il piede e «Stampato il» di ogni parte, il frontespizio compreso: il giorno della versione.
    expect($stampato)->toBe(array_fill_keys(FascicoloDelRendiconto::PARTI, ['01/03/2027']))
        // Le stampe del conguaglio dicono il giorno del calcolo: quello della versione, con le cifre uguali.
        ->and($calcolato['conguaglio'])->toBe(['01/03/2027']);
})->with(['senza studio' => [false], 'con lo studio e il logo' => [true]]);

it('dopo la prima parte conservata la versione dice ancora che il PDF non è intero, e quante parti ci sono', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    $v = rvsFermaSenzaPdf($this, $s, ['riepilogo', 'situazione', 'conguaglio']);
    rvsConserva($this, $s, $v, 'riepilogo');
    $riga = VersioniDelRendiconto::elenco($s['e'])[0];

    expect($riga['parti_conservate'])->toBe(['riepilogo'])
        ->and($riga['troppo'])->toBe('Il PDF del fascicolo non è conservato intero: conservata 1 parte su 3. Conserva le altre una per una, o carica il PDF che hai mandato.');

    rvsConserva($this, $s, $v, 'situazione');
    expect(VersioniDelRendiconto::elenco($s['e'])[0]['troppo'])->toContain('conservate 2 parti su 3');
    rvsConserva($this, $s, $v, 'conguaglio');
    expect(VersioniDelRendiconto::elenco($s['e'])[0]['troppo'])->toBeNull();
});

it('«Elimina la versione»: la fermata e mai approvata se ne va con i suoi documenti e i file, e l\'esercizio dopo si elimina; l\'approvata resta', function () {
    $s = cpcViaVerdi($this);
    $chiuso = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $fermata = RendicontoVersione::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $chiuso->id, 'numero' => 1, 'stato' => 'presentata', 'forma' => 1, 'cifre' => [],
        'calcolato_il' => '2026-03-01 09:00:00']);
    Storage::disk('local')->put('documenti/'.$s['c']->id.'/rendiconto/v1.pdf', '%PDF-1.4 versione 1');
    $documento = $fermata->documenti()->create(['name' => 'v1.pdf', 'description' => 'Fascicolo', 'path' => 'documenti/'.$s['c']->id.'/rendiconto/v1.pdf', 'created_by' => null]);
    $sintesi = route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $chiuso]);

    // Finché c'è, trattiene l'esercizio, e il rifiuto dice quale versione e dove si elimina.
    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.destroy', [$s['c'], $chiuso]));
    expect(session('message')['message'] ?? '')->toContain('la versione 1 del rendiconto, fermata per l\'assemblea il 01/03/2026 e mai approvata: si elimina dalla Sintesi del rendiconto');

    $this->actingAs($this->user)->from($sintesi)->delete(route('admin.gestionale.esercizi.rendiconto.versioni.destroy', [$s['c'], $chiuso, $fermata->id]))->assertRedirect($sintesi);
    expect(RendicontoVersione::find($fermata->id))->toBeNull()
        ->and(Documento::find($documento->id))->toBeNull()
        ->and(Storage::disk('local')->exists('documenti/'.$s['c']->id.'/rendiconto/v1.pdf'))->toBeFalse();

    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.destroy', [$s['c'], $chiuso]));
    expect(Esercizio::find($chiuso->id))->toBeNull();

    // L'approvata non si elimina: la frase dice perché, e la versione resta.
    $altro = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2024', 'data_inizio' => '2024-01-01', 'data_fine' => '2024-12-31', 'stato' => 'chiuso']);
    $approvata = RendicontoVersione::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $altro->id, 'numero' => 1, 'stato' => 'approvata', 'forma' => 1, 'cifre' => [],
        'calcolato_il' => '2025-03-01 09:00:00', 'data_delibera' => '2025-03-15']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.rendiconto.versioni.destroy', [$s['c'], $altro, $approvata->id]));
    expect(RendicontoVersione::find($approvata->id))->not->toBeNull()
        ->and(session('message')['message'] ?? '')->toBe('La versione 1 è approvata: resta, con il suo PDF, ed è la delibera dell\'assemblea. Si elimina solo una versione fermata e mai approvata.');
});

it('una bozza della nota salvata senza niente dentro non trattiene l\'esercizio', function () {
    $s = cpcViaVerdi($this);
    $chiuso = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    NotaSintetica::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $chiuso->id,
        'contenuto' => \App\Services\Gestionale\NotaSinteticaDelRendiconto::normalizza([])]);

    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.destroy', [$s['c'], $chiuso]));

    expect(Esercizio::find($chiuso->id))->toBeNull();
});

it('le cifre fermate che non tornano si dicono sulla versione, con il nome della voce; quelle che tornano no', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    $v = rvsFerma($this, $s, ['parti' => ['riepilogo']]);
    expect(VersioniDelRendiconto::elenco($s['e'])[0]['cifre_non_tornano'])->toBeNull();

    $cifre = $v->cifre;
    $cifre['conguaglio']['gestioni'][0]['invariante'] = [$s['pulizie']->id];
    DB::table('rendiconto_versioni')->where('id', $v->id)->update(['cifre' => json_encode($cifre)]);

    expect(VersioniDelRendiconto::elenco($s['e'])[0]['cifre_non_tornano'])->toBe('Le cifre del conguaglio fermate non tornano per «Pulizie»: uscite e voci ferme non sono '
        .'uguali alle spese delle persone più quelle non attribuite. È un errore del programma, non dei tuoi dati: il PDF è conservato com\'è. Segnalalo prima dell\'assemblea.');
});

it('le cifre del conguaglio copiano dalla lettura le voci ferme, il già versato da decidere e i giorni per ruolo, quando ci sono', function () {
    $letto = ['gestioni' => [7 => ['nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'non_calcolabile' => null, 'uscite' => [11 => 1000], 'voci_ferme' => [12 => 500],
        'non_attribuite' => [12 => 500], 'periodi_diversi' => [12 => ['Preventivo', 'Integrativo']], 'periodi_diversi_perche' => [12 => 'titolare'],
        'gia_versato_da_decidere' => [21 => 4000], 'totali' => ['a' => 1000]]],
        'persone' => [7 => [3 => [21 => ['voci' => [11 => 1000], 'a' => 1000, 'personali' => ['non_chieste' => 0], 'b' => ['quote' => 900, 'totale' => 900], 'conguaglio' => 100,
            'posizione' => 0, 'fuori_dalle_rate' => 0, 'saldo' => 100, 'non_disponibile' => null, 'gia_versato_non_usato' => ['questo_esercizio' => 0, 'tutti_gli_esercizi' => 0],
            'gia_versato_da_decidere' => 4000, 'gia_versato_da_decidere_perche' => 'inquilino', 'gia_versato_unita' => 0]]]],
        'da_altre_gestioni' => ['persone' => [], 'totali' => ['posizione' => 0, 'personali' => 0, 'conguaglio' => 0, 'saldo' => 0]]];
    $giorni = [3 => [21 => ['giorni' => null, 'giorni_per_ruolo' => [['ruolo' => 'inquilino', 'giorni' => 365], ['ruolo' => 'proprietario', 'giorni' => 306]]]]];

    $c = \App\Services\Gestionale\CifreDelRendiconto::conguaglioDallaLettura($letto, $giorni, [3 => 'Ines'], [21 => 'U2']);

    expect($c['gestioni'][0]['voci_ferme'])->toBe([['id' => 12, 'importo' => 500]])
        ->and($c['gestioni'][0]['gia_versato_da_decidere'])->toBe([['id' => 21, 'importo' => 4000]])
        ->and($c['gestioni'][0]['periodi_diversi'])->toBe([['id' => 12, 'piani' => ['Preventivo', 'Integrativo']]])
        ->and($c['gestioni'][0]['per_voce'])->toBe([['conto_id' => 11, 'uscite' => 1000, 'voci_ferme' => 0, 'persone' => 1000, 'non_attribuite' => 0],
            ['conto_id' => 12, 'uscite' => 0, 'voci_ferme' => 500, 'persone' => 0, 'non_attribuite' => 500]])
        ->and($c['gestioni'][0]['invariante'])->toBe([])
        ->and(array_intersect_key($c['righe'][0], array_flip(['nome', 'unita', 'giorni', 'giorni_per_ruolo', 'gia_versato_da_decidere', 'gia_versato_da_decidere_perche'])))
        ->toBe(['nome' => 'Ines', 'unita' => 'U2', 'gia_versato_da_decidere' => 4000, 'gia_versato_da_decidere_perche' => 'inquilino', 'giorni' => null,
            'giorni_per_ruolo' => [['ruolo' => 'inquilino', 'giorni' => 365], ['ruolo' => 'proprietario', 'giorni' => 306]]]);
});

it('lo stato del rendiconto anche nell\'elenco degli esercizi e nella scheda dell\'esercizio', function () {
    $s = rvsViaVerdi($this);
    rvsGiorno($this, '2027-03-01');
    $vecchio = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    RendicontoVersione::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $vecchio->id, 'numero' => 1, 'stato' => 'approvata', 'forma' => 1, 'cifre' => [],
        'calcolato_il' => '2026-03-01 09:00:00', 'data_delibera' => '2026-03-15', 'numero_verbale' => '4']);
    rvsFerma($this, $s, ['parti' => ['riepilogo']]);

    $elenco = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.index', $s['c']))->assertOk()->viewData('page')['props']['stati_rendiconto'];
    expect(array_map(fn ($x) => $x['breve'], $elenco))->toEqual([$s['e']->id => 'Fermato il 01/03/2027', $vecchio->id => 'Approvato il 15/03/2026'])
        ->and($elenco[$vecchio->id]['frase'])->toBe('Approvato il 15/03/2026 (verbale n. 4).');

    $scheda = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.edit', [$s['c'], $vecchio]))->assertOk()->viewData('page')['props'];
    expect($scheda['stato_rendiconto']['frase'])->toBe('Approvato il 15/03/2026 (verbale n. 4).');
});

<?php

/**
 * La scheda «Nota sintetica» dalla sua rotta, su Via Verdi (`NotaSinteticaFattiTest`, il caso del conguaglio con le spese scritte con le fatture): tieni, togli,
 * riscrivi; il fatto riscritto con le cifre cambiate; la copia dall'anno prima e da un altro condominio (i testi e gli elenchi, mai i fatti); i fornitori
 * suggeriti, che non si stampano; i testi dello studio; gli altri debiti certi sotto la riga della situazione patrimoniale; la frase dei controlli spiegati;
 * la versione che ferma le frasi della nota; le rotte dietro il pannello e nel condominio giusto.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\ModelloTesto;
use App\Models\User;
use App\Services\Gestionale\Lettere\ModelliLettere;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\SintesiDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        Permission::findOrCreate($permesso->value, 'web');
    }
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche',
        \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
});

/** Via Verdi al 01/03/2027 (come `nsfViaVerdi`, con un nome suo: le funzioni dei file di prova hanno nomi unici). */
function nssdViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $test->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));

    return $s;
}

function nssdSalva($test, array $s, array $contenuto)
{
    return $test->actingAs($test->user)->from(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->put(route('admin.gestionale.esercizi.rendiconto.nota.update', [$s['c'], $s['e']]), ['contenuto' => $contenuto]);
}

function nssdStampa(array $s): array
{
    $nota = app(NotaSinteticaDelRendiconto::class);
    $stampa = $nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e']));

    return collect($stampa['parti'])->flatMap(fn ($p) => array_column($p['fatti'], 'testo', 'chiave'))->all();
}

it('la scheda: le cinque parti con i fatti proposti, nessuno tenuto di serie; tieni, togli, riscrivi decidono che cosa si stampa', function () {
    $s = nssdViaVerdi($this);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/movimenti/rendiconto/NotaSintetica')
            ->where('nota.parti.0.chiave', 'criteri')->where('nota.parti.4.chiave', 'altre')->where('nota.vuota', true)
            ->where('nota.parti.1.fatti.0.chiave', 'G1')->where('nota.parti.1.fatti.0.stato', 'proposto')
            ->where('stato_rendiconto.stato', 'bozza'));
    // Nessun fatto tenuto: la stampa non ha niente.
    expect(nssdStampa($s))->toBe([]);

    $g1 = 'Le spese dell\'esercizio sono €'."\u{a0}".'4.200,00 e le quote chieste ai condòmini con le rate sono €'."\u{a0}".'4.000,00: le spese superano le quote di €'."\u{a0}".'200,00 (disavanzo).';
    nssdSalva($this, $s, ['fatti' => [
        'G1' => ['stato' => 'tenuto'],
        'K1' => ['stato' => 'tolto'],
        'G7' => ['stato' => 'tenuto', 'riscritto' => 'Il conguaglio da approvare è di € 280,00: lo dico io.', 'proposto' => 'La frase di allora.', 'il' => '2027-03-01'],
        // Un fatto tolto con una frase riscritta: la frase non resta.
        'N4' => ['stato' => 'tolto', 'riscritto' => 'non resta'],
    ]])->assertSessionHasNoErrors();
    expect(nssdStampa($s))->toBe(['G1' => $g1, 'G7' => 'Il conguaglio da approvare è di € 280,00: lo dico io.'])
        ->and(NotaSinteticaDelRendiconto::contenuto($s['e'])['fatti']['N4'])->toBe(['stato' => 'tolto', 'riscritto' => null, 'proposto' => null, 'il' => null]);
});

it('il fatto riscritto: quando le cifre cambiano la pagina dice che cosa direbbe oggi, e la stampa tiene la frase dell\'amministratore; il fatto che non c\'è più sparisce', function () {
    $s = nssdViaVerdi($this);
    $nota = app(NotaSinteticaDelRendiconto::class);
    $oggi = collect($nota->perLaScheda($s['c'], $s['e'], $nota->letture($s['c'], $s['e']))['parti'][1]['fatti'])->firstWhere('chiave', 'G1')['testo_di_oggi'];
    nssdSalva($this, $s, ['fatti' => ['G1' => ['stato' => 'tenuto', 'riscritto' => 'Abbiamo speso € 200,00 più delle quote.', 'proposto' => $oggi, 'il' => '2027-03-01'],
        'Q8' => ['stato' => 'tenuto']]])->assertSessionHasNoErrors();
    $prima = collect($nota->perLaScheda($s['c'], $s['e'], $nota->letture($s['c'], $s['e']))['parti'][1]['fatti'])->firstWhere('chiave', 'G1');
    expect($prima['cambiato'])->toBeFalse();

    // Una fattura di Manutenzioni di € 60,00 scritta nel 2026: le spese passano a € 4.260,00.
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 6000, []);
    $scheda = $nota->perLaScheda($s['c'], $s['e'], $nota->letture($s['c'], $s['e']));
    $g1 = collect($scheda['parti'][1]['fatti'])->firstWhere('chiave', 'G1');
    expect($g1['cambiato'])->toBeTrue()
        ->and($g1['testo_di_oggi'])->toContain('€'."\u{a0}".'4.260,00')->toContain('€'."\u{a0}".'260,00 (disavanzo)')
        ->and($g1['proposto_allora'])->toBe($oggi)
        ->and(nssdStampa($s)['G1'])->toBe('Abbiamo speso € 200,00 più delle quote.')
        // Q8, tenuto, oggi non si propone (ogni passaggio è registrato): la pagina lo dice, la stampa no.
        ->and(array_column($scheda['spariti'], 'chiave'))->toBe(['Q8'])
        ->and(nssdStampa($s))->not->toHaveKey('Q8');
});

it('«Copia dall\'anno prima» e «Copia da un altro condominio»: i testi, gli elenchi e la dichiarazione, mai i fatti, le cause e le spiegazioni', function () {
    $s = nssdViaVerdi($this);
    $prima = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    NotaSinteticaDelRendiconto::salva($prima, NotaSinteticaDelRendiconto::normalizza([
        'parti' => ['altre' => ['testo' => 'I documenti si vedono in studio.']], 'fatti' => ['G1' => ['stato' => 'tenuto']], 'cause' => ['9' => 'una causa'],
        'spiegazioni' => ['R6' => 'una spiegazione'], 'contratti' => [['cosa' => 'Pulizie', 'con_chi' => 'Lucenti', 'importo' => 300000, 'scadenza' => '2026-12-31']],
        'debiti_certi' => [['creditore' => 'Studio legale', 'importo' => 350000, 'fonte' => 'sentenza']],
        'dichiarazione' => ['liti' => ['stato' => 'nessuna']],
    ]), $this->user);
    $copia = $this->actingAs($this->user)->getJson(route('admin.gestionale.esercizi.rendiconto.nota.copia', [$s['c'], $s['e']]).'?da='.$prima->id)->assertOk()->json();
    expect($copia['da'])->toBe('la bozza della nota di «Esercizio 2025»')
        ->and($copia['contenuto']['parti']['altre']['testo'])->toBe('I documenti si vedono in studio.')
        ->and($copia['contenuto']['contratti'][0])->toMatchArray(['cosa' => 'Pulizie', 'importo' => 300000, 'scadenza' => '2026-12-31'])
        ->and($copia['contenuto']['debiti_certi'][0]['importo'])->toBe(350000)
        ->and($copia['contenuto']['dichiarazione']['liti']['stato'])->toBe('nessuna')
        ->and($copia['contenuto']['fatti'])->toBe([])->and($copia['contenuto']['cause'])->toBe([])->and($copia['contenuto']['spiegazioni'])->toBe([]);
    // La scheda sa qual è l'anno prima.
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('anno_prima.id', $prima->id));

    // Da un altro condominio, con una versione approvata: la nota della versione, non la bozza di dopo.
    $altro = Condominio::factory()->create(['nome' => 'Via Gialli']);
    $suo = Esercizio::factory()->create(['condominio_id' => $altro->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    NotaSinteticaDelRendiconto::salva($suo, NotaSinteticaDelRendiconto::normalizza(['parti' => ['criteri' => ['testo' => 'La bozza di dopo.']]]), $this->user);
    RendicontoVersione::create(['condominio_id' => $altro->id, 'esercizio_id' => $suo->id, 'numero' => 1, 'stato' => RendicontoVersione::APPROVATA, 'forma' => RendicontoVersione::FORMA,
        'cifre' => [], 'nota' => ['bozza' => NotaSinteticaDelRendiconto::normalizza(['parti' => ['criteri' => ['testo' => 'La nota approvata.']]]), 'stampa' => []],
        'calcolato_il' => now(), 'pdf' => [], 'data_delibera' => '2027-02-01']);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('altri.0.esercizio_id', $suo->id)->where('altri.0.condominio', 'Via Gialli'));
    $daAltro = $this->actingAs($this->user)->getJson(route('admin.gestionale.esercizi.rendiconto.nota.copia', [$s['c'], $s['e']]).'?da='.$suo->id)->assertOk()->json();
    expect($daAltro['contenuto']['parti']['criteri']['testo'])->toBe('La nota approvata.')
        ->and($daAltro['da'])->toBe('la nota della versione 1 (approvata) di «Esercizio 2026», condominio «Via Gialli»');
    // Un esercizio senza nota: lo dice.
    $vuoto = Esercizio::factory()->create(['condominio_id' => $altro->id, 'nome' => 'Esercizio 2024', 'data_inizio' => '2024-01-01', 'data_fine' => '2024-12-31', 'stato' => 'chiuso']);
    $this->actingAs($this->user)->getJson(route('admin.gestionale.esercizi.rendiconto.nota.copia', [$s['c'], $s['e']]).'?da='.$vuoto->id)->assertNotFound()
        ->assertJson(['message' => 'L\'esercizio «Esercizio 2024» non ha una nota da copiare.']);
});

it('i fornitori dell\'anno sono un suggerimento per i contratti: nella scheda sì, nella stampa no', function () {
    $s = nssdViaVerdi($this);
    $categoria = DB::table('categorie_fornitore')->insertGetId(['name' => 'Servizi di pulizia', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('fornitori')->where('id', $s['giornale']['fornitore'])->update(['categoria_id' => $categoria, 'ragione_sociale' => 'Pulizie Lucenti']);
    DB::table('fatture_passive')->insert(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $s['giornale']['fornitore'], 'tipo_documento' => 'fattura',
        'numero_documento' => 'NSSD-1', 'data_documento' => '2026-05-01', 'data_scadenza' => '2026-05-31', 'importo_imponibile' => 1000, 'importo_iva' => 0, 'totale_documento' => 1000, 'netto_a_pagare' => 1000,
        'stato_approvazione' => 'approvata', 'created_at' => now(), 'updated_at' => now()]);
    $nota = app(NotaSinteticaDelRendiconto::class);
    $scheda = $nota->perLaScheda($s['c'], $s['e'], $nota->letture($s['c'], $s['e']));
    expect($scheda['fornitori'])->toContain(['categoria' => 'Servizi di pulizia', 'fornitore' => 'Pulizie Lucenti']);
    nssdSalva($this, $s, ['parti' => ['altre' => ['testo' => 'Una riga.']]]);
    $stampa = $nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e']));
    expect(json_encode($stampa, JSON_UNESCAPED_UNICODE))->not->toContain('Pulizie Lucenti');
});

it('i testi dello studio della nota: «Salva come testo dello studio» li salva nella libreria, per il condominio o per tutti, fuori dai modelli delle lettere', function () {
    $s = nssdViaVerdi($this);
    $rotta = route('admin.gestionale.nota-sintetica.testi.store', [$s['c']]);
    $r = $this->actingAs($this->user)->postJson($rotta, ['tipo' => 'altre', 'nome' => 'Documenti in studio', 'testo' => 'I documenti si vedono in studio, su appuntamento.', 'per_tutti' => false])
        ->assertCreated()->json();
    expect($r['testo'])->toMatchArray(['tipo' => 'altre', 'nome' => 'Documenti in studio', 'per_tutti' => false])
        ->and(ModelloTesto::where('ambito', ModelloTesto::AMBITO_NOTA)->value('condominio_id'))->toBe($s['c']->id)
        // Non è un modello delle lettere.
        ->and(app(ModelliLettere::class)->delCondominio($s['c']))->toBe([]);
    // Lo stesso nome per lo stesso campo e «vale per»: rifiutato.
    $this->actingAs($this->user)->postJson($rotta, ['tipo' => 'altre', 'nome' => ' documenti  in studio ', 'testo' => 'Altro.', 'per_tutti' => false])->assertUnprocessable();
    // La scheda li porta; un altro condominio vede solo quelli per tutti.
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('testi_studio.0.nome', 'Documenti in studio')->where('puo_salvare_testi', true));
    // Chi non gestisce le impostazioni di stampa non salva.
    $collaboratore = User::factory()->create();
    $ruolo = Role::firstOrCreate(['name' => 'collaboratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo('Accesso pannello amministratore');
    $collaboratore->assignRole($ruolo);
    $this->actingAs($collaboratore)->postJson($rotta, ['tipo' => 'altre', 'nome' => 'Mio', 'testo' => 'x', 'per_tutti' => true])->assertForbidden();
});

it('gli altri debiti certi della nota sotto la riga della situazione patrimoniale, nella pagina e nella stampa; la frase non dice più che la nota non c\'è', function () {
    $s = nssdViaVerdi($this);
    nssdSalva($this, $s, ['debiti_certi' => [['creditore' => 'Studio legale Prova', 'importo' => '3500.00', 'fonte' => 'sentenza del Tribunale', 'scadenza' => '2027-03-31']]])
        ->assertSessionHasNoErrors();
    // L'importo arriva in euro dalla pagina e si conserva in centesimi.
    expect(NotaSinteticaDelRendiconto::contenuto($s['e'])['debiti_certi'][0]['importo'])->toBe(350000);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c'], $s['e']]))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('debiti_certi.0.creditore', 'Studio legale Prova')->where('debiti_certi.0.importo', 350000));
    $situazione = app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);
    $html = view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => $situazione, 'parte' => 'situazione',
        'controlli' => null, 'esito' => null, 'anno_a_cavallo' => null, 'senza_firma' => true,
        'debiti_certi' => NotaSinteticaDelRendiconto::contenuto($s['e'])['debiti_certi']])->render();
    expect(html_entity_decode(strip_tags($html)))->toContain('Altri debiti certi, che non sono fatture: non sono nel giornale né nei totali, li ha scritti l\'amministratore nella nota sintetica.')
        ->toContain('Studio legale Prova')->toContain('€'."\u{a0}".'3.500,00');
    $vue = (string) file_get_contents(base_path('resources/js/pages/gestionale/movimenti/statoPatrimoniale/Show.vue'));
    expect($vue)->not->toContain('il programma non ha una nota esplicativa del rendiconto dove scriverlo')->toContain('«Altri debiti certi»');
});

it('la frase dei controlli per chi legge: rimanda alla nota per quelli spiegati, all\'assemblea per gli altri', function () {
    $esito = ['tornano' => 10, 'non_tornano' => 1, 'da_leggere' => 2, 'non_calcolati' => 0];
    expect(SintesiDelRendiconto::controlliPerChiLegge($esito))->toStartWith('I controlli: 10 tornano, 1 non torna, 2 da leggere (l\'amministratore li spiegherà in assemblea).')
        ->and(SintesiDelRendiconto::controlliPerChiLegge($esito, 3))->toStartWith('I controlli: 10 tornano, 1 non torna, 2 da leggere (la spiegazione è nella nota sintetica).')
        ->and(SintesiDelRendiconto::controlliPerChiLegge($esito, 1))->toStartWith('I controlli: 10 tornano, 1 non torna, 2 da leggere (1 spiegato nella nota sintetica; gli altri 2 l\'amministratore li spiegherà in assemblea).')
        ->and(SintesiDelRendiconto::controlliPerChiLegge($esito, 2))->toStartWith('I controlli: 10 tornano, 1 non torna, 2 da leggere (2 spiegati nella nota sintetica; l\'altro l\'amministratore lo spiegherà in assemblea).');
});

it('la versione ferma le frasi della nota: cambiare la nota dopo non cambia la versione; la Sintesi dice che cosa manca, la finestra dell\'approvazione anche', function () {
    $s = nssdViaVerdi($this);
    nssdSalva($this, $s, ['fatti' => ['G1' => ['stato' => 'tenuto'], 'G7' => ['stato' => 'tenuto']], 'parti' => ['altre' => ['testo' => 'Il testo di allora.']]]);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('nota.vuota', false)
            ->where('nota.mancanze', fn ($m) => in_array('La parte «I criteri del rendiconto» non ha né fatti tenuti né testo: tieni un suo fatto o scrivi il testo, in quella parte della nota.', $m->all(), true)
                && in_array('La dichiarazione sulle liti, le impugnazioni e i sinistri non è fatta: la trovi nella parte «Le questioni pendenti» della nota.', $m->all(), true)));
    $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))
        ->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s['c'], $s['e']]), ['parti' => ['nota', 'riepilogo']])->assertSessionHasNoErrors();
    $v = RendicontoVersione::where('esercizio_id', $s['e']->id)->firstOrFail();
    $frasi = collect($v->nota['stampa']['parti'])->flatMap(fn ($p) => array_column($p['fatti'], 'testo', 'chiave'))->all();
    expect($frasi['G1'])->toContain('€'."\u{a0}".'4.200,00')->and($frasi['G7'])->toContain('€'."\u{a0}".'280,00')
        ->and($v->nota['bozza']['parti']['altre']['testo'])->toBe('Il testo di allora.');

    // Dopo: una fattura in più e la nota riscritta. La versione non cambia.
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 6000, []);
    nssdSalva($this, $s, ['fatti' => ['G1' => ['stato' => 'tolto']], 'parti' => ['altre' => ['testo' => 'Il testo di dopo.']]]);
    $fresca = $v->fresh();
    expect(collect($fresca->nota['stampa']['parti'])->flatMap(fn ($p) => array_column($p['fatti'], 'testo', 'chiave'))->all())->toBe($frasi)
        ->and($fresca->nota['bozza']['parti']['altre']['testo'])->toBe('Il testo di allora.')
        ->and(nssdStampa($s))->not->toHaveKey('G1');
    // La finestra dell'approvazione dice che cosa mancava alla nota della versione.
    $elenco = \App\Services\Gestionale\VersioniDelRendiconto::elenco($s['e']);
    expect($elenco[0]['nota_mancanze'])->toContain('La dichiarazione sulle liti, le impugnazioni e i sinistri non è fatta: la trovi nella parte «Le questioni pendenti» della nota.');
});

it('le rotte della nota sono dietro il pannello e nel condominio giusto', function () {
    $s = nssdViaVerdi($this);
    $altro = Condominio::factory()->create();
    foreach (['admin.gestionale.esercizi.rendiconto.nota', 'admin.gestionale.esercizi.rendiconto.nota.stampa'] as $nome) {
        $this->actingAs($this->user)->get(route($nome, [$altro, $s['e']]))->assertNotFound();
    }
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.rendiconto.nota.update', [$altro, $s['e']]), ['contenuto' => ['parti' => []]])->assertNotFound();
    $this->actingAs($this->user)->getJson(route('admin.gestionale.esercizi.rendiconto.nota.copia', [$altro, $s['e']]).'?da='.$s['e']->id)->assertNotFound();
    // Fuori dal pannello: un utente senza il permesso del pannello non entra.
    $condomino = User::factory()->create();
    $this->actingAs($condomino)->get(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))->assertForbidden();
});

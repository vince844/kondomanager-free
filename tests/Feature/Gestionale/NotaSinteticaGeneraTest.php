<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Genera la nota» e le cose copiate, su Via Verdi al 01/03/2027 con la nota dell'esercizio 2025: in un clic i fatti proposti tenuti (non quelli tolti) e i
 * testi e gli elenchi dell'anno prima dove mancano, niente di inventato; la dichiarazione e i dipendenti copiati restano da confermare e non si stampano
 * finché l'amministratore non li conferma; ogni uso lascia una riga nel registro della nota, che la pagina non scrive e che resta quando la nota cambia.
 * La copia da un altro condominio porta i contratti senza importo e scadenza. Le risposte «ci sono» senza testo, i testi dello studio rinominati ed
 * eliminati, le lunghezze di una frase e di una riga.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ModelloTesto;
use App\Models\User;
use App\Services\Gestionale\FattiDellaNota;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
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
    $this->user = User::factory()->create(['name' => 'Anna Rossi']);
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
});

function nsgViaVerdi($test): array
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

/** L'esercizio 2025 dello stesso condominio, con la sua nota: un testo, un contratto, un debito, la dichiarazione intera e i dipendenti. */
function nsgAnnoPrima(array $s, $test): Esercizio
{
    $prima = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    NotaSinteticaDelRendiconto::salva($prima, NotaSinteticaDelRendiconto::normalizza([
        'parti' => ['altre' => ['testo' => 'I documenti si vedono in studio.'], 'criteri' => ['testo' => 'Il criterio di allora.']],
        'fatti' => ['G1' => ['stato' => 'tenuto']],
        'contratti' => [['cosa' => 'Pulizie', 'con_chi' => 'Lucenti', 'importo' => 300000, 'scadenza' => '2026-12-31']],
        'debiti_certi' => [['creditore' => 'Studio legale', 'importo' => 350000, 'fonte' => 'sentenza']],
        'dichiarazione' => ['liti' => ['stato' => 'nessuna'], 'impugnazioni' => ['stato' => 'nessuna'], 'sinistri' => ['stato' => 'nessuna']],
        'dipendenti' => ['stato' => 'nessuno'],
    ]), $test->user);

    return $prima;
}

function nsgStampa(array $s): array
{
    $nota = app(NotaSinteticaDelRendiconto::class);

    return $nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e']));
}

function nsgGenera($test, array $s, string $nota = '')
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.esercizi.rendiconto.nota.genera', [$s['c'], $s['e']]), ['nota' => $nota]);
}

it('«Genera la nota»: tiene i fatti proposti, non quelli tolti; copia dall\'anno prima i testi delle parti vuote e gli elenchi; non inventa niente', function () {
    $s = nsgViaVerdi($this);
    nsgAnnoPrima($s, $this);
    // L'amministratore ha già tolto K1 e scritto i criteri: restano suoi.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['fatti' => ['K1' => ['stato' => 'tolto']],
        'parti' => ['criteri' => ['testo' => 'Il mio criterio di quest\'anno.']]]), $this->user);
    $nota = app(NotaSinteticaDelRendiconto::class);
    $proposti = array_column(app(FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], $nota->letture($s['c'], $s['e']))['fatti'], 'chiave');

    $esito = nsgGenera($this, $s, 'La bozza per il consiglio')->assertOk()->json();
    $c = NotaSinteticaDelRendiconto::contenuto($s['e']);

    expect($esito['tenuti'])->toBe(count($proposti) - 1)
        ->and($esito['copiato_da'])->toBe('la bozza della nota di «Esercizio 2025»')
        ->and($c['fatti']['K1']['stato'])->toBe('tolto')
        ->and(collect($proposti)->reject(fn ($k) => $k === 'K1')->every(fn ($k) => ($c['fatti'][$k]['stato'] ?? null) === 'tenuto'))->toBeTrue()
        ->and($c['parti']['criteri']['testo'])->toBe('Il mio criterio di quest\'anno.')
        ->and($c['parti']['altre']['testo'])->toBe('I documenti si vedono in studio.')
        ->and($c['contratti'][0])->toMatchArray(['cosa' => 'Pulizie', 'importo' => 300000])
        ->and($c['debiti_certi'][0]['creditore'])->toBe('Studio legale')
        // Le cause e le spiegazioni sono dei conti di quest'anno: non arrivano.
        ->and($c['cause'])->toBe([])->and($c['spiegazioni'])->toBe([])
        // La dichiarazione e i dipendenti copiati: da confermare.
        ->and(collect($c['dichiarazione'])->every(fn ($d) => $d['stato'] === 'nessuna' && $d['da_confermare'] === true))->toBeTrue()
        ->and($c['dipendenti'])->toMatchArray(['stato' => 'nessuno', 'da_confermare' => true])
        // La finestra dice che cosa manca.
        ->and($esito['mancanze'])->toContain('La dichiarazione copiata da un\'altra nota è da confermare: non si stampa finché non la confermi, nella parte «Le questioni pendenti» della nota.')
        ->toContain('La riga dei dipendenti copiata da un\'altra nota è da confermare: non si stampa finché non la confermi, nella parte «I rapporti in corso» della nota.')
        ->toContain('I contratti sono quelli dell\'anno prima: rileggi scadenze e importi, nella parte «I rapporti in corso» della nota.');
    // Non confermate, la dichiarazione e i dipendenti non si stampano.
    $questioni = collect(nsgStampa($s)['parti'])->firstWhere('chiave', 'questioni');
    $rapporti = collect(nsgStampa($s)['parti'])->firstWhere('chiave', 'rapporti');
    expect($questioni['dichiarazione'])->toBe([])->and($rapporti['dipendenti'])->toBeNull();
});

it('il registro della nota: chi, quando, che cosa mancava e la sua nota; la pagina non lo scrive, e resta quando la nota cambia', function () {
    $s = nsgViaVerdi($this);
    nsgAnnoPrima($s, $this);
    $esito = nsgGenera($this, $s, 'La bozza per il consiglio')->assertOk()->json();
    $registro = NotaSinteticaDelRendiconto::contenuto($s['e'])['registro'];
    expect($registro)->toHaveCount(1)
        ->and($registro[0])->toMatchArray(['tipo' => 'genera', 'utente_id' => $this->user->id, 'utente' => 'Anna Rossi', 'nota' => 'La bozza per il consiglio',
            'il' => now()->utc()->toIso8601String()])
        ->and($registro[0]['dati']['mancanze'])->toBe($esito['mancanze'])
        ->and($registro[0]['dati']['tenuti'])->toBe($esito['tenuti']);

    // La pagina salva la nota cambiata, con un registro suo nel contenuto: vale quello salvato.
    $contenuto = NotaSinteticaDelRendiconto::contenuto($s['e']);
    $contenuto['parti']['altre']['testo'] = 'Il testo di dopo.';
    $contenuto['registro'] = [['tipo' => 'genera', 'il' => '2020-01-01T00:00:00+00:00', 'utente' => 'Nessuno', 'nota' => 'scritto dalla pagina', 'dati' => []]];
    $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->put(route('admin.gestionale.esercizi.rendiconto.nota.update', [$s['c'], $s['e']]), ['contenuto' => $contenuto])->assertSessionHasNoErrors();
    expect(NotaSinteticaDelRendiconto::contenuto($s['e'])['parti']['altre']['testo'])->toBe('Il testo di dopo.')
        ->and(NotaSinteticaDelRendiconto::contenuto($s['e'])['registro'])->toBe($registro);

    // Un secondo uso aggiunge una riga; la scheda le mostra, la più recente prima, con il giorno e l'ora dell'amministratore.
    $this->travelTo(\Carbon\Carbon::parse('2027-03-02 18:30', 'Europe/Rome'));
    nsgGenera($this, $s)->assertOk();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('nota.registro', 2)->where('nota.registro.0.quando', '02/03/2027 18:30')
            ->where('nota.registro.0.nota', '')->where('nota.registro.1.nota', 'La bozza per il consiglio')->where('nota.registro.1.utente', 'Anna Rossi'));
});

it('«Genera la nota» senza un anno prima con una nota: tiene i fatti e dice che i testi non si copiano; la sua nota ha una lunghezza massima', function () {
    $s = nsgViaVerdi($this);
    $esito = nsgGenera($this, $s)->assertOk()->json();
    expect($esito['copiato_da'])->toBeNull()
        ->and($esito['mancanze'][0])->toBe('Non c\'è un esercizio prima con una nota: i testi e gli elenchi non si copiano.')
        ->and($esito['tenuti'])->toBeGreaterThan(0);
    nsgGenera($this, $s, str_repeat('a', NotaSinteticaDelRendiconto::LUNGHEZZA_RIGA + 1))->assertStatus(422)->assertJsonValidationErrors('nota');
    // L'esercizio di un altro condominio: 404.
    $altro = Condominio::factory()->create();
    $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.nota.genera', [$altro, $s['e']]), ['nota' => ''])->assertNotFound();
});

it('la dichiarazione e i dipendenti copiati si stampano quando l\'amministratore li conferma; la copia non li fa contare come nota scritta', function () {
    $s = nsgViaVerdi($this);
    $prima = nsgAnnoPrima($s, $this);
    $copia = NotaSinteticaDelRendiconto::copiabile($prima)['contenuto'];
    $copia['parti'] = array_fill_keys(array_keys(NotaSinteticaDelRendiconto::PARTI), ['testo' => '']);
    $copia['contratti'] = [];
    $copia['debiti_certi'] = [];
    NotaSinteticaDelRendiconto::salva($s['e'], $copia, $this->user);
    expect(NotaSinteticaDelRendiconto::bozzaVuota(NotaSinteticaDelRendiconto::contenuto($s['e'])))->toBeTrue()
        ->and(nsgStampa($s)['vuota'])->toBeTrue();

    // Dalla pagina, con la conferma: la dichiarazione e i dipendenti si stampano.
    $c = NotaSinteticaDelRendiconto::contenuto($s['e']);
    foreach (array_keys($c['dichiarazione']) as $k) {
        $c['dichiarazione'][$k]['da_confermare'] = false;
    }
    $c['dipendenti']['da_confermare'] = false;
    $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->put(route('admin.gestionale.esercizi.rendiconto.nota.update', [$s['c'], $s['e']]), ['contenuto' => $c])->assertSessionHasNoErrors();
    $parti = collect(nsgStampa($s)['parti'])->keyBy('chiave');
    expect($parti['questioni']['dichiarazione'][0])->toStartWith('L\'amministratore dichiara che non ci sono cause in corso')
        ->and($parti['rapporti']['dipendenti'])->toBe('Il condominio non ha dipendenti.');
});

it('la copia da un altro condominio: i contratti senza importo e scadenza, e lo dice; dall\'anno prima con le loro cifre', function () {
    $s = nsgViaVerdi($this);
    $altro = Condominio::factory()->create(['nome' => 'Via Gialli']);
    $suo = Esercizio::factory()->create(['condominio_id' => $altro->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    NotaSinteticaDelRendiconto::salva($suo, NotaSinteticaDelRendiconto::normalizza([
        'contratti' => [['cosa' => 'Ascensore', 'con_chi' => 'Ascensori Prova', 'importo' => 240000, 'scadenza' => '2027-06-30', 'rinnovo' => 'tacito']],
        'dichiarazione' => ['liti' => ['stato' => 'nessuna']], 'dipendenti' => ['stato' => 'ci_sono', 'testo' => 'Un portiere'],
    ]), $this->user);
    $daAltro = $this->actingAs($this->user)->getJson(route('admin.gestionale.esercizi.rendiconto.nota.copia', [$s['c'], $s['e']]).'?da='.$suo->id)->assertOk()->json();
    expect($daAltro['altro_condominio'])->toBeTrue()
        ->and($daAltro['contenuto']['contratti'][0])->toMatchArray(['cosa' => 'Ascensore', 'con_chi' => 'Ascensori Prova', 'rinnovo' => 'tacito', 'importo' => null, 'scadenza' => null])
        ->and($daAltro['contenuto']['dichiarazione']['liti'])->toMatchArray(['stato' => 'nessuna', 'da_confermare' => true])
        ->and($daAltro['contenuto']['dipendenti'])->toMatchArray(['stato' => 'ci_sono', 'da_confermare' => true]);
    $prima = nsgAnnoPrima($s, $this);
    $dallAnnoPrima = $this->actingAs($this->user)->getJson(route('admin.gestionale.esercizi.rendiconto.nota.copia', [$s['c'], $s['e']]).'?da='.$prima->id)->assertOk()->json();
    expect($dallAnnoPrima['altro_condominio'])->toBeFalse()
        ->and($dallAnnoPrima['contenuto']['contratti'][0])->toMatchArray(['importo' => 300000, 'scadenza' => '2026-12-31']);
});

it('«ci sono» senza dire quali: la stampa dice la frase senza un trattino, e la finestra lo mette fra le cose da completare', function () {
    $s = nsgViaVerdi($this);
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['dichiarazione' => ['liti' => ['stato' => 'ci_sono'], 'impugnazioni' => ['stato' => 'nessuna'],
        'sinistri' => ['stato' => 'ci_sono', 'testo' => 'una infiltrazione dal tetto']]]), $this->user);
    $dichiarazione = collect(nsgStampa($s)['parti'])->firstWhere('chiave', 'questioni')['dichiarazione'];
    $mancanze = NotaSinteticaDelRendiconto::mancanze(NotaSinteticaDelRendiconto::contenuto($s['e']), []);

    expect($dichiarazione)->toBe(['L\'amministratore dichiara che nessuna delibera dell\'assemblea è stata impugnata.', 'Ci sono cause in corso.',
        'Ci sono sinistri aperti. una infiltrazione dal tetto.'])
        ->and(implode(' ', $dichiarazione))->not->toContain('—')
        ->and($mancanze)->toContain('Hai scritto «ci sono cause in corso», ma non quali: scrivilo nella parte «Le questioni pendenti» della nota.')
        ->not->toContain('Hai scritto «ci sono sinistri aperti», ma non quali: scrivilo nella parte «Le questioni pendenti» della nota.');
});

it('nessuna parte toccata: le cinque righe delle parti sono una, che dice dove si comincia', function () {
    $s = nsgViaVerdi($this);
    $mancanze = NotaSinteticaDelRendiconto::mancanze(NotaSinteticaDelRendiconto::contenuto($s['e']), []);
    expect($mancanze[0])->toBe(NotaSinteticaDelRendiconto::FRASE_INTATTA)
        ->and(collect($mancanze)->filter(fn ($m) => str_starts_with($m, 'La parte «'))->all())->toBe([]);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('nota.intatta', true)->where('nota.frase_intatta', NotaSinteticaDelRendiconto::FRASE_INTATTA));
    // Una parte toccata: le altre quattro hanno la loro riga.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['parti' => ['altre' => ['testo' => 'Un testo.']]]), $this->user);
    $mancanze = NotaSinteticaDelRendiconto::mancanze(NotaSinteticaDelRendiconto::contenuto($s['e']), []);
    expect($mancanze)->not->toContain(NotaSinteticaDelRendiconto::FRASE_INTATTA)
        ->and(collect($mancanze)->filter(fn ($m) => str_starts_with($m, 'La parte «'))->count())->toBe(4);
});

it('i testi dello studio della nota: si rinominano e si eliminano da chi gestisce le stampe; non quelli delle lettere né di un altro condominio', function () {
    $s = nsgViaVerdi($this);
    $rotta = fn (string $azione, $id) => route('admin.gestionale.nota-sintetica.testi.'.$azione, [$s['c'], $id]);
    $testo = ModelloTesto::create(['ambito' => ModelloTesto::AMBITO_NOTA, 'tipo' => 'altre', 'nome' => 'Documenti in studio', 'testo' => 'I documenti si vedono in studio.', 'condominio_id' => null]);
    ModelloTesto::create(['ambito' => ModelloTesto::AMBITO_NOTA, 'tipo' => 'altre', 'nome' => 'Orari', 'testo' => 'Lo studio è aperto il martedì.', 'condominio_id' => null]);
    $diUnAltro = ModelloTesto::create(['ambito' => ModelloTesto::AMBITO_NOTA, 'tipo' => 'altre', 'nome' => 'Suo', 'testo' => 'x', 'condominio_id' => Condominio::factory()->create()->id]);
    $lettera = ModelloTesto::create(['ambito' => ModelloTesto::AMBITO_LETTERA, 'tipo' => 'sollecito', 'nome' => 'Sollecito', 'testo' => 'x', 'condominio_id' => null]);

    $this->actingAs($this->user)->putJson($rotta('update', $testo->id), ['nome' => '  Documenti   dallo studio '])->assertOk()
        ->assertJsonPath('testi.0.nome', 'Documenti dallo studio');
    expect($testo->fresh()->nome)->toBe('Documenti dallo studio')->and($testo->fresh()->updated_by)->toBe($this->user->id);
    // Lo stesso nome di un altro testo dello stesso campo: no.
    $this->actingAs($this->user)->putJson($rotta('update', $testo->id), ['nome' => 'orari'])->assertStatus(422)->assertJsonValidationErrors('nome');
    $this->actingAs($this->user)->putJson($rotta('update', $diUnAltro->id), ['nome' => 'Mio'])->assertNotFound();
    $this->actingAs($this->user)->deleteJson($rotta('destroy', $lettera->id))->assertNotFound();
    $this->actingAs($this->user)->deleteJson($rotta('destroy', $testo->id))->assertOk();
    expect(ModelloTesto::find($testo->id))->toBeNull()->and(ModelloTesto::find($lettera->id))->not->toBeNull();

    // Chi non gestisce le stampe non li tocca.
    $ruolo = Role::firstOrCreate(['name' => 'collaboratore-nsg', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $chi = User::factory()->create();
    $chi->assignRole($ruolo);
    $orari = ModelloTesto::where('nome', 'Orari')->firstOrFail();
    $this->actingAs($chi)->deleteJson($rotta('destroy', $orari->id))->assertForbidden();
    $this->actingAs($chi)->putJson($rotta('update', $orari->id), ['nome' => 'Altro'])->assertForbidden();
});

it('le lunghezze: una frase (un fatto riscritto, una causa, una spiegazione, una risposta) e una riga (una cosa da decidere, i dipendenti)', function () {
    $s = nsgViaVerdi($this);
    $metti = fn (array $contenuto) => $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->put(route('admin.gestionale.esercizi.rendiconto.nota.update', [$s['c'], $s['e']]), ['contenuto' => $contenuto]);
    $frase = NotaSinteticaDelRendiconto::LUNGHEZZA_FRASE;
    $riga = NotaSinteticaDelRendiconto::LUNGHEZZA_RIGA;

    $metti(['fatti' => ['G1' => ['stato' => 'tenuto', 'riscritto' => str_repeat('a', $frase + 1)]]])->assertSessionHasErrors('contenuto.fatti.G1.riscritto');
    $metti(['cause' => ['9' => str_repeat('a', $frase + 1)]])->assertSessionHasErrors('contenuto.cause.9');
    $metti(['dichiarazione' => ['liti' => ['stato' => 'ci_sono', 'testo' => str_repeat('a', $frase + 1)]]])->assertSessionHasErrors('contenuto.dichiarazione.liti.testo');
    $metti(['da_decidere' => [str_repeat('a', $riga + 1)]])->assertSessionHasErrors('contenuto.da_decidere.0');
    $metti(['dipendenti' => ['stato' => 'ci_sono', 'testo' => str_repeat('a', $riga + 1)]])->assertSessionHasErrors('contenuto.dipendenti.testo');
    $metti(['fatti' => ['G1' => ['stato' => 'tenuto', 'riscritto' => str_repeat('a', $frase)]], 'da_decidere' => [str_repeat('b', $riga)]])->assertSessionHasNoErrors();
    $c = NotaSinteticaDelRendiconto::contenuto($s['e']);
    expect(mb_strlen($c['fatti']['G1']['riscritto']))->toBe($frase)->and(mb_strlen($c['da_decidere'][0]))->toBe($riga);
});

it('la dichiarazione stampata: «che …, che … e che …», con le sole parti che ci sono, in ogni combinazione', function () {
    $dichiarazione = fn (array $risposte) => NotaSinteticaDelRendiconto::dichiarazione(collect(['liti', 'impugnazioni', 'sinistri'])
        ->mapWithKeys(fn ($k) => [$k => ['stato' => $risposte[$k] ?? null, 'testo' => '', 'da_confermare' => false]])->all());
    $liti = 'non ci sono cause in corso, né del condominio né contro il condominio';
    $impugnazioni = 'nessuna delibera dell\'assemblea è stata impugnata';
    $sinistri = 'non ci sono sinistri aperti';
    $dichiara = 'L\'amministratore dichiara che ';
    $nessuna = fn (string ...$chiavi) => array_fill_keys($chiavi, 'nessuna');

    expect($dichiarazione($nessuna('liti', 'impugnazioni', 'sinistri')))->toBe(['L\'amministratore dichiara che non ci sono cause in corso, né del condominio né contro '
        .'il condominio, che nessuna delibera dell\'assemblea è stata impugnata e che non ci sono sinistri aperti.'])
        ->and($dichiarazione($nessuna('liti', 'impugnazioni')))->toBe([$dichiara.$liti.' e che '.$impugnazioni.'.'])
        ->and($dichiarazione($nessuna('liti', 'sinistri')))->toBe([$dichiara.$liti.' e che '.$sinistri.'.'])
        ->and($dichiarazione($nessuna('impugnazioni', 'sinistri')))->toBe([$dichiara.$impugnazioni.' e che '.$sinistri.'.'])
        ->and($dichiarazione($nessuna('liti')))->toBe([$dichiara.$liti.'.'])
        ->and($dichiarazione($nessuna('impugnazioni')))->toBe([$dichiara.$impugnazioni.'.'])
        ->and($dichiarazione($nessuna('sinistri')))->toBe([$dichiara.$sinistri.'.'])
        ->and($dichiarazione([]))->toBe([])
        // Con una parte che c'è: la dichiarazione dice le altre due, e la parte che c'è va a sé.
        ->and($dichiarazione(['liti' => 'nessuna', 'impugnazioni' => 'ci_sono', 'sinistri' => 'nessuna']))
        ->toBe([$dichiara.$liti.' e che '.$sinistri.'.', 'Ci sono delibere impugnate.']);
    // Nessuna combinazione ha il punto e virgola che spezzava il parallelo.
    foreach ([['liti', 'impugnazioni', 'sinistri'], ['liti', 'impugnazioni'], ['impugnazioni', 'sinistri']] as $chiavi) {
        expect(implode(' ', $dichiarazione($nessuna(...$chiavi))))->not->toContain(';');
    }
});

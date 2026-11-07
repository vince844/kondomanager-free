<?php

/**
 * Il registro delle lettere di una persona, nella pagina del suo estratto conto (tipo, data, canale, stato, chi, il totale di allora e il residuo
 * di oggi delle stesse rate, il PDF conservato, la spedizione, le prove); la spedizione registrata in blocco; la prova che si toglie solo
 * finché l'esercizio della lettera non è chiuso; nell'elenco delle rate scadute l'ultima lettera e i filtri «nessuna lettera negli ultimi N
 * giorni» e «mai scritto», che contano solo le lettere partite o spedite: una stampata o scaricata senza la spedizione registrata no.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['name' => 'Anna Studio', 'email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

function lttEstratto($test, array $s, string $chi): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]))->assertOk()->viewData('page')['props'];
}

function lttElenco($test, array $s, array $query = []): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id] + $query))->assertOk()->viewData('page')['props'];
}

it('l\'estratto conto ha il registro delle lettere: il totale di allora e il residuo di oggi delle stesse rate, chi l\'ha emessa, il PDF', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();
    // Il 25/03 Ugo versa la rata 1 (€ 60,00): il 26/03 il residuo delle rate della lettera è € 120,00.
    $this->travelTo('2026-03-25 10:00:00');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 6000], '2026-03-25');
    $this->travelTo('2026-03-26 10:00:00');

    $lettere = lttEstratto($this, $s, 'ugo')['lettere'];
    expect($lettere)->toHaveCount(1)
        ->and($lettere[0])->toMatchArray(['tipo' => 'sollecito', 'emessa_il' => '2026-03-20', 'emesso_da' => 'Anna Studio', 'totale' => 18000, 'residuo_oggi' => 12000])
        ->and($lettere[0]['righe'][0])->toMatchArray(['canale' => 'stampa', 'stato' => 'stampata'])
        ->and($lettere[0]['pdf']['verificato'])->toBeTrue()
        ->and(lttEstratto($this, $s, 'bice')['lettere'])->toBe([]);
});

it('la spedizione si registra in blocco: stessa data e stesso canale, il numero della raccomandata riga per riga; una riga per email non si «spedisce»', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['tipo' => 'diffida', 'testo' => 'Testo']))->assertOk();
    $ugo = lttRighe($s, 'ugo')->sole();
    $bice = lttRighe($s, 'bice')->sole();

    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), [
        'invii' => [$ugo->id, $bice->id], 'canale' => 'raccomandata', 'data' => '2026-03-20', 'numeri' => [$ugo->id => 'RA111', $bice->id => 'RA222'],
    ])->assertOk()->assertJson(['spedite' => 2]);
    expect($ugo->fresh()->dati['spedizione']['numero'])->toBe('RA111')->and($bice->fresh()->dati['spedizione']['numero'])->toBe('RA222')
        ->and($ugo->fresh()->dati['eventi'][0])->toMatchArray(['evento' => 'spedizione', 'da' => $this->user->id]);

    $email = lttRigaScritta($s, 'ugo', 'sollecito', 'inviata', 0, 'email');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$email->id], 'canale' => 'raccomandata', 'data' => '2026-03-20'])->assertStatus(422);
    // Una data dopo oggi no.
    $altra = lttRigaScritta($s, 'ugo', 'diffida', 'stampata', 0);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$altra->id], 'canale' => 'a_mano', 'data' => '2026-03-21'])->assertStatus(422);
});

it('la prova: PDF, immagine, .eml e .p7m fino a 10 MB; si toglie finché l\'esercizio che contiene la data della lettera non è chiuso, anche quando quell\'esercizio non c\'è ancora; l\'evento resta', function () {
    $s = lttScenario($this);
    $carica = fn (RegistroInvio $r, UploadedFile $f) => $this->actingAs($this->user)->post(lttRotta($s, 'prove.store', ['invio' => $r->id]), ['file' => $f]);
    $togli = fn (RegistroInvio $r, int $doc) => $this->actingAs($this->user)->delete(lttRotta($s, 'prove.togli', ['invio' => $r->id, 'documento' => $doc]));
    $ultimaProva = fn (RegistroInvio $r) => (int) DB::table('documenti')->where('documentable_type', RegistroInvio::class)->where('documentable_id', $r->id)->max('id');

    // Una lettera del 20/03/2026, con l'esercizio 2026 aperto: la prova .eml si allega e si toglie.
    $marzo = lttRigaScritta($s, 'ugo', 'diffida', 'stampata', 0);
    $carica($marzo, UploadedFile::fake()->createWithContent('ricevuta-pec.eml', "From: a@pec.it\r\n\r\nconsegna"))->assertSessionHasNoErrors();
    $togli($marzo, $ultimaProva($marzo))->assertSessionHasNoErrors();
    expect(collect($marzo->fresh()->dati['eventi'])->pluck('evento')->all())->toBe(['prova_allegata', 'prova_tolta'])
        ->and(DB::table('documenti')->where('documentable_type', RegistroInvio::class)->where('documentable_id', $marzo->id)->count())->toBe(0);

    // Un file che non è una prova, o troppo grande: rifiutato.
    $carica($marzo, UploadedFile::fake()->create('programma.exe', 10))->assertSessionHasErrors('file');
    $carica($marzo, UploadedFile::fake()->create('scansione.pdf', 11 * 1024, 'application/pdf'))->assertSessionHasErrors('file');

    // Una lettera del 05/01/2027: l'esercizio che la contiene non c'è ancora, la prova si toglie.
    $this->travelTo('2027-01-05 10:00:00');
    $gennaio = lttRigaScritta($s, 'ugo', 'diffida', 'stampata', 0);
    $carica($gennaio, UploadedFile::fake()->image('avviso.jpg'))->assertSessionHasNoErrors();
    $togli($gennaio, $ultimaProva($gennaio))->assertSessionHasNoErrors();

    // Con l'esercizio 2027 chiuso, no: la prova resta.
    Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'chiuso']);
    $carica($gennaio, UploadedFile::fake()->image('avviso.png'))->assertSessionHasNoErrors();
    $togli($gennaio, $ultimaProva($gennaio))->assertSessionHasErrors('prova');
    expect(DB::table('documenti')->where('documentable_type', RegistroInvio::class)->where('documentable_id', $gennaio->id)->count())->toBe(1);
});

it('nell\'elenco: l\'ultima lettera, e i filtri «mai scritto» e «nessuna lettera negli ultimi N giorni» contano solo le lettere partite o spedite', function (string $stato, string $canale) {
    $s = lttScenario($this);
    // Una lettera solo in coda o non partita non conta: Ugo e Bice sono «mai scritto».
    lttRigaScritta($s, 'ugo', 'sollecito', 'in_coda', 1, 'email');
    lttRigaScritta($s, 'bice', 'sollecito', 'non_partita', 1, 'email');
    $nomi = fn (array $q) => collect(lttElenco($this, $s, $q)['righe']['data'])->pluck('nome')->all();
    expect($nomi(['mai_scritto' => 1]))->toBe(['Ugo', 'Bice'])
        ->and($nomi(['senza_lettere_da' => 30]))->toBe(['Ugo', 'Bice']);
    $riga = collect(lttElenco($this, $s)['righe']['data'])->firstWhere('nome', 'Ugo');
    expect($riga['ultima_lettera'])->toBeNull()
        ->and($riga['lettera_in_sospeso'])->toMatchArray(['tipo' => 'sollecito', 'stato' => 'in_coda']);

    // Un sollecito di dieci giorni fa che conta: Ugo non è più «mai scritto» né senza lettere da trenta giorni; da cinque giorni sì.
    lttRigaScritta($s, 'ugo', 'sollecito', $stato, 10, $canale);
    expect($nomi(['mai_scritto' => 1]))->toBe(['Bice'])
        ->and($nomi(['senza_lettere_da' => 30]))->toBe(['Bice'])
        ->and($nomi(['senza_lettere_da' => 5]))->toBe(['Ugo', 'Bice']);
    $riga = collect(lttElenco($this, $s)['righe']['data'])->firstWhere('nome', 'Ugo');
    expect($riga['ultima_lettera'])->toMatchArray(['tipo' => 'sollecito', 'stato' => $stato, 'canale' => $canale, 'data' => '2026-03-10']);
})->with([
    'inviata' => ['inviata', 'email'],
    'spedita' => ['spedita', 'stampa'],
]);

it('una lettera stampata o scaricata senza la spedizione registrata non conta per «mai scritto» né per «nessuna lettera negli ultimi N giorni»: resta l\'ultima lettera della colonna e resta da spedire', function (string $formato) {
    $s = lttScenario($this);
    $riga = lttRigaScritta($s, 'ugo', 'diffida', 'stampata', 10);
    $riga->update(['dati' => (array) $riga->dati + ['formato' => $formato]]);
    $nomi = fn (array $q) => collect(lttElenco($this, $s, $q)['righe']['data'])->pluck('nome')->all();

    // Il programma non sa se il PDF è stato stampato e mandato: Ugo è ancora fra chi non ha avuto lettere.
    expect($nomi(['mai_scritto' => 1]))->toBe(['Ugo', 'Bice'])
        ->and($nomi(['senza_lettere_da' => 30]))->toBe(['Ugo', 'Bice'])
        ->and($nomi(['da_spedire' => 1]))->toBe(['Ugo']);
    $ugo = collect(lttElenco($this, $s)['righe']['data'])->firstWhere('nome', 'Ugo');
    expect($ugo['ultima_lettera'])->toMatchArray(['tipo' => 'diffida', 'stato' => 'stampata', 'data' => '2026-03-10', 'canale' => $formato === 'zip' ? 'zip' : 'stampa']);

    // Registrata la spedizione (anche «a mano»), la lettera conta, dal giorno della spedizione.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'a_mano', 'data' => '2026-03-12'])->assertOk();
    expect($nomi(['mai_scritto' => 1]))->toBe(['Bice'])
        ->and($nomi(['senza_lettere_da' => 30]))->toBe(['Bice'])
        ->and($nomi(['senza_lettere_da' => 8]))->toBe(['Ugo', 'Bice'])
        ->and($nomi(['da_spedire' => 1]))->toBe([]);
})->with(['stampata' => ['stampa'], 'scaricata in un PDF per persona' => ['zip']]);

it('i filtri delle lettere alle 00:30 di Roma: il giorno della lettera e quello di oggi sono quelli dell\'amministratore', function () {
    config(['app.user_timezone' => 'Europe/Rome']);
    $s = lttScenario($this);
    // Una lettera partita l'11/03 alle 00:30 di Roma (il 10/03 alle 23:30 UTC); oggi il 20/03 alle 00:30 di Roma (il 19/03 alle 23:30 UTC):
    // per l'amministratore la lettera è dell'11/03, di nove giorni fa. «Negli ultimi N giorni» sono oggi e gli N − 1 giorni prima.
    $riga = lttRigaScritta($s, 'ugo', 'sollecito', 'inviata', 0, 'email');
    $riga->forceFill(['emesso_il' => \Carbon\Carbon::parse('2026-03-11 00:30', 'Europe/Rome')->utc()])->save();
    $this->travelTo(\Carbon\Carbon::parse('2026-03-20 00:30', 'Europe/Rome'));
    $nomi = fn (array $q) => collect(lttElenco($this, $s, $q)['righe']['data'])->pluck('nome')->all();
    expect($nomi(['senza_lettere_da' => 10]))->toBe(['Bice'])
        ->and($nomi(['senza_lettere_da' => 9]))->toBe(['Ugo', 'Bice']);
    $ugo = collect(lttElenco($this, $s)['righe']['data'])->firstWhere('nome', 'Ugo');
    expect($ugo['ultima_lettera']['data'])->toBe('2026-03-11');
});

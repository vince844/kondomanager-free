<?php

/**
 * Le regole delle lettere oltre lo scenario di base: quale lettera conta per «Nessuna lettera negli ultimi N giorni»; il conto dell'IBAN
 * ricordato che non c'è più (né la finestra né le lettere rifatte restano ferme); la spedizione registrata che si corregge; il tetto
 * dell'invio per email dei tipi che conservano il PDF.
 *
 * Lo scenario delle lettere: Ugo e Bice, oggi 20/03/2026, la cassa del condominio con l'IBAN. Nessuna email vera: la coda è finta e il
 * trasporto è `array`.
 */

use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\RegistroLettere;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['mail.default' => 'array']);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['name' => 'Anna Studio', 'email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

/** La banca di prima chiusa, e una banca nuova con l'IBAN. */
function lrgBancaNuova(array $s): Cassa
{
    $s['cassa']->update(['attiva' => false]);
    $b = Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $s['cassa']->conto_contabile_id, 'nome' => 'Banca nuova', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);
    $b->contoCorrente()->create(['iban' => 'IT02A0301503200000003517230', 'intestatario' => 'Condominio di prova']);

    return $b;
}

function lrgNomi($test, array $s, array $query): array
{
    return collect($test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id] + $query))
        ->viewData('page')['props']['righe']['data'])->pluck('nome')->sort()->values()->all();
}

it('per «Nessuna lettera negli ultimi N giorni» conta la lettera arrivata più di recente: una diffida spedita dopo un promemoria è la più recente', function () {
    $s = lttScenario($this); // oggi 20/03/2026
    // Una diffida stampata il 01/03 e spedita con raccomandata il 15/03; un promemoria per email partito il 05/03.
    $diffida = lttRigaScritta($s, 'ugo', 'diffida', 'spedita', 19);
    $diffida->update(['dati' => $diffida->dati + ['spedizione' => ['canale' => 'raccomandata', 'data' => '2026-03-15', 'numero' => 'RA1']]]);
    lttRigaScritta($s, 'ugo', 'promemoria', 'inviata', 15, 'email');

    // La raccomandata è di 5 giorni fa: con N = 10 Ugo non c'è, con N = 5 sì.
    expect(lrgNomi($this, $s, ['senza_lettere_da' => 10]))->toBe(['Bice'])
        ->and(lrgNomi($this, $s, ['senza_lettere_da' => 5]))->toBe(['Bice', 'Ugo']);
});

it('il conto dell\'IBAN ricordato che si è chiuso non si propone più, e con un conto solo la finestra prende quello di oggi', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();
    $b = lrgBancaNuova($s);

    $o = $this->actingAs($this->user)->getJson(lttRotta($s, 'opzioni'))->assertOk()->json();
    expect(collect($o['conti'])->pluck('id')->all())->toBe([$b->id])
        ->and($o['scelta_ricordata']['cassa_id'])->toBeNull();
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['cassa_id' => $b->id]))->assertOk();
});

it('una lettera chiesta con un conto del condominio chiuso o senza IBAN risponde con la strada, non con un 404; un conto di un altro condominio resta un 404', function () {
    $s = lttScenario($this);
    lrgBancaNuova($s);
    $vecchia = $s['cassa'];

    $r = $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['cassa_id' => $vecchia->id]));
    $r->assertStatus(422);
    expect($r->getContent())->toBe('Lettere non stampate. ' . EmissioneLettere::contoNonPiuUsabile('Banca principale'))
        ->and(lttRighe($s, 'ugo'))->toHaveCount(0);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['cassa_id' => $vecchia->id]))->assertStatus(422)
        ->assertJson(['message' => EmissioneLettere::contoNonPiuUsabile('Banca principale')]);

    // Senza l'IBAN: lo stesso.
    $vecchia->update(['attiva' => true]);
    $vecchia->contoCorrente()->update(['iban' => null]);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['cassa_id' => $vecchia->id]))->assertStatus(422);

    $t = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['cassa_id' => $t['cassa']->id]))->assertNotFound();
});

it('«Invia di nuovo» con il conto di prima chiuso: con un conto solo oggi la lettera porta quello, e l\'esito lo dice; senza conti, va senza l\'IBAN e lo dice', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'indirizzi' => ['email']]))->assertOk();
    $riga = lttRighe($s, 'ugo')->sole();
    $riga->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => 'x']);
    $b = lrgBancaNuova($s);

    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $riga->id]))->assertOk()->json();
    $nuova = lttRighe($s, 'ugo')->last();
    expect($nuova->dati['cassa_id'])->toBe($b->id)
        ->and($nuova->dati['iban'])->toBe('IT02A0301503200000003517230')
        ->and($r['conti_cambiati'])->toBe([['prima' => 'Banca principale', 'ora' => 'Banca nuova']]);

    // Nessun conto con l'IBAN: la lettera rifatta non porta le coordinate, e l'esito lo dice.
    $nuova->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => 'x']);
    $b->update(['attiva' => false]);
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $nuova->id]))->assertOk()->json();
    expect(lttRighe($s, 'ugo')->last()->dati)->not->toHaveKey('iban')
        ->and($r['conti_cambiati'])->toBe([['prima' => 'Banca nuova', 'ora' => null]]);
});

it('una spedizione registrata si corregge: la riga mostra l\'ultima, il registro tiene le due, la prova resta', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['tipo' => 'diffida', 'persone' => [$s['persone']['ugo']->id]]))->assertOk();
    $riga = lttRighe($s, 'ugo')->sole();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'raccomandata', 'data' => '2026-03-20', 'numeri' => [$riga->id => 'RA123']])->assertOk();
    $this->actingAs($this->user)->post(lttRotta($s, 'prove.store', ['invio' => $riga->id]), ['file' => UploadedFile::fake()->create('avviso.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();

    // Registrarla di nuovo non si può, e il rifiuto dice come correggerla.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'raccomandata', 'data' => '2026-03-20'])
        ->assertStatus(422)->assertJson(['message' => RegistroLettere::GIA_SPEDITA]);

    // Il 22/03 ci si accorge che la raccomandata è partita il 21/03, con un altro numero.
    $this->travelTo('2026-03-22 10:00:00');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione.correggi', ['invio' => $riga->id]), ['canale' => 'raccomandata', 'data' => '2026-03-21',
        'numero' => 'RA124', 'nota' => 'Numero scritto male'])->assertOk();
    $riga->refresh();
    expect($riga->stato)->toBe('spedita')
        ->and($riga->dati['spedizione'])->toBe(['canale' => 'raccomandata', 'data' => '2026-03-21', 'numero' => 'RA124', 'nota' => 'Numero scritto male'])
        ->and(collect($riga->dati['eventi'])->pluck('evento')->all())->toBe(['spedizione', 'prova_allegata', 'spedizione_corretta'])
        ->and(collect($riga->dati['eventi'])->last()['prima'])->toBe(['canale' => 'raccomandata', 'data' => '2026-03-20', 'numero' => 'RA123'])
        ->and(collect($riga->dati['eventi'])->last()['da_nome'])->toBe('Anna Studio')
        ->and(lttDocumentiDellaRiga($riga)->pluck('name')->all())->toContain('avviso.pdf');

    // Il giorno della spedizione è quello corretto anche per i filtri dell'elenco.
    expect(app(RegistroLettere::class)->ultimePerPersone($s['c']->id, [$s['persone']['ugo']->id], '2026-03-22')[$s['persone']['ugo']->id]['conta']['data_dei_filtri'])->toBe('2026-03-21');
});

it('la correzione della spedizione vale solo per una lettera spedita del condominio, con una data non dopo oggi e non prima della lettera', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['tipo' => 'diffida', 'persone' => [$s['persone']['ugo']->id]]))->assertOk();
    $riga = lttRighe($s, 'ugo')->sole();
    $corpo = ['canale' => 'raccomandata', 'data' => '2026-03-20', 'numero' => 'RA1'];
    // Non ancora spedita: niente da correggere.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione.correggi', ['invio' => $riga->id]), $corpo)->assertStatus(422)
        ->assertJson(['message' => RegistroLettere::SOLO_SPEDITE]);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'raccomandata', 'data' => '2026-03-20'])->assertOk();

    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione.correggi', ['invio' => $riga->id]), ['data' => '2026-03-21'] + $corpo)->assertStatus(422);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione.correggi', ['invio' => $riga->id]), ['data' => '2026-03-19'] + $corpo)->assertStatus(422)
        ->assertJson(['message' => 'La spedizione non può essere prima della lettera, del 20/03/2026.']);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione.correggi', ['invio' => $riga->id]), ['canale' => ''] + $corpo)->assertStatus(422);

    $t = lttScenario($this);
    $this->actingAs($this->user)->postJson(lttRotta($t, 'spedizione.correggi', ['invio' => $riga->id]), $corpo)->assertNotFound();

    // Fuori dal pannello: 403, e la spedizione resta quella.
    $this->actingAs(User::factory()->create())->postJson(lttRotta($s, 'spedizione.correggi', ['invio' => $riga->id]), $corpo + ['numero' => 'RA9'])->assertForbidden();
    expect($riga->fresh()->dati['spedizione'])->toBe(['canale' => 'raccomandata', 'data' => '2026-03-20']);
});

it('alle 00:30 di Roma del 21/03 la spedizione corretta può essere del 21/03, il giorno dell\'amministratore', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['tipo' => 'diffida', 'persone' => [$s['persone']['ugo']->id]]))->assertOk();
    $riga = lttRighe($s, 'ugo')->sole();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'raccomandata', 'data' => '2026-03-20'])->assertOk();
    $this->travelTo(Carbon::parse('2026-03-21 00:30', 'Europe/Rome'));
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione.correggi', ['invio' => $riga->id]), ['canale' => 'a_mano', 'data' => '2026-03-21'])->assertOk();
    expect($riga->fresh()->dati['spedizione'])->toBe(['canale' => 'a_mano', 'data' => '2026-03-21'])
        ->and(collect($riga->fresh()->dati['eventi'])->last()['giorno'])->toBe('2026-03-21');
});

it('il sollecito per email, che conserva un PDF per lettera, ha il tetto delle lettere per volta anche con la coda vera; il promemoria no', function () {
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    expect(EmissioneLettere::tettoDellInvio('sollecito'))->toBe(['lettere' => EmissioneLettere::TETTO_PDF, 'messaggi' => null])
        ->and(EmissioneLettere::tettoDellInvio('promemoria'))->toBe(['lettere' => null, 'messaggi' => null]);

    // 201 persone con un indirizzo: il sollecito ne manda 200 e ne restano 1, a persone intere; il promemoria tutte.
    $gruppi = array_map(fn ($i) => ['persona' => ['anagrafica_id' => $i], 'destinazioni' => [["p{$i}@prova.it", 'email']]], range(1, EmissioneLettere::TETTO_PDF + 1));
    [$dentro, $fuori] = EmissioneLettere::dentroIlTettoDellInvio($gruppi, 'sollecito');
    expect(count($dentro))->toBe(EmissioneLettere::TETTO_PDF)->and(array_map(fn ($g) => $g['persona']['anagrafica_id'], $fuori))->toBe([EmissioneLettere::TETTO_PDF + 1]);
    [$dentro, $fuori] = EmissioneLettere::dentroIlTettoDellInvio($gruppi, 'promemoria');
    expect(count($dentro))->toBe(EmissioneLettere::TETTO_PDF + 1)->and($fuori)->toBeNull();

    config(['queue.default' => 'sync', 'queue.connections.sync.driver' => 'sync']);
    expect(EmissioneLettere::tettoDellInvio('sollecito'))->toBe(['lettere' => EmissioneLettere::TETTO_PDF, 'messaggi' => \App\Services\Gestionale\Ricevute\EmissioneRicevute::TETTO_MESSAGGI_SENZA_CODA]);
    // Con la coda `sync` vale prima il tetto dei messaggi: 50, e restano 151.
    [$dentro, $fuori] = EmissioneLettere::dentroIlTettoDellInvio($gruppi, 'sollecito');
    expect(count($dentro))->toBe(\App\Services\Gestionale\Ricevute\EmissioneRicevute::TETTO_MESSAGGI_SENZA_CODA)->and(count($fuori))->toBe(151);
});

it('la strada della frase del tetto non salta nessuno: con «Mai scritto», registrata la spedizione della pagina 1, le altre persone sono di nuovo dalla pagina 1', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $unita = [];
    for ($i = 1; $i <= 12; $i++) {
        $unita['U' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = ['p' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1000 / 12];
    }
    $s = lcdScenario($unita, 3, 'prima_rata', [], 144000);
    lcdEmetti($this, $s, [1, 2], '2026-01-02');
    $this->travelTo('2026-03-20 10:00:00');
    $pagina = fn (int $n) => collect($this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id, 'mai_scritto' => 1,
        'per_page' => 10, 'page' => $n]))->assertOk()->viewData('page')['props']['righe']['data'])->pluck('anagrafica_id')->all();
    $prima = $pagina(1);
    $seconda = $pagina(2);
    expect($prima)->toHaveCount(10)->and($seconda)->toHaveCount(2);

    // Stampa delle lettere della pagina 1: la pagina dopo è la stessa («stampare non cambia l'ordine»).
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), ['tipo' => 'diffida', 'persone' => $prima, 'testo' => 'Gentile [nome], [totale] entro [termine].',
        'termine_forma' => 'giorni', 'termine' => 15])->assertOk();
    expect($pagina(2))->toBe($seconda);

    // Registrata la spedizione con il filtro «Mai scritto», chi l'ha avuta esce: le altre persone sono dalla pagina 1, come dice la frase.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => RegistroInvio::where('tipo', 'diffida')->pluck('id')->all(), 'canale' => 'raccomandata',
        'data' => '2026-03-20'])->assertOk();
    expect($pagina(1))->toBe($seconda)->and($pagina(2))->toBe([])
        ->and(EmissioneLettere::OLTRE_IL_TETTO)->toBe('Scegli le persone di una pagina dell\'elenco e prepara le loro, poi passa alla pagina dopo: stampare o scaricare le lettere '
            . 'non cambia l\'ordine dell\'elenco. Se registri la spedizione e l\'elenco ha il filtro «Mai scritto» o «Nessuna lettera negli ultimi N giorni», chi l\'ha '
            . 'avuta esce dall\'elenco: le altre persone ricominciano dalla pagina 1.');
});

<?php

/**
 * I modelli delle lettere nella finestra: il menu porta il testo del programma e i modelli di quel tipo validi nel condominio (prima quelli
 * del condominio, poi quelli per tutti); l'ultimo modello si ricorda per tipo e condominio dalla riga dell'ultima lettera; un modello cancellato
 * torna al testo del programma e la finestra lo dice. La lettera copia il testo: il registro tiene anche il numero e il nome del modello, e
 * cambiare o cancellare il modello dopo non cambia la lettera, il suo PDF conservato né la lettera rifatta. Un modello di un altro condominio è
 * un 404 per tutta la richiesta. «Salva come modello» e «Aggiorna» dalla finestra, con il permesso delle impostazioni di stampa.
 *
 * Il nome del condominio che comincia già con «Condominio» non si ripete nel titolo della lettera, nel campo [condominio] dopo «condominio» e
 * nel messaggio dell'email.
 *
 * Nessuna email vera: la coda è finta e il trasporto è `array`.
 */

use App\Models\Condominio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\ModelloTesto;
use App\Models\User;
use App\Notifications\Gestionale\LetteraNotification;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
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
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

function lmdModello(string $tipo, string $nome, string $testo, ?int $condominio = null): ModelloTesto
{
    return ModelloTesto::create(['ambito' => 'lettera', 'tipo' => $tipo, 'nome' => $nome, 'testo' => $testo, 'condominio_id' => $condominio]);
}

function lmdConIlPermesso($test): void
{
    $test->user->givePermissionTo(Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    $test->user = $test->user->fresh();
}

it('le opzioni: per ogni tipo i modelli validi nel condominio, prima i suoi e poi quelli per tutti; quelli di un altro condominio no; il testo del programma resta quello del programma', function () {
    $s = lttScenario($this);
    $altro = Condominio::factory()->create();
    $bTutti = lmdModello('sollecito', 'b per tutti', 'Testo B, [nome].');
    $aTutti = lmdModello('sollecito', 'A per tutti', 'Testo A, [nome].');
    $suo = lmdModello('sollecito', 'Z del condominio', 'Testo Z, [nome].', $s['c']->id);
    lmdModello('sollecito', 'Di un altro condominio', 'Estraneo.', $altro->id);
    $diffida = lmdModello('diffida', 'Diffida ferma', 'Testo della diffida, [termine].');
    ModelloTesto::create(['ambito' => 'comunicazione', 'tipo' => 'sollecito', 'nome' => 'Non è una lettera', 'testo' => 'X']);

    $o = $this->actingAs($this->user)->getJson(lttRotta($s, 'opzioni'))->assertOk()->json();
    expect(collect($o['modelli'])->map(fn ($m) => [$m['tipo'], $m['nome'], $m['per_tutti']])->all())->toBe([
        ['sollecito', 'Z del condominio', false], ['sollecito', 'A per tutti', true], ['sollecito', 'b per tutti', true], ['diffida', 'Diffida ferma', true],
    ])
        ->and(collect($o['modelli'])->firstWhere('id', $suo->id)['testo'])->toBe('Testo Z, [nome].')
        ->and($o['testi'])->toBe(TestiLettere::DI_SERIE)
        ->and($o['modelli_ricordati'])->toBe(['promemoria' => null, 'sollecito' => null, 'diffida' => null, 'messa_in_mora' => null]);
    expect([$aTutti->id, $bTutti->id, $diffida->id])->each->toBeInt();
});

it('la lettera scritta da un modello porta il suo numero e il suo nome; cambiato e poi cancellato il modello, la lettera, il suo PDF conservato e la lettera rifatta non cambiano', function () {
    $s = lttScenario($this);
    $testo = "Gentile [nome],\nrestano [totale] del condominio [condominio]. Versi entro [termine].";
    $m = lmdModello('sollecito', 'Sollecito cortese', $testo);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'testo' => $testo, 'modello_id' => $m->id]))->assertOk();
    $riga = lttRighe($s, 'ugo')->sole();
    $documento = lttDocumentiDellaRiga($riga)->sole();
    $pdf = Storage::disk('local')->get($documento->path);
    expect($riga->dati)->toMatchArray(['modello_id' => $m->id, 'modello_nome' => 'Sollecito cortese', 'modello' => $testo])
        ->and($riga->dati['testo'])->toStartWith("Gentile Ugo,\nrestano € 180,00");

    $prima = $riga->dati;
    $m->update(['nome' => 'Sollecito gentile', 'testo' => 'Un altro testo.']);
    $m->delete();
    $riga->refresh();
    $html = View::make('pdf.gestionale.lettera', app(EmissioneLettere::class)->datiDellaVista($s['c'], [app(EmissioneLettere::class)->voceDallaRiga($riga)]))->render();
    expect($riga->dati)->toBe($prima)
        ->and(Storage::disk('local')->get($documento->path))->toBe($pdf)
        ->and($riga->impronta)->toBe(hash('sha256', $pdf))
        ->and($html)->toContain('Gentile Ugo,')->and($html)->not->toContain('Un altro testo.');

    // Un sollecito per email dallo stesso modello, che non parte: «riprova» lo rifà con il testo e il nome del modello di allora.
    $m2 = lmdModello('sollecito', 'Sollecito breve', 'Gentile [nome], restano [totale]. Entro [termine].');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone']['bice']->id], 'testo' => $m2->testo, 'modello_id' => $m2->id]))->assertOk();
    RegistroInvio::where('anagrafica_id', $s['persone']['bice']->id)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => 'x']);
    $prima = lttRighe($s, 'bice')->count();
    $m2->update(['nome' => 'Rinominato', 'testo' => 'Cambiato.']);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'))->assertOk();
    $rifatte = lttRighe($s, 'bice');
    expect($rifatte->count())->toBe($prima * 2)
        ->and($rifatte->last()->dati)->toMatchArray(['modello_id' => $m2->id, 'modello_nome' => 'Sollecito breve', 'modello' => 'Gentile [nome], restano [totale]. Entro [termine].'])
        ->and($rifatte->last()->dati['testo'])->toBe('Gentile Bice, restano € 80,00. Entro 15 giorni dal ricevimento di questa lettera.');
});

it('l\'ultimo modello si ricorda per tipo e condominio dalla riga dell\'ultima lettera, anche di un altro utente; il testo del programma si ricorda come testo del programma; un modello cancellato o che non vale più nel condominio torna al testo del programma, con il suo nome', function () {
    $s = lttScenario($this);
    $cortese = lmdModello('sollecito', 'Sollecito cortese', 'Gentile [nome], entro [termine].');
    $fermo = lmdModello('sollecito', 'Sollecito fermo', 'Gentile [nome], entro [termine], senza rinvii.', $s['c']->id);
    $ricordati = fn () => $this->actingAs($this->user)->getJson(lttRotta($s, 'opzioni'))->assertOk()->json('modelli_ricordati');
    $stampa = fn (?ModelloTesto $m, string $chi = 'ugo', ?User $utente = null) => $this->actingAs($utente ?? $this->user)->post(lttRotta($s, 'stampa'),
        lttScelte($s, ['persone' => [$s['persone'][$chi]->id], 'testo' => $m?->testo ?? TestiLettere::DI_SERIE['sollecito'], 'modello_id' => $m?->id]))->assertOk();

    $stampa($cortese);
    expect($ricordati())->toBe(['promemoria' => null, 'sollecito' => ['id' => $cortese->id, 'nome' => 'Sollecito cortese', 'mancante' => false], 'diffida' => null, 'messa_in_mora' => null]);

    // Dopo, una lettera con il testo del programma: si ricorda il testo del programma.
    $stampa(null, 'bice');
    expect($ricordati()['sollecito'])->toBeNull();

    // Un altro utente scrive con «Sollecito fermo»: per tipo e condominio, non per utente.
    $altro = User::factory()->create();
    $altro->assignRole('admin');
    $stampa($fermo, 'ugo', $altro);
    expect($ricordati()['sollecito'])->toBe(['id' => $fermo->id, 'nome' => 'Sollecito fermo', 'mancante' => false]);

    // Le lettere di un altro condominio non contano.
    $s2 = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s2, 'stampa'), lttScelte($s2, ['persone' => [$s2['persone']['ugo']->id], 'testo' => $cortese->testo, 'modello_id' => $cortese->id]))->assertOk();
    expect($ricordati()['sollecito']['id'])->toBe($fermo->id);

    // «Sollecito fermo» passa a un altro condominio: qui non vale più, e si parte dal testo del programma con il suo nome.
    $fermo->update(['condominio_id' => $s2['c']->id]);
    expect($ricordati()['sollecito'])->toBe(['id' => null, 'nome' => 'Sollecito fermo', 'mancante' => true]);
    // Cancellato: lo stesso.
    $fermo->update(['condominio_id' => $s['c']->id]);
    $fermo->delete();
    expect($ricordati()['sollecito'])->toBe(['id' => null, 'nome' => 'Sollecito fermo', 'mancante' => true]);
});

it('un modello di un altro condominio non si usa: 404 per l\'anteprima, la stampa, il PDF per persona, l\'invio e «Aggiorna»; uno cancellato dopo la scelta lascia partire la lettera senza il nome; uno di un altro tipo non si scrive', function () {
    $s = lttScenario($this);
    lmdConIlPermesso($this);
    $altro = Condominio::factory()->create();
    $estraneo = lmdModello('sollecito', 'Estraneo', 'Gentile [nome], entro [termine].', $altro->id);
    $scelte = lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'testo' => $estraneo->testo, 'modello_id' => $estraneo->id]);

    $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), $scelte)->assertNotFound();
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), $scelte)->assertNotFound();
    $this->actingAs($this->user)->post(lttRotta($s, 'pdf-anteprima'), $scelte)->assertNotFound();
    $this->actingAs($this->user)->post(lttRotta($s, 'pdf-per-persona'), $scelte)->assertNotFound();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), $scelte)->assertNotFound();
    $this->actingAs($this->user)->putJson(lttRotta($s, 'modelli.update', ['modello' => $estraneo->id]), ['testo' => 'Rubato.'])->assertNotFound();
    expect(lttRighe($s, 'ugo'))->toHaveCount(0)->and($estraneo->fresh()->testo)->toBe('Gentile [nome], entro [termine].');

    // Cancellato dopo la scelta: la lettera parte con il suo testo, senza il modello.
    $cancellato = lmdModello('sollecito', 'Cancellato', 'Gentile [nome], entro [termine].');
    $id = $cancellato->id;
    $cancellato->delete();
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'testo' => 'Gentile [nome], entro [termine].', 'modello_id' => $id]))->assertOk();
    // Un modello della diffida con un sollecito: non è il modello di questa lettera.
    $diffida = lmdModello('diffida', 'Diffida', 'Gentile [nome], entro [termine].');
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['bice']->id], 'testo' => 'Gentile [nome], entro [termine].', 'modello_id' => $diffida->id]))->assertOk();
    expect(lttRighe($s, 'ugo')->sole()->dati)->not->toHaveKey('modello_id')->not->toHaveKey('modello_nome')
        ->and(lttRighe($s, 'bice')->sole()->dati)->not->toHaveKey('modello_id');
});

it('«Salva come modello» dalla finestra, con il nome e «vale per», e «Aggiorna» del modello di partenza: con il permesso delle impostazioni di stampa; il nome doppio si rifiuta; le lettere già scritte non cambiano', function () {
    $s = lttScenario($this);
    $corpo = ['tipo' => 'sollecito', 'nome' => 'Del condominio', 'testo' => 'Gentile [nome], entro [termine].', 'per_tutti' => false];
    $this->actingAs($this->user)->postJson(lttRotta($s, 'modelli.store'), $corpo)->assertForbidden();
    expect(ModelloTesto::count())->toBe(0);

    lmdConIlPermesso($this);
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'modelli.store'), $corpo)->assertCreated()->json();
    $m = ModelloTesto::sole();
    expect([$m->ambito, $m->tipo, $m->nome, $m->testo, (int) $m->condominio_id, (int) $m->created_by, (int) $m->updated_by])
        ->toBe(['lettera', 'sollecito', 'Del condominio', 'Gentile [nome], entro [termine].', $s['c']->id, $this->user->id, $this->user->id])
        ->and($r['modello'])->toMatchArray(['id' => $m->id, 'nome' => 'Del condominio', 'per_tutti' => false])
        ->and(collect($r['modelli'])->pluck('nome')->all())->toBe(['Del condominio']);

    // Lo stesso nome per lo stesso condominio: rifiutato. Per tutti i condomini: un altro modello.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'modelli.store'), ['nome' => 'DEL CONDOMINIO'] + $corpo)->assertStatus(422)->assertJsonValidationErrors('nome');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'modelli.store'), ['per_tutti' => true] + $corpo)->assertCreated();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'modelli.store'), ['per_tutti' => null] + $corpo)->assertStatus(422)->assertJsonValidationErrors('per_tutti');

    // Una lettera dal modello, poi «Aggiorna»: il modello cambia il testo e non il nome; la lettera resta.
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'testo' => $m->testo, 'modello_id' => $m->id]))->assertOk();
    $this->actingAs($this->user)->putJson(lttRotta($s, 'modelli.update', ['modello' => $m->id]), ['testo' => "Gentile [nome],\r\nil nuovo testo, entro [termine].  "])->assertOk();
    expect($m->fresh()->only(['nome', 'testo']))->toBe(['nome' => 'Del condominio', 'testo' => "Gentile [nome],\nil nuovo testo, entro [termine]."])
        ->and(lttRighe($s, 'ugo')->sole()->dati['modello'])->toBe('Gentile [nome], entro [termine].');

    // Senza il permesso «Aggiorna» no; un modello che non c'è: 404.
    $senza = User::factory()->create();
    $senza->assignRole('admin');
    $this->actingAs($senza)->putJson(lttRotta($s, 'modelli.update', ['modello' => $m->id]), ['testo' => 'X'])->assertForbidden();
    $this->actingAs($this->user)->putJson(lttRotta($s, 'modelli.update', ['modello' => 999999]), ['testo' => 'X'])->assertNotFound();
});

it('«Condominio Condominio»: con il nome che comincia già con «Condominio» il titolo, il campo [condominio] dopo «condominio» e l\'email lo dicono una volta; senza, come prima', function () {
    $s = lttScenario($this);
    $vista = function (Condominio $c) use ($s) {
        $riga = lttRighe($s, 'ugo')->last();

        return trim(preg_replace('/\s+/u', ' ', strip_tags(View::make('pdf.gestionale.lettera', app(EmissioneLettere::class)->datiDellaVista($c, [app(EmissioneLettere::class)->voceDallaRiga($riga)]))->render())));
    };
    $stampa = fn () => $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'testo' => TestiLettere::DI_SERIE['sollecito']]))->assertOk();

    $s['c']->update(['nome' => 'Condominio Le Rose']);
    $stampa();
    $testo = lttRighe($s, 'ugo')->last()->dati['testo'];
    $html = $vista($s['c']->fresh());
    expect($testo)->toContain('dalla contabilità del Condominio Le Rose risultano')
        ->and($testo)->not->toMatch('/condominio\s+condominio/iu')
        ->and($html)->toContain('Sollecito Condominio Le Rose')
        ->and($html)->not->toMatch('/condominio\s+condominio/iu')
        ->and(LetteraNotification::testo('Condominio Le Rose', ['tipo' => 'sollecito', 'persona' => ['nome' => 'Ugo']])['righe'][0])
        ->toBe('In allegato il sollecito del Condominio Le Rose: le rate e il totale sono nel PDF.');

    // Le maiuscole non contano.
    expect(TestiLettere::riempi('Del condominio [condominio].', 'Ugo', 100, null, 'CONDOMINIO VIA VERDI 3'))->toBe('Del CONDOMINIO VIA VERDI 3.')
        ->and(TestiLettere::condominioConNome('condominio Via Verdi'))->toBe('condominio Via Verdi')
        // Una parola che comincia con «condominio» ma è un'altra non è la stessa parola.
        ->and(TestiLettere::condominioConNome('Condominiale Est'))->toBe('Condominio Condominiale Est')
        // E una parola che finisce con «condominio» non è la parola davanti al campo: «Supercondominio [condominio]» resta com'è.
        ->and(TestiLettere::riempi('Il Supercondominio [condominio] e il condominio [condominio].', 'Ugo', 100, null, 'Condominio Le Rose'))
        ->toBe('Il Supercondominio Condominio Le Rose e il Condominio Le Rose.');

    // Senza la parola nel nome: «Condominio» nel titolo e «del condominio» nel testo, come prima.
    $s['c']->update(['nome' => 'Le Rose']);
    $stampa();
    $testo = lttRighe($s, 'ugo')->last()->dati['testo'];
    expect($testo)->toContain('dalla contabilità del condominio Le Rose risultano')
        ->and($vista($s['c']->fresh()))->toContain('Sollecito Condominio Le Rose')
        ->and(LetteraNotification::testo('Le Rose', ['tipo' => 'sollecito', 'persona' => ['nome' => 'Ugo']])['righe'][0])
        ->toBe('In allegato il sollecito del condominio Le Rose: le rate e il totale sono nel PDF.')
        // Il campo da solo è il nome com'è.
        ->and(TestiLettere::riempi('[condominio]', 'Ugo', 100, null, 'Condominio Le Rose'))->toBe('Condominio Le Rose');
});

<?php

/**
 * Il modello ricordato è l'ultimo modello **scelto** nel blocco delle lettere di un tipo, nel condominio. Non lo cambiano le lettere rifatte
 * («Riprova le non partite», «Invia di nuovo»), che ripetono il modello di allora senza che nessuno lo scelga, né la lettera di una persona
 * (dall'estratto conto o da «Prepara una lettera» nella riga), che ha un testo per lei come il testo scritto per una persona, che non si ricorda.
 * La riga rifatta dice da quale riga viene; la lettera di una persona lo dice anche lei.
 *
 * Lo scenario delle lettere: Ugo e Bice, oggi 20/03/2026. Nessuna email vera: la coda è finta e il trasporto è `array`.
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\ModelloTesto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

function lmrModello(string $nome, string $testo): ModelloTesto
{
    return ModelloTesto::create(['ambito' => 'lettera', 'tipo' => 'sollecito', 'nome' => $nome, 'testo' => $testo]);
}

function lmrRicordato($test, array $s): ?array
{
    return $test->actingAs($test->user)->getJson(lttRotta($s, 'opzioni'))->assertOk()->json('modelli_ricordati.sollecito');
}

it('«Riprova le non partite» rifà la lettera con il modello di allora, ma il ricordato resta l\'ultimo scelto; eliminato quello di allora, niente «mancante»', function () {
    $s = lttScenario($this);
    $a = lmrModello('Sollecito A', 'A: Gentile [nome], entro [termine].');
    $b = lmrModello('Sollecito B', 'B: Gentile [nome], entro [termine].');
    // Un sollecito per email a Bice con A, che non parte; poi uno stampato a Ugo con B.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone']['bice']->id], 'testo' => $a->testo, 'modello_id' => $a->id]))->assertOk();
    RegistroInvio::where('anagrafica_id', $s['persone']['bice']->id)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => 'x']);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'testo' => $b->testo, 'modello_id' => $b->id]))->assertOk();
    expect(lmrRicordato($this, $s))->toBe(['id' => $b->id, 'nome' => 'Sollecito B', 'mancante' => false]);

    $prima = RegistroInvio::where('anagrafica_id', $s['persone']['bice']->id)->orderBy('id')->first();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'))->assertOk();
    $rifatta = RegistroInvio::where('anagrafica_id', $s['persone']['bice']->id)->orderByDesc('id')->first();
    // La lettera rifatta ha il modello di allora e dice da quale riga viene.
    expect($rifatta->id)->not->toBe($prima->id)
        ->and($rifatta->dati)->toMatchArray(['modello_id' => $a->id, 'modello_nome' => 'Sollecito A', 'rifatta_da' => $prima->id])
        ->and(lmrRicordato($this, $s))->toBe(['id' => $b->id, 'nome' => 'Sollecito B', 'mancante' => false]);

    $a->delete();
    expect(lmrRicordato($this, $s))->toBe(['id' => $b->id, 'nome' => 'Sollecito B', 'mancante' => false]);
});

it('«Invia di nuovo» dopo il modello di allora rinominato ed eliminato: la lettera rifatta ha il testo e il nome di allora, il ricordato resta l\'ultimo scelto', function () {
    $s = lttScenario($this);
    $a = lmrModello('Sollecito A', 'A: Gentile [nome], restano [totale], entro [termine].');
    $b = lmrModello('Sollecito B', 'B: Gentile [nome], entro [termine].');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone']['bice']->id], 'testo' => $a->testo, 'modello_id' => $a->id]))->assertOk();
    RegistroInvio::where('anagrafica_id', $s['persone']['bice']->id)->update(['stato' => RegistroInvio::STATO_INVIATA]);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'testo' => $b->testo, 'modello_id' => $b->id]))->assertOk();
    $a->update(['testo' => 'Testo nuovo di A.', 'nome' => 'A rinominato']);
    $a->delete();

    $riga = RegistroInvio::where('anagrafica_id', $s['persone']['bice']->id)->orderBy('id')->first();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $riga->id]))->assertOk();
    $nuova = RegistroInvio::where('anagrafica_id', $s['persone']['bice']->id)->orderByDesc('id')->first();
    expect($nuova->dati)->toMatchArray(['modello_id' => $a->id, 'modello_nome' => 'Sollecito A', 'modello' => 'A: Gentile [nome], restano [totale], entro [termine].',
        'rifatta_da' => $riga->id])
        ->and($nuova->dati['testo'])->toStartWith('A: Gentile Bice, restano € 80,00')
        ->and(lmrRicordato($this, $s))->toBe(['id' => $b->id, 'nome' => 'Sollecito B', 'mancante' => false]);
});

it('la lettera di una persona non cambia il modello ricordato del condominio; il blocco con una persona spuntata sì', function () {
    $s = lttScenario($this);
    $a = lmrModello('Sollecito A', 'A: Gentile [nome], entro [termine].');
    $b = lmrModello('Sollecito B', 'B: Gentile [nome], entro [termine].');
    // Il blocco con A.
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['modello_id' => $a->id, 'testo' => $a->testo]))->assertOk();
    // Poi una lettera a Ugo solo, dall'estratto conto, con B e il testo cambiato per lui.
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'singola' => 1, 'modello_id' => $b->id,
        'testo' => 'Caro Ugo, come detto al telefono, entro [termine].']))->assertOk();
    $diUgo = lttRighe($s, 'ugo')->last();
    expect($diUgo->dati)->toMatchArray(['modello_id' => $b->id, 'singola' => true])
        ->and(lmrRicordato($this, $s))->toBe(['id' => $a->id, 'nome' => 'Sollecito A', 'mancante' => false]);

    // Il blocco con una persona sola spuntata è una scelta del blocco: il ricordato diventa B.
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['bice']->id], 'modello_id' => $b->id, 'testo' => $b->testo]))->assertOk();
    expect(lttRighe($s, 'bice')->last()->dati)->not->toHaveKey('singola')
        ->and(lmrRicordato($this, $s))->toBe(['id' => $b->id, 'nome' => 'Sollecito B', 'mancante' => false]);
});

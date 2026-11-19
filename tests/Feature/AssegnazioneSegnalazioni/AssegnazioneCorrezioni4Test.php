<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Segnalazioni: il giro 4 (i rilievi della quarta revisione)
 *
 * - **D1** Il modulo di modifica chiede se chi salva approva le segnalazioni. In un database dove la riga di quel
 *   permesso non c'è (un test del core che crea solo i permessi che usa, un'installazione a metà di un aggiornamento)
 *   la risposta è «no»: il modulo si salva, e non approva. Prima il salvataggio cadeva con un errore.
 * - **D2** L'assegnazione dal modulo di modifica e da «Crea segnalazione» si scrive dopo che il salvataggio degli altri
 *   campi è confermato, non dentro la stessa transazione: la sua email non deve partire per un salvataggio che poi
 *   viene annullato.
 */

use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__ . '/AssegnazioneScenario.php';

beforeEach(function () {
    asgPrepara($this);
});

/** Toglie dal database la riga di un permesso, com'è dove quel permesso non è stato creato. */
function asg4SenzaLaRigaDelPermesso(string $nome): void
{
    $id = DB::table('permissions')->where('name', $nome)->value('id');
    expect($id)->not->toBeNull("Il permesso «{$nome}» non è nel database di prova");
    DB::table('role_has_permissions')->where('permission_id', $id)->delete();
    DB::table('model_has_permissions')->where('permission_id', $id)->delete();
    DB::table('permissions')->where('id', $id)->delete();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
}

/** Il modulo di modifica del pannello come lo manda la pagina, con i campi dati al posto di quelli salvati. */
function asg4Salva($test, User $chi, Segnalazione $s, array $campi = []): TestResponse
{
    return $test->actingAs($chi)->put(route('admin.segnalazioni.update', $s), $campi + [
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'can_comment' => true, 'is_featured' => false, 'is_published' => (bool) $s->is_published,
        'condominio_id' => $s->condominio_id,
    ]);
}

/** Il modulo «Crea segnalazione» del pannello, per il palazzo Aurora. */
function asg4Crea($test, User $chi, array $campi = []): TestResponse
{
    return $test->actingAs($chi)->post(route('admin.segnalazioni.store'), $campi + [
        'subject' => 'Citofono muto', 'description' => 'Dal secondo piano non si sente.', 'priority' => 'media',
        'stato' => 'aperta', 'can_comment' => true, 'is_featured' => false, 'is_published' => true,
        'condominio_id' => $test->aurora->id,
    ]);
}

/**
 * Annota a che profondità di transazione si scrive l'assegnazione di una segnalazione. Dentro una transazione aperta
 * dal controller la profondità è più alta di quella che il test trova prima della richiesta.
 */
function asg4ProfonditaDellAssegnazione(): \ArrayObject
{
    $profondita = new \ArrayObject;
    Segnalazione::saved(function (Segnalazione $s) use ($profondita) {
        if ($s->wasChanged('assigned_to')) {
            $profondita->append(DB::transactionLevel());
        }
    });

    return $profondita;
}

// --- D1: senza la riga del permesso «Approva segnalazioni» ---------------------------------------------------------

test('senza la riga di «Approva segnalazioni» nel database il modulo di modifica si salva, e non approva', function (array $campi) {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => true]);
    asg4SenzaLaRigaDelPermesso('Approva segnalazioni');

    $risposta = asg4Salva($this, $this->admin, $s, ['subject' => 'Il cancello non chiude, ancora'] + $campi);

    $risposta->assertRedirect()->assertSessionHasNoErrors();
    expect(asgRiga($s)->subject)->toBe('Il cancello non chiude, ancora')
        ->and((bool) asgRiga($s)->is_approved)->toBeFalse();
})->with([
    'senza il campo' => [[]],
    'con «Approvata»' => [['is_approved' => true]],
    'con «Da approvare»' => [['is_approved' => false]],
]);

test('senza quella riga un\'approvazione già data resta, qualunque cosa mandi il modulo', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => true, 'is_published' => true]);
    asg4SenzaLaRigaDelPermesso('Approva segnalazioni');

    asg4Salva($this, $this->admin, $s, ['subject' => 'Titolo nuovo', 'is_approved' => false])->assertRedirect()->assertSessionHasNoErrors();

    expect(asgRiga($s)->subject)->toBe('Titolo nuovo')
        ->and((bool) asgRiga($s)->is_approved)->toBeTrue();
});

test('senza quella riga chi assegna continua ad assegnare dal modulo', function () {
    asg4SenzaLaRigaDelPermesso('Approva segnalazioni');

    asg4Salva($this, $this->admin, $this->s, ['assigned_to' => $this->elena->id])->assertRedirect()->assertSessionHasNoErrors();

    expect((int) asgRiga($this->s)->assigned_to)->toBe($this->elena->id)
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe(ccmIds($this->elena));
});

test('senza quella riga la pagina di modifica si apre', function () {
    asg4SenzaLaRigaDelPermesso('Approva segnalazioni');

    $props = asgProps($this->actingAs($this->admin)->get(route('admin.segnalazioni.edit', $this->s)));

    expect($props)->not->toBeNull('La pagina di modifica non si apre')
        ->and($props['assegnazione']['puo_assegnare'] ?? null)->toBeTrue();
});

test('senza la riga di «Assegna segnalazioni» il modulo di modifica e «Crea segnalazione» si salvano, e non assegnano', function () {
    asg4SenzaLaRigaDelPermesso(ASG_PERMESSO);

    asg4Salva($this, $this->admin, $this->s, ['subject' => 'Titolo nuovo', 'assigned_to' => $this->elena->id])->assertRedirect()->assertSessionHasNoErrors();
    asg4Crea($this, $this->admin, ['assigned_to' => $this->elena->id])->assertRedirect()->assertSessionHasNoErrors();

    $nata = DB::table('segnalazioni')->where('subject', 'Citofono muto')->first();
    expect(asgRiga($this->s)->subject)->toBe('Titolo nuovo')
        ->and(asgRiga($this->s)->assigned_to)->toBeNull()
        ->and($nata)->not->toBeNull('La segnalazione non è nata')
        ->and($nata->assigned_to)->toBeNull();
});

// --- D2: l'assegnazione dopo il salvataggio confermato -------------------------------------------------------------

test('dal modulo di modifica l\'assegnazione si scrive dopo che il salvataggio degli altri campi è confermato', function (string $caso) {
    $s = $caso === 'assegna' ? $this->s : asgAssegnata($this, $this->collaboratrice);
    $fuori = DB::transactionLevel();
    $profondita = asg4ProfonditaDellAssegnazione();

    asg4Salva($this, $this->admin, $s, ['subject' => 'Titolo nuovo', 'assigned_to' => $caso === 'toglie' ? null : $this->elena->id])
        ->assertSessionHasNoErrors();

    expect($profondita->getArrayCopy())->toBe([$fuori], 'L\'assegnazione è stata scritta dentro la transazione del salvataggio')
        ->and(asgRiga($s)->subject)->toBe('Titolo nuovo')
        ->and(asgRiga($s)->assigned_to === null)->toBe($caso === 'toglie');
})->with(['assegna', 'riassegna', 'toglie']);

test('creando una segnalazione l\'assegnazione si scrive dopo che la segnalazione è confermata', function () {
    $fuori = DB::transactionLevel();
    $profondita = asg4ProfonditaDellAssegnazione();

    asg4Crea($this, $this->admin, ['assigned_to' => $this->elena->id])->assertSessionHasNoErrors();

    $nata = DB::table('segnalazioni')->where('subject', 'Citofono muto')->first();
    expect($profondita->getArrayCopy())->toBe([$fuori], 'L\'assegnazione è stata scritta dentro la transazione della creazione')
        ->and((int) $nata->assigned_to)->toBe($this->elena->id)
        ->and((int) $nata->assegnata_da)->toBe($this->admin->id);
});

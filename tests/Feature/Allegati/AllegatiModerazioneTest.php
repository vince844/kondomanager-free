<?php

/**
 * # Allegati: approvare, rinominare, eliminare, impostare (Coda 460, decisioni 239.7 e 241)
 *
 * Approvare, rinominare ed eliminare i file degli altri, e cambiare le impostazioni degli allegati di un
 * elemento, si fa solo dal pannello e solo con il permesso dell'azione: il pannello da solo non basta (il
 * collaboratore ce l'ha di serie e non approva), e il permesso senza il pannello non apre niente (un «Approva»
 * dato per sbaglio a un condòmino). L'autore rinomina ed elimina i propri, se ha i permessi «propri» e vede
 * ancora l'elemento. Rinominare non cambia l'autore, lo stato né chi ha approvato (Coda 396). Eliminare toglie
 * la riga e il file. Eliminare la segnalazione o la comunicazione toglie i suoi allegati.
 *
 * Gli allegati in attesa aprono nell'Inbox dell'amministratore una voce per elemento, che si chiude da sola
 * quando non ne resta nessuno in attesa.
 *
 * Cosa non copre: l'ordine fra due moderatori che approvano ed eliminano lo stesso file nello stesso istante.
 */

use App\Models\Evento;
use App\Services\Documenti\ArchivioDocumenti;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->luigi = algUtente([$this->aurora->id], 'Luigi Verdi');
    $this->collaboratore = algUtente([$this->aurora->id], 'Carla Neri', 'collaboratore');
    $this->s = algSegnalazione($this->aurora, $this->mario);
});

test('l\'amministratore approva un allegato in attesa: diventa pubblicato, con chi e quando, e il condòmino del palazzo lo vede', function () {
    $id = algAllegatoSalvato($this->s, $this->mario, 'foto.jpg', approvato: false);
    expect(algIdInScheda($this, $this->luigi, $this->s))->toBe([]);

    $this->actingAs($this->admin)->post(route('admin.allegati.approva', ['allegato' => $id]))->assertRedirect();

    $riga = DB::table('documenti')->find($id);
    expect((bool) $riga->is_approved)->toBeTrue()
        ->and((int) $riga->moderato_da)->toBe($this->admin->id)
        ->and($riga->moderato_at)->not->toBeNull()
        ->and((int) $riga->created_by)->toBe($this->mario->id)
        ->and(algIdInScheda($this, $this->luigi, $this->s))->toBe([$id]);
});

test('il collaboratore non approva senza il permesso; con «Approva allegati segnalazioni» concesso a lui sì', function () {
    $id = algAllegatoSalvato($this->s, $this->mario, 'foto.jpg', approvato: false);

    expect(algRifiutato($this->actingAs($this->collaboratore)->post(route('admin.allegati.approva', ['allegato' => $id]))))->toBeTrue()
        ->and((bool) DB::table('documenti')->find($id)->is_approved)->toBeFalse();

    $this->collaboratore->givePermissionTo(ALG_PERMESSI['segnalazioni']['approva']);
    $this->actingAs($this->collaboratore->fresh())->post(route('admin.allegati.approva', ['allegato' => $id]))->assertRedirect();
    expect((bool) DB::table('documenti')->find($id)->is_approved)->toBeTrue();
});

test('il permesso di approvare dato a un condòmino non apre niente: niente pannello, e nel portale i file in attesa degli altri restano nascosti', function () {
    $id = algAllegatoSalvato($this->s, $this->mario, 'foto.jpg', approvato: false);
    $this->luigi->givePermissionTo(ALG_PERMESSI['segnalazioni']['approva'], ALG_PERMESSI['segnalazioni']['elimina']);
    $luigi = $this->luigi->fresh();

    expect(algRifiutato($this->actingAs($luigi)->post(route('admin.allegati.approva', ['allegato' => $id]))))->toBeTrue()
        ->and(algRifiutato($this->actingAs($luigi)->delete(route('admin.allegati.destroy', ['allegato' => $id]))))->toBeTrue()
        ->and(algRifiutato($this->actingAs($luigi)->delete(route('user.allegati.destroy', ['allegato' => $id]))))->toBeTrue()
        ->and(algIdInScheda($this, $luigi, $this->s))->toBe([])
        ->and(algRifiutato(algScarica($this, $luigi, $id)))->toBeTrue()
        ->and(DB::table('documenti')->find($id))->not->toBeNull();
});

test('l\'autore elimina il proprio allegato dal portale: via la riga e il file; un altro condòmino no', function () {
    $id = algAllegatoSalvato($this->s, $this->mario, 'foto.jpg');
    $path = DB::table('documenti')->find($id)->path;

    expect(algRifiutato($this->actingAs($this->luigi)->delete(route('user.allegati.destroy', ['allegato' => $id]))))->toBeTrue();
    app(ArchivioDocumenti::class)->disco()->assertExists($path);

    $this->actingAs($this->mario)->delete(route('user.allegati.destroy', ['allegato' => $id]))->assertRedirect();
    expect(DB::table('documenti')->find($id))->toBeNull();
    app(ArchivioDocumenti::class)->disco()->assertMissing($path);
});

test('l\'autore che non vede più la segnalazione non elimina il suo file dal portale', function () {
    $id = algAllegatoSalvato($this->s, $this->luigi, 'foto.jpg');
    $this->luigi->anagrafica->condomini()->detach($this->aurora->id);

    expect(algRifiutato($this->actingAs($this->luigi->fresh())->delete(route('user.allegati.destroy', ['allegato' => $id]))))->toBeTrue()
        ->and(DB::table('documenti')->find($id))->not->toBeNull();
});

test('dal pannello l\'amministratore elimina il file di chiunque; il collaboratore solo i suoi', function () {
    $diMario = algAllegatoSalvato($this->s, $this->mario, 'di-mario.jpg');
    $delCollaboratore = algAllegatoSalvato($this->s, $this->collaboratore, 'suo.pdf');

    expect(algRifiutato($this->actingAs($this->collaboratore)->delete(route('admin.allegati.destroy', ['allegato' => $diMario]))))->toBeTrue();
    $this->actingAs($this->collaboratore)->delete(route('admin.allegati.destroy', ['allegato' => $delCollaboratore]))->assertRedirect();
    $this->actingAs($this->admin)->delete(route('admin.allegati.destroy', ['allegato' => $diMario]))->assertRedirect();

    expect(algRighe($this->s))->toHaveCount(0);
});

test('l\'autore rinomina il proprio file: cambia il nome, non l\'autore, lo stato né chi l\'ha approvato; l\'amministratore rinomina quello di chiunque, e l\'autore resta', function () {
    $id = algAllegatoSalvato($this->s, $this->mario, 'IMG_2041.jpg');
    DB::table('documenti')->where('id', $id)->update(['moderato_da' => $this->admin->id, 'moderato_at' => now()->subDay()]);
    $prima = DB::table('documenti')->find($id);

    expect(algRifiutato($this->actingAs($this->luigi)->patch(route('user.allegati.update', ['allegato' => $id]), ['nome' => 'Mio.jpg'])))->toBeTrue();

    $this->actingAs($this->mario)->patch(route('user.allegati.update', ['allegato' => $id]), ['nome' => 'Cancello rotto.jpg'])->assertRedirect();
    $dopo = DB::table('documenti')->find($id);
    expect($dopo->name)->toBe('Cancello rotto.jpg')
        ->and((int) $dopo->created_by)->toBe($this->mario->id)
        ->and((bool) $dopo->is_approved)->toBe((bool) $prima->is_approved)
        ->and((int) $dopo->moderato_da)->toBe($this->admin->id)
        ->and($dopo->moderato_at)->toBe($prima->moderato_at)
        ->and((int) $dopo->updated_by)->toBe($this->mario->id)
        ->and($dopo->path)->toBe($prima->path);

    $this->actingAs($this->admin)->patch(route('admin.allegati.update', ['allegato' => $id]), ['nome' => 'Cancello ingresso B.jpg'])->assertRedirect();
    $ultimo = DB::table('documenti')->find($id);
    expect($ultimo->name)->toBe('Cancello ingresso B.jpg')
        ->and((int) $ultimo->created_by)->toBe($this->mario->id)
        ->and((int) $ultimo->updated_by)->toBe($this->admin->id);

    $vuoto = $this->actingAs($this->admin)->patch(route('admin.allegati.update', ['allegato' => $id]), ['nome' => '']);
    $vuoto->assertSessionHasErrors('nome');
});

test('le impostazioni degli allegati di un elemento le cambia dal pannello chi approva; il collaboratore senza il permesso e il condòmino no', function () {
    $rotta = route('admin.segnalazioni.allegati.impostazioni', $this->s);

    $this->actingAs($this->admin)->patch($rotta, ['allegati_accesso' => 'consultazione', 'allegati_moderati' => false])->assertRedirect();
    $this->s->refresh();
    expect($this->s->allegati_accesso)->toBe('consultazione')->and((bool) $this->s->allegati_moderati)->toBeFalse();

    expect(algRifiutato($this->actingAs($this->collaboratore)->patch($rotta, ['allegati_accesso' => 'aperti', 'allegati_moderati' => true])))->toBeTrue()
        ->and(algRifiutato($this->actingAs($this->mario)->patch($rotta, ['allegati_accesso' => 'aperti', 'allegati_moderati' => true])))->toBeTrue();
    expect($this->s->fresh()->allegati_accesso)->toBe('consultazione');

    $this->actingAs($this->admin)->patch($rotta, ['allegati_accesso' => 'nascosti', 'allegati_moderati' => true])->assertSessionHasErrors('allegati_accesso');

    $c = algComunicazione([$this->aurora], $this->admin);
    $this->actingAs($this->admin)->patch(route('admin.comunicazioni.allegati.impostazioni', $c), ['allegati_accesso' => 'riservati', 'allegati_moderati' => true])->assertRedirect();
    expect($c->fresh()->allegati_accesso)->toBe('riservati');
});

test('togliere la moderazione a un elemento non pubblica i file già in attesa', function () {
    $id = algAllegatoSalvato($this->s, $this->mario, 'foto.jpg', approvato: false);

    $this->actingAs($this->admin)->patch(route('admin.segnalazioni.allegati.impostazioni', $this->s), ['allegati_accesso' => 'aperti', 'allegati_moderati' => false])->assertRedirect();

    expect((bool) DB::table('documenti')->find($id)->is_approved)->toBeFalse();
});

test('eliminare la segnalazione o la comunicazione elimina i suoi allegati, righe e file; gli altri restano', function () {
    $altra = algSegnalazione($this->aurora, $this->mario, ['subject' => 'Un\'altra']);
    $suo = algAllegatoSalvato($this->s, $this->mario, 'foto.jpg');
    $dellAltra = algAllegatoSalvato($altra, $this->mario, 'resta.jpg');
    $path = DB::table('documenti')->find($suo)->path;

    $this->actingAs($this->admin)->delete(route('admin.segnalazioni.destroy', $this->s))->assertRedirect();

    expect(DB::table('documenti')->find($suo))->toBeNull()
        ->and(DB::table('documenti')->find($dellAltra))->not->toBeNull();
    app(ArchivioDocumenti::class)->disco()->assertMissing($path);

    $c = algComunicazione([$this->aurora], $this->admin);
    $dellaComunicazione = algAllegatoSalvato($c, $this->admin, 'convocazione.pdf');
    $pathC = DB::table('documenti')->find($dellaComunicazione)->path;
    $this->actingAs($this->admin)->delete(route('admin.comunicazioni.destroy', $c))->assertRedirect();

    expect(DB::table('documenti')->find($dellaComunicazione))->toBeNull();
    app(ArchivioDocumenti::class)->disco()->assertMissing($pathC);
});

test('un caricamento in attesa apre una voce nell\'Inbox per l\'elemento, una sola anche dopo due caricamenti; si chiude quando non resta niente in attesa', function () {
    algCarica($this, $this->mario, $this->s, [algFoto('uno.jpg')])->assertSessionHasNoErrors();
    algCarica($this, $this->luigi, $this->s, [algFoto('due.jpg')])->assertSessionHasNoErrors();

    $voci = fn () => Evento::query()->where('tipo', 'allegato')
        ->where('eventable_type', $this->s->getMorphClass())->where('eventable_id', $this->s->id);
    expect($voci()->count())->toBe(1)
        ->and((bool) $voci()->first()->is_completed)->toBeFalse();

    [$uno, $due] = algRighe($this->s)->pluck('id')->all();
    $this->actingAs($this->admin)->post(route('admin.allegati.approva', ['allegato' => $uno]))->assertRedirect();
    expect((bool) $voci()->first()->is_completed)->toBeFalse();

    $this->actingAs($this->admin)->delete(route('admin.allegati.destroy', ['allegato' => $due]))->assertRedirect();
    expect((bool) $voci()->first()->fresh()->is_completed)->toBeTrue();
});

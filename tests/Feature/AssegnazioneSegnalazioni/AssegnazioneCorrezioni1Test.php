<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Assegnazione delle segnalazioni: il giro di correzioni 1
 *
 * - **A1** Il permesso «Assegna segnalazioni» arriva nel database con il seeder dei ruoli, che gira dopo il codice. Finché
 *   la sua riga non c'è, le regole rispondono «no» e le pagine si aprono: non cadono.
 * - **A2** La risorsa non va a cercare chi segue la segnalazione dove nessuno l'ha caricato: l'elenco del portale non fa
 *   richieste al database per un dato che non mostra.
 * - **A3** Con l'impostazione accesa, ogni assegnazione a qualcuno di una segnalazione ancora aperta la porta a «in
 *   lavorazione»: anche una riassegnazione.
 * - **A5** Le due email non portano gli utenti: chi ha fatto il gesto può sparire prima dell'invio, e se sparisce la
 *   segnalazione l'invio si scarta senza errori.
 *
 * A4 (il titolo nel portale) lo prova il vitest `SegnalazioniViewSeguitaDa.test.ts`.
 */

use App\Http\Resources\Portale\SegnalazionePortaleResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__ . '/AssegnazioneScenario.php';

beforeEach(function () {
    asgPrepara($this);
});

/** Toglie dal database la riga del permesso nuovo, com'è su un'installazione dove il seeder dei ruoli non è ancora girato. */
function asgSenzaIlPermessoNelDatabase(): void
{
    $id = DB::table('permissions')->where('name', ASG_PERMESSO)->value('id');
    expect($id)->not->toBeNull('Il permesso non è nel database di prova');
    DB::table('role_has_permissions')->where('permission_id', $id)->delete();
    DB::table('model_has_permissions')->where('permission_id', $id)->delete();
    DB::table('permissions')->where('id', $id)->delete();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
}

/** La notifica della classe data partita all'utente. */
function asgNotificaPartita(User $user, string $classe): object
{
    $notifica = null;
    Notification::assertSentTo($user, $classe, function ($n) use (&$notifica) {
        $notifica = $n;

        return true;
    });

    return $notifica;
}

// --- A1: senza la riga del permesso ------------------------------------------------------------------------------

test('senza il permesso nel database le regole rispondono «no», e non cadono', function () {
    asgSenzaIlPermessoNelDatabase();
    $regole = ASG_REGOLE;

    expect($regole::puoAssegnare($this->admin->fresh()))->toBeFalse()
        ->and($regole::puoAssegnare($this->collaboratrice->fresh()))->toBeFalse()
        ->and($regole::puoRicevere($this->elena->fresh()))->toBeTrue();
});

test('senza il permesso nel database la scheda del pannello si apre, senza i comandi per assegnare', function (string $chi) {
    $s = asgAssegnata($this, $this->elena);
    asgSenzaIlPermessoNelDatabase();
    $utente = $this->{$chi}->fresh();

    $risposta = $this->actingAs($utente)->get(route('admin.segnalazioni.show', $s));

    $risposta->assertOk();
    $props = asgProps($risposta);
    expect($props['assegnazione'])->toEqual(['puo_assegnare' => false, 'io' => $utente->id, 'assegnabili' => []])
        ->and(asgRisorsa($props['segnalazione'])['assegnata_a']['name'])->toBe('Elena Studio');
})->with(['admin', 'collaboratrice']);

test('senza il permesso nel database l\'elenco del pannello si apre, con la colonna e il filtro', function () {
    $s = asgAssegnata($this, $this->elena);
    asgSenzaIlPermessoNelDatabase();

    $risposta = $this->actingAs($this->collaboratrice->fresh())->get(route('admin.segnalazioni.index'));

    $risposta->assertOk();
    $props = asgProps($risposta);
    expect(collect(asgElenco($props))->firstWhere('id', $s->id)['assegnata_a']['name'])->toBe('Elena Studio')
        ->and(collect($props['assegnatari'])->pluck('name')->all())->toContain('Elena Studio');
});

test('senza il permesso nel database il comando risponde «non puoi», non un errore', function () {
    asgSenzaIlPermessoNelDatabase();

    asgAssegna($this, $this->collaboratrice->fresh(), $this->s, $this->elena->id)->assertForbidden();

    expect(asgRiga($this->s)->assigned_to)->toBeNull();
});

// --- A2: la risorsa non carica da sé chi segue la segnalazione ---------------------------------------------------

test('la risorsa non va a cercare chi segue la segnalazione, se nessuno l\'ha caricato', function () {
    $s = asgAssegnata($this, $this->elena);

    $perIlPannello = Segnalazione::with(['condominio', 'createdBy'])->findOrFail($s->id);
    $pannello = (new SegnalazioneResource($perIlPannello))->resolve();
    expect($perIlPannello->relationLoaded('assignedTo'))->toBeFalse('La risorsa del pannello ha caricato da sé chi segue la segnalazione')
        ->and($perIlPannello->relationLoaded('assegnataDa'))->toBeFalse('La risorsa del pannello ha caricato da sé chi ha assegnato')
        ->and($pannello['assegnata_a'] ?? null)->toBeNull();

    asgImpostazione(ASG_IMP_PORTALE, true);
    $perIlPortale = Segnalazione::with(['condominio', 'createdBy.anagrafica'])->findOrFail($s->id);
    $portale = (new SegnalazionePortaleResource($perIlPortale))->resolve();
    expect($perIlPortale->relationLoaded('assignedTo'))->toBeFalse('La risorsa del portale ha caricato da sé chi segue la segnalazione')
        ->and($portale['seguita_da'] ?? null)->toBeNull()
        ->and($portale)->not->toHaveKey('assegnata_a');
});

test('con la relazione caricata la risorsa del pannello dice chi segue la segnalazione, come prima', function () {
    $s = asgAssegnata($this, $this->elena);

    $caricata = Segnalazione::with(['condominio', 'createdBy', 'assignedTo'])->findOrFail($s->id);
    $dati = (new SegnalazioneResource($caricata))->resolve();

    expect($dati['assegnata_a'])->toMatchArray(['id' => $this->elena->id, 'name' => 'Elena Studio', 'attivo' => true]);
});

test('l\'elenco del portale non fa richieste al database per le segnalazioni assegnate', function (bool $impostazione) {
    asgImpostazione(ASG_IMP_PORTALE, $impostazione);
    $conta = function (): int {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->mario)->get(route('user.segnalazioni.index'))->assertOk();
        $quante = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $quante;
    };
    $segnalazioni = collect(range(1, 8))->map(fn (int $n) => ccmSegnalazione($this->aurora, $this->mario, ['subject' => "Guasto numero {$n}"]));
    $persone = collect(range(1, 8))->map(fn (int $n) => ccmStudioSenzaAnagrafica("Collaboratore {$n}", 'collaboratore'));
    $senza = $conta();

    $segnalazioni->each(function ($s, int $i) use ($persone) {
        DB::table('segnalazioni')->where('id', $s->id)->update(['assigned_to' => $persone[$i]->id, 'assegnata_da' => $this->admin->id, 'assegnata_il' => now()]);
    });
    $con = $conta();

    expect($con - $senza)->toBeLessThanOrEqual(1, "Con otto segnalazioni assegnate l'elenco del portale fa " . ($con - $senza) . ' richieste in più');
})->with(['impostazione spenta' => [false], 'impostazione accesa' => [true]]);

// --- A3: lo stato -------------------------------------------------------------------------------------------------

test('con l\'impostazione accesa, riassegnare una segnalazione ancora aperta la porta a «in lavorazione»', function () {
    // Assegnata quando l'impostazione era spenta: è rimasta aperta.
    $s = asgAssegnata($this, $this->collaboratrice);
    asgImpostazione(ASG_IMP_STATO, true);

    asgAssegna($this, $this->admin, $s, $this->elena->id)->assertSessionHasNoErrors();

    expect(asgRiga($s)->stato)->toBe('in lavorazione')
        ->and((int) asgRiga($s)->assigned_to)->toBe($this->elena->id);
});

test('vale anche per chi prende per sé una segnalazione aperta di un altro', function () {
    $s = asgAssegnata($this, $this->collaboratrice);
    asgImpostazione(ASG_IMP_STATO, true);

    asgAssegna($this, $this->elena, $s, $this->elena->id)->assertSessionHasNoErrors();

    expect(asgRiga($s)->stato)->toBe('in lavorazione');
});

test('con l\'impostazione spenta riassegnare non cambia lo stato, e una chiusa resta chiusa anche con l\'impostazione accesa', function () {
    $aperta = asgAssegnata($this, $this->collaboratrice);
    asgAssegna($this, $this->admin, $aperta, $this->elena->id)->assertSessionHasNoErrors();
    expect(asgRiga($aperta)->stato)->toBe('aperta');

    asgImpostazione(ASG_IMP_STATO, true);
    $chiusa = asgAssegnata($this, $this->collaboratrice, null, ['stato' => 'chiusa']);
    asgAssegna($this, $this->admin, $chiusa, $this->elena->id)->assertSessionHasNoErrors();
    expect(asgRiga($chiusa)->stato)->toBe('chiusa');
});

// --- A5: le email in coda -----------------------------------------------------------------------------------------

test('le due email non portano gli utenti: chi ha fatto il gesto e chi la riceve possono sparire prima dell\'invio', function () {
    $s = asgAssegnata($this, $this->collaboratrice);
    asgAssegna($this, $this->admin, $s, $this->elena->id);
    $assegnata = asgNotificaPartita($this->elena, ASG_MAIL_ASSEGNATA);
    $tolta = asgNotificaPartita($this->collaboratrice, ASG_MAIL_TOLTA);

    foreach ([$assegnata, $tolta] as $notifica) {
        $utenti = collect((new ReflectionObject($notifica))->getProperties())
            ->filter(fn (ReflectionProperty $p) => $p->isInitialized($notifica) && $p->getValue($notifica) instanceof User);
        expect($utenti->map(fn (ReflectionProperty $p) => $p->getName())->values()->all())->toBe([], get_class($notifica) . ' porta un utente intero');
    }

    // Com'è in coda: scritta, e riletta dopo che i due utenti non ci sono più.
    $inCoda = [serialize($assegnata), serialize($tolta)];
    DB::table('segnalazioni')->where('id', $s->id)->update(['assegnata_da' => null]);
    DB::table('users')->where('id', $this->admin->id)->delete();

    $riletta = unserialize($inCoda[0]);
    expect(implode("\n", $riletta->toMail($this->elena)->introLines))->toContain('Studio Bianchi');

    $riletta = unserialize($inCoda[1]);
    expect(implode("\n", $riletta->toMail($this->collaboratrice)->introLines))->toContain('Studio Bianchi')
        ->and(implode("\n", $riletta->toMail($this->collaboratrice)->introLines))->toContain('Elena Studio');
});

test('se la segnalazione viene eliminata prima dell\'invio, l\'email si scarta senza errori', function (string $quale) {
    $s = asgAssegnata($this, $this->collaboratrice);
    asgAssegna($this, $this->admin, $s, $this->elena->id);
    [$chi, $classe] = $quale === 'assegnata' ? [$this->elena, ASG_MAIL_ASSEGNATA] : [$this->collaboratrice, ASG_MAIL_TOLTA];
    $notifica = asgNotificaPartita($chi, $classe);
    $inCoda = serialize($notifica);
    $siScarta = (new SendQueuedNotifications($chi, $notifica, ['mail']))->deleteWhenMissingModels;

    DB::table('segnalazioni')->where('id', $s->id)->delete();

    try {
        $riletta = unserialize($inCoda);
        // La notifica non porta il model: allora è `shouldSend` a fermarla.
        expect($riletta->shouldSend($chi, 'mail'))->toBeFalse();
    } catch (ModelNotFoundException) {
        // La notifica porta il model: allora la coda la deve scartare, non segnare come fallita.
        expect($siScarta)->toBeTrue('La notifica porta la segnalazione ma non dice alla coda di scartarla se manca (deleteWhenMissingModels)');
    }
})->with(['assegnata', 'non più assegnata']);

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Exceptions\RegistroModifiche\RigaDelRegistroIntoccabile;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\Autore;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\ImprontaDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, decisione 518, §2 e §3 del progetto della tappa 1): la riga del registro si scrive nella transazione della modifica, il suo
 * posto nella catena lo dà il sigillo dopo il commit, e nessuno la cambia o la cancella con il modello.
 *
 * Che cosa non copre: la serratura vera di MySQL fra due processi (la suite gira su SQLite in memoria, una connessione sola: «due transazioni che si
 * chiudono a rovescio» si simula nascondendo al primo sigillo la riga non ancora confermata); il comando pianificato dentro `schedule:run` (si lancia il
 * comando); un sigillo che si ferma a metà di un blocco su MySQL.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
});

function rmScrivi(array $sopra = []): RegistroModifica
{
    return RegistroModifiche::scrivi(array_replace([
        'area' => 'persone',
        'oggetto_tipo' => 'anagrafica',
        'oggetto_id' => 57,
        'oggetto_nome' => 'Anagrafica di Ida Romano',
        'azione' => 'modificata',
        'campi' => [['chiave' => 'email', 'etichetta' => 'Email', 'prima' => 'ida.romano@libero.it', 'dopo' => 'ida.romano@gmail.com',
            'valore_prima' => 'ida.romano@libero.it', 'valore_dopo' => 'ida.romano@gmail.com', 'segreto' => false]],
        'condomini' => [12 => 'Condominio Via Roma 12'],
        'soggetti' => [['anagrafica', 57]],
    ], $sopra));
}

it('scrive la riga con l\'autore in sessione, i nomi copiati, i condomini e i soggetti, e il sigillo le dà il primo posto della catena', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $marco = User::factory()->create(['name' => 'Marco Galli']);
    $marco->assignRole(Role::COLLABORATORE->value);
    $this->actingAs($marco);

    $riga = rmScrivi()->fresh();

    expect($riga->autore_tipo)->toBe('persona')
        ->and($riga->autore_id)->toBe($marco->id)
        ->and($riga->autore_nome)->toBe('Marco Galli')
        ->and($riga->autore_ruolo)->toBe('collaboratore')
        ->and($riga->origine)->toBe('pagina')
        ->and($riga->condomini())->toBe([12 => 'Condominio Via Roma 12'])
        ->and($riga->soggetti())->toBe([['anagrafica', 57]])
        ->and($riga->impronta_valori)->toBe(ImprontaDelRegistro::deiValori($riga->getAttributes()))
        ->and($riga->sequenza)->toBe(1)
        ->and($riga->impronta_precedente)->toBeNull()
        ->and($riga->impronta)->toBe(ImprontaDelRegistro::dellaRiga(null,
            ImprontaDelRegistro::intestazione($riga->getAttributes(), 1, [12 => 'Condominio Via Roma 12'], [['anagrafica', 57]]), $riga->impronta_valori));

    $seconda = rmScrivi(['azione' => 'creata'])->fresh();
    expect($seconda->sequenza)->toBe(2)
        ->and($seconda->impronta_precedente)->toBe($riga->impronta)
        ->and(DB::table('registro_modifiche_catena')->value('ultima_impronta'))->toBe($seconda->impronta);
});

it('senza una persona in sessione l\'autore è «Il programma»', function () {
    $riga = rmScrivi()->fresh();

    expect($riga->autore_tipo)->toBe('programma')
        ->and($riga->autore_nome)->toBe('Il programma')
        ->and($riga->autore_id)->toBeNull();
});

it('una modifica che fallisce dopo aver scritto la riga non lascia la riga, né i suoi condomini e soggetti', function () {
    $prima = RegistroModifica::query()->count();

    expect(fn () => DB::transaction(function () {
        rmScrivi();
        throw new RuntimeException('la modifica non va');
    }))->toThrow(RuntimeException::class, 'la modifica non va');

    expect(RegistroModifica::query()->count())->toBe($prima)
        ->and(DB::table('registro_modifiche_condomini')->count())->toBe(0)
        ->and(DB::table('registro_modifiche_soggetti')->count())->toBe(0);
});

it('il sigillo arriva dopo il commit: dentro la transazione la riga non ha ancora il suo posto', function () {
    $dentro = null;
    DB::transaction(function () use (&$dentro) {
        $riga = rmScrivi();
        $dentro = DB::table('registro_modifiche')->where('id', $riga->id)->value('sequenza');
    });

    expect($dentro)->toBeNull()
        ->and(DB::table('registro_modifiche')->whereNull('sequenza')->count())->toBe(0);
});

it('un errore del sigillo non fa fallire la richiesta: la riga resta da sigillare, il log lo dice, il comando la sigilla dopo', function () {
    Log::spy();
    Schema::drop('registro_modifiche_catena');

    $riga = DB::transaction(fn () => rmScrivi());

    expect(RegistroModifica::query()->whereKey($riga->id)->exists())->toBeTrue()
        ->and($riga->fresh()->sequenza)->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn ($messaggio) => str_contains($messaggio, 'sigillo non riuscito'))->atLeast()->once();

    (require database_path('migrations/2026_11_19_100000_create_registro_modifiche_tables.php'))->up();
    $this->artisan('kondomanager:sigilla-registro')->expectsOutputToContain('Sigillata 1 riga.')->assertSuccessful();

    expect($riga->fresh()->sequenza)->toBe(1);
});

it('dentro una transazione già aperta il sigillo non apre un\'altra transazione di Laravel: un savepoint suo, rilasciato; se non va, la transazione di chi chiama continua', function () {
    // Un savepoint per sigillo, mai rilasciato, rallentava su SQLite ogni scrittura dopo (l'emissione di 3.000 unità diventava più che lineare).
    $aperte = 0;
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$aperte) {
        $aperte++;
    });
    $righe = [rmScrivi(), rmScrivi(['azione' => 'creata'])];

    expect($aperte)->toBe(0)
        ->and(array_map(fn ($r) => $r->fresh()->sequenza, $righe))->toBe([1, 2])
        // Rilasciato: non c'è più un savepoint con quel nome da rilasciare.
        ->and(fn () => DB::connection()->getPdo()->exec('RELEASE SAVEPOINT registro_sigillo'))->toThrow(PDOException::class);

    Log::spy();
    $terza = DB::transaction(function () {
        $riga = rmScrivi();
        Schema::drop('registro_modifiche_catena');
        \App\Services\RegistroModifiche\SigilloDelRegistro::sigillaSenzaFermare();

        return $riga;
    });
    Log::shouldHaveReceived('warning')->withArgs(fn ($messaggio) => str_contains($messaggio, 'sigillo non riuscito'))->atLeast()->once();
    expect($terza->fresh()->sequenza)->toBeNull();
});

it('una riga non si cambia e non si cancella con il modello, neanche con update() o delete()', function () {
    $riga = rmScrivi()->fresh();

    expect(fn () => $riga->update(['oggetto_nome' => 'Altro']))->toThrow(RigaDelRegistroIntoccabile::class)
        ->and(fn () => $riga->delete())->toThrow(RigaDelRegistroIntoccabile::class)
        ->and(fn () => $riga->forceDelete())->toThrow(RigaDelRegistroIntoccabile::class)
        ->and($riga->fresh()->oggetto_nome)->toBe('Anagrafica di Ida Romano');
});

it('un gesto scrive una riga sola; durante conta le scritture nel lotto e un gesto dentro un altro non scrive una riga sua', function () {
    $riga = RegistroModifiche::durante(
        Gesto::su('piani_rate', 'piano_rate', 4, 'Piano rate «Ordinario 2027»')->riepilogo(fn ($lotto) => 'Piano «Ordinario 2027» generato: '
            . $lotto->quante('rata', 'creata') . ' rate'),
        function () {
            ContestoDelRegistro::lottoAperto()->conta('rata', 'creata', 1);
            ContestoDelRegistro::lottoAperto()->conta('rata', 'creata', 2);
            RegistroModifiche::gesto(Gesto::su('piani_rate', 'piano_rate', 4, 'Piano rate «Ordinario 2027»')->riepilogo('dentro'));

            return 'fatto';
        });

    $righe = RegistroModifica::query()->get();
    expect($riga)->toBe('fatto')
        ->and($righe)->toHaveCount(1)
        ->and($righe[0]->azione)->toBe('gesto')
        ->and($righe[0]->riepilogo)->toBe('Piano «Ordinario 2027» generato: 2 rate')
        ->and($righe[0]->lotto)->toBe(['conteggi' => ['piano_rate.gesto' => 1, 'rata.creata' => 2], 'id' => ['piano_rate' => [4], 'rata' => [1, 2]]])
        ->and(ContestoDelRegistro::inUnGesto())->toBeFalse();
});

it('durante rimette lo stato anche se il lavoro fallisce, e la riga del gesto non c\'è', function () {
    expect(fn () => RegistroModifiche::durante(Gesto::su('piani_rate', 'piano_rate', 4, 'Piano'), function () {
        throw new RuntimeException('a metà');
    }))->toThrow(RuntimeException::class);

    expect(ContestoDelRegistro::inUnGesto())->toBeFalse()
        ->and(RegistroModifica::query()->count())->toBe(0);
});

it('un autore dichiarato vale per il lavoro e poi torna quello di prima', function () {
    $riga = RegistroModifiche::comeAutore(Autore::programma('importazione', 'Importazione da Danea avviata da Andrea Costa'), fn () => rmScrivi());

    expect($riga->origine)->toBe('importazione')
        ->and($riga->origine_dettaglio)->toBe('Importazione da Danea avviata da Andrea Costa')
        ->and(RegistroModifiche::autore()->origine)->not->toBe('importazione');
});

it('un\'area o un\'azione sconosciuta è un errore di programma e ferma la modifica', function () {
    expect(fn () => rmScrivi(['area' => 'cucina']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => rmScrivi(['azione' => 'cucinata']))->toThrow(InvalidArgumentException::class);
});

it('il JSON canonico non dipende dall\'ordine delle chiavi né dalle chiavi 0..n-1 fuori ordine (MySQL le riordina)', function () {
    expect(ImprontaDelRegistro::canonico(['b' => 1, 'a' => ['y' => 2, 'x' => 3]]))->toBe('{"a":{"x":3,"y":2},"b":1}')
        ->and(ImprontaDelRegistro::canonico([1 => 'a', 0 => 'b']))->toBe('["b","a"]')
        ->and(ImprontaDelRegistro::canonico(['è' => 'à/b']))->toBe('{"è":"à/b"}')
        ->and(ImprontaDelRegistro::canonico([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]))->toBe('[0,1,2,3,4,5,6,7,8,9,10]');
});

it('una riga del registro che non si scrive dentro una transazione aperta fa rifiutare il commit, anche se chi chiama prende l\'eccezione e va avanti', function () {
    $ida = \App\Models\Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida.romano@libero.it']);
    $righePrima = RegistroModifica::query()->count();
    // Il database rifiuta la riga del registro (un disco pieno, una colonna che non c'è): qui la nascita del modello della riga solleva.
    RegistroModifica::creating(fn () => throw new RuntimeException('disco pieno'));

    expect(fn () => DB::transaction(function () use ($ida) {
        try {
            $ida->update(['email' => 'ida.romano@gmail.com']);
        } catch (RuntimeException) {
            // Un `catch` che va avanti: la modifica della scheda c'è, la sua riga no.
        }
    }))->toThrow(RuntimeException::class, 'Una riga del registro delle modifiche non è stata scritta: la modifica non si conferma.');

    expect($ida->fresh()->email)->toBe('ida.romano@libero.it')
        ->and(RegistroModifica::query()->count())->toBe($righePrima);

    // E con la transazione aperta e chiusa a mano.
    DB::beginTransaction();
    try {
        $ida->update(['email' => 'ida.romano@gmail.com']);
    } catch (RuntimeException) {
    }
    expect(fn () => DB::commit())->toThrow(RuntimeException::class, 'la modifica non si conferma');
    expect($ida->fresh()->email)->toBe('ida.romano@libero.it');

    // Fuori da una transazione la riga che non va ferma la modifica da sé (la transazione la apre il salvataggio): niente da rifiutare dopo.
    RegistroModifica::flushEventListeners();
    $ida->update(['email' => 'ida.romano@gmail.com']);
    expect($ida->fresh()->email)->toBe('ida.romano@gmail.com');
});

it('un legame su una scheda appena creata fuori da una transazione lo scrive il log, per trovare il punto (decisione 554); dentro una transazione no', function () {
    Log::spy();
    $condominio = \App\Models\Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $carlo = \App\Models\Anagrafica::query()->create(['nome' => 'Carlo Fontana', 'indirizzo' => 'Via Roma 12']);
    RegistroModifiche::legame($carlo, 'condomini', 'Condomini', fn () => $carlo->condomini()->attach($condominio->id));

    Log::shouldHaveReceived('warning')->withArgs(fn ($messaggio, $contesto = []) => str_contains($messaggio, 'scheda appena creata')
        && ($contesto['scheda'] ?? null) === 'anagrafica:' . $carlo->id)->once();

    DB::transaction(function () use ($condominio) {
        $luca = \App\Models\Anagrafica::query()->create(['nome' => 'Luca Bianchi', 'indirizzo' => 'Via Roma 12']);
        RegistroModifiche::legame($luca, 'condomini', 'Condomini', fn () => $luca->condomini()->attach($condominio->id));
    });
    Log::shouldHaveReceived('warning')->withArgs(fn ($messaggio) => str_contains($messaggio, 'scheda appena creata'))->once();
});

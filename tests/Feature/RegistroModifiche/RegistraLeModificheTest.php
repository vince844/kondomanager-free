<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\RegolaRecupero;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Immobile;
use App\Models\RegistroModifica;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\DB;

/**
 * 1.12.0-beta.13 (Coda 679, §4 del progetto, P8, P19): il tratto delle schede scrive, nella transazione del salvataggio, i dati cambiati con le parole
 * della pagina; niente riga se cambiano solo i campi del programma; dentro un gesto conta nel lotto; i legami scrivono «collegata» sulla scheda giusta.
 *
 * Che cosa non copre: le pagine (i test per area fanno il gesto vero dalla rotta); `saveQuietly()` e `withoutEvents()`, che per scelta non scrivono; le
 * scritture in blocco (le guarda la guardia); tutte le 29 schede una per una (le dichiarazioni le controlla il test della classificazione).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa']);
    $this->actingAs($this->andrea);
    $this->condominio = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->ida = Anagrafica::factory()->create(['nome' => 'Ida Romano', 'email' => 'ida.romano@libero.it', 'pec' => null, 'user_id' => null]);
    $this->ida->condomini()->attach($this->condominio->id);
    $this->esercizio = \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id]);
});

function rlmUltima(): RegistroModifica
{
    return RegistroModifica::query()->orderByDesc('id')->firstOrFail();
}

it('una modifica scrive i soli dati cambiati, con le parole della pagina, com\'erano e come sono, e il condominio della persona', function () {
    $this->ida->update(['email' => 'ida.romano@gmail.com', 'pec' => 'ida.romano@pec.it', 'scadenza_documento' => '2030-06-30']);

    $riga = rlmUltima();
    $campi = collect($riga->campi)->keyBy('chiave');
    expect($riga->azione)->toBe('modificata')
        ->and($riga->area)->toBe('persone')
        ->and($riga->oggetto_tipo)->toBe('anagrafica')
        ->and($riga->oggetto_nome)->toBe('Anagrafica di Ida Romano')
        ->and($riga->autore_nome)->toBe('Andrea Costa')
        ->and($riga->condomini())->toBe([$this->condominio->id => 'Via Roma 12'])
        ->and($riga->soggetti())->toBe([['anagrafica', $this->ida->id]])
        ->and($campi['email'])->toMatchArray(['etichetta' => 'Indirizzo email primario', 'prima' => 'ida.romano@libero.it', 'dopo' => 'ida.romano@gmail.com'])
        ->and($campi['pec'])->toMatchArray(['etichetta' => 'Indirizzo email PEC', 'prima' => null, 'dopo' => 'ida.romano@pec.it'])
        ->and($campi['scadenza_documento']['dopo'])->toBe('30/06/2030')
        ->and($campi->keys()->sort()->values()->all())->toBe(['email', 'pec', 'scadenza_documento']);
});

it('un salvataggio che non cambia niente, o solo i campi del programma, non scrive righe', function () {
    $prima = RegistroModifica::query()->count();

    $this->ida->update(['email' => 'ida.romano@libero.it']);
    $this->condominio->update(['codice_identificativo' => 'ZZZ999']);

    expect(RegistroModifica::query()->count())->toBe($prima);
});

it('una scheda nuova scrive «creata» con i dati scritti; una eliminata «eliminata» con i dati che c\'erano e il condominio letto prima', function () {
    $carlo = Anagrafica::query()->create(['nome' => 'Carlo Fontana', 'indirizzo' => 'Via Po 3', 'telefono' => '333 120 4455']);
    $creata = rlmUltima();
    expect($creata->azione)->toBe('creata')
        ->and($creata->riepilogo)->toBe('Con 3 dati.')
        ->and(collect($creata->campi)->pluck('dopo', 'etichetta')->all())->toBe(['Nome e cognome' => 'Carlo Fontana', 'Indirizzo di residenza' => 'Via Po 3', 'Telefono' => '333 120 4455']);

    $id = $this->ida->id;
    $this->ida->delete();
    $eliminata = rlmUltima();
    expect($eliminata->azione)->toBe('eliminata')
        ->and($eliminata->oggetto_id)->toBe($id)
        ->and($eliminata->oggetto_nome)->toBe('Anagrafica di Ida Romano')
        ->and($eliminata->condomini())->toBe([$this->condominio->id => 'Via Roma 12'])
        ->and(collect($eliminata->campi)->firstWhere('chiave', 'email'))->toMatchArray(['prima' => 'ida.romano@libero.it', 'dopo' => null]);
});

it('la riga è nella transazione del salvataggio: se dopo qualcosa non va, né la modifica né la riga restano', function () {
    $prima = RegistroModifica::query()->count();

    expect(fn () => DB::transaction(function () {
        $this->ida->update(['email' => 'altra@example.it']);
        throw new RuntimeException('dopo il salvataggio');
    }))->toThrow(RuntimeException::class);

    expect(RegistroModifica::query()->count())->toBe($prima)
        ->and($this->ida->fresh()->email)->toBe('ida.romano@libero.it');
});

it('dentro una transazione già aperta il salvataggio e l\'eliminazione di una scheda non ne aprono un\'altra: la riga sta in quella di chi chiama', function () {
    // Un savepoint per salvataggio, mai rilasciato da Laravel, su SQLite resta aperto fino alla fine della transazione
    // esterna, e ogni scrittura dopo costa in proporzione ai savepoint aperti (l'emissione di 3.000 unità più che lineare).
    $piuDentro = 0;
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function ($evento) use (&$piuDentro) {
        $piuDentro = max($piuDentro, $evento->connection->transactionLevel());
    });

    $diChiChiama = DB::transaction(function () {
        $this->ida->update(['email' => 'ida@example.it']);
        Anagrafica::factory()->create(['nome' => 'Luca Esposito', 'user_id' => null])->delete();

        return DB::transactionLevel();
    });

    // Dopo il commit il sigillo apre la sua, allo stesso livello: nessuna sotto quella di chi chiama.
    expect($piuDentro)->toBe($diChiChiama)
        ->and(RegistroModifica::query()->where('oggetto_tipo', 'anagrafica')->pluck('azione')->all())->toContain('modificata', 'creata', 'eliminata');
});

it('senza una transazione aperta il salvataggio ne apre una sua: se la riga non va, la modifica non resta', function () {
    // Nei test c'è sempre la transazione della prova: qui una connessione a parte, vuota, dove il salvataggio parte dal livello zero come in una pagina.
    config(['database.connections.livello_zero' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $predefinita = DB::getDefaultConnection();
    app('auth')->forgetGuards();
    DB::setDefaultConnection('livello_zero');
    try {
        (require base_path('database/migrations/2026_11_19_100000_create_registro_modifiche_tables.php'))->up();
        (require base_path('database/migrations/2025_11_29_064536_create_categorie_fornitore_table.php'))->up();
        $categoria = \App\Models\CategoriaFornitore::query()->create(['name' => 'Idraulici']);
        expect(DB::transactionLevel())->toBe(0)
            ->and(RegistroModifica::query()->count())->toBe(1)
            ->and(RegistroModifica::query()->value('sequenza'))->toBe(1);

        \Illuminate\Support\Facades\Event::listen('eloquent.creating: ' . RegistroModifica::class, function () {
            throw new RuntimeException('la riga non va');
        });

        expect(fn () => $categoria->update(['name' => 'Elettricisti']))->toThrow(RuntimeException::class, 'la riga non va')
            ->and(DB::transactionLevel())->toBe(0)
            ->and(DB::table('categorie_fornitore')->where('id', $categoria->id)->value('name'))->toBe('Idraulici')
            ->and(RegistroModifica::query()->count())->toBe(1);
        expect(fn () => $categoria->delete())->toThrow(RuntimeException::class, 'la riga non va')
            ->and(DB::table('categorie_fornitore')->where('id', $categoria->id)->exists())->toBeTrue();
    } finally {
        DB::setDefaultConnection($predefinita);
        DB::purge('livello_zero');
    }
});

it('come in una pagina, senza la transazione della prova: la «Creata» aspetta la fine della transazione e si scrive prima del commit; se non va, non resta niente', function () {
    // Decisione 554: qui la transazione più esterna è al livello 1 e Laravel lancia `committing`, come nel programma.
    config(['database.connections.livello_zero' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $predefinita = DB::getDefaultConnection();
    app('auth')->forgetGuards();
    DB::setDefaultConnection('livello_zero');
    try {
        (require base_path('database/migrations/2026_11_19_100000_create_registro_modifiche_tables.php'))->up();
        (require base_path('database/migrations/2025_11_29_064536_create_categorie_fornitore_table.php'))->up();

        $dentro = DB::transaction(function () {
            $categoria = \App\Models\CategoriaFornitore::query()->create(['name' => 'Idraulici']);
            $categoria->update(['description' => 'Pronto intervento']);

            return RegistroModifica::query()->count();
        });
        $riga = RegistroModifica::query()->sole();
        expect($dentro)->toBe(0)
            ->and($riga->azione)->toBe('creata')
            ->and($riga->riepilogo)->toBe('Con 2 dati.')
            ->and(collect($riga->campi)->pluck('dopo', 'chiave')->all())->toBe(['name' => 'Idraulici', 'description' => 'Pronto intervento'])
            ->and($riga->sequenza)->toBe(1);

        \Illuminate\Support\Facades\Event::listen('eloquent.creating: ' . RegistroModifica::class, function () {
            throw new RuntimeException('la riga non va');
        });
        expect(fn () => DB::transaction(fn () => \App\Models\CategoriaFornitore::query()->create(['name' => 'Elettricisti'])))
            ->toThrow(RuntimeException::class, 'la riga non va')
            ->and(DB::transactionLevel())->toBe(0)
            ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
            ->and(DB::table('categorie_fornitore')->where('name', 'Elettricisti')->exists())->toBeFalse()
            ->and(\App\Services\RegistroModifiche\RigheRimandate::quante())->toBe(0);
    } finally {
        DB::setDefaultConnection($predefinita);
        DB::purge('livello_zero');
    }
});

it('una scheda nata in una transazione interna già chiusa non perde la sua «Creata» se un\'altra transazione interna torna indietro', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;

    DB::transaction(function () {
        DB::transaction(fn () => Anagrafica::query()->create(['nome' => 'Carlo Fontana', 'indirizzo' => 'Via Po 3']));
        try {
            DB::transaction(function () {
                Anagrafica::query()->create(['nome' => 'Marta Neri', 'indirizzo' => 'Via Po 5']);
                throw new RuntimeException('questa no');
            });
        } catch (RuntimeException) {
            // La pagina dice l'errore e va avanti: Carlo Fontana resta.
        }
    });

    expect(RegistroModifica::query()->where('id', '>', $prima)->pluck('oggetto_nome')->all())->toBe(['Anagrafica di Carlo Fontana'])
        ->and(Anagrafica::query()->where('nome', 'Marta Neri')->exists())->toBeFalse();
});

it('dentro un gesto il tratto non scrive righe sue: conta nel lotto del gesto', function () {
    $prima = RegistroModifica::query()->count();

    RegistroModifiche::durante(Gesto::su('persone', 'importazione', 3, 'Importazione da Danea')->riepilogo('Importazione: 2 persone'), function () {
        Anagrafica::query()->create(['nome' => 'Luca Esposito', 'indirizzo' => 'Via Po 4']);
        Anagrafica::query()->create(['nome' => 'Marta Neri', 'indirizzo' => 'Via Po 5']);
    });

    expect(RegistroModifica::query()->count())->toBe($prima + 1)
        ->and(rlmUltima()->lotto['conteggi'])->toBe(['anagrafica.creata' => 2]);
});

it('increment() lancia «updated» senza save(): si registra lo stesso', function () {
    $regola = RegolaRecupero::query()->create(['condominio_id' => $this->condominio->id, 'dal' => '2026-01-01', 'tasso_tipo' => 'scritto',
        'tasso_annuo' => '1.6000', 'fonte' => 'regolamento', 'giorni_decorrenza' => 0]);
    $regola->increment('giorni_decorrenza', 5);

    $riga = rlmUltima();
    expect($riga->azione)->toBe('modificata')
        ->and($riga->scheda_tipo)->toBe('regole_recupero')
        ->and($riga->scheda_id)->toBe($this->condominio->id)
        ->and($riga->campi[0])->toMatchArray(['etichetta' => 'Interessi dopo la scadenza (giorni)', 'prima' => '0', 'dopo' => '5']);
});

it('la titolarità scrive «collegata» nella «Storia» dell\'unità, con la persona fra i soggetti e la frase della bozza', function () {
    $unita = Immobile::query()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Appartamento', 'interno' => '7']);
    TitolaritaImmobile::query()->create(['immobile_id' => $unita->id, 'anagrafica_id' => $this->ida->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'data_inizio' => '2019-01-01', 'attivo' => true]);

    $riga = rlmUltima();
    expect($riga->azione)->toBe('collegata')
        ->and($riga->oggetto_tipo)->toBe('titolarita')
        ->and($riga->scheda_tipo)->toBe('immobile')
        ->and($riga->scheda_id)->toBe($unita->id)
        ->and($riga->soggetti())->toBe([['anagrafica', $this->ida->id]])
        ->and($riga->riepilogo)->toBe('All\'unità Int. 7: proprietario al 100 % dal 01/01/2019.');
});

it('un documento che il programma genera (registro degli invii) non scrive righe; uno dell\'archivio sì', function () {
    $prima = RegistroModifica::query()->count();
    Documento::query()->forceCreate(['name' => 'Lettera di sollecito', 'path' => 'x.pdf', 'documentable_type' => RegistroInvio::class, 'documentable_id' => 1]);
    expect(RegistroModifica::query()->count())->toBe($prima);

    Documento::query()->create(['name' => 'Regolamento', 'path' => 'r.pdf']);
    expect(rlmUltima()->oggetto_nome)->toBe('Documento «Regolamento»');
});

it('una scrittura contabile fuori da un gesto scrive «Scrittura registrata» con il rimando; dentro un gesto si conta', function () {
    $scrittura = ScritturaContabile::query()->create(['condominio_id' => $this->condominio->id, 'esercizio_id' => $this->esercizio->id,
        'data_registrazione' => '2026-12-12', 'data_competenza' => '2026-12-12',
        'causale' => 'Incasso rata', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata']);

    $riga = rlmUltima();
    expect($riga->area)->toBe('contabilita')
        ->and($riga->azione)->toBe('gesto')
        ->and($riga->riepilogo)->toBe('Scrittura registrata: Incasso rata')
        ->and($riga->rimando)->toMatchArray(['tipo' => 'scrittura_contabile', 'id' => $scrittura->id]);

    $scrittura->update(['stato' => 'annullata']);
    expect(rlmUltima()->riepilogo)->toBe('Scrittura annullata: Incasso rata');
});

it('una fattura nuova dice «Registrata» con il totale scritto dai centesimi', function () {
    FatturaPassiva::unguard();
    $fornitore = \App\Models\Fornitore::query()->create(['ragione_sociale' => 'Serramenti Marino']);
    FatturaPassiva::query()->create(['condominio_id' => $this->condominio->id, 'esercizio_id' => $this->esercizio->id, 'fornitore_id' => $fornitore->id,
        'numero_documento' => '88', 'data_documento' => '2026-12-12', 'data_scadenza' => '2027-01-12', 'importo_imponibile' => 1188525, 'importo_iva' => 261475,
        'totale_documento' => 1450000, 'netto_a_pagare' => 1450000, 'tipo_documento' => 'fattura']);
    FatturaPassiva::reguard();

    $riga = rlmUltima();
    expect($riga->oggetto_nome)->toBe('Fattura n. 88 di Serramenti Marino')
        ->and($riga->riepilogo)->toBe('Registrata, € 14.500,00')
        ->and($riga->soggetti())->toBe([['fornitore', $fornitore->id]]);
});

it('«Ripristina» della Storia: il salvataggio dentro comeRipristino scrive «ripristinata» con il perché, non «modificata»', function () {
    RegistroModifiche::comeRipristino('Ripristina dalla Storia, modifica del 16/12/2026 alle 09:12',
        fn () => $this->ida->update(['email' => 'ida.romano@gmail.com']));
    $riga = rlmUltima();
    expect($riga->azione)->toBe('ripristinata')
        ->and($riga->perche)->toBe('Ripristina dalla Storia, modifica del 16/12/2026 alle 09:12')
        ->and($riga->campi[0])->toMatchArray(['prima' => 'ida.romano@libero.it', 'dopo' => 'ida.romano@gmail.com']);

    $this->ida->update(['email' => 'altra@example.it']);
    expect(rlmUltima()->azione)->toBe('modificata')->and(rlmUltima()->perche)->toBeNull();
});

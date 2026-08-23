<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * «Carica debito pregresso» rifiutava ogni importo, e in silenzio.
 *
 * La modale usa `MoneyInput` con `masked: true`, quindi il campo vale «380,00» — punto per le
 * migliaia, virgola per i decimali. Il controller validava `'importo' => 'required|numeric'`, e
 * `is_numeric('380,00')` in PHP è **false**: la validazione falliva sempre. La modale, che non
 * aveva nessun `InputError`, non mostrava niente: il pulsante restava lì e non succedeva nulla.
 *
 * Il costo non era locale. La pagina stessa dichiara che «il sistema ti permetterà di registrare
 * e saldare fatture degli anni passati solo se il relativo debito è stato prima dichiarato in
 * questa sezione»: con il caricamento rotto, l'intero percorso delle **fatture pregresse** era
 * irraggiungibile — cioè proprio quello di chi rileva un condominio senza consegne.
 *
 * La conversione ora passa da `MoneyHelper::toCents()`, che è il confine di ingresso del progetto
 * e sa leggere sia la stringa mascherata sia un numero puro.
 */
beforeEach(function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('Accesso pannello amministratore');

    $this->condominio = Condominio::factory()->create();

    $this->esercizio = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'stato' => 'aperto',
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $this->condominio->id,
        'nome' => 'Ordinaria',
        'tipo' => 'ordinaria',
        'data_inizio' => '2026-01-01',
    ]);
    $gestione->esercizi()->attach($this->esercizio->id, ['attiva' => true]);

    $this->fornitore = Fornitore::create([
        'ragione_sociale' => 'Idraulica Tevere S.r.l.',
        'soggetto_ritenuta' => false,
        'perc_imponibile_ritenuta' => 100,
        'perc_ritenuta' => 4,
        'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico',
    ]);

    $this->actingAs($this->user);
});

/**
 * ★ Il caso che falliva: l'importo com'è scritto dalla maschera.
 */
it('accetta l\'importo mascherato che la modale produce davvero', function () {
    $this->post(route('admin.fornitori.situazione-debitoria.store', $this->fornitore->id), [
        'condominio_id' => $this->condominio->id,
        'descrizione' => 'Fattura idraulico 2025 non saldata',
        'importo' => '380,00',
    ])->assertSessionHasNoErrors();

    $saldo = Saldo::where('fornitore_id', $this->fornitore->id)->first();

    expect($saldo)->not->toBeNull()
        // Negativo: un debito verso fornitore è una passività del condominio.
        ->and($saldo->saldo_iniziale)->toBe(-38000)
        ->and($saldo->anagrafica_id)->toBeNull();
});

it('legge le migliaia col punto senza troncare l\'importo', function () {
    // «1.500,00» letto con `(float)` varrebbe 1,5. È il modo in cui un debito da millecinquecento
    // euro diventerebbe un euro e mezzo senza che nessuno se ne accorga.
    $this->post(route('admin.fornitori.situazione-debitoria.store', $this->fornitore->id), [
        'condominio_id' => $this->condominio->id,
        'descrizione' => 'Saldo lavori 2024',
        'importo' => '1.500,00',
    ])->assertSessionHasNoErrors();

    expect(Saldo::where('fornitore_id', $this->fornitore->id)->first()->saldo_iniziale)->toBe(-150000);
});

it('accetta anche un numero puro, per chi arriva da API o da un altro client', function () {
    $this->post(route('admin.fornitori.situazione-debitoria.store', $this->fornitore->id), [
        'condominio_id' => $this->condominio->id,
        'descrizione' => 'Saldo manutenzione',
        'importo' => 250.50,
    ])->assertSessionHasNoErrors();

    expect(Saldo::where('fornitore_id', $this->fornitore->id)->first()->saldo_iniziale)->toBe(-25050);
});

/**
 * Un rifiuto deve avere un nome: prima non ne aveva nessuno, e la modale non aveva nemmeno
 * il posto dove scriverlo.
 */
it('rifiuta lo zero con un errore sul campo, non in silenzio', function () {
    $this->post(route('admin.fornitori.situazione-debitoria.store', $this->fornitore->id), [
        'condominio_id' => $this->condominio->id,
        'descrizione' => 'Riga vuota',
        'importo' => '0,00',
    ])->assertSessionHasErrors('importo');

    expect(Saldo::where('fornitore_id', $this->fornitore->id)->count())->toBe(0);
});

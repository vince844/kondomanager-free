<?php

/**
 * beta.34 — lo schema di test deve essere quello di produzione.
 *
 * Su SQLite sopravviveva un indice UNIQUE (esercizio_id, anagrafica_id,
 * immobile_id) che su MySQL era stato rimosso a marzo 2026: la guardia
 * `tryDropIndex()` usciva subito su driver non-MySQL. Kondomanager gira su
 * MySQL/MariaDB, quindi non era un difetto per gli utenti — ma i test giravano
 * su uno schema PIÙ SEVERO del reale, e il caso legittimo qui sotto non era
 * scrivibile. Uno schema di prova più severo di quello vero non protegge:
 * nasconde.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);// Verifica che l'indice unique non sopravviva piu' nello schema di test.
test('lo schema di test non ha piu il vincolo unique sui saldi', function () {
    $idx = Illuminate\Support\Facades\DB::select("PRAGMA index_list('saldi')");
    $unici = array_values(array_filter($idx, fn ($i) => (int) $i->unique === 1));
    expect($unici)->toBeEmpty();
});

test('la stessa persona puo avere saldi su due gestioni della stessa unita', function () {
    $c = App\Models\Condominio::create(['nome'=>'C','uuid'=>(string) Illuminate\Support\Str::uuid(),'indirizzo'=>'V','citta'=>'M','cap'=>'20100','provincia'=>'MI']);
    $e = App\Models\Esercizio::create(['condominio_id'=>$c->id,'nome'=>'2026','data_inizio'=>'2026-01-01','data_fine'=>'2026-12-31','stato'=>'aperto']);
    $g1 = App\Models\Gestione::create(['condominio_id'=>$c->id,'nome'=>'Ord','data_inizio'=>'2026-01-01','tipo'=>'ordinaria','saldo_applicato'=>false]);
    $g2 = App\Models\Gestione::create(['condominio_id'=>$c->id,'nome'=>'Str','data_inizio'=>'2026-01-01','tipo'=>'straordinaria','saldo_applicato'=>false]);
    $a = App\Models\Anagrafica::create(['condominio_id'=>$c->id,'nome'=>'X','cognome'=>'Y','email'=>'x@y.it','indirizzo'=>'V','cap'=>'00100','citta'=>'R','provincia'=>'RM','codice_fiscale'=>'XXXYYY80A01H501U']);
    $i = App\Models\Immobile::create(['condominio_id'=>$c->id,'nome'=>'I1','descrizione'=>'A','interno'=>'1','foglio'=>'1','particella'=>'1','subalterno'=>'1']);

    foreach ([$g1, $g2] as $k => $g) {
        App\Models\Saldo::create([
            'esercizio_id'=>$e->id,'condominio_id'=>$c->id,'anagrafica_id'=>$a->id,
            'immobile_id'=>$i->id,'gestione_id'=>$g->id,'saldo_iniziale'=>1000*($k+1),
            'origine'=>'manuale','is_applicato'=>false,
        ]);
    }

    // Era impossibile prima della correzione della guardia: SQLite lo vietava.
    expect(App\Models\Saldo::where('condominio_id', $c->id)->count())->toBe(2);
});

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 754 (7): l'incasso oltre la propria quota.
 *
 * Paolo, compratore, ha € 100,00 sulla rata 1; Giulia, venditrice, € 300,00 sulla stessa rata e sulla stessa unità. Cercando per unità la riga porta
 * € 400,00, e l'amministratore incassa € 400,00 a nome di Paolo. Il motore salda solo le quote del pagante: i € 300,00 che restano non avevano dove
 * andare, la scrittura usciva sbilanciata (dare € 400,00, avere € 100,00) e il controllo della partita doppia la fermava con una pagina d'errore 500,
 * buttando via la distribuzione fatta a mano.
 *
 * Ora l'incasso si ferma con la fascia «Incasso non registrato» sulla stessa pagina, con la frase che dice quanto deve il pagante, di chi è il resto e
 * come registrarlo; niente è scritto. I due incassi separati, e l'eccedenza dichiarata, passano come prima.
 */

use App\Models\Anagrafica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/** La rata 1 con le due quote sulla stessa unità: Paolo € 100,00, Giulia € 300,00 (centesimi). */
function b14oqScenario(bool $conGiulia = true): object
{
    [$condominio, , $gestione, , $capitolo, , $immobileId] = setupContabile();
    $contoCassaId = DB::table('conti_contabili')->insertGetId(['condominio_id' => $condominio->id, 'ruolo' => null, 'codice' => '1010.01', 'nome' => 'Banca',
        'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);
    $cassaId = DB::table('casse')->insertGetId(['condominio_id' => $condominio->id, 'conto_contabile_id' => $contoCassaId, 'nome' => 'Banca', 'tipo' => 'banca',
        'saldo_iniziale' => 0, 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);
    $persona = fn (string $nome, string $cf) => tap(Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(str_replace(' ', '.', $nome)).'@test.it',
        'indirizzo' => 'Via Roma 1', 'codice_fiscale' => $cf]),
        fn ($a) => DB::table('anagrafica_condominio')->insert(['anagrafica_id' => $a->id, 'condominio_id' => $condominio->id, 'created_at' => now(), 'updated_at' => now()]));
    $paolo = $persona('Paolo Compratore', 'CMPPLA80A01H501Z');
    $giulia = $persona('Giulia Venditrice', 'VNDGLI80A41H501Z');
    $pianoId = DB::table('piani_rate')->insertGetId(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Piano', 'numero_rate' => 1,
        'metodo_distribuzione' => 'rata_zero', 'stato' => 'bozza', 'tipo' => 'ordinario', 'created_at' => now(), 'updated_at' => now()]);
    $rataId = DB::table('rate')->insertGetId(['piano_rate_id' => $pianoId, 'conto_id' => $capitolo->id, 'numero_rata' => 1, 'data_scadenza' => '2026-03-31',
        'descrizione' => 'Rata 1', 'importo_totale' => $conGiulia ? 40000 : 10000, 'stato' => 'emessa', 'created_at' => now(), 'updated_at' => now()]);
    $quota = fn (Anagrafica $a, int $cents) => DB::table('rate_quote')->insertGetId(['rata_id' => $rataId, 'anagrafica_id' => $a->id, 'immobile_id' => $immobileId,
        'importo' => $cents, 'importo_pagato' => 0, 'stato' => 'aperta', 'data_scadenza' => '2026-03-31', 'created_at' => now(), 'updated_at' => now()]);

    return (object) ['condominio' => $condominio, 'gestione' => $gestione, 'cassaId' => $cassaId, 'paolo' => $paolo, 'giulia' => $giulia,
        'qPaolo' => $quota($paolo, 10000), 'qGiulia' => $conGiulia ? $quota($giulia, 30000) : null];
}

function b14oqUtente(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $u = \App\Models\User::factory()->create();
    $u->givePermissionTo('Accesso pannello amministratore');

    return $u;
}

/** «Nuovo incasso rate» → `movimenti-rate.store`, dalla pagina dell'incasso (importi in euro, come li manda il modulo). */
function b14oqIncassa($test, object $s, int $paganteId, float $totale, array $righe, float $eccedenza = 0, ?int $versatoDa = null)
{
    return $test->from(route('admin.gestionale.movimenti-rate.create', $s->condominio->id))
        ->post(route('admin.gestionale.movimenti-rate.store', $s->condominio->id), array_filter([
            'pagante_id' => $paganteId, 'cassa_id' => $s->cassaId, 'gestione_id' => $s->gestione->id, 'data_pagamento' => '2026-03-01',
            'importo_totale' => $totale, 'descrizione' => 'Incasso', 'eccedenza' => $eccedenza, 'versato_da_id' => $versatoDa,
            'dettaglio_pagamenti' => $righe,
        ], fn ($v) => $v !== null));
}

function b14oqQuota(int $id): object
{
    return DB::table('rate_quote')->where('id', $id)->first(['importo', 'importo_pagato']);
}

test('Q1b: € 400,00 a nome di Paolo sulla riga dell\'unità si ferma con la frase, senza la pagina 500 e senza scrivere niente', function () {
    $s = b14oqScenario();
    $this->actingAs(b14oqUtente());

    $r = b14oqIncassa($this, $s, $s->paolo->id, 400, [['rata_id' => $s->qPaolo, 'importo' => 400]]);

    expect($r->status())->not->toBe(500);
    // La catena: si torna a «Nuovo incasso rate» con il modulo compilato e il motivo nella fascia «Incasso non registrato».
    $r->assertRedirect(route('admin.gestionale.movimenti-rate.create', $s->condominio->id));
    $r->assertSessionHasErrors(['importo_totale' => 'Paolo Compratore deve ancora € 100,00 sulla rata n. 1 e hai inserito € 400,00. I € 300,00 in più sono di '
        .'Giulia Venditrice, che ha una quota su questa rata: registra € 100,00 a nome di Paolo Compratore e € 300,00 a nome di Giulia Venditrice, con '
        .'«Versato da» Paolo Compratore se il versamento è suo.']);
    expect(session()->getOldInput('dettaglio_pagamenti'))->toBe([['rata_id' => $s->qPaolo, 'importo' => 400]]);

    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'incasso_rata')->count())->toBe(0)
        ->and(DB::table('righe_scritture')->count())->toBe(0)
        ->and(DB::table('quota_scrittura')->count())->toBe(0)
        ->and((int) b14oqQuota($s->qPaolo)->importo_pagato)->toBe(0)
        ->and((int) b14oqQuota($s->qGiulia)->importo_pagato)->toBe(0);
});

test('Q2: i due incassi separati, € 100,00 a nome di Paolo e € 300,00 a nome di Giulia con «Versato da» Paolo, passano come prima', function () {
    $s = b14oqScenario();
    $this->actingAs(b14oqUtente());

    b14oqIncassa($this, $s, $s->paolo->id, 100, [['rata_id' => $s->qPaolo, 'importo' => 100]])->assertSessionHasNoErrors();
    b14oqIncassa($this, $s, $s->giulia->id, 300, [['rata_id' => $s->qGiulia, 'importo' => 300]], versatoDa: $s->paolo->id)->assertSessionHasNoErrors();

    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'incasso_rata')->count())->toBe(2)
        ->and((int) b14oqQuota($s->qPaolo)->importo_pagato)->toBe(10000)
        ->and((int) b14oqQuota($s->qGiulia)->importo_pagato)->toBe(30000);
    // Il denaro entrato in cassa è tutto: € 400,00 in dare sulla banca.
    $contoBanca = DB::table('casse')->where('id', $s->cassaId)->value('conto_contabile_id');
    expect((int) DB::table('righe_scritture')->where('conto_contabile_id', $contoBanca)->where('tipo_riga', 'dare')->sum('importo'))->toBe(40000);
});

test('Q4: € 150,00 su una quota da € 100,00 con l\'eccedenza dichiarata passa come prima: € 50,00 diventano credito di Paolo', function () {
    $s = b14oqScenario(conGiulia: false);
    $this->actingAs(b14oqUtente());

    b14oqIncassa($this, $s, $s->paolo->id, 150, [['rata_id' => $s->qPaolo, 'importo' => 100]], eccedenza: 50)->assertSessionHasNoErrors();

    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'incasso_rata')->count())->toBe(1)
        ->and((int) b14oqQuota($s->qPaolo)->importo_pagato)->toBe(15000);
});

test('Q1b con il credito di Paolo usato insieme al denaro: si ferma con la stessa frase e non scrive niente, né l\'incasso né la compensazione', function () {
    $s = b14oqScenario();
    $this->actingAs(b14oqUtente());
    // Un credito di Paolo di € 40,00 su un'altra rata dello stesso piano (un anticipo: la quota negativa non ancora usata).
    $rataCredito = DB::table('rate')->insertGetId(['piano_rate_id' => DB::table('rate')->value('piano_rate_id'), 'conto_id' => DB::table('rate')->value('conto_id'),
        'numero_rata' => 0, 'data_scadenza' => '2026-01-31', 'descrizione' => 'Rata 0', 'importo_totale' => -4000, 'stato' => 'emessa',
        'created_at' => now(), 'updated_at' => now()]);
    $qCredito = DB::table('rate_quote')->insertGetId(['rata_id' => $rataCredito, 'anagrafica_id' => $s->paolo->id, 'immobile_id' => DB::table('rate_quote')->value('immobile_id'),
        'importo' => -4000, 'importo_pagato' => 0, 'stato' => 'credito', 'data_scadenza' => '2026-01-31', 'created_at' => now(), 'updated_at' => now()]);

    $r = b14oqIncassa($this, $s, $s->paolo->id, 360, [['rata_id' => $s->qPaolo, 'importo' => 400], ['rata_id' => $qCredito, 'importo' => -40]]);

    expect($r->status())->not->toBe(500);
    $r->assertSessionHasErrors(['importo_totale' => 'Paolo Compratore deve ancora € 100,00 sulla rata n. 1 e hai inserito € 400,00. I € 300,00 in più sono di '
        .'Giulia Venditrice, che ha una quota su questa rata: registra € 100,00 a nome di Paolo Compratore e € 300,00 a nome di Giulia Venditrice, con '
        .'«Versato da» Paolo Compratore se il versamento è suo.']);
    expect(DB::table('scritture_contabili')->count())->toBe(0)
        ->and((int) b14oqQuota($qCredito)->importo_pagato)->toBe(0)
        ->and((int) b14oqQuota($s->qPaolo)->importo_pagato)->toBe(0);
});

test('la stessa riga oltre la quota senza nessun altro sulla rata si ferma e dice di lasciare il resto come parte in più', function () {
    $s = b14oqScenario(conGiulia: false);
    $this->actingAs(b14oqUtente());

    $r = b14oqIncassa($this, $s, $s->paolo->id, 150, [['rata_id' => $s->qPaolo, 'importo' => 150]]);

    expect($r->status())->not->toBe(500);
    $r->assertSessionHasErrors(['importo_totale' => 'Paolo Compratore deve ancora € 100,00 sulla rata n. 1 e hai inserito € 150,00. Sulla riga scrivi '
        .'€ 100,00: con lo stesso importo versato, i € 50,00 in più diventano la parte in più (eccedenza), un credito di Paolo Compratore.']);
    expect(DB::table('scritture_contabili')->count())->toBe(0)
        ->and((int) b14oqQuota($s->qPaolo)->importo_pagato)->toBe(0);
});

test('con «Versato da» la frase nomina chi ha versato, e non scrive niente', function () {
    $s = b14oqScenario();
    $this->actingAs(b14oqUtente());
    $marco = Anagrafica::forceCreate(['nome' => 'Marco Figlio', 'email' => 'marco.figlio@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'FGLMRC90A01H501Z']);
    DB::table('anagrafica_condominio')->insert(['anagrafica_id' => $marco->id, 'condominio_id' => $s->condominio->id, 'created_at' => now(), 'updated_at' => now()]);

    $r = b14oqIncassa($this, $s, $s->paolo->id, 400, [['rata_id' => $s->qPaolo, 'importo' => 400]], versatoDa: $marco->id);

    expect($r->status())->not->toBe(500);
    $r->assertSessionHasErrors(['importo_totale' => 'Paolo Compratore deve ancora € 100,00 sulla rata n. 1 e hai inserito € 400,00. I € 300,00 in più sono di '
        .'Giulia Venditrice, che ha una quota su questa rata: registra € 100,00 a nome di Paolo Compratore e € 300,00 a nome di Giulia Venditrice, con '
        .'«Versato da» Marco Figlio se il versamento è suo.']);
    expect(DB::table('scritture_contabili')->count())->toBe(0);
});

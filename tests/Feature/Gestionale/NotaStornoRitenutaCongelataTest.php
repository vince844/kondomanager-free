<?php

/**
 * Modificare una nota di credito nata da uno storno le faceva perdere la ritenuta
 * congelata, lasciando un residuo fantasma sul 2202 (Coda 123).
 *
 * ## Il fatto, misurato il 04/09/2026 indagando la segnalazione della Coda 122
 *
 * `StornoFatturaController` passa `ritenuta_override` a `registraFattura()` proprio per
 * FISSARE la ritenuta della nota di credito all'importo REALE dell'originale, invece di
 * farla ricalcolare da `RitenutaService::calcola()` sull'anagrafica ATTUALE del fornitore
 * (design §8 punto 2 — vedi `RitenutaCalcolo::override()`). Il valore congelato viene
 * persistito in `dati_extra.fiscal.ritenuta_details`.
 *
 * ⚠️ **Ma `aggiornaFattura()` non lo leggeva mai.** Riaprire quella nota per correggere
 * anche solo una descrizione ricalcolava sempre la ritenuta dal vivo. Se il fornitore
 * cambiava anagrafica fra lo storno e la modifica (es. diventa forfetario), il ricalcolo
 * dava un importo diverso da quello davvero registrato — riaprendo lo stesso residuo
 * fantasma su 2201/2202 che l'override dello storno doveva chiudere, stavolta dalla porta
 * della modifica invece che da quella dello storno.
 *
 * ⛔ **E dopo non si richiude più**: l'originale è già stornato e non è ristornabile, la
 * nota non è stornabile perché ha netto negativo. L'unico rimedio era rimettere
 * temporaneamente l'anagrafica com'era e risalvare.
 *
 * ✅ Una fattura ordinaria non è toccata: è autosufficiente, il ricalcolo dal vivo è il
 * comportamento giusto. La NC da storno è l'unico documento il cui importo è definito per
 * riferimento a un altro — è per questo che, e solo per questo, va congelata.
 */

use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * Registra una fattura con ritenuta 4%, la storna, e restituisce [nc, fattura, ctx].
 * Riprende esattamente lo scenario di RitenutaSezione8Test — «lo storno usa l'importo
 * originale» — e lo estende oltre lo storno, dentro la modifica.
 */
function scenarioNotaDaStorno(): array
{
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    expect((int) $fattura->importo_ritenuta)->toBe(4000);

    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    test()->actingAs($user);

    test()->post(route('admin.gestionale.fatture.storno', ['condominio' => $condominio->id, 'fattura' => $fattura->id]))
        ->assertSessionHasNoErrors();

    $nc = \App\Models\Gestionale\FatturaPassiva::where('numero_documento', 'STORNO-'.$fattura->numero_documento)->firstOrFail();

    expect((int) $nc->importo_ritenuta)->toBe(-4000)
        ->and($nc->dati_extra['nota_storno'] ?? null)->not->toBeNull();

    return [$nc, $fattura, $ctx];
}

function saldoErario(int $condominioId): array
{
    $contoErario = ContoContabile::where('condominio_id', $condominioId)->where('ruolo', 'debiti_erario_ritenute')->first();

    return [
        'dare'  => (int) DB::table('righe_scritture')->where('conto_contabile_id', $contoErario->id)->where('tipo_riga', 'dare')->sum('importo'),
        'avere' => (int) DB::table('righe_scritture')->where('conto_contabile_id', $contoErario->id)->where('tipo_riga', 'avere')->sum('importo'),
    ];
}

test('cambio anagrafica DOPO lo storno, poi si corregge un dettaglio della nota: la ritenuta resta congelata', function () {
    [$nc, , $ctx] = scenarioNotaDaStorno();
    [$condominio, , , $fornitore, $capitolo] = $ctx;

    // Il fornitore diventa forfetario DOPO lo storno: RitenutaService::calcola()
    // darebbe 0€ se la modifica lo interrogasse di nuovo.
    $fornitore->update(['regime_forfetario' => true]);

    (new FatturaPassivaService)->aggiornaFattura($nc, [
        'numero_documento'   => $nc->numero_documento.'-corretto',
        'data_documento'     => $nc->data_documento->format('Y-m-d'),
        'data_scadenza'      => $nc->data_scadenza->format('Y-m-d'),
        'modalita_pagamento' => $nc->modalita_pagamento,
        'righe' => [[
            'descrizione' => '[STORNO] Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'concorre_base_ritenuta' => true,
        ]],
    ]);

    $fresh = $nc->fresh();

    expect((int) $fresh->importo_ritenuta)->toBe(-4000, 'La ritenuta congelata deve sopravvivere alla modifica, anche col fornitore ormai forfetario.');

    $saldo = saldoErario($condominio->id);
    expect($saldo['dare'])->toBe($saldo['avere'], 'Nessun residuo fantasma: DARE e AVERE sul 2202 devono restare quadrati dopo la modifica.')
        ->and($saldo['avere'])->toBe(4000);

    // ⚠️ Il conto Erario da solo non basta: verifica anche che l'INTERA scrittura
    // ricreata da aggiornaFattura() quadri, non solo il conto su cui si concentra
    // questa coda. Chiesto esplicitamente da Vincenzo prima di chiudere la beta.17.
    assertQuadraturaPerfetta($fresh->scritture->first()->id);
});

test('senza cambio di anagrafica la modifica dà lo stesso risultato, con o senza congelamento — il controesempio che tiene stretta la correzione', function () {
    [$nc, , $ctx] = scenarioNotaDaStorno();
    [, , , , $capitolo] = $ctx;

    // Nessun cambio di anagrafica: il ricalcolo dal vivo avrebbe dato comunque 40€.
    // Il fix non deve introdurre nessuna differenza in questo caso, il più comune.
    (new FatturaPassivaService)->aggiornaFattura($nc, [
        'numero_documento'   => $nc->numero_documento,
        'data_documento'     => $nc->data_documento->format('Y-m-d'),
        'data_scadenza'      => $nc->data_scadenza->format('Y-m-d'),
        'modalita_pagamento' => $nc->modalita_pagamento,
        'righe' => [[
            'descrizione' => '[STORNO] Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'concorre_base_ritenuta' => true,
        ]],
    ]);

    expect((int) $nc->fresh()->importo_ritenuta)->toBe(-4000);
});

test('una fattura ORDINARIA continua a ricalcolare dal vivo — il congelamento non deve uscire dal suo perimetro', function () {
    // Il controesempio più importante: se il criterio fosse sbagliato (es. "qualunque
    // nota di credito" invece di "solo quella nata da uno storno"), questo test lo
    // scoprirebbe. Una fattura ordinaria non ha mai `dati_extra.nota_storno`.
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );
    expect((int) $fattura->importo_ritenuta)->toBe(4000);

    // Il fornitore diventa forfetario prima della modifica: qui il ricalcolo dal vivo
    // (0€) è il comportamento CORRETTO, non un difetto — una fattura autosufficiente
    // deve riflettere le regole fiscali di oggi, non quelle di quando è nata.
    $fornitore->update(['regime_forfetario' => true]);

    (new FatturaPassivaService)->aggiornaFattura($fattura, [
        'numero_documento'   => $fattura->numero_documento,
        'data_documento'     => $fattura->data_documento->format('Y-m-d'),
        'data_scadenza'      => $fattura->data_scadenza->format('Y-m-d'),
        'modalita_pagamento' => $fattura->modalita_pagamento,
        'righe' => [[
            'descrizione' => 'Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'concorre_base_ritenuta' => true,
        ]],
    ]);

    expect((int) $fattura->fresh()->importo_ritenuta)->toBe(0, 'Una fattura ordinaria deve ricalcolare dal vivo: qui 0€ è corretto, non un residuo.');
});

test('lo storno di un fornitore MAI soggetto a ritenuta resta a zero anche se nel frattempo lo diventa', function () {
    // Simmetrico al test principale, nella direzione opposta: il congelamento vale
    // anche per un importo congelato a ZERO, non solo per uno congelato positivo.
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Fornitura', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );
    expect((int) $fattura->importo_ritenuta)->toBe(0);

    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    test()->actingAs($user);

    test()->post(route('admin.gestionale.fatture.storno', ['condominio' => $condominio->id, 'fattura' => $fattura->id]))
        ->assertSessionHasNoErrors();

    $nc = \App\Models\Gestionale\FatturaPassiva::where('numero_documento', 'STORNO-'.$fattura->numero_documento)->firstOrFail();
    expect((int) $nc->importo_ritenuta)->toBe(0);

    // Il fornitore diventa soggetto a ritenuta DOPO lo storno.
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    (new FatturaPassivaService)->aggiornaFattura($nc, [
        'numero_documento'   => $nc->numero_documento,
        'data_documento'     => $nc->data_documento->format('Y-m-d'),
        'data_scadenza'      => $nc->data_scadenza->format('Y-m-d'),
        'modalita_pagamento' => $nc->modalita_pagamento,
        'righe' => [[
            'descrizione' => '[STORNO] Fornitura', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'concorre_base_ritenuta' => true,
        ]],
    ]);

    expect((int) $nc->fresh()->importo_ritenuta)->toBe(0, 'Una NC il cui originale non aveva ritenuta non deve acquisirne una perché il fornitore la ottiene dopo.');
});

test('la tagliola dei DUE salvataggi: sbagliare l\'imponibile e rimetterlo a posto non deve cristallizzare lo zero', function () {
    // ⚠️ **Il difetto che la prima stesura di questa coda aveva introdotto**, trovato dalla
    // revisione avversariale del 05/09/2026 e provato con una sonda prima di correggerlo.
    // Il congelamento leggeva i campi della NOTA, che il passo 5 di aggiornaFattura riscrive
    // a ogni salvataggio: un salvataggio con l'imponibile alterato passava dal ramo dal vivo
    // e cancellava il valore congelato dalla nota, e il salvataggio successivo — tornato
    // all'imponibile giusto — congelava lo ZERO appena scritto. Il residuo fantasma sul 2202
    // diventava PERMANENTE, cioè esattamente il danno che questa coda esiste per impedire.
    // Misurato allora: ritenuta 0, € 40,00 di AVERE senza contropartita, nessuna via d'uscita
    // dall'interfaccia. Ora la fonte è la fattura originale, che è immodificabile.
    //
    // Ogni altro test di questo file fa UN solo salvataggio: il difetto viveva nella
    // sequenza, ed è la ragione per cui nessuno di loro poteva vederlo.
    [$nc, , $ctx] = scenarioNotaDaStorno();
    [$condominio, , , $fornitore, $capitolo] = $ctx;

    $fornitore->update(['regime_forfetario' => true]);

    $payload = fn (float $imponibile) => [
        'numero_documento'   => $nc->numero_documento,
        'data_documento'     => $nc->data_documento->format('Y-m-d'),
        'data_scadenza'      => $nc->data_scadenza->format('Y-m-d'),
        'modalita_pagamento' => $nc->modalita_pagamento,
        'righe' => [[
            'descrizione' => '[STORNO] Appalto', 'importo_imponibile' => $imponibile, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'concorre_base_ritenuta' => true,
        ]],
    ];

    // 1. L'amministratore sbaglia: mette 1.200,00. Imponibile diverso dall'originale,
    //    quindi ricalcolo dal vivo — e col fornitore forfetario la ritenuta va a zero.
    (new FatturaPassivaService)->aggiornaFattura($nc->fresh(), $payload(1200));
    expect((int) $nc->fresh()->importo_ritenuta)->toBe(0);

    // 2. Si accorge e rimette 1.000,00, l'importo dell'originale. Qui il congelamento deve
    //    tornare a mordere leggendo l'originale — che nessun salvataggio può aver toccato.
    (new FatturaPassivaService)->aggiornaFattura($nc->fresh(), $payload(1000));

    expect((int) $nc->fresh()->importo_ritenuta)
        ->toBe(-4000, 'Rimesso l\'imponibile dell\'originale, la ritenuta congelata deve tornare: se resta 0 la tagliola è ancora aperta.');

    $saldo = saldoErario($condominio->id);
    expect($saldo['dare'])->toBe($saldo['avere'], 'Nessun residuo fantasma sul 2202 dopo la sequenza.')
        ->and($saldo['avere'])->toBe(4000);

    assertQuadraturaPerfetta($nc->fresh()->scritture->first()->id);
});

test('righe miste concorre_base_ritenuta=true/false su una NC congelata: la ritenuta resta quella dell\'originale e la scrittura quadra', function () {
    // ⚠️ Chiesto esplicitamente da Vincenzo prima di chiudere la beta.17: la casella
    // «Concorre alla base ritenuta» ha un tooltip nuovo in questa stessa beta, e nella NC
    // congelata è disabilitata — ma il valore che porta scritto sulle righe non deve mai
    // rompere la quadratura contabile, disabilitata o no. Qui l'originale ha DUE righe: una
    // prestazione da € 1.000,00 che concorre alla base, e un contributo di cassa
    // previdenziale da € 40,00 che non vi concorre (stesso schema del punto 9 di
    // RitenutaSezione8Test, ma portato dentro lo storno). Base = 1.000,00, ritenuta 4% = 40,00.
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;
    $fornitore->update(['soggetto_ritenuta' => true, 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4]);

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [
                [
                    'descrizione' => 'Prestazione professionale', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                    'conto_id' => $capitolo->id, 'is_sopravvenienza' => false, 'concorre_base_ritenuta' => true,
                ],
                [
                    'descrizione' => 'Contributo cassa professionale 4%', 'importo_imponibile' => 40, 'aliquota_iva' => 0,
                    'conto_id' => $capitolo->id, 'is_sopravvenienza' => false, 'concorre_base_ritenuta' => false,
                ],
            ],
        ]),
        $condominio->id
    );

    expect((int) $fattura->importo_ritenuta)->toBe(4000);
    assertQuadraturaPerfetta($fattura->scritture->first()->id);

    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    test()->actingAs($user);

    test()->post(route('admin.gestionale.fatture.storno', ['condominio' => $condominio->id, 'fattura' => $fattura->id]))
        ->assertSessionHasNoErrors();

    $nc = \App\Models\Gestionale\FatturaPassiva::where('numero_documento', 'STORNO-'.$fattura->numero_documento)->firstOrFail();
    expect((int) $nc->importo_ritenuta)->toBe(-4000);
    assertQuadraturaPerfetta($nc->scritture->first()->id);

    // Il fornitore cambia anagrafica dopo lo storno: il congelamento deve mordere.
    $fornitore->update(['regime_forfetario' => true]);

    // In modifica si riscrivono le stesse due righe, con lo stesso flag ciascuna — lo
    // storno le ha propagate identiche (StornoFatturaController:143-146) — e si corregge
    // solo la causale della prima.
    (new FatturaPassivaService)->aggiornaFattura($nc, [
        'numero_documento'   => $nc->numero_documento,
        'data_documento'     => $nc->data_documento->format('Y-m-d'),
        'data_scadenza'      => $nc->data_scadenza->format('Y-m-d'),
        'modalita_pagamento' => $nc->modalita_pagamento,
        'righe' => [
            [
                'descrizione' => '[STORNO] Prestazione professionale — corretta', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'concorre_base_ritenuta' => true,
            ],
            [
                'descrizione' => '[STORNO] Contributo cassa professionale 4%', 'importo_imponibile' => 40, 'aliquota_iva' => 0,
                'conto_id' => $capitolo->id, 'concorre_base_ritenuta' => false,
            ],
        ],
    ]);

    $fresh = $nc->fresh();

    expect((int) $fresh->importo_ritenuta)->toBe(-4000, 'La ritenuta congelata (calcolata sulla base corretta di € 1.000,00, non su € 1.040,00) deve sopravvivere.');
    assertQuadraturaPerfetta($fresh->scritture->first()->id);
});

test('il caso anomalo — righe della NC alterate in importo: nessun errore, ma il congelamento non si applica più', function () {
    // ⚠️ Questa coda non blocca la modifica delle righe di una NC da storno (il difetto
    // che chiude è un altro), ma il congelamento presuppone che l'imponibile non sia
    // cambiato. Se cambia, l'unica opzione sensata resta il ricalcolo dal vivo — un
    // importo congelato non avrebbe più relazione con un imponibile diverso da quello
    // per cui era stato calcolato. Il codice lo segnala con un log, non con un blocco.
    Log::spy();

    [$nc, , $ctx] = scenarioNotaDaStorno();
    [, , , $fornitore, $capitolo] = $ctx;
    $fornitore->update(['regime_forfetario' => true]);

    (new FatturaPassivaService)->aggiornaFattura($nc, [
        'numero_documento'   => $nc->numero_documento,
        'data_documento'     => $nc->data_documento->format('Y-m-d'),
        'data_scadenza'      => $nc->data_scadenza->format('Y-m-d'),
        'modalita_pagamento' => $nc->modalita_pagamento,
        'righe' => [[
            // Imponibile raddoppiato rispetto all'originale (1000 → 2000): rompe
            // deliberatamente la natura di specchio della NC da storno.
            'descrizione' => '[STORNO] Appalto', 'importo_imponibile' => 2000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'concorre_base_ritenuta' => true,
        ]],
    ]);

    // Il fornitore è forfetario: il ricalcolo dal vivo dà 0€, non un errore.
    expect((int) $nc->fresh()->importo_ritenuta)->toBe(0);

    Log::shouldHaveReceived('warning')
        ->with('Coda 123: NC da storno modificata con imponibile diverso dall\'originale, ritenuta ricalcolata dal vivo', \Mockery::type('array'))
        ->once();
});

test('la pagina di modifica passa l\'originale al frontend, non solo la NC', function () {
    // ⚠️ **Trovato verificando a video la correzione stessa, il 05/09/2026 — non da un
    // rilievo della revisione avversariale.** Il fix server-side legge l'originale
    // immutabile, ma FatturaRegisterEdit.vue confrontava contro `props.fattura` (la NC
    // stessa, mutabile): dopo un salvataggio con l'imponibile sbagliato, l'anteprima si
    // guastava insieme alla NC e continuava a mostrare zero anche quando il salvataggio
    // successivo avrebbe già ripristinato il valore congelato. `nota_storno_originale` è
    // il rimedio: la prop che fa vedere al frontend la stessa fonte di verità del backend.
    [$nc, $fatturaOriginale, $ctx] = scenarioNotaDaStorno();
    [$condominio] = $ctx;

    // scenarioNotaDaStorno() ha già autenticato l'utente che ha eseguito lo storno.
    $risposta = $this->get(route('admin.gestionale.fatture.edit', [$condominio, $nc]));

    $risposta->assertOk();
    $risposta->assertInertia(fn ($page) => $page
        ->component('gestionale/movimenti/fatture/FatturaRegisterEdit')
        ->has('nota_storno_originale')
        ->where('nota_storno_originale.importo_imponibile', $fatturaOriginale->importo_imponibile)
        ->where('nota_storno_originale.importo_ritenuta', $fatturaOriginale->importo_ritenuta)
    );
});

test('su una fattura ORDINARIA (mai nata da storno) la prop resta null', function () {
    // Il controesempio: la query su `stornata_da_id` non deve scattare per documenti che
    // non sono note di credito da storno — sarebbe un lavoro sprecato, e null è il segnale
    // corretto che il frontend legge già per decidere se il congelamento esiste.
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Appalto', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');

    $risposta = $this->actingAs($user)
        ->get(route('admin.gestionale.fatture.edit', [$condominio, $fattura]));

    $risposta->assertInertia(fn ($page) => $page->where('nota_storno_originale', null));
});

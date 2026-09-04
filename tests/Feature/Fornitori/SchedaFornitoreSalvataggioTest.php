<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * «Il pulsante Salva modifiche non riporta alcun effetto».
 *
 * Segnalato sul forum il 30/08/2026: nessun messaggio verde, nessun messaggio rosso, dati non
 * salvati. Il rifiuto c'era — un 422 — ma riguardava campi che la schermata non sapeva stampare:
 * misurate **22 chiavi mute su 39 validate** in `FornitoriEdit.vue` e 20 su 38 in `FornitoriNew`.
 * È la classe della Coda 51 in roadmap, applicata alla prima schermata.
 *
 * Sul percorso `store`/`update` dei fornitori **non esisteva alcun test**: questo file è il primo.
 *
 * COSA QUESTO FILE NON COPRE, dichiarato perché non torni più in mente dopo:
 * - non verifica che l'errore sia **visibile a schermo** — verifica che arrivi in sessione con la
 *   chiave giusta. La resa è coperta dalla guardia di copertura in fondo al file, che confronta le
 *   chiavi di `rules()` con i `form.errors.<chiave>` del template, e da lì in poi solo dall'occhio;
 * - non copre il conto corrente (`contiCorrenti`) oltre a ciò che serve a non far esplodere
 *   l'update, né la cancellazione dell'IBAN svuotando il campo, che resta una decisione aperta;
 * - non copre i tre `VueDatePicker`, che mandano un `Date` serializzato in UTC;
 * - non copre il percorso con i permessi negati.
 */
beforeEach(function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($this->user);
});

/** Il carico minimo che la schermata manda a ogni salvataggio. */
function payloadFornitore(array $override = []): array
{
    return array_merge([
        'ragione_sociale'            => 'Idraulica Tevere S.r.l.',
        'stato'                      => 'attivo',
        'modalita_pagamento_default' => 'bonifico',
        'giorni_scadenza'            => 30,
        'soggetto_ritenuta'          => false,
        'certificazione_iso'         => false,
        'residente_fiscale'          => true,
        'regime_forfetario'          => false,
        'provvigioni_base_ridotta'   => false,
    ], $override);
}

function creaFornitore(array $override = []): Fornitore
{
    return Fornitore::create(array_merge([
        'ragione_sociale'          => 'Idraulica Tevere S.r.l.',
        'stato'                    => 'attivo',
        'giorni_scadenza'          => 30,
        'modalita_pagamento_default' => 'bonifico',
        'soggetto_ritenuta'        => false,
        'perc_imponibile_ritenuta' => 100,
    ], $override));
}

/*
|--------------------------------------------------------------------------
| Il riquadro «Override manuale (facoltativo)» che era obbligatorio
|--------------------------------------------------------------------------
*/

it('salva un fornitore soggetto a ritenuta col regime nuovo e senza codice tributo', function () {
    // Il caso misurato in sviluppo: «Edil Facciate Srl» ha tipo_ritenuta e natura_percipiente,
    // quindi `RitenutaService::calcolaRegimeNuovo()` ricava il codice tributo dall'enum. Con
    // `required_if:soggetto_ritenuta,true` su codice_tributo quella scheda era **impossibile da
    // salvare per sempre**, qualunque campo si toccasse.
    $fornitore = creaFornitore([
        'soggetto_ritenuta'  => true,
        'tipo_ritenuta'      => 'lavoro_autonomo_20',
        'natura_percipiente' => 'persona_fisica_irpef',
        'perc_ritenuta'      => 20,
        'codice_tributo'     => null,
    ]);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'ragione_sociale'    => 'Edil Facciate S.r.l.',
        'soggetto_ritenuta'  => true,
        'tipo_ritenuta'      => 'lavoro_autonomo_20',
        'natura_percipiente' => 'persona_fisica_irpef',
        'perc_ritenuta'      => 20,
        'codice_tributo'     => '',
    ]))->assertSessionHasNoErrors();

    expect($fornitore->fresh()->ragione_sociale)->toBe('Edil Facciate S.r.l.');
});

it('salva un fornitore a regime legacy con la sola aliquota e senza codice tributo', function () {
    // «Impresa Manutenzioni Demo s.r.l.», che nasce così dal CondominioDemoSeeder: nessun
    // tipo_ritenuta, perc_ritenuta valorizzata, codice_tributo nullo.
    $fornitore = creaFornitore([
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
        'codice_tributo'    => null,
    ]);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
        'codice_tributo'    => '',
        'telefono'          => '06 5550101',
    ]))->assertSessionHasNoErrors();

    expect($fornitore->fresh()->telefono)->toBe('06 5550101');
});

it('rifiuta ancora un fornitore soggetto a ritenuta senza regime e senza aliquota', function () {
    // La guardia che NON deve cadere allentando le altre due: senza tipo_ritenuta e senza
    // perc_ritenuta, `RitenutaService::calcolaRegimeLegacy()` solleva DomainException al momento
    // di registrare una fattura. Meglio rifiutarlo qui, dove c'è un campo a cui attaccare l'errore.
    $fornitore = creaFornitore();

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'soggetto_ritenuta' => true,
        'tipo_ritenuta'     => '',
        'perc_ritenuta'     => '',
    ]))->assertSessionHasErrors('perc_ritenuta');
});

it('riporta la percentuale imponibile al default della colonna quando la casella è svuotata', function () {
    // `perc_imponibile_ritenuta` è `decimal(5,2) NOT NULL default 100.00`: svuotata arrivava come
    // null e avrebbe fatto esplodere l'UPDATE dentro il try/catch, cioè un messaggio d'errore
    // generico al posto di un salvataggio riuscito.
    $fornitore = creaFornitore(['perc_imponibile_ritenuta' => 50]);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'perc_imponibile_ritenuta' => '',
    ]))->assertSessionHasNoErrors();

    expect((float) $fornitore->fresh()->perc_imponibile_ritenuta)->toBe(100.0);
});

/*
|--------------------------------------------------------------------------
| I limiti che rifiutavano dati veri
|--------------------------------------------------------------------------
*/

it('accetta due recapiti scritti nella stessa casella', function () {
    // 25 caratteri contro il vecchio `max:20`, su una colonna `varchar(255)`.
    $fornitore = creaFornitore();

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'telefono' => '06 1234567 / 333 1234567',
    ]))->assertSessionHasNoErrors();

    expect($fornitore->fresh()->telefono)->toBe('06 1234567 / 333 1234567');
});

it('rifiuta un codice fiscale più lungo della colonna invece di lasciarlo esplodere in SQL', function () {
    // `codice_fiscale` è `varchar(20)` e la validazione diceva `max:255`: con `strict => true`
    // il rifiuto arrivava dal database, cioè come messaggio generico del catch.
    $fornitore = creaFornitore();

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'codice_fiscale' => str_repeat('A', 21),
    ]))->assertSessionHasErrors('codice_fiscale');
});

/*
|--------------------------------------------------------------------------
| I rifiuti che restano, e che ora hanno dove comparire
|--------------------------------------------------------------------------
*/

it('rifiuta con la chiave giusta i casi che il segnalante incontrava', function (array $payload, string $chiave) {
    $fornitore = creaFornitore();

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore($payload))
        ->assertSessionHasErrors($chiave);
})->with([
    'giorni di scadenza svuotati'  => [['giorni_scadenza' => ''], 'giorni_scadenza'],
    'giorni di scadenza a parole'  => [['giorni_scadenza' => '30 gg d.f.'], 'giorni_scadenza'],
    'pec uguale alla email'        => [['email' => 'info@ditta.it', 'pec' => 'info@ditta.it'], 'pec'],
    'ateco con la descrizione'     => [['codice_ateco' => '43.22.01 impianti idraulici'], 'codice_ateco'],
]);

it('dice su quale archivio è già in uso un indirizzo email', function () {
    // La collisione tipica è la stessa persona in due ruoli. Senza il nome dell'archivio
    // l'amministratore non ha modo di sapere quale dei tre guardare.
    Anagrafica::factory()->create(['email' => 'studio@esempio.it']);
    $fornitore = creaFornitore();

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'email' => 'studio@esempio.it',
    ]))->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain("un'anagrafica");
});

/*
|--------------------------------------------------------------------------
| I rappresentanti: la scheda del fornitore non li tocca più
|--------------------------------------------------------------------------
*/

it('la modifica del fornitore non tocca i suoi rappresentanti', function () {
    // Il difetto segnalato di riflesso: `update()` faceva `detach()` senza argomenti quando il
    // modulo non mandava `anagrafica_id`, cioè portava via **tutti** i rappresentanti a ogni
    // salvataggio, con il messaggio verde. La correzione non è stata rimettere la casella — che non
    // sa esprimere il `ruolo`, obbligatorio dall'altra parte — ma togliere del tutto la scrittura.
    $titolare = Anagrafica::factory()->create();
    $tecnico = Anagrafica::factory()->create();
    $fornitore = creaFornitore();
    $fornitore->referenti()->attach($titolare->id, ['ruolo' => 'titolare']);
    $fornitore->referenti()->attach($tecnico->id, ['ruolo' => 'tecnico']);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'telefono' => '06 5550101',
    ]))->assertSessionHasNoErrors();

    $rappresentanti = $fornitore->fresh()->referenti;
    expect($rappresentanti->pluck('id')->sort()->values()->all())
        ->toBe(collect([$titolare->id, $tecnico->id])->sort()->values()->all());
    expect($rappresentanti->firstWhere('id', $titolare->id)->pivot->ruolo)->toBe('titolare');
    expect($rappresentanti->firstWhere('id', $tecnico->id)->pivot->ruolo)->toBe('tecnico');
});

it('un rappresentante scelto alla creazione nasce con il suo ruolo', function () {
    // Fino alla beta.6 questa strada faceva `attach()` **senza ruolo**, creando una riga che la
    // scheda «Rappresentanti» avrebbe rifiutato (lì `ruolo` è `required`) e che compariva lì con la
    // colonna Ruolo vuota: due porte sulla stessa parete con regole diverse.
    $anagrafica = Anagrafica::factory()->create();

    $this->post(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale' => 'Nuova Ditta S.r.l.',
        'anagrafica_id'   => $anagrafica->id,
        'ruolo'           => 'titolare',
    ]))->assertSessionHasNoErrors();

    $fornitore = Fornitore::where('ragione_sociale', 'Nuova Ditta S.r.l.')->firstOrFail();
    expect($fornitore->referenti->firstWhere('id', $anagrafica->id)->pivot->ruolo)->toBe('titolare');
});

it('alla creazione il ruolo è obbligatorio appena si sceglie un rappresentante', function () {
    $anagrafica = Anagrafica::factory()->create();

    $this->post(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale' => 'Altra Ditta S.r.l.',
        'anagrafica_id'   => $anagrafica->id,
        'ruolo'           => '',
    ]))->assertSessionHasErrors('ruolo');
});

it('senza rappresentante il ruolo non serve', function () {
    $this->post(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale' => 'Ditta Senza Contatti S.r.l.',
    ]))->assertSessionHasNoErrors();
});

/*
|--------------------------------------------------------------------------
| La stessa rotta, chiamata da un modale invece che da una pagina intera
|--------------------------------------------------------------------------
| Aggiunto aprendo la riprogettazione della UI di importazione XML (02/09/2026):
| il fornitore letto da un XML e non ancora in anagrafica si crea senza lasciare
| la pagina di registrazione della fattura. store() negozia sull'Accept invece di
| raddoppiare la rotta — stessa CreateFornitoreRequest, stessa Fornitore::create(),
| stessi effetti collaterali di sopra: qui si verifica solo che l'esito cambi forma
| (JSON invece di redirect), non che la logica di salvataggio sia diversa.
*/

it('con Accept: json risponde con il fornitore creato invece di un redirect', function () {
    $risposta = $this->postJson(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale' => 'Fornitore da XML S.r.l.',
        'partita_iva'     => '01234567897',
    ]));

    $risposta->assertCreated();
    $fornitore = Fornitore::where('ragione_sociale', 'Fornitore da XML S.r.l.')->firstOrFail();
    expect($risposta->json())->toBe([
        'id' => $fornitore->id,
        'ragione_sociale' => 'Fornitore da XML S.r.l.',
    ]);
});

it('con Accept: json un payload non valido risponde 422 con gli errori, non un redirect', function () {
    // Stessa regola di validazione delle altre righe di questo file (ragione_sociale
    // required): qui si verifica solo che l'ESITO cambi forma con Accept: json.
    $risposta = $this->postJson(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale' => '',
    ]));

    $risposta->assertStatus(422);
    expect($risposta->json('errors'))->toHaveKey('ragione_sociale');
});

it('con Accept: json il percorso a pagina intera continua a rispondere con un redirect', function () {
    // Controprova incrociata: la negoziazione non deve aver rotto il flusso esistente
    // per chi arriva da Inertia (nessun header Accept: application/json esplicito).
    $risposta = $this->post(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale' => 'Ditta Pagina Intera S.r.l.',
    ]));

    $risposta->assertRedirect(route('admin.fornitori.index'));
});

/*
|--------------------------------------------------------------------------
| La guardia di copertura — per questa schermata sola
|--------------------------------------------------------------------------
*/

it('rende a schermo ogni chiave validata della scheda fornitore', function (string $request, string $vue, array $ammesse) {
    // ⛔ La guardia **globale** su tutti i FormRequest è stata valutata e scartata (Coda 51 in
    // roadmap): segnalerebbe 210 chiavi su 675 e si spegnerebbe al primo giro. Questa vive su una
    // schermata sola, con l'elenco chiuso delle eccezioni scritto qui sotto: se qualcuno aggiunge
    // un campo senza il suo `<InputError>`, diventa rossa.
    //
    // ⚠️ COSA NON VEDE: un `<InputError>` annidato dentro un `v-if` chiuso conta come reso, perché
    // il confronto è testuale sul template. Il riquadro di riepilogo in testa al modulo esiste
    // anche per questo.
    $sorgenteRequest = file_get_contents(app_path("Http/Requests/Fornitore/{$request}.php"));
    $corpoRules = preg_match('/public function rules\(\).*?\n    }/s', $sorgenteRequest, $m) ? $m[0] : '';
    preg_match_all("/^\s+'([a-z_]+)'\s*=>/m", $corpoRules, $chiavi);

    $validate = array_unique($chiavi[1]);
    expect($validate)->not->toBeEmpty('Il riconoscitore delle chiavi non ha trovato niente: la guardia sarebbe verde senza guardare.');

    $template = file_get_contents(resource_path("js/pages/fornitori/{$vue}.vue"));
    preg_match_all('/form\.errors\.([a-z_]+)/', $template, $rese);
    $rese = array_unique($rese[1]);

    $mute = array_values(array_diff($validate, $rese, $ammesse));

    expect($mute)->toBe([], "Chiavi validate senza un posto dove comparire in {$vue}.vue: " . implode(', ', $mute));
})->with([
    // Le ammesse sono caselle di spunta, pulsanti a stato e campi che a schermo non ci sono:
    // dall'interfaccia non possono fallire, e il riquadro di riepilogo le mostrerebbe comunque.
    // `anagrafica_id_originale` non è un campo che si compila — è il referente che la scheda
    // stava mostrando, rimandato indietro perché il controller sappia quale togliere.
    'modifica' => [
        'UpdateFornitoreRequest',
        'FornitoriEdit',
        ['certificazione_iso', 'soggetto_ritenuta', 'residente_fiscale', 'regime_forfetario', 'provvigioni_base_ridotta', 'stato', 'nazione'],
    ],
    'inserimento' => [
        'CreateFornitoreRequest',
        'FornitoriNew',
        ['certificazione_iso', 'soggetto_ritenuta', 'residente_fiscale', 'regime_forfetario', 'provvigioni_base_ridotta', 'nazione'],
    ],
]);

/*
|--------------------------------------------------------------------------
| La natura del percipiente: obbligatoria dove decide, presa d'atto sul resto
|--------------------------------------------------------------------------
|
| Coda 119, punto 1 — scelta di Vincenzo del 03/09/2026.
|
| ⚠️ **La regola non è «obbligatoria se soggetto a ritenuta».** Quella l'abbiamo già
| scritta una volta, sui tre campi dell'override, ed è la beta.6: ogni scheda già a
| database con la spunta e il campo vuoto diventò impossibile da salvare, anche solo per
| correggere un telefono. Qui l'obbligo è ristretto su tre fronti, e ogni test qui sotto
| ne protegge uno: il regime (solo l'appalto fa dipendere il codice dalla natura), il
| forfetario (che una ritenuta non la genera mai), e il pregresso (presa d'atto).
*/

it('alla creazione, un fornitore in appalto senza natura viene rifiutato', function () {
    $this->post(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale'   => 'Edilizia Senza Natura S.r.l.',
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
    ]))->assertSessionHasErrors('natura_percipiente');
});

it('e il rifiuto dice perche, non solo che manca', function () {
    // Un messaggio che dice «il campo è obbligatorio» non aiuta chi non sa cosa sia la
    // natura del percipiente: deve portare il motivo, che è lo stesso del blocco sull'F24.
    $risposta = $this->postJson(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale'   => 'Edilizia Senza Natura S.r.l.',
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
    ]));

    $risposta->assertStatus(422);
    expect($risposta->json('errors.natura_percipiente.0'))
        ->toContain('1019')
        ->toContain('1020');
});

it('al 20% invece non serve, perche li il codice tributo e 1040 comunque', function () {
    // ⚠️ Il controesempio che tiene stretta la regola: senza questo, «obbligatoria
    // sempre» passerebbe e chiederemmo un dato che sul modello F24 non cambia niente.
    $this->post(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale'   => 'Studio Professionale Senza Natura',
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 20,
    ]))->assertSessionHasNoErrors();
});

it('a un forfetario non si chiede, perche una ritenuta non la genera mai', function () {
    // RitenutaService esce con MotivoEsclusioneRitenuta::FORFETARIO prima ancora di
    // guardare soggetto_ritenuta: quel fornitore a un F24 non ci arriva.
    $this->post(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale'          => 'Artigiano Forfetario',
        'soggetto_ritenuta'        => true,
        'perc_ritenuta'            => 4,
        'regime_forfetario'        => true,
        'forfetario_dichiarato_il' => '2026-01-01',
    ]))->assertSessionHasNoErrors();
});

it('accendendo la spunta su una scheda esistente, la natura diventa obbligatoria', function () {
    // Non è pregresso: l'incompletezza la sta producendo adesso chi sta salvando.
    $fornitore = creaFornitore(['soggetto_ritenuta' => false]);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
    ]))->assertSessionHasErrors('natura_percipiente');
});

it('toccando il blocco fiscale di una scheda gia incompleta, la natura diventa obbligatoria', function () {
    // La presa d'atto vale finché si sta cambiando altro. Chi mette le mani nel riquadro
    // della ritenuta sta lavorando proprio lì: è il momento in cui il dato si chiede.
    $fornitore = creaFornitore([
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
    ]);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
        'tipo_ritenuta'     => 'appalto_4',   // prima era vuoto: il riquadro è stato toccato
    ]))->assertSessionHasErrors('natura_percipiente');
});

it('ma cambiare solo l\'IBAN di quella stessa scheda continua a funzionare', function () {
    // ⚠️ È la beta.6 che non deve ripetersi, ed è la ragione della presa d'atto.
    $fornitore = creaFornitore([
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
    ]);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
        'iban_principale'   => 'IT60X0542811101000000123456',
    ]))->assertSessionHasNoErrors();

    expect($fornitore->fresh()->iban_principale)->toBe('IT60X0542811101000000123456');
});

it('e l\'aliquota scritta 4.00 invece di 4 non conta come blocco toccato', function () {
    // ⚠️ `perc_ritenuta` è decimal(5,2): a database torna «4.00», il modulo rimanda «4».
    // Confrontarli come stringhe direbbe che il riquadro è stato toccato ogni volta, e la
    // presa d'atto non varrebbe mai — cioè avremmo il blocco secco senza averlo scelto.
    $fornitore = creaFornitore([
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => 4,
    ]);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'soggetto_ritenuta' => true,
        'perc_ritenuta'     => '4.00',
        'telefono'          => '06 5550202',
    ]))->assertSessionHasNoErrors();
});

/*
|--------------------------------------------------------------------------
| «No» e «non gliel'ha mai chiesto nessuno» sono due cose diverse (Coda 116)
|--------------------------------------------------------------------------
|
| `soggetto_ritenuta` è `NOT NULL default 0`: un fornitore appena censito e uno per cui la
| risposta è davvero no hanno lo stesso valore in colonna. `ritenuta_decisa_il` registra che
| qualcuno ha guardato — ed è ciò che permette di fare la domanda **una volta sola** invece
| che a ogni fattura.
*/

it('creando un fornitore la posizione sulla ritenuta risulta decisa, anche rispondendo no', function () {
    // ⚠️ È il caso che conta: il «no» esplicito deve valere quanto un sì. Senza, il fornitore
    // appena creato tornerebbe subito nell'elenco di quelli da chiedere.
    $this->post(route('admin.fornitori.store'), payloadFornitore([
        'ragione_sociale'   => 'Ferramenta Senza Ritenuta',
        'soggetto_ritenuta' => false,
    ]))->assertSessionHasNoErrors();

    $fornitore = Fornitore::where('ragione_sociale', 'Ferramenta Senza Ritenuta')->firstOrFail();

    expect($fornitore->posizioneRitenutaMaiDecisa())->toBeFalse();
});

it('cambiando solo l\'IBAN la posizione resta quella che era: non decisa', function () {
    // ⚠️ Il difetto da non introdurre. Chi cambia l'IBAN non si è pronunciato sulla ritenuta,
    // e marcare quella scheda come decisa registrerebbe una risposta che nessuno ha dato —
    // cioè spegnerebbe la domanda senza averla fatta.
    $fornitore = creaFornitore(['soggetto_ritenuta' => false]);
    $fornitore->update(['ritenuta_decisa_il' => null]);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'soggetto_ritenuta' => false,
        'iban_principale'   => 'IT60X0542811101000000123456',
    ]))->assertSessionHasNoErrors();

    expect($fornitore->fresh()->posizioneRitenutaMaiDecisa())->toBeTrue();
});

it('toccando il riquadro fiscale la posizione risulta decisa', function () {
    $fornitore = creaFornitore(['soggetto_ritenuta' => false]);
    $fornitore->update(['ritenuta_decisa_il' => null]);

    $this->put(route('admin.fornitori.update', $fornitore), payloadFornitore([
        'soggetto_ritenuta'  => true,
        'perc_ritenuta'      => 4,
        'natura_percipiente' => 'soggetto_ires',
    ]))->assertSessionHasNoErrors();

    expect($fornitore->fresh()->posizioneRitenutaMaiDecisa())->toBeFalse();
});

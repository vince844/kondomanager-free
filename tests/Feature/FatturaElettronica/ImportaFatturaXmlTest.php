<?php

/**
 * Beta.14, decisione 1 di apertura («due porte, una stanza»,
 * `docs/lettura_xml_fatture_passive.md`): questo endpoint legge un XML e restituisce i
 * dati per precompilare il modulo — non crea nessuna fattura.
 */

use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

function fileFixtura(string $nome, string $nomeCaricato): UploadedFile
{
    $contenuto = file_get_contents(base_path('tests/Fixtures/fatturapa/'.$nome));

    return UploadedFile::fake()->createWithContent($nomeCaricato, $contenuto);
}

test('legge la fixture reale con cassa previdenziale e converte i centesimi in euro', function () {
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('reale_anonimizzata_cassa_previdenziale.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    expect($dati['documento']['tipo_documento'])->toBe('fattura')
        ->and($dati['documento']['numero_documento'])->toBe('6')
        ->and($dati['documento']['data_documento'])->toBe('2026-02-19')
        ->and($dati['documento']['modalita_pagamento'])->toBe('bonifico')
        ->and($dati['documento']['iban_fornitore'])->toBe('IT43X0100003245100000000001')
        ->and($dati['righe'])->toHaveCount(1)
        // 3500 centesimi -> 35.0 euro. `MoneyHelper::fromCents()` in PHP restituisce
        // proprio un float, ma un JSON numero intero-di-valore non porta ".0": il
        // round-trip lo consegna come 35, non 35.0 — esattamente il numero che il
        // form Vue riceverà, non 3500 e non una stringa formattata "35,00".
        ->and($dati['righe'][0]['importo_imponibile'])->toBe(35)
        ->and($dati['fornitore']['esito'])->toBe('non_trovato')
        ->and($dati['fornitore']['letto_da_xml']['denominazione'])->toBe('GIULIA BIANCHI');
});

test('aggancia il fornitore quando la partita IVA combacia con uno già in anagrafica', function () {
    $condominio = Condominio::factory()->create();
    $fornitore = Fornitore::create([
        'ragione_sociale' => 'Giulia Bianchi',
        'partita_iva' => '01234567897',
    ]);

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('reale_anonimizzata_cassa_previdenziale.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    expect($dati['fornitore']['esito'])->toBe('trovato')
        ->and($dati['fornitore']['candidati'])->toHaveCount(1)
        ->and($dati['fornitore']['candidati'][0]['id'])->toBe($fornitore->id);
});

test('segnala più di un fornitore quando la partita IVA non è univoca', function () {
    $condominio = Condominio::factory()->create();
    $a = Fornitore::create(['ragione_sociale' => 'Studio A', 'partita_iva' => '01234567897']);
    $b = Fornitore::create(['ragione_sociale' => 'Studio B', 'partita_iva' => '01234567897']);

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('reale_anonimizzata_cassa_previdenziale.xml', 'fattura.xml')]
    );

    $dati = $risposta->json();

    expect($dati['fornitore']['esito'])->toBe('ambiguo')
        ->and($dati['fornitore']['candidati'])->toHaveCount(2)
        ->and(collect($dati['fornitore']['candidati'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

test('la fixture con allegato reale non ha scadenze: niente IBAN, niente data di scadenza, senza rompersi', function () {
    // ⚠️ Questa fixture (ritenuta + allegato PDF reale, anonimizzato) non ha nessun
    // <DatiPagamento>: verificato con una lettura diretta del parser prima di scrivere
    // questa asserzione. `data_scadenza`/`iban_fornitore`/`modalita_pagamento` devono
    // restare null — mai un valore inventato per un campo che il file non dichiara.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('reale_anonimizzata_ritenuta_allegato.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    expect($dati['documento']['numero_documento'])->toBe('492')
        ->and($dati['documento']['data_scadenza'])->toBeNull()
        ->and($dati['documento']['iban_fornitore'])->toBeNull()
        ->and($dati['documento']['modalita_pagamento'])->toBeNull()
        ->and($dati['righe'])->toHaveCount(8)
        ->and($dati['avvisi']['righe_non_quadrano_col_riepilogo'])->toBeFalse()
        ->and($dati['avvisi']['lotto_con_altri_documenti'])->toBe(0);
});

test('un XML malformato risponde 422 con un messaggio, non 500', function () {
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => UploadedFile::fake()->createWithContent('rotto.xml', '<?xml version="1.0"?><Aperto><NonChiuso>')]
    );

    $risposta->assertStatus(422);

    // ⚠️ Si asserisce la **proprietà**, non le parole esatte: il messaggio va a schermo e
    // lo legge un amministratore di condominio, quindi non deve contenere il gergo del
    // parser e deve dire che cosa fare. Il dettaglio tecnico non si perde — finisce nel
    // log, scritto dal controller (vedi `FatturaPaParseException`).
    $errore = $risposta->json('errore');
    expect($errore)->not->toContain('Specification mandates')
        ->and($errore)->not->toContain('libxml')
        ->and($errore)->toContain('riscaricalo');
});

test('un utente senza accesso al pannello amministratore prende 403', function () {
    $condominio = Condominio::factory()->create();
    $senzaPermessi = User::factory()->create();

    $risposta = $this->actingAs($senzaPermessi)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('reale_anonimizzata_cassa_previdenziale.xml', 'fattura.xml')]
    );

    $risposta->assertForbidden();
});

test('una nota di credito con importi negativi nel file arriva positiva, pronta per il -1 del servizio', function () {
    // Trovato dalla revisione avversariale della beta.14. FatturaPassivaService::registraFattura()
    // si aspetta SEMPRE una magnitudine positiva in righe.*.importo_imponibile e applica lui
    // il -1 quando tipo_documento è nota_credito (FatturaPassivaService.php:36). Se questo
    // endpoint restituisse il -50 dichiarato nel file, il form lo precompilerebbe negativo e
    // il servizio lo moltiplicherebbe di nuovo per -1: un credito registrato come debito.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_nota_credito_importi_negativi.xml', 'nota_credito.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    expect($dati['documento']['tipo_documento'])->toBe('nota_credito')
        ->and($dati['righe'])->toHaveCount(1)
        ->and($dati['righe'][0]['importo_imponibile'])->toBe(50);
});

test('la stessa nota di credito con importi già positivi nel file resta positiva (l\'altra forma ammessa)', function () {
    // Controprova incrociata: la fixture "gemella" con la convenzione opposta (positiva,
    // come sintetica_nota_credito.xml già in uso altrove) non deve cambiare comportamento
    // per effetto della correzione — l'abs() su un numero già positivo è un no-op.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_nota_credito.xml', 'nota_credito.xml')]
    );

    $risposta->assertOk();
    expect($risposta->json('righe.0.importo_imponibile'))->toBe(50);
});

test('una riga negativa dentro una fattura ordinaria (non nota di credito) mantiene il segno', function () {
    // La controprova che tiene la correzione onesta: abs() normalizza SOLO la nota di
    // credito. Questa fixture riproduce il file 06 del collaudo sugli undici XML veri
    // (docs/lettura_xml_fatture_passive.md) — una bolletta gas TD01 con un conguaglio
    // negativo sugli oneri di sistema. Se abs() venisse applicato anche qui, il
    // conguaglio si raddoppierebbe (-1,80 → +1,80) invece di annullarsi, e la fattura
    // registrata non corrisponderebbe più al documento ricevuto.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_fattura_riga_negativa.xml', 'bolletta.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    expect($dati['documento']['tipo_documento'])->toBe('fattura')
        ->and($dati['righe'])->toHaveCount(2)
        ->and($dati['righe'][0]['importo_imponibile'])->toBe(44.35)
        ->and($dati['righe'][1]['importo_imponibile'])->toBe(-1.8);
});

test('la ritenuta dichiarata nel file viene esposta, non buttata', function () {
    // Trovato dalla revisione avversariale della beta.14: il parser legge <DatiRitenuta>
    // da tempo, ma questo endpoint non lo aveva mai incluso nella risposta. Non decide se
    // applicarla — quello dipende dall'anagrafica del fornitore, che l'XML non conosce —
    // ma non può più sparire in silenzio.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_con_ritenuta.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    // Stesso round-trip JSON già documentato più sopra in questo file: un float di valore
    // intero (80.0, 20.0) arriva come intero (80, 20), non come "80.0" — è il numero che il
    // frontend riceve davvero, non un dettaglio da correggere qui.
    expect($dati['ritenuta'])->not->toBeNull()
        ->and($dati['ritenuta']['tipo'])->toBe('RT02')
        ->and($dati['ritenuta']['importo'])->toBe(80)
        ->and($dati['ritenuta']['aliquota'])->toBe(20)
        ->and($dati['ritenuta']['causale_pagamento'])->toBe('A');
});

test('il contributo cassa resta fuori dalla base della ritenuta, e i conti del file tornano', function () {
    // ⚠️ **Questa fixture è costruita perché i numeri discriminino da soli.** Le righe fanno
    // € 3.200,00, il contributo cassa € 160,00 con <Ritenuta>NO</Ritenuta>, e la ritenuta
    // dichiarata è € 128,00 — cioè il 4% di 3.200, NON di 3.360. Se il contributo entrasse
    // nella base, il calcolo darebbe € 134,40 e non combacerebbe più con quanto il documento
    // dichiara: è la trappola 1 della catena in docs/catene_fra_moduli.md, resa visibile da
    // un solo confronto.
    //
    // È anche datata nell'esercizio corrente, apposta: l'unico file di collaudo con un
    // contributo cassa vero è del 2025 e la pagina lo manda — correttamente — nel percorso
    // «debito pregresso», dove il registro non c'è e il caso non si può guardare a video.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_cassa_e_ritenuta_esercizio_corrente.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    $cassa = collect($dati['righe'])->firstWhere('descrizione', 'Contributo cassa previdenziale 5% (TC03)');
    expect($cassa)->not->toBeNull()
        ->and($cassa['importo_imponibile'])->toBe(160)
        ->and($cassa['concorre_base_ritenuta'])->toBeFalse();

    // Le righe ordinarie NON portano la chiave: per loro «concorre» è il default di tutta
    // la catena, ed è la ragione per cui il frontend legge `!== false` e non `=== true`.
    $prestazione = collect($dati['righe'])->firstWhere('descrizione', '- Direzione Lavori (D.L.).');
    expect($prestazione)->not->toBeNull()
        ->and($prestazione)->not->toHaveKey('concorre_base_ritenuta');

    expect($dati['ritenuta']['tipo'])->toBe('RT01')
        ->and($dati['ritenuta']['importo'])->toBe(128)
        ->and($dati['ritenuta']['aliquota'])->toBe(4);
});

test('la gemella senza ritenuta porta lo stesso contributo cassa, e nessuna ritenuta', function () {
    // ⚠️ Serve al verso OPPOSTO del confronto — il modulo trattiene e il file tace — che è il
    // caso in cui si rischia di trattenere al fornitore denaro che forse non andava trattenuto.
    // È la gemella esatta della fixture qui sopra, senza il blocco DatiRitenuta: due file
    // identici in tutto il resto rendono il confronto a video una differenza sola.
    // Questo test esiste anche perché una fixture che nessun test nomina è una fixture che
    // prima o poi qualcuno cancella.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_senza_ritenuta_esercizio_corrente.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    expect($dati['ritenuta'])->toBeNull();

    $cassa = collect($dati['righe'])->firstWhere('descrizione', 'Contributo cassa previdenziale 5% (TC03)');
    expect($cassa)->not->toBeNull()
        ->and($cassa['concorre_base_ritenuta'])->toBeFalse();
});

test('un contributo previdenziale non viene scambiato per una ritenuta d\'acconto', function () {
    // ⚠️ Lo schema FatturaPA usa <DatiRitenuta> per SEI cose diverse: RT01 e RT02 sono
    // ritenute d'acconto, ma RT03 = contributo INPS, RT04 = ENASARCO, RT05 = ENPAM,
    // RT06 = altro contributo previdenziale. Sono un'altra cosa: non le versa il
    // condominio con l'F24 e il nostro enum TipoRitenuta non ha nessun case per loro.
    //
    // Il parser li raccoglie tutti — ed è giusto, è una lettura fedele del file — ma il
    // significato si assegna qui: esporre un ENASARCO come «la ritenuta del documento»
    // farebbe trattenere al fornitore denaro che non spetta all'Erario.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_solo_contributo_enasarco.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    expect($dati['ritenuta'])->toBeNull();

    // Ma non si tace: il documento dichiara qualcosa, e l'amministratore deve saperlo.
    expect($dati['avvisi']['contributi_previdenziali_dichiarati'])->toBe(['RT04']);
});

test('con un contributo previdenziale PRIMA della ritenuta, si espone la ritenuta', function () {
    // È l'ordine che rompeva `ritenute[0]`: prendere il primo blocco è una scelta
    // arbitraria travestita da indice. Lo schema dichiara DatiRitenuta ripetibile
    // (maxOccurs="unbounded") e non impone nessun ordine.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_enasarco_prima_della_ritenuta.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    expect($dati['ritenuta'])->not->toBeNull()
        ->and($dati['ritenuta']['tipo'])->toBe('RT02')
        ->and($dati['ritenuta']['importo'])->toBe(80)
        ->and($dati['avvisi']['contributi_previdenziali_dichiarati'])->toBe(['RT04']);
});

test('un documento senza DatiRitenuta restituisce null, non un blocco a zero', function () {
    // Zero ritenuta e ritenuta-non-dichiarata sono due fatti diversi: un blocco a zero
    // lascerebbe intendere che il fornitore ha dichiarato "niente da trattenere", quando
    // in realtà il file non ne parla affatto.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('reale_anonimizzata_cassa_previdenziale.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    expect($risposta->json('ritenuta'))->toBeNull();
});

test('letto_da_xml espone l\'anagrafica completa del cedente, non solo i tre campi dell\'aggancio', function () {
    // Aggiunto aprendo la riprogettazione della UI (02/09/2026): il fornitore non
    // trovato si crea senza uscire dalla pagina, e per farlo il frontend ha bisogno
    // di più della sola denominazione/partita IVA/codice fiscale già usate per
    // l'aggancio — il parser li leggeva già, mancava solo esporli.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('reale_anonimizzata_cassa_previdenziale.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    $letto = $risposta->json('fornitore.letto_da_xml');

    expect($letto)->toHaveKeys([
        'denominazione', 'partita_iva', 'partita_iva_paese', 'codice_fiscale',
        'indirizzo', 'cap', 'comune', 'provincia', 'nazione', 'email', 'regime_forfetario',
    ])
        ->and($letto['partita_iva_paese'])->toBe('IT')
        // Verificato leggendo la fixture, non assunto: questa dichiara RF19.
        ->and($letto['regime_forfetario'])->toBeTrue();
});

test('un regime diverso da RF19 (es. RF01) resta regime_forfetario=false, non un default sempre-vero', function () {
    // Controprova incrociata sulla fixture qui sopra: RF01 (il regime ordinario,
    // il più comune) non deve accendere il flag.
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_natura_esente.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
    expect($risposta->json('fornitore.letto_da_xml.regime_forfetario'))->toBeFalse();
});

test('un file intestato a un altro condominio viene rifiutato spiegando perché', function () {
    // Deciso con Vincenzo il 02/09/2026: l'importazione resta dentro un condominio
    // solo, ma «resta dentro» deve dirlo — non ignorare in silenzio un file emesso
    // per un altro destinatario. La fixture dichiara CF 80123450158.
    $condominio = Condominio::factory()->create([
        'nome' => 'Condominio Via Roma 1',
        'codice_fiscale' => '99999999999',
    ]);

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_con_ritenuta.xml', 'fattura.xml')]
    );

    $risposta->assertStatus(422);
    expect($risposta->json('errore'))
        ->toContain('80123450158')
        ->toContain('Condominio Via Roma 1');
});

test('lo stesso codice fiscale (a maiuscole/spazi diversi) non fa scattare il rifiuto', function () {
    $condominio = Condominio::factory()->create([
        'codice_fiscale' => ' 80123450158 ',
    ]);

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_con_ritenuta.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
});

test('un condominio senza codice fiscale in anagrafica non blocca l\'importazione', function () {
    // Controprova mirata: rifiutare quando l'informazione manca (invece che quando è
    // positivamente diversa) scarterebbe file legittimi per una domanda a cui il
    // sistema stesso non sa rispondere.
    $condominio = Condominio::factory()->create(['codice_fiscale' => null]);

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('sintetica_con_ritenuta.xml', 'fattura.xml')]
    );

    $risposta->assertOk();
});

test('un file con estensione non ammessa viene rifiutato', function () {
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => UploadedFile::fake()->create('fattura.exe', 10)]
    );

    $risposta->assertStatus(422);
    $risposta->assertJsonValidationErrors(['file']);
});

test('il contributo cassa previdenziale arriva come riga di spesa, e la fattura torna intera', function () {
    // ⚠️ **Il difetto più grave della beta.14, trovato dalla Fase 1-bis (reperto 1).**
    // Il contributo vive in `DatiGeneraliDocumento`, non nelle `DettaglioLinee`:
    // mappando solo le righe, di questa parcella di geometra da € 4.099,20 se ne
    // registravano € 3.904,00 — € 195,20 in meno, **in silenzio**, perché il giorno
    // prima la spia «le righe non quadrano» era stata spenta sottraendo il contributo
    // dallo scarto invece di aggiungerlo alle righe. Fixture reale, file 10 del
    // collaudo sugli undici XML del forum.
    $condominio = Condominio::factory()->create(['codice_fiscale' => '30101010103']);

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('collaudo_reali/10-TD01-cassa-previdenziale-TC03-fatture-collegate.xml', 'f.xml')]
    );

    $risposta->assertOk();
    $righe = $risposta->json('righe');

    // Tre righe dal file + una per il contributo.
    expect($righe)->toHaveCount(4);

    $contributo = $righe[3];
    expect($contributo['importo_imponibile'])->toBe(160)
        // L'aliquota è quella del BLOCCO cassa, non ereditata dalle righe.
        // (`22` e non `22.0`: il round-trip JSON consegna un numero intero-di-valore
        // senza il decimale, come già annotato per `importo_imponibile` qui sopra.)
        ->and($contributo['aliquota_iva'])->toBe(22)
        ->and($contributo['descrizione'])->toContain('Contributo cassa previdenziale')
        ->and($contributo['descrizione'])->toContain('5%')
        ->and($contributo['descrizione'])->toContain('TC03');

    // La somma delle righe è ora l'imponibile che la fattura dichiara di sé: è questo
    // che chiude il buco da € 195,20 (imponibile + la sua IVA).
    expect(array_sum(array_column($righe, 'importo_imponibile')))->toBe(3360);
});

test('il contributo cassa NON entra nella base della ritenuta, così l\'F24 resta corretto', function () {
    // ⚠️ **La riga che tiene in piedi l'F24.** `concorre_base_ritenuta` vale `true` per
    // difetto ovunque (`RitenutaService::baseImponibile()` con `?? true`, e nel frontend
    // con `!== false`): lasciando la riga del contributo al default, la base salirebbe
    // da € 3.200,00 a € 3.360,00 — si verserebbe all'Erario più del dovuto e si
    // pagherebbe al professionista di meno. Prima di questa correzione l'F24 era giusto
    // solo per omissione, perché il contributo non entrava affatto nel sistema.
    //
    // Il docblock di `baseImponibile()` cita il «contributo cassa professionale» come
    // il caso per cui quel flag è nato: qui si prova che ci arriva davvero.
    $condominio = Condominio::factory()->create(['codice_fiscale' => '30101010103']);

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('collaudo_reali/10-TD01-cassa-previdenziale-TC03-fatture-collegate.xml', 'f.xml')]
    );

    expect($risposta->json('righe.3.concorre_base_ritenuta'))->toBeFalse();

    // Controprova sul motore che quel numero lo consuma davvero, non su un intermedio:
    // si passano le righe come arrivano dall'endpoint e si guarda la base che finirà
    // nel calcolo della ritenuta, cioè nell'F24.
    // Niente factory: `Fornitore` non ne ha una — si costruisce come negli altri test
    // del gestionale (vedi GateSaldiLiberiTest).
    $fornitore = Fornitore::create([
        'ragione_sociale' => 'Geom. Prova',
        'partita_iva' => '01234567890',
        'soggetto_ritenuta' => true,
        'regime_forfetario' => false,
        'perc_ritenuta' => 20,
        'perc_imponibile_ritenuta' => 100,
    ]);
    $calcolo = app(App\Services\Gestionale\RitenutaService::class)->calcola(
        $fornitore,
        array_map(fn ($r) => [
            'importo_imponibile' => (int) round($r['importo_imponibile'] * 100),
            'concorre_base_ritenuta' => $r['concorre_base_ritenuta'] ?? true,
        ], $risposta->json('righe')),
        0,
        true,
        now(),
    );

    expect($calcolo->baseImponibile)->toBe(320000); // € 3.200,00, non € 3.360,00
});

test('un contributo cassa a zero non genera una riga da compilare', function () {
    // Esiste davvero (fixture reale): un blocco dichiarato con importo 0,00. Generare
    // la riga costringerebbe l'amministratore ad assegnare un capitolo a un importo che
    // non c'è — la validazione lo pretende per ogni riga non «fuori preventivo».
    $condominio = Condominio::factory()->create();

    $risposta = $this->actingAs($this->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => fileFixtura('reale_anonimizzata_cassa_previdenziale.xml', 'f.xml')]
    );

    expect($risposta->json('righe'))->toHaveCount(1);
});

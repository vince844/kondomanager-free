<?php

/**
 * beta.45 — Il saldo di apertura scritto in modifica finisce a giornale.
 *
 * Il percorso è riproducibile con il mouse, oggi: **crea una cassa con il saldo di apertura
 * vuoto** — `RegistraAperturaCassaAction` esce subito su `importo === 0` e non registra
 * niente, correttamente — **poi modificala e scrivi 5.000**. `UpdateCassaAction` salva la
 * colonna e non chiama mai l'azione: colonna piena, giornale vuoto, Stato Patrimoniale
 * sbilanciato esattamente di quell'importo.
 *
 * Da quel momento la cassa è nello stato in cui si trovano i condomìni demo 28 e 29, e appena
 * prende un movimento operativo la guardia blocca perfino la sola **ridigitazione** del
 * valore: il vicolo cieco si chiude alle spalle.
 *
 * ## Perché la beta.26 non l'aveva visto
 *
 * Quella beta ha messo mano a questa identica guardia, e ha guardato una direzione sola.
 * Correggeva il doppio conteggio — il campo restava modificabile *dopo* che l'apertura era
 * già a giornale — introducendo `hasAperturaRegistrata()`. È il caso **speculare** del
 * nostro: là si riscriveva la colonna con l'apertura già registrata, qui si scrive la colonna
 * con l'apertura mai registrata. Stesso campo, stesso metodo, stesso effetto sullo Stato
 * Patrimoniale, verso opposto.
 *
 * ## «Registrare com'è» e «cambiare il valore» sono due cose diverse
 *
 * La guardia vieta di *modificare* il saldo di apertura su una cassa con movimenti, ed è
 * giusto. Ma finiva per vietare anche di *portare a giornale* un valore che resta identico —
 * operazione che non sposta un centesimo e che è l'unica via d'uscita per le casse già in
 * quello stato. I due casi ora si distinguono.
 */

use App\Actions\Cassa\UpdateCassaAction;
use App\Enums\TipoMovimentoContabile;
use App\Models\Gestionale\Cassa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/** Una cassa con il suo conto di liquidità, creata senza passare dall'azione di creazione. */
function cassaSenzaApertura(int $condominioId, int $saldoCents = 0): Cassa
{
    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'ruolo' => 'conto_bancario',
        'codice' => '1010.'.uniqid(),
        'nome' => 'Banca '.uniqid(),
        'tipo' => 'attivo',
        'categoria' => 'liquidita',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return Cassa::create([
        'condominio_id' => $condominioId,
        'nome' => 'Conto corrente condominiale',
        'tipo' => 'banca',
        'conto_contabile_id' => $contoId,
        'saldo_iniziale' => $saldoCents,
        'attiva' => true,
    ]);
}

/** Il payload che il form di modifica manda: gli importi arrivano come stringhe mascherate. */
function datiModificaCassa(Cassa $cassa, ?string $saldo = null): array
{
    return array_filter([
        'nome' => $cassa->nome,
        'tipo' => $cassa->tipo,
        'descrizione' => null,
        'saldo_iniziale' => $saldo,
    ], fn ($v) => $v !== null);
}

function apertureDi(Cassa $cassa): int
{
    return DB::table('righe_scritture as rs')
        ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
        ->where('rs.conto_contabile_id', $cassa->conto_contabile_id)
        ->where('sc.tipo_movimento', TipoMovimentoContabile::APERTURA->value)
        ->count();
}

test('il saldo di apertura scritto in modifica finisce a giornale', function () {
    // Il percorso della segnalazione, passo per passo: cassa nata vuota, importo aggiunto dopo.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 0);

    expect(apertureDi($cassa))->toBe(0);

    app(UpdateCassaAction::class)->execute($cassa, datiModificaCassa($cassa, '5.000,00'));

    expect(apertureDi($cassa))->toBe(1)
        // Il dato si SPOSTA: mai in due posti insieme, o il saldo verrebbe contato due volte.
        ->and((int) $cassa->fresh()->saldo_iniziale)->toBe(0);
});

test('la scrittura porta l\'importo giusto, nel verso giusto', function () {
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 0);

    app(UpdateCassaAction::class)->execute($cassa, datiModificaCassa($cassa, '5.000,00'));

    $riga = DB::table('righe_scritture as rs')
        ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
        ->where('rs.conto_contabile_id', $cassa->conto_contabile_id)
        ->where('sc.tipo_movimento', TipoMovimentoContabile::APERTURA->value)
        ->first(['rs.importo', 'rs.tipo_riga']);

    expect((int) $riga->importo)->toBe(500000)
        ->and($riga->tipo_riga)->toBe('dare');
});

test('ri-salvare senza cambiare l\'importo porta a giornale una cassa già in quello stato', function () {
    // È la via d'uscita per le casse che ci sono già finite: «registrare com'è» non sposta
    // un centesimo, e prima era vietato insieme al cambio di valore.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 500000);

    app(UpdateCassaAction::class)->execute($cassa, datiModificaCassa($cassa, '5.000,00'));

    expect(apertureDi($cassa))->toBe(1)
        ->and((int) $cassa->fresh()->saldo_iniziale)->toBe(0);
});

test('un salvataggio che non tocca il campo lascia comunque il giornale in ordine', function () {
    // Il form rimanda tutti i campi a ogni salvataggio, anche quelli non toccati: la
    // registrazione non deve dipendere dal fatto che l'utente abbia scritto in quella casella.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 500000);

    app(UpdateCassaAction::class)->execute($cassa, datiModificaCassa($cassa));

    expect(apertureDi($cassa))->toBe(1);
});

test('non registra due volte l\'apertura', function () {
    // `RegistraAperturaCassaAction` è già idempotente, ma la guardia va esercitata da qui:
    // è il punto in cui un doppio salvataggio è normale, non un caso di laboratorio.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 0);

    app(UpdateCassaAction::class)->execute($cassa, datiModificaCassa($cassa, '5.000,00'));
    app(UpdateCassaAction::class)->execute($cassa->fresh(), datiModificaCassa($cassa, '5.000,00'));

    expect(apertureDi($cassa))->toBe(1);
});

test('una cassa a saldo zero non produce nessuna scrittura', function () {
    // Controprova: la maggior parte delle casse nasce e resta a zero, e il giornale non deve
    // riempirsi di aperture da zero euro.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 0);

    app(UpdateCassaAction::class)->execute($cassa, datiModificaCassa($cassa, '0,00'));

    expect(apertureDi($cassa))->toBe(0);
});

/**
 * Passo 1 — la rotta che mancava alla diagnosi.
 *
 * L'azione era già scritta, transazionale e idempotente, e aveva **un solo chiamante**: la
 * creazione della cassa. Dal widget del Libro Giornale non c'era modo di invocarla, e la
 * pagina a cui la diagnosi rimandava non offre nessun pulsante che la chiami — l'unico che
 * c'è passa da `UpdateCassaAction`, che fino alla beta.45 l'apertura non la chiamava mai.
 *
 * I sei esiti possibili si dividono in **tre risposte**: fatto, non c'era niente da fare,
 * manca qualcosa e ti dico cosa. Un unico «non è stato possibile» avrebbe spostato il vicolo
 * cieco di un passo.
 */

use App\Enums\EsitoAperturaCassa;
use Spatie\Permission\Models\Permission;

function utenteApertura(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');

    return $user;
}

test('la rotta porta a giornale il saldo di una cassa rimasta indietro', function () {
    // Il caso reale dei condomìni demo: colonna piena, giornale vuoto, e nessun modo di
    // rimediare dall'interfaccia.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 500000);

    $this->actingAs(utenteApertura())
        ->post(route('admin.gestionale.casse.registra-apertura', [$condominio->id, $cassa->id]))
        ->assertRedirect();

    expect(apertureDi($cassa))->toBe(1)
        ->and((int) $cassa->fresh()->saldo_iniziale)->toBe(0);
});

test('una cassa di un altro condominio non si tocca', function () {
    [$condominio] = setupContabile();
    [$altro] = setupContabile();
    $cassa = cassaSenzaApertura($altro->id, 500000);

    // ⚠️ **404 e non 403, dalla beta.66.** La guardia a mano nel controller c'è ancora e
    // risponderebbe 403, ma non ci si arriva più: la rotta è vincolata al condominio nell'indirizzo
    // (`scopeBindings()`), quindi la cassa viene cercata **dentro** quel condominio e non si trova.
    // È il rifiuto migliore dei due, perché non conferma nemmeno che quella cassa esista.
    $this->actingAs(utenteApertura())
        ->post(route('admin.gestionale.casse.registra-apertura', [$condominio->id, $cassa->id]))
        ->assertNotFound();

    expect(apertureDi($cassa))->toBe(0);
});

test('«già a posto» non viene presentato come un fallimento', function () {
    // Se il messaggio di uno stato corretto ha la faccia di un errore, si impara a diffidare
    // anche di quelli veri.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 0);

    $risposta = $this->actingAs(utenteApertura())
        ->post(route('admin.gestionale.casse.registra-apertura', [$condominio->id, $cassa->id]));

    // Il trait annida: `with('message' => ['type' => ..., 'message' => ...])`.
    $flash = $risposta->getSession()->get('message');

    expect($flash['type'])->toBe('info')
        ->and($flash['message'])->toContain('niente da fare');
});

test('i sei esiti sono distinti, e due non sono errori', function () {
    // Il conto esatto, fissato qui perché la roadmap ne dichiarava quattro: teneva insieme
    // esercizio e contropartita, che sono due mancanze con due rimedi diversi.
    expect(EsitoAperturaCassa::cases())->toHaveCount(6);

    $nonErrori = collect(EsitoAperturaCassa::cases())->filter(fn ($e) => $e->giaAPosto());

    expect($nonErrori->pluck('value')->all())->toBe(['importo_zero', 'gia_registrata']);
});

test('ogni esito dice cosa manca, non solo che non si può', function () {
    // Un messaggio che descrive un ostacolo senza dire come si supera lascia chi legge
    // esattamente dov'era.
    foreach (EsitoAperturaCassa::cases() as $esito) {
        expect(strlen($esito->messaggio()))->toBeGreaterThan(40);
    }

    expect(EsitoAperturaCassa::CONTROPARTITA_MANCANTE->messaggio())->toContain('Fondo Passate Gestioni')
        ->and(EsitoAperturaCassa::ESERCIZIO_MANCANTE->messaggio())->toContain('esercizio')
        ->and(EsitoAperturaCassa::CONTO_MANCANTE->messaggio())->toContain('Risorse e fondi');
});

test('senza il conto Fondo Passate Gestioni l\'esito lo dice', function () {
    // Distinguere questa mancanza da quella dell'esercizio è metà del senso del Passo 1.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 500000);

    DB::table('conti_contabili')->where('condominio_id', $condominio->id)
        ->where('ruolo', 'passate_gestioni')->delete();

    expect(app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($cassa))
        ->toBe(EsitoAperturaCassa::CONTROPARTITA_MANCANTE);
});

/**
 * Passo 3 — la diagnosi era cieca su metà dei casi.
 *
 * `StatoPatrimonialeService` somma **tutti** i saldi di apertura non ancora a giornale,
 * negativi compresi: un conto scoperto entra nell'Attivo con il suo segno e produce sbilancio
 * esattamente come uno positivo. La diagnosi però cercava `saldo_iniziale > 0`, quindi quella
 * cassa non compariva fra le cause e lo sbilancio finiva nel ramo «causa non nota» — cioè
 * l'unico che non offre niente da fare.
 *
 * Non è un caso di frontiera: `RegistraAperturaCassaAction` gestisce esplicitamente il saldo
 * negativo invertendo i versi, quindi il conto scoperto è previsto dal modello contabile.
 */
test('una cassa con apertura negativa compare nella diagnosi', function () {
    [$condominio, $esercizio] = setupContabile();
    cassaSenzaApertura($condominio->id, -150000);

    $risposta = $this->actingAs(utenteApertura())
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio->id, $esercizio->id]));

    $risposta->assertOk();

    $casse = data_get($risposta->viewData('page')['props'], 'diagnosi.casse_senza_apertura', []);

    expect($casse)->toHaveCount(1)
        ->and($casse[0]['saldo_iniziale'])->toBe(-150000);
});

test('una cassa a zero non entra nella diagnosi', function () {
    // Controprova: la diagnosi deve elencare le cause, non tutte le casse. Se segnalasse
    // anche quelle a posto, l'elenco diventerebbe rumore da ignorare.
    [$condominio, $esercizio] = setupContabile();
    cassaSenzaApertura($condominio->id, 0);

    $risposta = $this->actingAs(utenteApertura())
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio->id, $esercizio->id]));

    // `diagnosi` è null quando lo Stato Patrimoniale quadra — le query girano solo quando
    // servono — e una cassa a zero non lo sbilancia: entrambe le strade portano a «vuoto».
    expect(data_get($risposta->viewData('page')['props'], 'diagnosi.casse_senza_apertura', []))->toBeEmpty();
});

test('la cassa sparisce dalla diagnosi quando l\'apertura è registrata', function () {
    // Il ciclo completo: la diagnosi la nomina, il pulsante la cura, la diagnosi tace.
    [$condominio, $esercizio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 500000);

    $this->actingAs(utenteApertura())
        ->post(route('admin.gestionale.casse.registra-apertura', [$condominio->id, $cassa->id]));

    $risposta = $this->actingAs(utenteApertura())
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio->id, $esercizio->id]));

    expect(data_get($risposta->viewData('page')['props'], 'diagnosi.casse_senza_apertura', []))->toBeEmpty();
});

/**
 * Passo 4 — la scorciatoia che l'interfaccia rendeva conveniente.
 *
 * Finché non è esistito un modo di registrare l'apertura, l'unico gesto che faceva tornare
 * verde il bollino dello Stato Patrimoniale era **eliminare la cassa**. E funzionava: `casse`
 * non ha `deleted_at`, quindi è una cancellazione vera; `righe_scritture.cassa_id` è
 * `nullOnDelete`, quindi le scritture restano bilanciate; e la liquidità non contabilizzata
 * spariva insieme alla riga. Il bollino diventava verde e nessuno aveva sistemato niente.
 *
 * Il controllo era scritto nel controller e **commentato**, con la nota «DA IMPLEMENTARE
 * QUANDO AVREMO I MOVIMENTI». I movimenti ci sono da un pezzo.
 */
test('una cassa con saldo di apertura non registrato non si elimina', function () {
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 500000);

    $this->actingAs(utenteApertura())
        ->delete(route('admin.gestionale.casse.destroy', [$condominio->id, $cassa->id]));

    expect(Cassa::find($cassa->id))->not->toBeNull();
});

test('il divieto dice perché e come uscirne', function () {
    // Un divieto muto è il difetto che la beta.34 era nata per togliere: il messaggio deve
    // nominare l'importo in gioco e la via d'uscita.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 500000);

    $motivo = $cassa->motivoBloccoEliminazione();

    expect($motivo)->toContain('5.000,00')
        ->and($motivo)->toContain('Registra prima');
});

/** Un movimento operativo sulla cassa: qualunque scrittura che non sia l'apertura. */
function movimentoOperativoSu(Cassa $cassa, $condominio, $esercizio): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'data_registrazione' => '2026-03-01', 'data_competenza' => '2026-03-01',
        'numero_protocollo' => 'MOV-'.uniqid(), 'causale' => 'Movimento',
        'tipo_movimento' => 'rettifica', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('righe_scritture')->insert([
        'scrittura_id' => $scritturaId,
        'conto_contabile_id' => $cassa->conto_contabile_id,
        'cassa_id' => $cassa->id,
        'tipo_riga' => 'dare', 'importo' => 5000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('una cassa con movimenti operativi non si elimina', function () {
    [$condominio, $esercizio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 0);
    movimentoOperativoSu($cassa, $condominio, $esercizio);

    $this->actingAs(utenteApertura())
        ->delete(route('admin.gestionale.casse.destroy', [$condominio->id, $cassa->id]));

    expect(Cassa::find($cassa->id))->not->toBeNull()
        ->and($cassa->motivoBloccoEliminazione())->toContain('disattivala');
});

test('una cassa vuota e senza movimenti si elimina ancora', function () {
    // Controprova: la guardia non deve trasformare l'eliminazione in un'operazione
    // impossibile. Una risorsa creata per sbaglio e mai usata va via come prima.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 0);

    expect($cassa->motivoBloccoEliminazione())->toBeNull();

    $this->actingAs(utenteApertura())
        ->delete(route('admin.gestionale.casse.destroy', [$condominio->id, $cassa->id]));

    expect(Cassa::find($cassa->id))->toBeNull();
});

test('dopo aver registrato l\'apertura la cassa resta protetta dai suoi movimenti', function () {
    // Il caso che chiude il cerchio: registrata l'apertura la colonna torna a zero, quindi il
    // secondo motivo cade — ma la scrittura di apertura da sola non è un movimento operativo,
    // e una cassa con la sola apertura deve poter essere eliminata se davvero non serve.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 500000);

    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($cassa);

    expect($cassa->fresh()->motivoBloccoEliminazione())->toBeNull();
});

test('la scrittura di apertura ha un protocollo suo', function () {
    // Cadeva nel `default` del match dei prefissi, cioè in `SCR` — che significa «scrittura
    // non classificata». Il protocollo si scrive una volta sola: andava deciso prima di
    // esporre il pulsante, non dopo aver riempito il giornale.
    [$condominio] = setupContabile();
    $cassa = cassaSenzaApertura($condominio->id, 500000);

    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($cassa);

    $protocollo = DB::table('righe_scritture as rs')
        ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
        ->where('rs.conto_contabile_id', $cassa->conto_contabile_id)
        ->where('sc.tipo_movimento', TipoMovimentoContabile::APERTURA->value)
        ->value('sc.numero_protocollo');

    expect($protocollo)->toStartWith('APE-')
        ->and($protocollo)->not->toStartWith('SCR-');
});

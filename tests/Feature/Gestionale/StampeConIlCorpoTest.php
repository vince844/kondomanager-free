<?php

use App\Models\Anagrafica;
use App\Models\Immobile;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__ . '/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Le stampe rispondono con il PDF nel corpo (1.11.0-beta.28, Coda 151).
 *
 * `Output(..., 'I')` di mPDF scrive il PDF sull'output buffer e torna una stringa vuota: il browser
 * riceve il file dall'echo, ma il corpo della risposta Laravel è vuoto e un test sullo status resta
 * verde su zero byte — o, dove un test HTTP non c'era (estratto conto, F24), nessuno lo guardava.
 * La beta.23 l'ha tolta dal Libro Giornale, la .27 dalle tre stampe del piano rate; qui si chiude
 * l'ultima, l'estratto conto dell'anagrafica (le altre tre stanno nei loro file: `StampePDFTest`,
 * `GeneraDelegheF24Test`). La distinta del pagamento fornitore non aveva il difetto — `streamDownload`
 * porta i byte — e qui si pinna solo il nome, che usciva col prefisso raddoppiato.
 */
function utenteCheStampa(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

/** Il corpo di una risposta, sia normale sia in streaming. */
function corpoDellaRisposta($risposta): string
{
    return $risposta->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
        ? $risposta->streamedContent()
        : (string) $risposta->getContent();
}

test('l\'estratto conto dell\'anagrafica risponde con il PDF nel corpo e con un nome di file parlante', function () {
    [$condominio, $esercizio] = setupPagamentiHttp();
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => 'Int 1', 'descrizione' => 'Appartamento di prova', 'interno' => '1',
        'foglio' => '1', 'particella' => '1', 'subalterno' => '1',
    ]);
    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Mario Rossi', 'email' => 'mario.rossi@kondomanager.test',
        'indirizzo' => 'Via di prova 1', 'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'RSSMRA70A01H501U',
    ]);
    $immobile->anagrafiche()->attach($anagrafica->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-01-01']);
    // La rotta è scoped: l'anagrafica si cerca dentro `Condominio::anagrafiche()`, che è la pivot.
    $anagrafica->condomini()->syncWithoutDetaching([$condominio->id]);

    $risposta = $this->actingAs(utenteCheStampa())
        ->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$condominio, $anagrafica]));

    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(strlen($risposta->getContent()))->toBeGreaterThan(1000)
        ->and($risposta->getContent())->toStartWith('%PDF')
        // Prima: «EC_Mario_Rossi_Esercizio anno 2026.pdf», con gli spazi dell'esercizio dentro il nome.
        ->and($risposta->headers->get('Content-Disposition'))
            ->toBe('inline; filename="estratto-conto-'.\Illuminate\Support\Str::slug($condominio->nome).'-'.$esercizio->data_inizio->format('Y').'-mario-rossi.pdf"');
});

test('la distinta del pagamento fornitore scarica con il PDF nel corpo e con un nome di file parlante', function () {
    $ctx = setupPagamentiHttp();
    [$condominio] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);
    $pagamento = (new PagamentoFornitoreService())->registraPagamento(datiPagamento($ctx, $fattura), $condominio->id);

    $risposta = $this->actingAs(utenteCheStampa())
        ->get(route('admin.gestionale.pagamenti-fornitori.distinta', [$condominio, $pagamento]));

    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $corpo = corpoDellaRisposta($risposta);
    expect(strlen($corpo))->toBeGreaterThan(1000)
        ->and($corpo)->toStartWith('%PDF')
        // `streamDownload` lascia il nome senza virgolette quando è solo ASCII: si guarda il nome, non
        // la punteggiatura. Prima usciva «Distinta_PAG-PAG-2026-00001_…»: il protocollo porta già il prefisso.
        ->and($risposta->headers->get('Content-Disposition'))->toMatch('/^attachment; filename="?distinta-pagamento-pag-\d{4}-\d+-\d{8}\.pdf"?$/');
});

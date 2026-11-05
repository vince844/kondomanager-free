<?php

use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * Che cosa il programma ricorda delle scelte dell'amministratore, e che cosa no. Si ricorda ciò che si ripete (lo stato del bollo, la causale, gli
 * indirizzi, l'autorizzazione del bollo in modo virtuale); non si ricorda ciò che è di quel foglio: la nota è della ricevuta su cui si scrive, e la
 * marca del contrassegno è messa su un foglio solo. Con la soglia del bollo e con l'originale: quale emissione è l'originale, su che cosa si guarda
 * la soglia.
 *
 * Che cosa NON copre: i testi a video (nei test JavaScript).
 */
function stampaScelta($test, object $s, $utente, array $campi)
{
    return $test->actingAs($utente)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), $campi);
}

function opzioniScelta($test, object $s, $utente, $incasso): array
{
    return $test->actingAs($utente)
        ->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))
        ->assertOk()->json();
}

test('la nota non si ricorda: è della ricevuta su cui si scrive, e quella scritta per una persona non finisce sulla ricevuta di un\'altra', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $perAnna = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $quotaDiCarlo = quotaDaPagareDiCarlo($s, 3, 10000);
    $perCarlo = incassaPerRicevuta($s, $s->carlo, [[$quotaDiCarlo, 100.00]], '2026-02-02', 100.00);

    stampaScelta($this, $s, $u, ['incassi' => [$perAnna->id], 'bollo' => 'assolta', 'nota' => 'Contanti consegnati a mano dal figlio'])->assertOk();

    // Né la finestra di un incasso, né la pagina delle ricevute, né il servizio che le ripropone ricordano la nota.
    expect(app(EmissioneRicevute::class)->sceltaRicordata($s->condominio, $u))->not->toHaveKey('nota')
        ->and(opzioniScelta($this, $s, $u, $perCarlo)['scelta_ricordata'])->not->toHaveKey('nota')
        ->and($this->actingAs($u)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id]))->viewData('page')['props']['scelta_ricordata'])
        ->not->toHaveKey('nota');

    // Confermando le scelte che la pagina propone (il bollo ricordato, e nessuna nota), la ricevuta di Carlo non porta la nota di Anna.
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia', ['condominio' => $s->condominio->id]), [
        'incassi' => [$perCarlo->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'assolta',
    ])->assertOk();
    $riga = RegistroInvio::where('documentabile_id', $perCarlo->id)->sole();
    expect($riga->dati)->not->toHaveKey('nota');
});

test('«assolta» con il contrassegno non si ripropone, perché la marca è su un foglio solo; con il modo virtuale sì, e lo stato non dovuto e da assolvere restano', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $sopra = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $sotto = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-02', 60.00);
    $ricordata = fn () => app(EmissioneRicevute::class)->sceltaRicordata($s->condominio, $u);

    stampaScelta($this, $s, $u, ['incassi' => [$sopra->id], 'bollo' => 'assolta', 'modo_bollo' => 'contrassegno', 'estremi_bollo' => '01170512345678'])->assertOk();
    expect($ricordata())->toMatchArray(['bollo_sopra' => null, 'bollo_sotto' => null, 'modo_bollo' => null, 'estremi_bollo' => null]);

    // Un'altra scelta per le ricevute sotto la soglia: quella si ricorda, e la soglia sopra resta senza proposta.
    stampaScelta($this, $s, $u, ['incassi' => [$sotto->id], 'bollo' => 'da_assolvere'])->assertOk();
    expect($ricordata())->toMatchArray(['bollo_sopra' => null, 'bollo_sotto' => 'da_assolvere', 'modo_bollo' => null]);

    // Assolta senza dire come: neanche questa è l'autorizzazione di un modo che si ripete.
    stampaScelta($this, $s, $u, ['incassi' => [$sopra->id], 'bollo' => 'assolta'])->assertOk();
    expect($ricordata())->toMatchArray(['bollo_sopra' => null, 'modo_bollo' => null]);

    stampaScelta($this, $s, $u, ['incassi' => [$sopra->id], 'bollo' => 'assolta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'autorizzazione n. 12 del 10/01/2020'])->assertOk();
    expect($ricordata())->toMatchArray(['bollo_sopra' => 'assolta', 'bollo_sotto' => 'da_assolvere', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'autorizzazione n. 12 del 10/01/2020']);

    // Dopo un contrassegno la proposta torna vuota, anche se prima c'era il modo virtuale.
    stampaScelta($this, $s, $u, ['incassi' => [$sopra->id], 'bollo' => 'assolta', 'modo_bollo' => 'contrassegno'])->assertOk();
    expect($ricordata())->toMatchArray(['bollo_sopra' => null, 'modo_bollo' => null, 'estremi_bollo' => null]);
});

test('la soglia del bollo si guarda sul solo denaro versato, non sul credito usato, e la finestra dice quanto è credito', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    // Anna versa € 200,00 sulla rata 1 da € 150,00: € 50,00 restano a credito sulla quota.
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-01-10', 200.00);
    // La rata 2 (€ 120,50) si paga con € 50,00 di quel credito e € 70,50 di denaro: la ricevuta copre € 120,50, ma il denaro è sotto € 77,47.
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50], [$s->anna1->fresh(), -50.00]], '2026-02-01', 70.50);

    $opzioni = opzioniScelta($this, $s, $u, $incasso);

    expect($opzioni['importo'])->toBe(7050)
        ->and($opzioni['sopra_soglia'])->toBeFalse()
        ->and($opzioni['credito_usato'])->toBe(5000);

    // La stampa chiede il bollo per le ricevute «fino a € 77,47».
    stampaScelta($this, $s, $u, ['incassi' => [$incasso->id], 'bollo_sopra' => 'assolta'])->assertStatus(422)->assertSee('fino a € 77,47', false);
    stampaScelta($this, $s, $u, ['incassi' => [$incasso->id], 'bollo_sotto' => 'non_dovuta'])->assertOk();
    expect(RegistroInvio::sole()->dati['soglia'])->toBe('sotto');
});

test('una ricevuta in coda o non partita non è l\'originale: lo è la prima emissione stampata o partita, e le copie dopo dicono la sua data', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $giorno = fn (int $d) => now()->setDate(2026, 11, $d)->setTime(10, 0);

    $this->travelTo($giorno(2));
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();
    expect(opzioniScelta($this, $s, $u, $incasso)['copia'])->toBeNull();

    // Il 03/11 la stampa è la prima emissione uscita: è l'originale, non una copia.
    $this->travelTo($giorno(3));
    stampaScelta($this, $s, $u, ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();
    RegistroInvio::where('canale', 'email')->update(['stato' => 'non_partita', 'errore' => 'Rifiutato']);

    // Il 04/11 la stampa è una copia dell'originale del 03/11, non di un invio che non è mai partito.
    $this->travelTo($giorno(4));
    stampaScelta($this, $s, $u, ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();

    $righe = RegistroInvio::orderBy('id')->get();
    expect($righe[0]->canale)->toBe('email')
        ->and($righe[0]->dati)->not->toHaveKey('copia_del')
        ->and($righe[1]->dati)->not->toHaveKey('copia_del')
        ->and($righe[2]->dati['copia_del'])->toBe('2026-11-03')
        ->and(opzioniScelta($this, $s, $u, $incasso)['copia']['del'])->toBe('2026-11-03');
});

test('la finestra di un incasso manda della persona il nome e non l\'indirizzo postale', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    $opzioni = opzioniScelta($this, $s, amministratoreRicevute(), $incasso);

    expect(array_keys($opzioni['posizione']))->toBe(['id', 'nome']);
});

test('sul bollo i testi della beta dicono una frase generica e non affermano niente sulla legge', function () {
    // I tre changelog dell'amministratore: la frase, nella lingua, con l'etichetta della finestra in italiano.
    foreach (['it' => 'commercialista', 'en' => 'accountant', 'pt' => 'contabilista'] as $lingua => $professionista) {
        $voci = json_decode(file_get_contents(resource_path("data/changelogs/{$lingua}/1.12.0-beta.2.json")), true, 512, JSON_THROW_ON_ERROR)['features'];
        $testo = implode("\n", $voci);

        expect($testo)->toContain('«Importo sopra € 77,47»')
            ->and($testo)->toContain($professionista)
            ->and($testo)->not->toMatch('/di norma|as a rule|normally carries|em regra paga|lettura|a reading|leitura|€ 2,00|€ 2\.00|bollo di € 2/i');
    }

    // La voce pubblica della beta, in `docs/changelog.md`, fino alla beta.1.
    $pubblico = file_get_contents(base_path('docs/changelog.md'));
    $voce = substr($pubblico, (int) strpos($pubblico, '## [1.12.0-beta.2]'), (int) strpos($pubblico, '## [1.12.0-beta.1]') - (int) strpos($pubblico, '## [1.12.0-beta.2]'));
    $voce = preg_replace('/\s+/', ' ', $voce);

    expect($voce)->toContain('Se e come la ricevuta sconta il bollo lo decidi tu, con il tuo commercialista: il programma non lo calcola e non lo assolve.')
        ->and($voce)->toContain('«Importo sopra € 77,47»')
        ->and($voce)->not->toMatch('/di norma|lettura|€ 2,00/i');
});

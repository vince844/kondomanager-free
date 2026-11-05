<?php

use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * Un incasso delle versioni di prima con le quote di più persone e senza «Versato da»: la ricevuta non può dire per conto di chi, né a chi va.
 * Non si fa, e il programma lo dice con l'estratto conto di ciascuna persona. Sul database condiviso nessun incasso è così (0 su 42); il caso
 * esiste nei dati che le versioni vecchie potrebbero aver lasciato. Un incasso con «Versato da» che copre anche la rata di chi ha versato resta
 * una ricevuta.
 *
 * Che cosa NON copre: la pagina a video.
 */
function incassoConLeQuoteDiDuePersone(object $s): ScritturaContabile
{
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    // Una quota di Ugo legata allo stesso incasso, come la lasciava un incasso registrato con le regole di prima.
    DB::table('quota_scrittura')->insert([
        'scrittura_contabile_id' => $incasso->id, 'rate_quota_id' => $s->ugo1->id, 'importo_pagato' => 6000,
        'data_pagamento' => '2026-02-01', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $incasso->fresh();
}

test('il contenuto di un incasso con le quote di due persone senza «Versato da» lo dice: le persone, non una ricevuta', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassoConLeQuoteDiDuePersone($s);

    $contenuto = app(RicevutaIncasso::class)->per($incasso);

    expect(collect($contenuto['piu_persone'])->map(fn ($p) => [$p['id'], $p['nome']])->all())->toBe([[$s->anna->id, 'Anna Bruni'], [$s->ugo->id, 'Ugo Neri']]);
});

test('con «Versato da» le quote della posizione e di chi ha versato sono una ricevuta sola, e un incasso normale non ha persone da elencare', function () {
    $s = scenarioRicevutaIncasso();
    quotaDaPagareDiCarlo($s, 3, 10000);
    // Carlo versa € 200,00 per Anna: € 150,00 sulla rata 1 di Anna, € 50,00 di parte in più sulla rata 3 di Carlo.
    $perAltri = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 200.00, ['versato_da_id' => $s->carlo->id]);
    $normale = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);

    expect(app(RicevutaIncasso::class)->per($perAltri->fresh())['piu_persone'])->toBe([])
        ->and(app(RicevutaIncasso::class)->per($normale->fresh())['piu_persone'])->toBe([]);
});

test('la finestra non offre la ricevuta di un incasso con le quote di più persone, e indica l\'estratto conto di ciascuna', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassoConLeQuoteDiDuePersone($s);

    $opzioni = $this->actingAs(amministratoreRicevute())
        ->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->assertOk()->json();

    expect($opzioni['emettibile'])->toBeFalse()
        ->and($opzioni['motivo'])->toContain('Anna Bruni')->toContain('Ugo Neri')->toContain('estratto conto')->toContain('non dice chi ha versato')
        ->and(collect($opzioni['persone_dell_incasso'])->pluck('id')->all())->toBe([$s->anna->id, $s->ugo->id]);
});

test('la stampa, l\'anteprima del PDF e l\'invio di un incasso con le quote di più persone si rifiutano con il messaggio, e niente si scrive', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassoConLeQuoteDiDuePersone($s);
    $u = amministratoreRicevute();
    $c = ['condominio' => $s->condominio->id];

    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', $c), ['incassi' => [$incasso->id], 'bollo' => 'assolta'])
        ->assertStatus(422)->assertSee('estratto conto', false);
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.pdf-anteprima', $c), ['incassi' => [$incasso->id]])->assertStatus(422)->assertSee('Ugo Neri', false);
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia-una', $c + ['scrittura' => $incasso->id]), ['destinatari' => [$s->anna->id], 'bollo' => 'assolta'])
        ->assertStatus(422)->assertJsonFragment(['message' => 'Questo incasso copre le rate di più persone (Anna Bruni, Ugo Neri) e non dice chi ha versato: non si può fare una ricevuta sola. Per ciascuna persona c\'è l\'estratto conto.']);

    expect(RegistroInvio::count())->toBe(0);
    Notification::assertNothingSent();
});

test('nel blocco un incasso con le quote di più persone resta fuori dall\'elenco, dall\'anteprima e dall\'invio, e si conta a parte', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $strano = incassoConLeQuoteDiDuePersone($s);
    $buono = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    $u = amministratoreRicevute();
    $c = ['condominio' => $s->condominio->id];

    $elenco = $this->actingAs($u)->get(route('admin.gestionale.ricevute.index', $c + ['ricevuta' => 'tutte']))->viewData('page')['props'];
    $anteprima = $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.anteprima', $c), ['incassi' => [$strano->id, $buono->id], 'indirizzi' => ['email']])->assertOk()->json();
    $invio = $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia', $c), ['incassi' => [$strano->id, $buono->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'assolta'])
        ->assertOk()->json();

    expect(collect($elenco['incassi']['data'])->pluck('id')->all())->toBe([$buono->id])
        ->and($anteprima['ricevute'])->toBe(1)
        ->and($anteprima['piu_persone'])->toBe(1)
        ->and($anteprima['senza_versamento'])->toBe(0)
        ->and($invio['ricevute'])->toBe(1)
        ->and($invio['piu_persone'])->toBe(1)
        ->and(RegistroInvio::pluck('documentabile_id')->unique()->all())->toBe([$buono->id]);
});

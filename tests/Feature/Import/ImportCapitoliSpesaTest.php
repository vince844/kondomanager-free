<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\ImportBatch;
use App\Services\CondominioService;
use App\Services\Import\Canonical\CanonicalCapitolo;
use App\Services\Import\Canonical\CanonicalStrutturaSpese;
use App\Services\Import\Canonical\CanonicalVoceSpesa;
use App\Services\Import\ImportContext;
use App\Services\Import\Livelli\LivelloCapitoli;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Parser\BilancioConsuntivoParser;
use App\Services\Import\ReportRecognizer;
use App\Services\Import\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Il livello dei capitoli di spesa — «il buco» del §7 di `modelli_import_manuale.md`.
 *
 * Fino alla 1.11.0-beta.4 il `bilancio_consuntivo` veniva **riconosciuto e buttato**: finiva nel
 * ramo `default` di `ImportVerificaService`, che ne legge la testata e scarta il contenuto, perché
 * non esisteva un livello in cui i capitoli potessero atterrare.
 */
function condominioConGestione(): array
{
    $c = Condominio::create([
        'nome' => 'CONDOMINIO CON CAPITOLI',
        'codice_fiscale' => '97123456780',
        'indirizzo' => 'Via dei Capitoli 1',
    ]);

    $e = Esercizio::create([
        'condominio_id' => $c->id,
        'nome' => '2025/2026',
        'data_inizio' => '2025-11-01',
        'data_fine' => '2026-10-31',
        'stato' => 'aperto',
    ]);

    app(CondominioService::class)->createDefaultGestione($c, $e);

    return [$c, $e];
}

function importaCapitoli(Condominio $c, Esercizio $e, ?CanonicalStrutturaSpese $struttura): array
{
    $ctx = new ImportContext(ImportBatch::create(['sorgente' => 'danea']));

    if ($struttura !== null) {
        $ctx->conCanonico(LivelloCapitoli::CHIAVE, $struttura);
    }

    $ctx->risolvi(LivelloCondominio::CHIAVE, $c);
    $ctx->risolvi(LivelloEsercizi::CHIAVE, $e);

    return [(new LivelloCapitoli)->commit($ctx), $ctx];
}

function struttura(array $capitoli, ?int $totale = null, int $personali = 0): CanonicalStrutturaSpese
{
    return new CanonicalStrutturaSpese($capitoli, $totale, $personali);
}

function capitolo(string $nome, array $voci, ?int $totale = null): CanonicalCapitolo
{
    return new CanonicalCapitolo(
        $nome,
        array_map(fn (array $v) => new CanonicalVoceSpesa($v[0], $v[1]), $voci),
        $totale,
    );
}

it('scrive i capitoli e le loro voci nel piano dei conti della gestione', function () {
    [$c, $e] = condominioConGestione();

    [$esito] = importaCapitoli($c, $e, struttura([
        capitolo('AMMINISTRAZIONE', [['Compenso amministratore', 145200], ['Cancelleria', 21000]], 166200),
        capitolo('ASSICURAZIONE', [['Polizza globale fabbricato', 216700]], 216700),
    ]));

    expect($esito->riuscito())->toBeTrue()
        // due capitoli più tre voci
        ->and($esito->creati)->toBe(5);

    $piano = $c->gestioni()->first()->pianoConto;

    expect($piano)->not->toBeNull()
        // ⚠️ Senza l'anno: il piano appartiene alla gestione, non a un esercizio.
        ->and($piano->nome)->not->toContain('2025');

    $capitoli = Conto::where('piano_conto_id', $piano->id)->whereNull('parent_id')->orderBy('nome')->get();

    expect($capitoli->pluck('nome')->all())->toBe(['AMMINISTRAZIONE', 'ASSICURAZIONE'])
        ->and($capitoli->every(fn (Conto $x) => $x->is_capitolo === true))->toBeTrue();

    $voci = Conto::where('parent_id', $capitoli->firstWhere('nome', 'AMMINISTRAZIONE')->id)->get();

    expect($voci->pluck('nome')->all())->toBe(['Compenso amministratore', 'Cancelleria'])
        ->and($voci->every(fn (Conto $x) => $x->is_capitolo === false))->toBeTrue();
});

it('scrive gli importi a zero, e la cifra del consuntivo nella descrizione', function () {
    // ⚠️ `conti.importo` è il **fabbisogno** — quello che si chiede ai condòmini — mentre un
    // consuntivo è la fotografia di quanto si è speso l'anno prima. Scriverlo come importo
    // significherebbe deliberare un preventivo che nessuna assemblea ha approvato.
    [$c, $e] = condominioConGestione();

    importaCapitoli($c, $e, struttura([
        capitolo('AMMINISTRAZIONE', [['Compenso amministratore', 145200]], 145200),
    ]));

    $voce = Conto::where('nome', 'Compenso amministratore')->first();

    expect($voce->importo)->toBe(0)
        ->and($voce->descrizione)->toContain('1.452,00');
});

it('non scrive niente quando il lotto non porta un bilancio consuntivo', function () {
    // È il caso normale — la maggior parte delle importazioni non ha quel file — e **non** deve
    // diventare «si è fermata a Capitoli di spesa»: stando il livello terzo, fermerebbe anche
    // unità, persone e saldi.
    [$c, $e] = condominioConGestione();

    [$esito] = importaCapitoli($c, $e, null);

    expect($esito->riuscito())->toBeTrue()
        ->and($esito->giaAPosto())->toBeTrue()
        ->and($esito->prerequisitiMancanti)->toBe([]);
});

it('non importa un capitolo senza voci, e lo dice', function () {
    [$c, $e] = condominioConGestione();

    [$esito] = importaCapitoli($c, $e, struttura([capitolo('VUOTO', [], 5000)]));

    expect($esito->creati)->toBe(0)
        ->and($esito->saltati)->toBe(1)
        ->and(collect($esito->avvisi)->pluck('codice'))->toContain('capitoli.senza_voci');
});

it('non duplica un capitolo che nel piano c\'è già', function () {
    [$c, $e] = condominioConGestione();
    $s = struttura([capitolo('AMMINISTRAZIONE', [['Compenso', 100000]], 100000)]);

    importaCapitoli($c, $e, $s);
    [$secondo] = importaCapitoli($c, $e, $s);

    expect($secondo->creati)->toBe(0)
        ->and($secondo->saltati)->toBe(1)
        ->and(Conto::whereNull('parent_id')->where('nome', 'AMMINISTRAZIONE')->count())->toBe(1);
});

it('non lascia in archivio un piano dei conti quando si blocca', function () {
    // ⚠️ `EsitoCommit::bloccato()` è un **ritorno normale**, non un'eccezione: la transazione che
    // `ImportRunner` avvolge attorno al commit non viene annullata. Tutto ciò che può bloccare va
    // quindi risolto **prima** di scrivere, altrimenti restano in archivio righe non registrate.
    $c = Condominio::create([
        'nome' => 'CONDOMINIO SENZA GESTIONE',
        'codice_fiscale' => '97123456781',
        'indirizzo' => 'Via Sola 2',
    ]);

    $e = Esercizio::create([
        'condominio_id' => $c->id,
        'nome' => '2026',
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
        'stato' => 'aperto',
    ]);

    [$esito] = importaCapitoli($c, $e, struttura([capitolo('AMMINISTRAZIONE', [['Compenso', 100000]], 100000)]));

    expect($esito->riuscito())->toBeFalse()
        ->and(collect($esito->rilievi)->pluck('codice'))->toContain('capitoli.gestione_assente')
        // Niente scritto: né il piano né i conti.
        ->and(App\Models\Gestionale\PianoConto::count())->toBe(0)
        ->and(Conto::count())->toBe(0);
});

it('legge il file vero: quattro capitoli, diciassette voci, e la quadratura a zero', function () {
    // La fixture riproduce la forma dell'export di un amministratore: il totale del capitolo sulla
    // riga dell'**ultima** voce del gruppo, e «Spese personali» come capitolo senza voci.
    $fogli = (new SpreadsheetReader)->leggi(base_path('tests/Fixtures/import/danea/bilancio_consuntivo.xls'));
    $esito = (new ReportRecognizer)->riconosci($fogli[0]);

    expect($esito->tipo?->value)->toBe('bilancio_consuntivo');

    $letto = (new BilancioConsuntivoParser)->estrai($fogli[0], $esito->rigaIntestazione);
    $s = $letto['struttura'];

    expect($s->capitoli)->toHaveCount(4)
        ->and($s->voci())->toHaveCount(17)
        ->and($s->quadra())->toBeTrue()
        ->and($s->scartoCents())->toBe(0);

    // ⚠️ «Spese personali» non è fra i capitoli: è lo stesso denaro che importiamo già dentro i
    // saldi di apertura, e scriverlo qui lo conterebbe due volte.
    expect(collect($s->capitoli)->pluck('nome'))->not->toContain('Spese personali')
        ->and($s->spesePersonaliCents)->toBeGreaterThan(0)
        ->and(collect($letto['esito']->rilievi)->pluck('codice'))
        ->toContain('capitoli.spese_personali_non_importate');
});

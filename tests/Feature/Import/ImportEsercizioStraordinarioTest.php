<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ImportBatch;
use App\Services\CondominioService;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\ImportContext;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * **Un esercizio straordinario diventa una gestione, non un secondo anno contabile.**
 *
 * ⚠️ **Verificato sullo schema, non dedotto.** Il backup Firebird di Domustudio dice che là
 * l'esercizio non è una tabella a sé: è una riga di `TCONDOMINI`, con `DATAAPERTURA` e
 * `DATACHIUSURA` proprie, un `CONDGENID` che punta all'identità in `TCONDOMINI_GENERALE`, e una
 * colonna booleana **`STRAORDINARIO`** (`DEFAULT 0`). Un esercizio straordinario è quindi una riga
 * come le altre, con **periodo proprio** e **lo stesso condominio** dell'ordinario, esportata con
 * le stesse stampe: nel file l'unica differenza è la parola nella testata.
 *
 * Da noi lo stesso concetto è una gestione `straordinaria`, che ha date proprie e un legame
 * molti-a-molti con gli esercizi — un fondo lavori pluriennale ci sta dentro per costruzione.
 *
 * **Cosa succedeva prima**, misurato: si creava un **secondo esercizio** accavallato al primo,
 * entrambi `aperto` — contro l'invariante «al più uno aperto» su cui poggia
 * `HasEsercizio::getEsercizioCorrente()` — e la **stessa** gestione ordinaria veniva agganciata a
 * tutti e due. L'unica traccia del tipo era la parola «Straordinario» in una descrizione testuale.
 */
function condominioConOrdinario(): array
{
    $c = Condominio::create([
        'nome' => 'CONDOMINIO CON DUE GESTIONI',
        'codice_fiscale' => '97123456780',
        'indirizzo' => 'Via delle Due Gestioni 1',
    ]);

    $ordinario = Esercizio::create([
        'condominio_id' => $c->id,
        'nome' => '2025/2026',
        'data_inizio' => '2025-11-01',
        'data_fine' => '2026-10-31',
        'stato' => 'aperto',
    ]);

    app(CondominioService::class)->createDefaultGestione($c, $ordinario);

    return [$c, $ordinario];
}

function importaEsercizio(Condominio $c, CanonicalEsercizio $dati): array
{
    $ctx = new ImportContext(ImportBatch::create(['sorgente' => 'danea']));
    $ctx->conCanonico(LivelloEsercizi::CHIAVE, $dati);
    $ctx->risolvi(LivelloCondominio::CHIAVE, $c);

    return [(new LivelloEsercizi)->commit($ctx), $ctx];
}

function straordinario(string $inizio, string $fine, string $etichetta = 'FACCIATA 2026'): CanonicalEsercizio
{
    return new CanonicalEsercizio(
        etichetta: $etichetta,
        dataInizio: CarbonImmutable::parse($inizio),
        dataFine: CarbonImmutable::parse($fine),
        tipo: 'straordinario',
    );
}

it('non apre un secondo esercizio: mette lo straordinario in una gestione sua', function () {
    [$c, $ordinario] = condominioConOrdinario();

    // Periodo **proprio**, come in Danea: si accavalla all'ordinario ma non coincide.
    [$esito, $ctx] = importaEsercizio($c, straordinario('2026-01-01', '2026-12-31'));

    expect($esito->riuscito())->toBeTrue()
        ->and($esito->toArray()['creati'])->toBe(0);

    // Un solo esercizio: l'invariante «al più uno aperto» regge.
    expect(Esercizio::where('condominio_id', $c->id)->count())->toBe(1)
        ->and($ctx->risolto(LivelloEsercizi::CHIAVE)->id)->toBe($ordinario->id);

    // Due gestioni distinte, non una condivisa.
    $gestioni = $c->gestioni()->orderBy('tipo')->get();

    expect($gestioni->pluck('tipo')->all())->toBe(['ordinaria', 'straordinaria']);

    $straord = $gestioni->firstWhere('tipo', 'straordinaria');

    // Le date sono quelle **dello straordinario**, non dell'esercizio che lo ospita: è la ragione
    // per cui `gestioni` ha date proprie.
    expect($straord->data_inizio->toDateString())->toBe('2026-01-01')
        ->and($straord->data_fine->toDateString())->toBe('2026-12-31')
        ->and($straord->esercizi()->whereKey($ordinario->id)->exists())->toBeTrue();
});

it('dichiara la gestione straordinaria, così i saldi non finiscono nell\'ordinaria', function () {
    // ⚠️ `LivelloSaldi::gestione()` prendeva la più vecchia agganciata all'esercizio
    // (`orderBy('id')`): con due gestioni è **sempre** l'ordinaria, perché è nata prima. Senza
    // questa dichiarazione la gestione straordinaria sarebbe stata creata e mai usata.
    [$c] = condominioConOrdinario();

    [, $ctx] = importaEsercizio($c, straordinario('2026-01-01', '2026-12-31'));

    expect($ctx->risolto(LivelloEsercizi::GESTIONE)->tipo)->toBe('straordinaria');
});

it('sull\'ordinario dichiara la gestione ordinaria, e non ne inventa una seconda', function () {
    [$c, $ordinario] = condominioConOrdinario();

    [$esito, $ctx] = importaEsercizio($c, new CanonicalEsercizio(
        etichetta: '2025/2026',
        dataInizio: CarbonImmutable::parse('2025-11-01'),
        dataFine: CarbonImmutable::parse('2026-10-31'),
        tipo: 'ordinario',
    ));

    // Stesso periodo: è il duplicato, e senza decisione resta in attesa.
    expect($esito->riuscito())->toBeFalse();

    $ctx->conDecisioni([LivelloEsercizi::CHIAVE.':2025/2026' => 'salta']);

    (new LivelloEsercizi)->commit($ctx);

    expect($ctx->risolto(LivelloEsercizi::GESTIONE)->tipo)->toBe('ordinaria')
        ->and($c->gestioni()->count())->toBe(1)
        ->and(Esercizio::where('condominio_id', $ordinario->condominio_id)->count())->toBe(1);
});

it('si ferma se lo straordinario arriva prima dell\'esercizio che lo ospita', function () {
    // Uno straordinario è una gestione dentro un esercizio: senza esercizio non ha dove
    // appendersi. Meglio dirlo che aprire un anno contabile che l'amministratore non ha deliberato.
    $c = Condominio::create([
        'nome' => 'CONDOMINIO SENZA ORDINARIO',
        'codice_fiscale' => '97123456781',
        'indirizzo' => 'Via Sola 2',
    ]);

    [$esito] = importaEsercizio($c, straordinario('2026-01-01', '2026-12-31'));

    expect($esito->riuscito())->toBeFalse()
        ->and($c->gestioni()->count())->toBe(0)
        ->and(Esercizio::where('condominio_id', $c->id)->count())->toBe(0);

    $codici = collect($esito->rilievi)->pluck('codice');
    expect($codici)->toContain('esercizi.straordinario_senza_ordinario');
});

it('preferisce l\'esercizio aperto quando più d\'uno copre quel periodo', function () {
    [$c, $ordinario] = condominioConOrdinario();

    // Un esercizio chiuso che copre lo stesso periodo: non deve vincere lui.
    Esercizio::create([
        'condominio_id' => $c->id,
        'nome' => '2024/2025',
        'data_inizio' => '2024-11-01',
        'data_fine' => '2026-12-31',
        'stato' => 'chiuso',
    ]);

    [, $ctx] = importaEsercizio($c, straordinario('2026-01-01', '2026-12-31'));

    expect($ctx->risolto(LivelloEsercizi::CHIAVE)->id)->toBe($ordinario->id);
});

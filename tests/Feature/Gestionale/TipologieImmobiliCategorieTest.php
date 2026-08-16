<?php

/**
 * beta.53 — Le tipologie immobile dicono il vero sulla loro categoria.
 *
 * ⚠️ **Quattro nomi erano dichiarati due volte nel seeder, e `firstOrCreate` ha chiave sul solo
 * `nome`: vince la prima dichiarazione, la seconda non viene mai applicata.** Accertato sul
 * database reale il 15/08/2026.
 *
 * - `Magazzino` e `Deposito` erano dichiarati prima `unita_non_abitativa` e poi `pertinenza`:
 *   **non sono mai stati pertinenze su nessuna installazione**, malgrado il seeder lo dichiarasse.
 * - `Ufficio` era dichiarato prima `unita_abitativa` e poi `unita_non_abitativa`, e vinceva il
 *   primo. Un ufficio non è un'abitazione, e la distinzione serve alla comunicazione delle spese
 *   sulle parti comuni, dove l'abitazione principale e le sue pertinenze si accorpano.
 * - `Negozio` era duplicato con la stessa categoria: innocuo.
 *
 * Il difetto è invisibile a chi legge il seeder — le due righe stanno a venti righe di distanza,
 * in due blocchi diversi — e invisibile al database, dove il risultato sembra una scelta.
 *
 * ## Cosa questo file NON copre
 *
 * Non verifica che la categoria sia quella «giusta» in senso di dominio: `Magazzino` e `Deposito`
 * sono legittimamente pertinenza o unità autonoma a seconda dell'uso, e la categoria decide solo
 * quanto il campo «Pertinenza di» sarà in evidenza — non se si possa dichiarare il legame. Qui si
 * fissa che **non ci siano nomi doppi**, che è il difetto vero, e che `Ufficio` non torni fra le
 * abitazioni.
 */

use App\Models\Immobile;
use App\Models\TipologiaImmobile;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => test()->seed(TipologieImmobiliSeeder::class));

it('il seeder non contiene nomi ripetuti', function () {
    // ⚠️ **Il doppione non arriva mai a database** — `firstOrCreate` lo assorbe, e a database si
    // vede una riga sola che sembra una scelta. Cercarlo là non serve a niente: il difetto vive
    // nel **testo del seeder**, ed è lì che va cercato. È il motivo per cui questo test legge un
    // file sorgente, cosa che di norma non si fa.
    $sorgente = file_get_contents(database_path('seeders/TipologieImmobiliSeeder.php'));

    preg_match_all("/'nome'\s*=>\s*'([^']+)'/", $sorgente, $trovati);
    $nomi = $trovati[1];

    $doppi = array_keys(array_filter(array_count_values($nomi), fn ($n) => $n > 1));

    expect($doppi)->toBeEmpty(
        'Nomi dichiarati più volte nel seeder: '.implode(', ', $doppi)
        .'. `firstOrCreate` ha chiave sul solo nome, quindi la seconda dichiarazione non verrà mai applicata.'
    );
});

it('un ufficio non è un\'abitazione', function () {
    expect(TipologiaImmobile::where('nome', 'Ufficio')->value('categoria'))
        ->toBe('unita_non_abitativa');
});

it('le tipologie tipicamente pertinenziali sono classificate come tali', function () {
    // Le sole su cui il dominio non lascia dubbi: un box, una cantina, un posto auto o una
    // soffitta in un condominio servono un'altra unità, non stanno per conto proprio.
    foreach (['Box', 'Cantina', 'Posto auto', 'Soffitta', 'Garage'] as $nome) {
        expect(TipologiaImmobile::where('nome', $nome)->value('categoria'))
            ->toBe('pertinenza', "«{$nome}» dovrebbe essere una pertinenza");
    }
});

it('la relazione con gli immobili usa la colonna che esiste davvero', function () {
    // ⚠️ Dichiarava la chiave esterna `tipologia`, colonna inesistente: la vera è `tipologia_id`.
    // Il difetto era latente perché la relazione non aveva chiamanti, e si sarebbe svegliata alla
    // prima funzione che conta gli immobili per tipologia — cioè quella che serve al campo
    // «Pertinenza di».
    $condominio = \App\Models\Condominio::factory()->create();
    $box = TipologiaImmobile::where('nome', 'Box')->first();

    Immobile::create([
        'condominio_id' => $condominio->id,
        'tipologia_id'  => $box->id,
        'nome'          => 'Box 1',
        'interno'       => '1',
        'descrizione'   => 'Unità di prova',
    ]);

    // Senza la correzione questa riga solleva un errore SQL su colonna sconosciuta.
    expect($box->immobili()->count())->toBe(1);
});

<?php

/**
 * # Allegati, il giro di correzioni 2 (Coda 460)
 *
 * - **Un condominio che il database rifiuta di eliminare tiene i suoi allegati.** Un condominio con la contabilità
 *   (pagamenti a fornitori, deleghe F24) non si elimina: i vincoli del database lo impediscono e la pagina lo dice.
 *   La pulizia degli allegati non deve partire prima di quel rifiuto: righe e file si tolgono solo quando
 *   l'eliminazione è riuscita. Il condominio dimostrativo ha la contabilità vera; qui diventa un condominio
 *   normale, così passa dalla strada di tutti.
 * - **I filtri sbagliati non rompono le pagine.** Una data che non è una data, o un testo mandato come elenco, nei
 *   filtri della pagina «Allegati», del suo ZIP e della pagina «Audit e logs»: la pagina risponde, non va in errore.
 * - **Le spiegazioni dell'accesso dicono la regola vera.** «Aperti»: nelle comunicazioni dal portale carica solo
 *   chi l'ha scritta. «Solo consultazione»: chi ha caricato un file può ancora rinominarlo o eliminarlo.
 *
 * Cosa non copre: l'eliminazione di un utente che il database rifiutasse (oggi le chiavi verso `users` sono in
 * cascata o vanno a null: la correzione è la stessa, ma non c'è un caso vero da provare), le copie troncate da un
 * disco S3, il riquadro che mostra un'impostazione non salvata (si guarda a video).
 */

use App\Actions\Condominio\CreaCondominioDimostrativoAction;
use App\Models\Condominio;
use App\Services\Documenti\ArchivioDocumenti;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
});

test('un condominio con la contabilità non si elimina, e i suoi allegati restano: righe e file', function () {
    $this->seed([
        \Database\Seeders\CategoriaEventoSeeder::class,
        \Database\Seeders\TipologieImmobiliSeeder::class,
    ]);
    $this->actingAs($this->admin);
    $condominio = app(CreaCondominioDimostrativoAction::class)->esegui()['condominio'];
    DB::table('condomini')->where('id', $condominio->id)->update(['is_demo' => false]);
    $condominio = $condominio->fresh();

    $s = algSegnalazione($condominio, $this->admin);
    $foto = algAllegatoSalvato($s, $this->admin, 'cancello.jpg');
    $preventivo = algAllegatoSalvato($s, $this->admin, 'preventivo.pdf');
    $percorsi = DB::table('documenti')->whereIn('id', [$foto, $preventivo])->pluck('path', 'id')->all();

    $this->actingAs($this->admin)->delete(route('condomini.destroy', $condominio->id));

    expect(Condominio::find($condominio->id))->not->toBeNull(
        'Il condominio con la contabilità è stato eliminato: lo scenario non prova niente'
    );
    expect(DB::table('documenti')->whereIn('id', [$foto, $preventivo])->count())->toBe(2,
        'Il condominio è rimasto, ma le righe degli allegati delle sue segnalazioni sono sparite'
    );
    foreach ($percorsi as $id => $percorso) {
        expect(app(ArchivioDocumenti::class)->disco()->exists($percorso))->toBeTrue(
            "Il condominio è rimasto, ma il file dell'allegato {$id} è stato cancellato"
        );
    }
});

test('i filtri sbagliati non rompono la pagina «Allegati», il suo ZIP e la pagina «Audit e logs»', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    algAllegatoSalvato($s, $this->mario, 'foto.jpg');

    $richieste = [
        route('admin.allegati.index') . '?autore[]=x',
        route('admin.allegati.index') . '?dal=non-una-data&al=31-31-2026',
        route('admin.allegati.zip') . '?autore[]=x',
        route('admin.allegati.zip') . '?dal=non-una-data',
        route('logs.index') . '?date_from=abc',
        route('logs.index') . '?date_to=2026-13-45',
        route('logs.index') . '?search[]=x',
    ];
    $rotte = [];
    foreach ($richieste as $indirizzo) {
        $stato = $this->actingAs($this->admin)->get($indirizzo)->getStatusCode();
        if ($stato >= 500) {
            $rotte[] = "{$indirizzo} risponde {$stato}";
        }
    }
    expect($rotte)->toBe([], "Un filtro sbagliato rompe la pagina:\n" . implode("\n", $rotte));
});

test('le spiegazioni dell\'accesso dicono la regola vera', function () {
    app()->setLocale('it');

    expect(__('allegati.settings.hint_aperti'))->toBe(
        'Chi vede l\'elemento vede e scarica i file. Ne aggiunge di nuovi, dal portale, chi sta nel palazzo per le segnalazioni e chi l\'ha scritta per le comunicazioni.'
    );
    expect(__('allegati.settings.hint_consultazione'))->toBe(
        'Nel portale i file si vedono e si scaricano, ma non se ne aggiungono di nuovi. Chi ha caricato un file può ancora rinominarlo o eliminarlo.'
    );
});

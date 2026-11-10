<?php

/**
 * # Allegati, il giro di correzioni: la pagina d'insieme e lo ZIP (Coda 460)
 *
 * Quattro cose che i primi test non fissavano:
 *
 * - **La pagina e il numero nel menu usano la regola della scheda dentro la query.** Chi ha il pannello e
 *   «Visualizza proprie segnalazioni» apre solo le segnalazioni che ha scritto lui: nella pagina vede solo i loro
 *   allegati, e il totale della paginazione e il numero nel menu contano solo quelli. Filtrare dopo la paginazione
 *   non basta: il totale e le pagine direbbero il falso.
 * - **Lo ZIP legge i file uno alla volta**: la memoria del processo non cresce con la somma dei file. Il file si
 *   copia su un temporaneo locale e si aggiunge da lì, non come stringa (che resta in memoria fino alla chiusura).
 * - **`elenco.csv` non porta formule** e lo legge anche Excel: una cella che comincia con `=`, `+`, `-`, `@`, una
 *   tabulazione o un ritorno a capo ha davanti un apostrofo; il file comincia con il BOM UTF-8.
 * - **Un file che manca dal disco** non entra vuoto nello ZIP: l'elenco lo dice («file mancante», percorso vuoto).
 *   **Due elementi con la stessa data e lo stesso oggetto** hanno due cartelle: il primo, per numero, tiene il
 *   nome semplice; l'altro ha un suffisso, per esempio « (2)».
 *
 * Cosa non copre: le pagine oltre la prima (bastano il totale e il numero nel menu), i limiti di Windows sulla
 * lunghezza dei percorsi.
 */

use App\Services\Documenti\ArchivioDocumenti;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 10:00:00');
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->carla = algUtente([$this->aurora->id], 'Carla Neri');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** I byte dello ZIP di una risposta, qualunque sia la forma della risposta. */
function algcByteZip(TestResponse $risposta): string
{
    $base = $risposta->baseResponse;

    return $base instanceof BinaryFileResponse ? (string) file_get_contents($base->getFile()->getPathname()) : (string) $risposta->streamedContent();
}

/** Le voci di uno ZIP, con il loro contenuto, in ordine di nome. */
function algcVociZip(TestResponse $risposta): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'algczip');
    file_put_contents($tmp, algcByteZip($risposta));
    $zip = new ZipArchive();
    expect($zip->open($tmp))->toBeTrue('La risposta non è uno ZIP');
    $voci = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nome = $zip->getNameIndex($i);
        if (! str_ends_with($nome, '/')) {
            $voci[$nome] = $zip->getFromIndex($i);
        }
    }
    $zip->close();
    @unlink($tmp);
    ksort($voci);

    return $voci;
}

/** Le righe di `elenco.csv`, senza il BOM e senza l'intestazione, come array di celle. */
function algcRigheElenco(string $csv): array
{
    $senzaBom = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
    $righe = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $senzaBom)), fn ($r) => $r !== ''));

    return array_map(fn ($r) => str_getcsv($r, ';', '"', ''), array_slice($righe, 1));
}

test('con «Visualizza proprie segnalazioni» la pagina, il totale e il numero nel menu contano solo gli allegati delle sue segnalazioni', function () {
    $ruolo = algRuolo('Portineria', [
        'Accesso pannello amministratore',
        'Visualizza proprie segnalazioni',
        ALG_PERMESSI['segnalazioni']['visualizza'],
        ALG_PERMESSI['segnalazioni']['approva'],
    ]);
    $portiere = algUtente([$this->aurora->id], 'Paolo Galli', $ruolo);
    $sua = algSegnalazione($this->aurora, $portiere, ['subject' => 'Luce delle scale']);
    $altrui = algSegnalazione($this->aurora, $this->mario, ['subject' => 'Perdita in cantina']);
    $nellaSua = algAllegatoSalvato($sua, $this->mario, 'scale.jpg', approvato: false);
    algAllegatoSalvato($altrui, $this->mario, 'cantina.jpg', approvato: false);
    algAllegatoSalvato($altrui, $this->mario, 'cantina-2.jpg');

    // La regola della scheda: la segnalazione di Mario per lui non si apre.
    expect(algRifiutato($this->actingAs($portiere)->get(route('admin.segnalazioni.show', $altrui))))->toBeTrue();

    $props = $this->actingAs($portiere)->get(route('admin.allegati.index'))->assertOk()->viewData('page')['props'];
    $ids = collect($props['allegati']['data'])->pluck('id')->map(fn ($id) => (int) $id)->all();
    $testo = json_encode($props['allegati']['data']);

    expect($ids)->toBe([$nellaSua])
        ->and((int) $props['allegati']['total'])->toBe(1)
        ->and($testo)->not->toContain('Perdita in cantina')->not->toContain('cantina.jpg');

    $menu = $this->actingAs($portiere)->get(route('admin.dashboard'))->viewData('page')['props'];
    expect($menu['allegati_in_attesa'])->toBe(1);
});

test('lo ZIP legge i file uno alla volta: la memoria non cresce con la somma dei file', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $disco = app(ArchivioDocumenti::class)->disco();
    $peso = 4 * 1024 * 1024;
    for ($i = 1; $i <= 10; $i++) {
        $path = 'documenti/allegati/segnalazioni/' . $s->id . '/' . Str::random(40) . '.pdf';
        $disco->put($path, "%PDF-1.4\n" . random_bytes($peso - 9));
        DB::table('documenti')->insert([
            'name' => "verbale-{$i}.pdf", 'path' => $path, 'mime_type' => 'application/pdf', 'file_size' => $peso,
            'created_by' => $this->mario->id, 'is_published' => true, 'is_approved' => true,
            'documentable_type' => $s->getMorphClass(), 'documentable_id' => $s->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    gc_collect_cycles();
    memory_reset_peak_usage();
    $prima = memory_get_usage();
    $risposta = $this->actingAs($this->admin)->get(route('admin.allegati.zip'));
    $crescita = memory_get_peak_usage() - $prima;

    $risposta->assertOk();
    expect($crescita)->toBeLessThan(16 * 1024 * 1024, sprintf(
        'Lo ZIP di 40 MB di file ha fatto crescere la memoria di %.1f MB: i file si tengono tutti in memoria fino alla chiusura.',
        $crescita / 1024 / 1024,
    ));

    $voci = algcVociZip($risposta);
    expect($voci)->toHaveCount(11)
        ->and(strlen($voci['Condominio Aurora/Segnalazioni/2026-11-08 Il cancello non chiude/verbale-7.pdf']))->toBe($peso);
});

test('l\'elenco non porta formule: le celle che cominciano con = + - @ hanno l\'apostrofo davanti, e il file ha il BOM', function () {
    $lucia = algUtente([$this->aurora->id], '-Lucia Verdi');
    $s = algSegnalazione($this->aurora, $this->mario, ['subject' => '=HYPERLINK("https://esempio.invalid","Apri")']);
    algAllegatoSalvato($s, $this->mario, '+foto.jpg');
    algAllegatoSalvato($s, $lucia, '@preventivo.pdf');

    $voci = algcVociZip($this->actingAs($this->admin)->get(route('admin.allegati.zip'))->assertOk());
    $csv = $voci['elenco.csv'];
    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF", 'elenco.csv deve cominciare con il BOM UTF-8, o Excel rompe le lettere accentate');

    $righe = algcRigheElenco($csv);
    $celle = collect($righe)->flatten()->all();
    expect($righe)->toHaveCount(2);
    foreach ($celle as $cella) {
        expect(preg_match('/^[=+\-@\t\r]/', (string) $cella))->toBe(0, "Una cella dell'elenco comincia con un carattere da formula: {$cella}");
    }
    expect($celle)->toContain('\'=HYPERLINK("https://esempio.invalid","Apri")')
        ->toContain("'+foto.jpg")
        ->toContain("'@preventivo.pdf")
        ->toContain("'-Lucia Verdi");

    // Il nome dentro lo ZIP resta quello vero: l'apostrofo è solo nell'elenco.
    expect(implode("\n", array_keys($voci)))->toContain('/+foto.jpg')->toContain('/@preventivo.pdf');
});

test('un file che manca dal disco non entra vuoto nello ZIP, e l\'elenco lo dice', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $sparito = algAllegatoSalvato($s, $this->mario, 'sparito.jpg');
    algAllegatoSalvato($s, $this->mario, 'presente.jpg');
    app(ArchivioDocumenti::class)->disco()->delete(DB::table('documenti')->where('id', $sparito)->value('path'));

    $voci = algcVociZip($this->actingAs($this->admin)->get(route('admin.allegati.zip'))->assertOk());
    $cartella = 'Condominio Aurora/Segnalazioni/2026-11-08 Il cancello non chiude/';

    expect(array_keys($voci))->toContain($cartella . 'presente.jpg')
        ->not->toContain($cartella . 'sparito.jpg');

    $riga = collect(algcRigheElenco($voci['elenco.csv']))->first(fn ($r) => in_array('sparito.jpg', $r, true));
    expect($riga)->not->toBeNull('Il file mancante deve restare nell\'elenco, con il suo stato')
        ->and($riga)->toContain('file mancante')
        ->and(end($riga))->toBe('');
});

test('due elementi con la stessa data e lo stesso oggetto hanno due cartelle: il primo tiene il nome semplice', function () {
    $prima = algSegnalazione($this->aurora, $this->mario);
    $seconda = algSegnalazione($this->aurora, $this->carla);
    algAllegatoSalvato($prima, $this->mario, 'foto.jpg');
    algAllegatoSalvato($seconda, $this->carla, 'foto.jpg');

    $voci = algcVociZip($this->actingAs($this->admin)->get(route('admin.allegati.zip', ['condominio_id' => $this->aurora->id]))->assertOk());
    $cartelle = collect(array_keys($voci))->reject(fn ($n) => $n === 'elenco.csv')->map(fn ($n) => dirname($n))->unique()->values()->all();

    expect($cartelle)->toHaveCount(2)
        ->and($cartelle)->toContain('Condominio Aurora/Segnalazioni/2026-11-08 Il cancello non chiude');
});

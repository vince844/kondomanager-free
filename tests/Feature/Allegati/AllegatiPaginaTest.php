<?php

/**
 * # Allegati: la pagina d'insieme del pannello (Coda 460, decisioni 241.8 e 241.10)
 *
 * Una pagina sola con gli allegati di segnalazioni e comunicazioni di tutti i condomini, per chi ne ha cento:
 * quelli in attesa per primi, i filtri per stato, tipo, condominio, autore e data, «Approva» ed «Elimina» anche
 * in blocco, e «Scarica in ZIP» sugli allegati filtrati, con cartelle dai nomi veri e un elenco. La pagina mostra
 * a ciascuno ciò che vedrebbe nelle schede, con la stessa regola. Un'azione in blocco con un file che chi la
 * chiede non può toccare si rifiuta intera: nessun file cambia.
 *
 * Lo ZIP: `<condominio>/<Segnalazioni|Comunicazioni>/<AAAA-MM-GG> <oggetto>/<nome del file>`, con la data in cui
 * l'elemento è nato, i caratteri che un file system non accetta sostituiti come nel nome di scaricamento dei
 * documenti, e « (2)» davanti all'estensione quando due file hanno lo stesso nome. Una comunicazione di due palazzi
 * sta sotto tutti e due. `elenco.csv` dice, per ogni file, il condominio, il tipo, l'elemento, il nome, l'autore,
 * la data di caricamento, lo stato e il percorso nello ZIP.
 *
 * Cosa non copre: lo ZIP di migliaia di file (il progetto chiede di costruirlo su un file temporaneo leggendo i
 * file uno alla volta; un tetto, se servirà, si decide dopo la prova a video).
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 10:00:00');
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->carla = algUtente([$this->borea->id], "Carla D'Amico");
    $this->collaboratore = algUtente([$this->aurora->id, $this->borea->id], 'Carla Neri', 'collaboratore');
});

afterEach(function () {
    Carbon::setTestNow();
});

function algPagina($test, $user, array $filtri = []): array
{
    $risposta = $test->actingAs($user)->get(route('admin.allegati.index', $filtri));
    $risposta->assertOk();

    return $risposta->viewData('page')['props'];
}

/** Gli id della pagina, nell'ordine in cui la pagina li mostra. */
function algIdPagina(array $props): array
{
    return collect($props['allegati']['data'])->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** Le voci di uno ZIP restituito da una rotta, con il loro contenuto. */
function algVociZip(TestResponse $risposta): array
{
    $base = $risposta->baseResponse;
    $byte = $base instanceof BinaryFileResponse ? file_get_contents($base->getFile()->getPathname()) : $risposta->streamedContent();
    $tmp = tempnam(sys_get_temp_dir(), 'algzip');
    file_put_contents($tmp, $byte);
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
    ksort($voci);

    return $voci;
}

test('l\'amministratore vede tutti gli allegati di tutti i condomini, quelli in attesa per primi', function () {
    $sAurora = algSegnalazione($this->aurora, $this->mario);
    $sBorea = algSegnalazione($this->borea, $this->carla, ['subject' => 'Perdita in garage']);
    $c = algComunicazione([$this->aurora, $this->borea], $this->admin);

    $pubblicato = algAllegatoSalvato($sAurora, $this->mario, 'pubblicato.jpg');
    $inAttesa = algAllegatoSalvato($sBorea, $this->carla, 'in-attesa.jpg', approvato: false);
    $dellaComunicazione = algAllegatoSalvato($c, $this->admin, 'convocazione.pdf');

    $ids = algIdPagina(algPagina($this, $this->admin));
    expect($ids)->toHaveCount(3)
        ->and($ids[0])->toBe($inAttesa)
        ->and($ids)->toContain($pubblicato, $dellaComunicazione);
});

test('ogni riga dice il nome, l\'elemento con il suo collegamento, il condominio, l\'autore, la data, lo stato e il peso, senza email né percorsi', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    algAllegatoSalvato($s, $this->mario, 'cancello.jpg');

    $props = algPagina($this, $this->admin);
    $riga = $props['allegati']['data'][0];

    expect($riga['nome'])->toBe('cancello.jpg')
        ->and($riga['tipo'])->toBe('segnalazioni')
        ->and($riga['elemento'])->toBe('Il cancello non chiude')
        ->and($riga['url_elemento'])->toBe(route('admin.segnalazioni.show', $s))
        ->and($riga['condomini'])->toBe(['Condominio Aurora'])
        ->and($riga['autore'])->toBe('Mario Rossi')
        ->and($riga['stato'])->toBe('pubblicato')
        ->and($riga['dimensione'])->toBeInt()
        ->and($riga)->toHaveKeys(['caricato_il', 'url_apri', 'url_scarica', 'puo_approvare', 'puo_eliminare'])
        ->and(json_encode($props['allegati']))->not->toContain($this->mario->email)->not->toContain('documenti/allegati');
});

test('i filtri: stato, tipo, condominio, autore e data', function () {
    $sAurora = algSegnalazione($this->aurora, $this->mario);
    $sBorea = algSegnalazione($this->borea, $this->carla);
    $c = algComunicazione([$this->borea], $this->admin);

    $a = algAllegatoSalvato($sAurora, $this->mario, 'a.jpg');
    $b = algAllegatoSalvato($sBorea, $this->carla, 'b.jpg', approvato: false);
    $cc = algAllegatoSalvato($c, $this->admin, 'c.pdf');
    DB::table('documenti')->where('id', $a)->update(['created_at' => '2026-10-01 09:00:00']);

    $ordinati = fn (array $filtri) => collect(algIdPagina(algPagina($this, $this->admin, $filtri)))->sort()->values()->all();

    expect($ordinati(['stato' => 'in_attesa']))->toBe([$b])
        ->and($ordinati(['stato' => 'pubblicato']))->toBe([$a, $cc])
        ->and($ordinati(['tipo' => 'comunicazioni']))->toBe([$cc])
        ->and($ordinati(['condominio_id' => $this->borea->id]))->toBe([$b, $cc])
        ->and($ordinati(['autore' => 'Rossi']))->toBe([$a])
        ->and($ordinati(['dal' => '2026-11-01']))->toBe([$b, $cc])
        ->and($ordinati(['al' => '2026-10-31']))->toBe([$a]);
});

test('il collaboratore vede nella pagina ciò che vedrebbe nelle schede: i pubblicati e i suoi, non quelli in attesa degli altri', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $pubblicato = algAllegatoSalvato($s, $this->mario, 'pubblicato.jpg');
    algAllegatoSalvato($s, $this->mario, 'in-attesa.jpg', approvato: false);
    $suo = algAllegatoSalvato($s, $this->collaboratore, 'suo.pdf', approvato: false);

    expect(collect(algIdPagina(algPagina($this, $this->collaboratore)))->sort()->values()->all())->toBe([$pubblicato, $suo]);
});

test('il condòmino non apre la pagina', function () {
    expect(algRifiutato($this->actingAs($this->mario)->get(route('admin.allegati.index'))))->toBeTrue();
});

test('approva in blocco: tutti i file scelti diventano pubblicati; se uno non si può approvare, nessuno cambia', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $uno = algAllegatoSalvato($s, $this->mario, 'uno.jpg', approvato: false);
    $due = algAllegatoSalvato($s, $this->mario, 'due.jpg', approvato: false);
    $archivio = DB::table('documenti')->insertGetId([
        'name' => 'Regolamento', 'path' => 'documenti/regolamento.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        'created_by' => $this->admin->id, 'is_published' => true, 'is_approved' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $rifiutata = $this->actingAs($this->admin)->post(route('admin.allegati.approva-in-blocco'), ['ids' => [$uno, $archivio]]);
    expect(algRifiutato($rifiutata))->toBeTrue()
        ->and((bool) DB::table('documenti')->find($uno)->is_approved)->toBeFalse()
        ->and((bool) DB::table('documenti')->find($archivio)->is_approved)->toBeFalse();

    expect(algRifiutato($this->actingAs($this->collaboratore)->post(route('admin.allegati.approva-in-blocco'), ['ids' => [$uno, $due]])))->toBeTrue();

    $this->actingAs($this->admin)->post(route('admin.allegati.approva-in-blocco'), ['ids' => [$uno, $due]])->assertRedirect();
    expect(DB::table('documenti')->whereIn('id', [$uno, $due])->pluck('is_approved')->map(fn ($v) => (bool) $v)->all())->toBe([true, true]);
});

test('elimina in blocco: via righe e file; se uno non si può eliminare, nessuno sparisce', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $diMario = algAllegatoSalvato($s, $this->mario, 'di-mario.jpg');
    $delCollaboratore = algAllegatoSalvato($s, $this->collaboratore, 'suo.jpg');

    $rifiutata = $this->actingAs($this->collaboratore)->post(route('admin.allegati.elimina-in-blocco'), ['ids' => [$delCollaboratore, $diMario]]);
    expect(algRifiutato($rifiutata))->toBeTrue()
        ->and(DB::table('documenti')->whereIn('id', [$diMario, $delCollaboratore])->count())->toBe(2);

    $this->actingAs($this->admin)->post(route('admin.allegati.elimina-in-blocco'), ['ids' => [$delCollaboratore, $diMario]])->assertRedirect();
    expect(DB::table('documenti')->whereIn('id', [$diMario, $delCollaboratore])->count())->toBe(0)
        ->and(app(\App\Services\Documenti\ArchivioDocumenti::class)->disco()->allFiles('documenti/allegati'))->toBe([]);
});

test('lo ZIP di un condominio: le cartelle con i nomi veri, i doppioni numerati, i caratteri vietati sostituiti, l\'elenco; niente dell\'altro palazzo', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $verbale = algSegnalazione($this->aurora, $this->mario, ['subject' => 'Verbale 12/2026']);
    $sBorea = algSegnalazione($this->borea, $this->carla, ['subject' => 'Perdita in garage']);
    $c = algComunicazione([$this->aurora, $this->borea], $this->admin);

    algAllegatoSalvato($s, $this->mario, 'foto.jpg');
    algAllegatoSalvato($s, $this->mario, 'foto.jpg', approvato: false);
    algAllegatoSalvato($verbale, $this->mario, 'firma.pdf');
    algAllegatoSalvato($sBorea, $this->carla, 'garage.jpg');
    algAllegatoSalvato($c, $this->admin, 'convocazione.pdf');

    $voci = algVociZip($this->actingAs($this->admin)->get(route('admin.allegati.zip', ['condominio_id' => $this->aurora->id]))->assertOk());

    expect(array_keys($voci))->toBe([
        'Condominio Aurora/Comunicazioni/2026-11-08 Lavori al tetto/convocazione.pdf',
        'Condominio Aurora/Segnalazioni/2026-11-08 Il cancello non chiude/foto (2).jpg',
        'Condominio Aurora/Segnalazioni/2026-11-08 Il cancello non chiude/foto.jpg',
        'Condominio Aurora/Segnalazioni/2026-11-08 Verbale 12-2026/firma.pdf',
        'elenco.csv',
    ]);
    expect($voci['Condominio Aurora/Comunicazioni/2026-11-08 Lavori al tetto/convocazione.pdf'])->toBe(ALG_PDF);

    $righe = array_map(fn ($r) => str_getcsv($r, ';'), array_values(array_filter(explode("\n", str_replace("\r", '', $voci['elenco.csv'])))));
    expect($righe)->toHaveCount(5)
        ->and(implode(';', $righe[0]))->toContain('Condominio')->toContain('Autore')->toContain('Stato')
        ->and(collect($righe)->flatten()->implode(' '))->toContain('Mario Rossi')->toContain('in attesa')->not->toContain('garage.jpg');
});

test('lo ZIP senza filtri mette la comunicazione di due palazzi sotto tutti e due, e contiene solo ciò che chi lo chiede vede', function () {
    $c = algComunicazione([$this->aurora, $this->borea], $this->admin);
    algAllegatoSalvato($c, $this->admin, 'convocazione.pdf');
    $s = algSegnalazione($this->aurora, $this->mario);
    algAllegatoSalvato($s, $this->mario, 'in-attesa.jpg', approvato: false);

    $tutti = array_keys(algVociZip($this->actingAs($this->admin)->get(route('admin.allegati.zip'))->assertOk()));
    expect($tutti)->toContain(
        'Condominio Aurora/Comunicazioni/2026-11-08 Lavori al tetto/convocazione.pdf',
        'Condominio Borea/Comunicazioni/2026-11-08 Lavori al tetto/convocazione.pdf',
        'Condominio Aurora/Segnalazioni/2026-11-08 Il cancello non chiude/in-attesa.jpg',
    );

    $delCollaboratore = array_keys(algVociZip($this->actingAs($this->collaboratore)->get(route('admin.allegati.zip'))->assertOk()));
    expect($delCollaboratore)->not->toContain('Condominio Aurora/Segnalazioni/2026-11-08 Il cancello non chiude/in-attesa.jpg');
});

test('il numero degli allegati in attesa arriva al menu di chi approva; a chi non approva no', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    algAllegatoSalvato($s, $this->mario, 'uno.jpg', approvato: false);
    algAllegatoSalvato($s, $this->mario, 'due.jpg', approvato: false);
    algAllegatoSalvato($s, $this->mario, 'tre.jpg');

    $perAdmin = $this->actingAs($this->admin)->get(route('admin.dashboard'))->viewData('page')['props'];
    $perCollaboratore = $this->actingAs($this->collaboratore)->get(route('admin.dashboard'))->viewData('page')['props'];

    expect($perAdmin['allegati_in_attesa'])->toBe(2)
        ->and($perCollaboratore['allegati_in_attesa'] ?? 0)->toBe(0);
});

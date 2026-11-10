<?php

/**
 * # Allegati: chi li vede e chi li scarica (Coda 460, decisione 241.3; le lezioni delle Code 198, 263, 389, 411)
 *
 * Una regola sola per la scheda, per «Apri» e per «Scarica», dal pannello e dal portale: due porte, la stessa
 * serratura. Fuori dal pannello un allegato si vede se si vede l'elemento (la `show` della sua policy), se gli
 * allegati dell'elemento non sono «Solo amministrazione», se si ha «Visualizza allegati», e se è pubblicato o è
 * proprio. Nel pannello si vede se si apre la scheda e si ha «Visualizza allegati»; quelli in attesa degli altri
 * solo con «Approva». Chi non vede un file riceve un rifiuto, che non dice se il file esiste; un id che non è un
 * allegato di una segnalazione o di una comunicazione, per queste rotte, non c'è (404).
 *
 * «Apri» manda il file nel browser solo per i quattro tipi ammessi, con `nosniff`; «Scarica» lo consegna come
 * file, con il nome originale. Le props non portano email, percorsi sul disco né l'account dell'autore
 * (Coda 411 A1): solo il suo nome.
 *
 * Cosa non copre: l'elenco del portale (gli allegati non compaiono negli elenchi, solo nella scheda).
 */

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->luigi = algUtente([$this->aurora->id], 'Luigi Verdi');
    $this->carla = algUtente([$this->borea->id], "Carla D'Amico");
});

test('per ogni persona del portale e ogni allegato: la scheda lo mostra se e solo se «Scarica» e «Apri» lo consegnano', function () {
    $segnalazioni = [
        algSegnalazione($this->aurora, $this->mario),
        algSegnalazione($this->aurora, $this->mario, ['is_private' => true]),
        algSegnalazione($this->aurora, $this->mario, ['is_approved' => false]),
        algSegnalazione($this->aurora, $this->mario, ['allegati_accesso' => 'consultazione']),
        algSegnalazione($this->aurora, $this->mario, ['allegati_accesso' => 'riservati']),
        algSegnalazione($this->aurora, $this->luigi, [], [$this->carla]),
    ];
    $allegati = [];
    foreach ($segnalazioni as $s) {
        $allegati[$s->id] = [
            algAllegatoSalvato($s, $this->mario, 'di-mario.jpg'),
            algAllegatoSalvato($s, $this->luigi, 'di-luigi-in-attesa.jpg', approvato: false),
            algAllegatoSalvato($s, $this->mario, 'di-mario-in-attesa.pdf', approvato: false),
            algAllegatoSalvato($s, $this->admin, 'dello-studio.pdf'),
        ];
    }

    $casi = 0;
    foreach ([$this->mario, $this->luigi, $this->carla] as $persona) {
        foreach ($segnalazioni as $s) {
            $inScheda = algIdInScheda($this, $persona, $s) ?? [];
            foreach ($allegati[$s->id] as $id) {
                $casi++;
                $scarica = algScarica($this, $persona, $id);
                $apri = $this->actingAs($persona)->get(route('user.allegati.apri', ['allegato' => $id]));
                $mostrato = in_array($id, $inScheda, true);

                expect($scarica->status() === 200)->toBe($mostrato, "{$persona->name}, segnalazione {$s->id}, allegato {$id}: in scheda " . var_export($mostrato, true) . ", scarica {$scarica->status()}")
                    ->and($apri->status() === 200)->toBe($mostrato, "{$persona->name}, allegato {$id}: apri {$apri->status()}");
                if (! $mostrato) {
                    expect(algRifiutato($scarica))->toBeTrue();
                }
            }
        }
    }
    expect($casi)->toBe(72);

    // I punti fermi della griglia, scritti a mano.
    [$pubblica, , , , $riservata, $collegata] = $segnalazioni;
    expect(algIdInScheda($this, $this->luigi, $pubblica))->toBe([$allegati[$pubblica->id][0], $allegati[$pubblica->id][1], $allegati[$pubblica->id][3]])
        ->and(algIdInScheda($this, $this->mario, $pubblica))->toBe([$allegati[$pubblica->id][0], $allegati[$pubblica->id][2], $allegati[$pubblica->id][3]])
        ->and(algIdInScheda($this, $this->carla, $pubblica))->toBeNull()
        ->and(algIdInScheda($this, $this->mario, $riservata))->toBe([])
        ->and(algIdInScheda($this, $this->carla, $collegata))->toBe([$allegati[$collegata->id][0], $allegati[$collegata->id][3]]);
});

test('una segnalazione in moderazione di un vicino non consegna nemmeno i suoi allegati pubblicati (Code 198 e 263)', function () {
    $s = algSegnalazione($this->aurora, $this->mario, ['is_approved' => false]);
    $id = algAllegatoSalvato($s, $this->mario, 'foto.jpg');

    expect(algRifiutato(algScarica($this, $this->luigi, $id)))->toBeTrue()
        ->and(algScarica($this, $this->mario, $id)->status())->toBe(200);
});

test('una comunicazione in bozza o in moderazione non consegna i suoi allegati ai lettori; pubblicata sì', function () {
    $bozza = algComunicazione([$this->aurora], $this->admin, ['is_published' => false]);
    $inModerazione = algComunicazione([$this->aurora], $this->admin, ['is_approved' => false]);
    $pubblicata = algComunicazione([$this->aurora], $this->admin);

    expect(algRifiutato(algScarica($this, $this->mario, algAllegatoSalvato($bozza, $this->admin, 'a.pdf'))))->toBeTrue()
        ->and(algRifiutato(algScarica($this, $this->mario, algAllegatoSalvato($inModerazione, $this->admin, 'b.pdf'))))->toBeTrue()
        ->and(algScarica($this, $this->mario, algAllegatoSalvato($pubblicata, $this->admin, 'c.pdf'))->status())->toBe(200)
        ->and(algRifiutato(algScarica($this, $this->carla, algAllegatoSalvato($pubblicata, $this->admin, 'd.pdf'))))->toBeTrue();
});

test('nel pannello quelli in attesa degli altri li vede chi approva; il collaboratore vede i pubblicati e i suoi', function () {
    $collaboratore = algUtente([$this->aurora->id], 'Carla Neri', 'collaboratore');
    $s = algSegnalazione($this->aurora, $this->mario);
    $pubblicato = algAllegatoSalvato($s, $this->mario, 'pubblicato.jpg');
    $inAttesa = algAllegatoSalvato($s, $this->mario, 'in-attesa.jpg', approvato: false);
    $delCollaboratore = algAllegatoSalvato($s, $collaboratore, 'suo.pdf', approvato: false);

    expect(algIdInScheda($this, $this->admin, $s, 'admin'))->toBe([$pubblicato, $inAttesa, $delCollaboratore])
        ->and(algIdInScheda($this, $collaboratore, $s, 'admin'))->toBe([$pubblicato, $delCollaboratore])
        ->and(algScarica($this, $this->admin, $inAttesa, 'admin')->status())->toBe(200)
        ->and(algRifiutato(algScarica($this, $collaboratore, $inAttesa, 'admin')))->toBeTrue()
        ->and(algScarica($this, $collaboratore, $delCollaboratore, 'admin')->status())->toBe(200);
});

test('«Solo amministrazione»: nel portale non si vede niente, nemmeno i propri; nel pannello tutto', function () {
    $s = algSegnalazione($this->aurora, $this->mario, ['allegati_accesso' => 'riservati']);
    $id = algAllegatoSalvato($s, $this->mario, 'mia.jpg');

    expect(algIdInScheda($this, $this->mario, $s))->toBe([])
        ->and(algRifiutato(algScarica($this, $this->mario, $id)))->toBeTrue()
        ->and(algScarica($this, $this->admin, $id, 'admin')->status())->toBe(200);
});

test('chi vede le segnalazioni ma non ha «Visualizza allegati» non vede i file', function () {
    $senza = algUtente([$this->aurora->id], 'Paolo Galli', algRuolo('Segnalazioni senza allegati', ['Visualizza segnalazioni']));
    $s = algSegnalazione($this->aurora, $this->mario);
    $id = algAllegatoSalvato($s, $this->mario, 'foto.jpg');

    expect(algIdInScheda($this, $senza, $s))->toBe([])
        ->and(algRifiutato(algScarica($this, $senza, $id)))->toBeTrue();
});

test('per le rotte degli allegati un documento dell\'archivio, il documento di una persona o l\'allegato di un elemento che non c\'è più non esistono (404), anche per l\'amministratore', function () {
    $archivio = DB::table('documenti')->insertGetId([
        'name' => 'Regolamento', 'path' => 'documenti/regolamento.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        'created_by' => $this->admin->id, 'is_published' => true, 'is_approved' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $diUnaPersona = DB::table('documenti')->insertGetId([
        'name' => 'Rogito', 'path' => 'documenti/rogito.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        'created_by' => $this->admin->id, 'is_published' => true, 'is_approved' => true,
        'documentable_type' => \App\Models\Anagrafica::class, 'documentable_id' => $this->mario->anagrafica->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $s = algSegnalazione($this->aurora, $this->mario);
    $orfano = algAllegatoSalvato($s, $this->mario, 'foto.jpg');
    DB::table('segnalazioni')->where('id', $s->id)->delete();

    foreach ([$archivio, $diUnaPersona, $orfano] as $id) {
        foreach (['scarica', 'apri'] as $azione) {
            $this->actingAs($this->admin)->get(route("admin.allegati.{$azione}", ['allegato' => $id]))->assertNotFound();
            $this->actingAs($this->mario)->get(route("user.allegati.{$azione}", ['allegato' => $id]))->assertNotFound();
        }
    }
});

test('«Apri» manda nel browser le foto e i PDF con il loro tipo e `nosniff`; «Scarica» li consegna come file con il nome originale', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $foto = algAllegatoSalvato($s, $this->mario, 'cancello rotto.jpg');
    $pdf = algAllegatoSalvato($s, $this->mario, 'preventivo.pdf');

    foreach (['user' => $this->mario, 'admin' => $this->admin] as $porta => $chi) {
        $apri = $this->actingAs($chi)->get(route("{$porta}.allegati.apri", ['allegato' => $foto]))->assertOk();
        expect($apri->headers->get('Content-Type'))->toStartWith('image/jpeg')
            ->and(strtolower((string) $apri->headers->get('X-Content-Type-Options')))->toBe('nosniff')
            ->and((string) $apri->headers->get('Content-Disposition'))->toStartWith('inline');

        $apriPdf = $this->actingAs($chi)->get(route("{$porta}.allegati.apri", ['allegato' => $pdf]))->assertOk();
        expect($apriPdf->headers->get('Content-Type'))->toStartWith('application/pdf');

        $scarica = algScarica($this, $chi, $foto, $porta)->assertOk();
        expect((string) $scarica->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('cancello rotto.jpg')
            ->and(strtolower((string) $scarica->headers->get('X-Content-Type-Options')))->toBe('nosniff');
    }
});

test('un file a database con un tipo fuori dai quattro non si apre mai nel browser: si scarica soltanto', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $id = algAllegatoSalvato($s, $this->mario, 'foto.jpg');
    DB::table('documenti')->where('id', $id)->update(['mime_type' => 'text/html']);

    $apri = $this->actingAs($this->mario)->get(route('user.allegati.apri', ['allegato' => $id]));

    expect($apri->headers->get('Content-Type'))->not->toContain('text/html')
        ->and((string) $apri->headers->get('Content-Disposition'))->not->toStartWith('inline');
});

test('le props degli allegati portano il nome dell\'autore, non il suo account: niente email, niente percorso sul disco', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    algAllegatoSalvato($s, $this->luigi, 'foto.jpg');

    foreach ([['user', $this->mario], ['admin', $this->admin]] as [$porta, $chi]) {
        $allegati = algScheda($this, $chi, $s, $porta)['allegati'];
        $riga = $allegati[0];
        $testo = json_encode($allegati);

        expect($riga)->toHaveKeys(['id', 'nome', 'mime_type', 'dimensione', 'stato', 'autore', 'caricato_il', 'url_apri', 'url_scarica', 'puo_modificare', 'puo_eliminare', 'puo_approvare'])
            ->and($riga['autore'])->toBe('Luigi Verdi')
            ->and($riga['stato'])->toBe('pubblicato')
            ->and($testo)->not->toContain($this->luigi->email)
            ->and($testo)->not->toContain('documenti/allegati')
            ->and(array_values(array_intersect(array_keys($riga), ['path', 'created_by', 'email', 'created_by_user'])))->toBe([]);
    }
});

test('l\'archivio dei documenti non mostra gli allegati, né nel pannello né nel portale (guardia: è già così)', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $id = algAllegatoSalvato($s, $this->mario, 'foto-del-guasto.jpg');

    $this->actingAs($this->admin)->get(route('admin.documenti.index'))->assertOk()->assertDontSee('foto-del-guasto.jpg');
    $this->actingAs($this->admin)->get(route('admin.documenti.download', ['documento' => $id]))->assertNotFound();
    expect(algRifiutato($this->actingAs($this->mario)->get(route('user.documenti.download', ['documento' => $id]))))->toBeTrue();
});

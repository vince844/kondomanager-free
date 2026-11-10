<?php

/**
 * # Allegati: chi carica, e dove (Coda 460, decisione 241.2 e 241.3)
 *
 * Sulle segnalazioni carica chi ha il permesso e potrebbe commentarle: chi le vede dal portale (la regola di
 * `SegnalazionePolicy::show`, una sola, Code 198, 263 e 304) e sta nel palazzo, come chiede
 * `CommentoPolicy::create` (Coda 305), con gli allegati «aperti». Sulle comunicazioni caricano l'autore e chi
 * la può modificare dal pannello; i lettori mai. Dal pannello, chi ha «Carica» aggiunge file in ogni stato
 * dell'elemento: «Solo consultazione» e «Solo amministrazione» chiudono il portale, non lo studio.
 *
 * La scheda offre il caricamento (`allegati_config.puo_caricare`) se e solo se la rotta lo accetta: la griglia
 * in fondo lo prova per ogni persona e ogni elemento che apre.
 */

use App\Models\Segnalazione;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->luigi = algUtente([$this->aurora->id], 'Luigi Verdi');
    $this->carla = algUtente([$this->borea->id], "Carla D'Amico");
});

test('sulla segnalazione non privata e senza persone collegate carica ogni condòmino del palazzo; chi sta in un altro palazzo no', function () {
    $s = algSegnalazione($this->aurora, $this->mario);

    algCarica($this, $this->luigi, $s, [algFoto()])->assertSessionHasNoErrors();
    expect(algRifiutato(algCarica($this, $this->carla, $s, [algFoto()])))->toBeTrue();
    expect(algRighe($s))->toHaveCount(1);
});

test('la segnalazione privata di un vicino e quella in moderazione di un vicino non ricevono file; la propria in moderazione sì', function () {
    $privata = algSegnalazione($this->aurora, $this->mario, ['is_private' => true]);
    $inModerazione = algSegnalazione($this->aurora, $this->mario, ['is_approved' => false]);

    expect(algRifiutato(algCarica($this, $this->luigi, $privata, [algFoto()])))->toBeTrue()
        ->and(algRifiutato(algCarica($this, $this->luigi, $inModerazione, [algFoto()])))->toBeTrue();

    algCarica($this, $this->mario, $inModerazione, [algFoto()])->assertSessionHasNoErrors();
    expect(algRighe($privata))->toHaveCount(0)->and(algRighe($inModerazione))->toHaveCount(1);
});

test('la persona collegata a una segnalazione di un palazzo dove non sta la legge, ma non carica (come per il commento)', function () {
    $s = algSegnalazione($this->aurora, $this->mario, [], [$this->carla]);

    expect(algScheda($this, $this->carla, $s))->not->toBeNull()
        ->and(algRifiutato(algCarica($this, $this->carla, $s, [algFoto()])))->toBeTrue();
});

test('con gli allegati in «Solo consultazione» o «Solo amministrazione» il portale non carica; il pannello sì', function () {
    foreach (['consultazione', 'riservati'] as $accesso) {
        $s = algSegnalazione($this->aurora, $this->mario, ['allegati_accesso' => $accesso]);

        expect(algRifiutato(algCarica($this, $this->mario, $s, [algFoto()])))->toBeTrue("Il portale carica con «{$accesso}»");
        algCarica($this, $this->admin, $s, [algPdf()], 'admin')->assertSessionHasNoErrors();
        expect(algRighe($s))->toHaveCount(1);
    }
});

test('gli allegati non dipendono dai commenti: con la discussione chiusa si carica lo stesso', function () {
    $s = algSegnalazione($this->aurora, $this->mario, ['can_comment' => false]);

    algCarica($this, $this->luigi, $s, [algFoto()])->assertSessionHasNoErrors();
    expect(algRighe($s))->toHaveCount(1);
});

test('sulla comunicazione il lettore non carica; l\'autore dal portale sì, con il permesso', function () {
    $autore = algUtente([$this->aurora->id], 'Lucia Bassi', algRuolo('Scrive comunicazioni', [
        'Crea comunicazioni', 'Visualizza comunicazioni', 'Modifica proprie comunicazioni',
        ALG_PERMESSI['comunicazioni']['carica'], ALG_PERMESSI['comunicazioni']['visualizza'],
    ]));
    $c = algComunicazione([$this->aurora], $autore);

    expect(algScheda($this, $this->mario, $c))->not->toBeNull()
        ->and(algRifiutato(algCarica($this, $this->mario, $c, [algPdf()])))->toBeTrue();

    algCarica($this, $autore, $c, [algPdf()])->assertSessionHasNoErrors();
    expect(algRighe($c))->toHaveCount(1);
});

test('dal pannello caricano sulla comunicazione chi la può modificare: l\'amministratore su quella del collaboratore, il collaboratore su quella dell\'amministratore', function () {
    $collaboratore = algUtente([$this->aurora->id], 'Carla Neri', 'collaboratore');
    $delCollaboratore = algComunicazione([$this->aurora], $collaboratore);
    $dellAmministratore = algComunicazione([$this->aurora], $this->admin);

    algCarica($this, $this->admin, $delCollaboratore, [algPdf()], 'admin')->assertSessionHasNoErrors();
    algCarica($this, $collaboratore, $dellAmministratore, [algPdf()], 'admin')->assertSessionHasNoErrors();

    expect(algRighe($delCollaboratore))->toHaveCount(1)->and(algRighe($dellAmministratore))->toHaveCount(1);
});

test('chi non ha «Carica» non carica, anche dove potrebbe commentare', function () {
    $soloCommenti = algUtente([$this->aurora->id], 'Paolo Galli', algRuolo('Commenta soltanto', [
        'Visualizza segnalazioni', 'Commenta segnalazioni', ALG_PERMESSI['segnalazioni']['visualizza'],
    ]));
    $s = algSegnalazione($this->aurora, $this->mario);

    expect(algRifiutato(algCarica($this, $soloCommenti, $s, [algFoto()])))->toBeTrue()
        ->and(algRighe($s))->toHaveCount(0);
});

test('per ogni persona e ogni elemento che apre, la scheda offre il caricamento se e solo se la rotta lo accetta', function () {
    $collaboratore = algUtente([$this->aurora->id], 'Carla Neri', 'collaboratore');
    $lettore = algUtente([$this->aurora->id], 'Paolo Galli', algRuolo('Solo lettura', [
        'Visualizza segnalazioni', 'Visualizza comunicazioni',
        ALG_PERMESSI['segnalazioni']['visualizza'], ALG_PERMESSI['comunicazioni']['visualizza'],
    ]));

    $elementi = [
        algSegnalazione($this->aurora, $this->mario),
        algSegnalazione($this->aurora, $this->mario, ['is_private' => true]),
        algSegnalazione($this->aurora, $this->mario, ['allegati_accesso' => 'consultazione']),
        algSegnalazione($this->aurora, $this->mario, ['allegati_accesso' => 'riservati']),
        algSegnalazione($this->aurora, $this->mario, [], [$this->carla]),
        algSegnalazione($this->borea, $this->carla),
        algComunicazione([$this->aurora], $this->admin),
        algComunicazione([$this->aurora, $this->borea], $collaboratore),
    ];

    $persone = [
        [$this->mario, 'user'], [$this->luigi, 'user'], [$this->carla, 'user'], [$lettore, 'user'],
        [$this->admin, 'admin'], [$collaboratore, 'admin'],
    ];

    $casi = 0;
    foreach ($persone as [$persona, $porta]) {
        foreach ($elementi as $elemento) {
            $props = algScheda($this, $persona, $elemento, $porta);
            if ($props === null) {
                continue;
            }
            $casi++;
            $offerto = (bool) $props['allegati_config']['puo_caricare'];
            $stato = algCarica($this, $persona, $elemento, [algFoto('prova.jpg')], $porta)->status();
            $accettato = ! in_array($stato, [403, 404], true);

            expect($offerto)->toBe($accettato, "{$persona->name} ({$porta}), " . class_basename($elemento) . " {$elemento->id}: offerto " . var_export($offerto, true) . ", risposta {$stato}");

            // Si riparte da vuoto, perché il limite dei cinque non entri nella griglia.
            \Illuminate\Support\Facades\DB::table('documenti')->where('documentable_id', $elemento->id)->where('documentable_type', $elemento->getMorphClass())->delete();
        }
    }

    expect($casi)->toBeGreaterThan(20);
});

test('l\'elenco dei formati, il massimo e i posti rimasti arrivano alla scheda, con il limite di dimensione dei documenti', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    algAllegatoSalvato($s, $this->mario, 'uno.jpg');
    algAllegatoSalvato($s, $this->luigi, 'due.jpg', approvato: false);

    $config = algScheda($this, $this->mario, $s)['allegati_config'];

    expect($config['massimo'])->toBe(5)
        // Contano anche gli allegati in attesa degli altri, che Mario non vede: i posti sono dell'elemento.
        ->and($config['rimasti'])->toBe(3)
        ->and($config['formati'])->toBe(['jpg', 'jpeg', 'png', 'webp', 'pdf'])
        ->and($config['limite_file'])->toBe(\App\Support\LimiteCaricamento::etichetta())
        ->and($config['accesso'])->toBe('aperti');
});

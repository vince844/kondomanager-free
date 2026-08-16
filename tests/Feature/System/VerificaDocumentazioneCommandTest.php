<?php

/**
 * Il comando che misura lo stato di `docs/`.
 *
 * ## Perché questo file esiste
 *
 * Il comando è nato per essere lanciato di rado — in apertura di beta — e un comando che si lancia
 * di rado è quello che nessuno si accorge che ha smesso di funzionare. In più ha una regola di
 * giudizio che si può sbagliare in silenzio: distinguere un riferimento **certamente** rotto da uno
 * **ambiguo**. La prima versione sceglieva il file omonimo più lungo e dichiarava «risolve»,
 * facendo passare per sano un riferimento rotto: è la trappola della beta.52, misurare una cosa e
 * riportarla come se ne avesse misurata un'altra.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre il marciume semantico — un riferimento che risolve mentre il contenuto di quelle righe
 * è cambiato — perché il comando **non lo vede e non promette di vederlo**. Non copre la
 * formattazione dell'output oltre alle parole che contano.
 */

use Illuminate\Support\Facades\Artisan;

it('conta i documenti e dichiara la versione in sviluppo', function () {
    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('documenti')
        ->and($uscita)->toContain('versione in sviluppo');
});

it('un riferimento a un file inesistente è un falso certo', function () {
    Artisan::call('kondomanager:verifica-documentazione', ['--documento' => 'roadmap']);
    $uscita = Artisan::output();

    // Il documento vero non deve avere riferimenti a file spariti: se questo test si accende,
    // qualcuno ha rinominato o cancellato un file che la roadmap cita.
    expect($uscita)->toContain('Riferimenti file:riga che non risolvono: nessuno');
});

it('distingue l\'ambiguo dal rotto, invece di scegliere per conto suo', function () {
    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    // Nel progetto esistono trenta `DataTableRowActions.vue`: un riferimento al solo nome non è
    // «risolto», è indecidibile. Il comando deve dirlo, non indovinare.
    expect($uscita)->toContain('Riferimenti ambigui')
        ->and($uscita)->toContain('Si risolve scrivendo il percorso, non indovinando.');
});

it('mostra l\'età in beta, che è la cosa che nessuno calcola leggendo', function () {
    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('beta fa');
});

it('l\'età non mescola le serie: i numeri di beta ripartono da uno', function () {
    // ⚠️ Reperto della revisione della beta.56. L'età si calcolava sul numero più alto trovato,
    // qualunque serie fosse: all'apertura della 1.11 ogni documento verificato nella 1.10 avrebbe
    // avuto un'età **negativa** — «-37 beta fa» — e il comando esiste proprio per dire l'età.
    config(['app.version' => '1.11.0-beta.3']);

    Artisan::call('kondomanager:verifica-documentazione', ['--eta' => 1]);
    $uscita = Artisan::output();

    expect($uscita)->not->toMatch('#-\d+ beta fa#');

    // E la roadmap, verificata dentro la 1.10, dev'essere fra i vecchi: non fra i freschi.
    expect($uscita)->toContain('roadmap.md');
});

it('il filtro sull\'età non mostra niente quando nessun documento la supera', function () {
    Artisan::call('kondomanager:verifica-documentazione', ['--eta' => 999]);
    $uscita = Artisan::output();

    expect($uscita)->toContain('Nessun documento più vecchio di 999 beta.');
});

it('pretende che ogni voce di coda aperta stia nell\'indice della roadmap', function () {
    // L'indice ha le parole chiave scritte a mano — è il suo valore — ma la completezza la
    // verifica il comando: una lista mantenuta a memoria invecchia da sola.
    Artisan::call('kondomanager:verifica-documentazione');

    expect(Artisan::output())->toContain('Voci di coda che l\'indice della roadmap non elenca: nessuno');
});

it('non chiede al changelog un\'intestazione di stato', function () {
    // Il changelog non descrive codice: descrive cosa è cambiato, e ogni voce porta la sua
    // versione. Segnalarlo era un rilievo che nessuno avrebbe mai chiuso — e un elenco con dentro
    // una voce eterna è un elenco che si impara a saltare.
    Artisan::call('kondomanager:verifica-documentazione');

    expect(Artisan::output())->not->toContain('· changelog.md');
});

it('non modifica niente: è una diagnosi', function () {
    $prima = collect(glob(base_path('docs/*.md')))->mapWithKeys(fn ($f) => [$f => md5_file($f)]);

    Artisan::call('kondomanager:verifica-documentazione');

    $dopo = collect(glob(base_path('docs/*.md')))->mapWithKeys(fn ($f) => [$f => md5_file($f)]);

    expect($dopo->all())->toBe($prima->all());
});

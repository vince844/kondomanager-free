<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il registro del programma non riceve il testo libero dell'amministratore (Coda 237).
 *
 * Il motivo di uno storno e la causale di un giroconto li scrive l'amministratore, e portano nomi e fatti delle
 * persone («Mario Rossi ha pagato in contanti al portiere»). Restano nella scrittura, dove servono; nel registro
 * (`storage/logs`, che chi gestisce il server legge e che va nei rapporti degli errori) vanno l'id, l'utente,
 * l'importo e la lunghezza del testo.
 *
 * Due prove: il giroconto registrato e stornato dalla pagina non lascia il nome nel registro; e una guardia sul
 * sorgente che legge ogni chiamata `Log::` dell'applicazione e non vi ammette `motivo`, `causale`, `note` o
 * `descrizione` se non dentro `strlen`/`mb_strlen`. Le eccezioni sono scritte qui sotto, con il perché.
 */

use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

/** Le chiamate `Log::` che nominano uno di quei campi senza portarne il testo. File => frammento atteso. */
const LOG_CON_UN_CAMPO_SENZA_TESTO = [
    // `motivo` qui è la ragione dell'evento («nuovo» o «aggiornato»), un valore del programma.
    'app/Listeners/Notifiche/AvvisaIDestinatari.php' => '->motivo',
    // `note` qui sono gli id delle note di credito spostate, non un testo.
    'app/Services/Gestionale/FattureToccateDallaModifica.php' => "\$f['note']",
];

function registroLiberoCassa(int $condominioId, string $tipo, int $saldo): Cassa
{
    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'ruolo' => $tipo === 'banca' ? 'conto_bancario' : 'fondo_riserva',
        'codice' => '1010.'.uniqid(),
        'nome' => 'Conto '.$tipo.' '.uniqid(),
        'tipo' => 'attivo',
        'categoria' => 'liquidita',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Cassa::create([
        'condominio_id' => $condominioId,
        'nome' => ucfirst($tipo).' '.uniqid(),
        'tipo' => $tipo,
        'conto_contabile_id' => $contoId,
        'saldo_iniziale' => $saldo,
        'attiva' => true,
        'sottotipo_fondo' => $tipo === 'fondo' ? 'generico' : null,
    ]);
}

it('il giroconto registrato e stornato dalla pagina non lascia il testo libero nel registro', function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $admin = User::factory()->create();
    $admin->assignRole($ruolo);

    [$condominio, $esercizio, $gestione] = setupContabile();
    $banca = registroLiberoCassa($condominio->id, 'banca', 100000);
    $fondo = registroLiberoCassa($condominio->id, 'fondo', 0);

    $registro = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$registro) {
        $registro[] = $m->message.' '.json_encode($m->context);
    });

    $this->actingAs($admin)->post(route('admin.gestionale.giroconti.store', $condominio), [
        'cassa_origine_id' => $banca->id,
        'cassa_destinazione_id' => $fondo->id,
        'importo' => 250.00,
        'data_operazione' => now()->format('Y-m-d'),
        'causale' => 'Mario Rossi ha pagato in contanti al portiere',
        'gestione_id' => $gestione->id,
        'esercizio_id' => $esercizio->id,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $giroconto = ScritturaContabile::where('tipo_movimento', 'giroconto')->firstOrFail();
    expect($giroconto->causale)->toContain('Rossi');

    $this->actingAs($admin)->post(route('admin.gestionale.giroconti.storno', [$condominio, $giroconto]), [
        'motivo' => 'Mario Rossi ha pagato in contanti al portiere',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(ScritturaContabile::where('scrittura_padre_id', $giroconto->id)->exists())->toBeTrue();
    expect($registro)->not->toBeEmpty();
    foreach ($registro as $riga) {
        expect($riga)->not->toContain('Rossi');
    }
});

it('nessuna chiamata Log:: dell\'applicazione porta motivo, causale, note o descrizione', function () {
    $chiamata = '/Log::(info|warning|notice|debug|error|critical|alert|emergency)\s*\(/';
    $campo = '/(\$\w+\[\s*[\'"](motivo|causale|note|descrizione)[\'"]\s*\]|->(motivo|causale|note|descrizione)\b|\$(motivo|causale|note|descrizione)\b)/';
    $trovati = [];

    $file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($file as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $relativo = str_replace(base_path().'/', '', $f->getPathname());
        $sorgente = file_get_contents($f->getPathname());

        preg_match_all($chiamata, $sorgente, $inizi, PREG_OFFSET_CAPTURE);
        foreach ($inizi[0] as [, $inizio]) {
            $fine = strpos($sorgente, ');', $inizio);
            $frammento = substr($sorgente, $inizio, ($fine === false ? strlen($sorgente) : $fine) - $inizio);

            preg_match_all($campo, $frammento, $campi, PREG_OFFSET_CAPTURE);
            foreach ($campi[0] as [$testo, $dove]) {
                $prima = substr($frammento, max(0, $dove - 20), min(20, $dove));
                if (preg_match('/(mb_)?strlen\(\s*(\(string\)\s*)?$/', $prima)) {
                    continue;
                }
                if ((LOG_CON_UN_CAMPO_SENZA_TESTO[$relativo] ?? null) === $testo) {
                    continue;
                }
                $riga = substr_count(substr($sorgente, 0, $inizio), "\n") + 1;
                $trovati[] = "{$relativo}:{$riga} {$testo}";
            }
        }
    }

    expect($trovati)->toBe([]);
});

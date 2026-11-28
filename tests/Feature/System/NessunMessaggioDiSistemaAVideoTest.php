<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il testo di un guasto non arriva in pagina (Coda 279).
 *
 * Un `catch` generico di un controller (`\Throwable` o `\Exception`) mostra in pagina la frase delle regole del
 * programma come è, e per un guasto (il database, un file, un errore di PHP) una frase fissa con un riferimento,
 * mentre il dettaglio va nel registro con lo stesso riferimento (`MessaggioPerLaPagina`).
 *
 * Tre prove: la classe da sola; due controller, uno per famiglia (il messaggio della pagina con `flashError` e
 * l'errore del modulo con `withErrors`), con un servizio finto che solleva una `QueryException`; e una guardia sul
 * sorgente che legge i `catch` generici dei controller e non vi ammette `getMessage()` fuori dal registro.
 */

use App\Actions\Cassa\CreateCassaAction;
use App\Enums\Role as RoleEnum;
use App\Models\Condominio;
use App\Models\User;
use App\Services\UpdateService;
use App\Support\Errori\MessaggioPerLaPagina;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

/** I `catch` generici che possono mostrare il testo dell'eccezione, con il perché. File => motivo. */
const CATCH_CHE_MOSTRANO_IL_TESTO = [
    // La prova della posta: il rifiuto del server di posta è proprio la risposta che l'amministratore ha chiesto.
    'app/Http/Controllers/Impostazioni/MailSettingsController.php' => 'la risposta del server di posta',
];

function guastoDelDatabase(): QueryException
{
    return new QueryException('mysql', 'insert into `casse` (`nome`) values (?)', ['Banca'],
        new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'Banca' for key 'casse_nome_unique'"));
}

beforeEach(function () {
    config(['app.debug' => false]);
});

it('la regola del programma resta com\'è, il guasto diventa una frase con il riferimento', function () {
    expect(MessaggioPerLaPagina::di(new RuntimeException('La voce è già stata chiesta tutta.'), 'prova'))
        ->toBe('La voce è già stata chiesta tutta.')
        ->and(MessaggioPerLaPagina::di(new Exception('Il conto non è del condominio.'), 'prova'))
        ->toBe('Il conto non è del condominio.')
        ->and(MessaggioPerLaPagina::di(new \App\Exceptions\Gestionale\GirocontoNonAmmessoException('Il fondo vincolato non esce.'), 'prova'))
        ->toBe('Il fondo vincolato non esce.');

    $registro = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$registro) {
        $registro[] = $m->context['riferimento'] ?? null;
    });

    foreach ([guastoDelDatabase(), new Exception('Errore: SQLSTATE[42S22]: Column not found'), new TypeError('Argument #1 must be of type int')] as $guasto) {
        $frase = MessaggioPerLaPagina::di($guasto, 'prova');
        expect($frase)->not->toContain('SQLSTATE')
            ->not->toContain('Argument #1')
            ->toContain(end($registro));
    }
});

it('con il debug acceso chi sviluppa vede anche il testo vero', function () {
    config(['app.debug' => true]);

    expect(MessaggioPerLaPagina::di(guastoDelDatabase(), 'prova'))->toContain('SQLSTATE[23000]');
});

it('la cassa che non si salva per un guasto del database non mostra SQLSTATE nel messaggio della pagina', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(RoleEnum::AMMINISTRATORE->value);
    $condominio = Condominio::factory()->create();

    $this->mock(CreateCassaAction::class, fn ($m) => $m->shouldReceive('execute')->andThrow(guastoDelDatabase()));

    $risposta = $this->actingAs($admin)->post(route('admin.gestionale.casse.store', $condominio), [
        'nome' => 'Banca', 'tipo' => 'contanti',
    ]);

    $risposta->assertRedirect();
    $sessione = json_encode(session()->all());
    expect($sessione)->not->toContain('SQLSTATE')
        ->toContain('problema del programma');
});

it('l\'aggiornamento che non si prepara per un guasto non mostra il testo nell\'errore del modulo', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['installer.run_installer' => true]);
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->mock(UpdateService::class, function ($m) {
        $m->shouldReceive('isAutoUpdateEnabled')->andReturn(true);
        $m->shouldReceive('checkRemoteVersion')->andReturn(['version' => '9.9.9']);
        $m->shouldReceive('prepareForUpgrade')->andThrow(guastoDelDatabase());
    });

    $this->actingAs($admin)->from('/system/upgrade')->post(route('system.upgrade.launch'))
        ->assertRedirect('/system/upgrade')
        ->assertSessionHasErrors('msg');

    $errore = session('errors')->first('msg');
    expect($errore)->not->toContain('SQLSTATE')->toContain('problema del programma');
});

it('nessun catch generico dei controller mette il testo dell\'eccezione in pagina', function () {
    $catch = '/catch\s*\(\s*([\\\\\w|\s]+?)\s+\$(\w+)\s*\)\s*\{/';
    $registro = '/(\\\\?Illuminate\\\\Support\\\\Facades\\\\)?Log::\w+\s*\(|report\s*\(|ErroriDiagnosticabili::registra\s*\(|MessaggioPerLaPagina::di\s*\(|logger\(\)->\w+\(/';
    $trovati = [];

    $file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Http/Controllers')));
    foreach ($file as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $relativo = str_replace(base_path().'/', '', $f->getPathname());
        if (array_key_exists($relativo, CATCH_CHE_MOSTRANO_IL_TESTO)) {
            continue;
        }
        $sorgente = file_get_contents($f->getPathname());

        preg_match_all($catch, $sorgente, $blocchi, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($blocchi as $b) {
            $tipi = array_map(fn ($t) => class_basename(trim($t)), explode('|', $b[1][0]));
            if (! array_intersect($tipi, ['Throwable', 'Exception'])) {
                continue;
            }

            // Il corpo del blocco, fino alla graffa che lo chiude.
            $inizio = $b[0][1] + strlen($b[0][0]);
            $i = $inizio;
            for ($livello = 1; $livello > 0 && $i < strlen($sorgente); $i++) {
                $livello += $sorgente[$i] === '{' ? 1 : ($sorgente[$i] === '}' ? -1 : 0);
            }
            $corpo = substr($sorgente, $inizio, $i - $inizio);

            // Il registro può avere il testo: le chiamate al registro si tolgono, con le loro parentesi.
            while (preg_match($registro, $corpo, $m, PREG_OFFSET_CAPTURE)) {
                $j = $m[0][1] + strlen($m[0][0]);
                for ($p = 1; $p > 0 && $j < strlen($corpo); $j++) {
                    $p += $corpo[$j] === '(' ? 1 : ($corpo[$j] === ')' ? -1 : 0);
                }
                $corpo = substr($corpo, 0, $m[0][1]).substr($corpo, $j);
            }

            $variabile = '$'.$b[2][0];
            // Leggere il testo per decidere (`str_contains`) non lo mostra.
            $corpo = preg_replace('/(str_contains|stripos|strpos)\(\s*'.preg_quote($variabile, '/').'->getMessage\(\)/', '', $corpo);
            if (str_contains($corpo, $variabile.'->getMessage()')) {
                $riga = substr_count(substr($sorgente, 0, $b[0][1]), "\n") + 1;
                $trovati[] = "{$relativo}:{$riga}";
            }
        }
    }

    expect($trovati)->toBe([]);
});

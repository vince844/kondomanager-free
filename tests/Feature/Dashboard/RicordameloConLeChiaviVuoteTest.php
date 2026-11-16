<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Ricordamelo» scrive sempre le due chiavi che fanno tornare prima un compito, `aggiornato_il` e `scadenza`, con `null` quando non servono: per la
 * segnalazione e il bonifico segnalato tutte e due, per i commenti e gli allegati la scadenza. La regola che nasconde il compito legge un `null` del JSON
 * come un valore che manca, su ogni database: su SQLite `json_extract` dà NULL; su MySQL `JSON_UNQUOTE` di un `null` del JSON dà la parola «null», e la
 * regola la riporta a NULL con `NULLIF`. Senza, su MySQL questi compiti restavano nell'Inbox dopo il «Ricordamelo».
 *
 * La suite gira su SQLite: la prima prova tiene la forma dei dati (le chiavi scritte, con `null`) e il compito nascosto; la seconda tiene la lettura del
 * ramo di MySQL.
 */

use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

it('i compiti con il promemoria a parte e senza scadenza: le due chiavi sono scritte con null, e il compito resta nascosto fino al giorno', function (string $tipo) {
    $e = $tipo === 'verifica_pagamento' ? icsBonifico($this)[0] : icsCompito($this, $tipo, [], 'Pagare la ditta');
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    $r = RispostaPromemoria::where('evento_id', $e->id)->where('argomento', 'compito')->sole();
    $grezzo = (string) DB::table('risposte_promemoria')->where('id', $r->id)->value('dati');

    expect($r->dati)->toHaveKeys(['aggiornato_il', 'scadenza'])
        ->and($r->dati['scadenza'])->toBeNull()
        ->and($grezzo)->toContain('"scadenza":null')
        ->and(Evento::query()->compitiAperti()->whereKey($e->id)->exists())->toBeFalse()
        ->and(Evento::query()->compitiNonChiusi()->whereKey($e->id)->exists())->toBeTrue()
        ->and(icsCasella($this))->toBe([]);
    if (in_array($tipo, ['segnalazione_guasto', 'verifica_pagamento'], true)) {
        expect($r->dati['aggiornato_il'])->toBeNull()->and($grezzo)->toContain('"aggiornato_il":null');
    }

    // Il giorno scelto il compito torna.
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-06-15 00:30', 'Europe/Rome')->utc());
    expect(Evento::query()->compitiAperti()->whereKey($e->id)->exists())->toBeTrue();
})->with(['segnalazione_guasto', 'verifica_pagamento', 'commento', 'allegato']);

it('sul ramo di MySQL un null del JSON letto dalla regola è NULL, non la parola «null»', function () {
    $sorgente = (string) file_get_contents(app_path('Models/Evento.php'));
    preg_match('/function sqlNienteDiNuovo\(\).*?\n    }\n/s', $sorgente, $m);
    $funzione = $m[0] ?? '';
    preg_match('/\} else \{\n(.*?)\n        \}\n/s', $funzione, $ramo);

    expect($funzione)->toContain('JSON_UNQUOTE(JSON_EXTRACT(')
        ->and($ramo[1] ?? '')->toContain("NULLIF(JSON_UNQUOTE(JSON_EXTRACT({\$colonna}, '\\\$.{\$chiave}')), 'null')");
});

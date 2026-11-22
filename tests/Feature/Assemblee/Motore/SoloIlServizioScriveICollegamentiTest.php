<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13 (Coda 679, §8 e §13 rilievo 22 del progetto della tappa 2): `delibere_collegamenti` si scrive solo da `CollegamentiDelleDelibere`. La lettura
 * del codice di `app/`, come la lista chiusa degli scrittori del registro: chi nomina la tabella o il modello con una scrittura (`create`, `update`,
 * `delete`, `insert`, `save`) fuori dal servizio fa rosso.
 *
 * Che cosa non copre: una scrittura su una relazione tenuta in una variabile, o su una query costruita altrove; le letture (le fanno anche le proposte e il
 * comando di verifica, ed è giusto).
 */

it('nessun file di app/, fuori dal servizio, scrive i collegamenti', function () {
    $scrittori = [];
    $scrittura = '/(DB::table\(\s*[\'"]delibere_collegamenti[\'"]\s*\)[^;]*->(insert|update|delete|upsert|insertOrIgnore|updateOrInsert)\s*\()'
        . '|(CollegamentoAllaDelibera::(query\(\)[^;]*->)?(create|insert|update|delete|firstOrCreate|updateOrCreate|forceCreate)\s*\()/s';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $percorso = str_replace(base_path() . '/', '', $file->getPathname());
        if ($percorso === 'app/Services/Assemblee/CollegamentiDelleDelibere.php') {
            continue;
        }
        if (preg_match($scrittura, (string) file_get_contents($file->getPathname()))) {
            $scrittori[] = $percorso;
        }
    }

    expect($scrittori)->toBe([]);
});

it('la guardia morde: una scrittura con il modello o con la tabella si vede', function () {
    $scrittura = '/(DB::table\(\s*[\'"]delibere_collegamenti[\'"]\s*\)[^;]*->(insert|update|delete|upsert|insertOrIgnore|updateOrInsert)\s*\()'
        . '|(CollegamentoAllaDelibera::(query\(\)[^;]*->)?(create|insert|update|delete|firstOrCreate|updateOrCreate|forceCreate)\s*\()/s';
    expect(preg_match($scrittura, "CollegamentoAllaDelibera::create(['ruolo' => 'x']);"))->toBe(1)
        ->and(preg_match($scrittura, "DB::table('delibere_collegamenti')->where('id', 1)->delete();"))->toBe(1)
        ->and(preg_match($scrittura, "CollegamentoAllaDelibera::query()->where('id', 1)->delete();"))->toBe(1)
        ->and(preg_match($scrittura, "DB::table('delibere_collegamenti')->where('id', 1)->exists();"))->toBe(0);
});

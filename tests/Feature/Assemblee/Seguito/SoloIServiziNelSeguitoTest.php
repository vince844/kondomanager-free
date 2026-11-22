<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * R3 del seguito delle delibere: nessuna scrittura nei moduli fuori dal servizio del modulo. La cartella `app/Services/Assemblee/Seguito/` non scrive in
 * blocco nessuna tabella (`DB::table(...)->update/insert/delete`), non scrive i modelli dei moduli (il piano, le versioni, la fattura, l'accordo, la dispensa,
 * il fondo, la comunicazione, il compito: `->update(`, `->save(`, `::create(` e le loro sorelle), e i collegamenti alle delibere li scrive solo
 * `CollegamentiDelleDelibere`. I modelli delle assemblee (la delibera, la cosa da fare) sono del seguito stesso.
 *
 * Come legge: per ogni file, le classi dei modelli fuori da `App\Models\Assemblee` (dagli `use` e dai nomi scritti per intero), le variabili legate a quelle
 * classi (il tipo di un parametro, `$x = Classe::…`, `foreach (Classe::… as $x)`, `$x instanceof Classe`, `@var Classe $x`), poi le scritture su quelle
 * variabili e sulle classi.
 *
 * Che cosa non copre: una scrittura su una variabile senza tipo (un `Model` reso da `SchedaDelCondominio::cerca()`), o su un modello raggiunto da una
 * relazione (`$assemblea->documenti()->create(…)`, i documenti dell'assemblea): le catene provano che passano dai servizi dei moduli.
 */

/** Le scritture sui modelli dei moduli in un testo PHP: «Classe::metodo» o «$variabile->metodo». @return list<string> */
function sisScrittureNeiModuli(string $testo): array
{
    // Il nome con cui il file scrive la classe => la classe: l'alias o il nome corto degli `use`, e il nome intero scritto nel codice.
    $classi = [];
    if (preg_match_all('/^use\s+(App\\\\Models\\\\[\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $testo, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as $u) {
            $classi[($u[2] ?? '') !== '' ? $u[2] : substr($u[1], (int) strrpos($u[1], '\\') + 1)] = $u[1];
        }
    }
    if (preg_match_all('/\\\\?(App\\\\Models\\\\[\w\\\\]*\w)(?=::|\s+\$)/', $testo, $m) > 0) {
        foreach ($m[1] as $piena) {
            $classi[$piena] = $piena;
        }
    }
    $deiModuli = array_filter($classi, fn (string $piena) => ! str_starts_with($piena, 'App\\Models\\Assemblee\\') && $piena !== 'App\\Models\\User');
    $trovate = [];
    $variabili = [];
    foreach (array_keys($deiModuli) as $nome) {
        // Il nome corto non deve essere la coda di un nome intero o di un altro nome («Evento» dentro «App\Models\Evento» o «EventoTipo»).
        $n = (str_contains($nome, '\\') ? '' : '(?<![\w\\\\])') . preg_quote($nome, '/') . '(?!\w)';
        foreach (['/' . $n . '\s+(\$\w+)/', '/(\$\w+)\s*=\s*' . $n . '::/', '/foreach\s*\(\s*' . $n . '::[^;{]*?\bas\s+(\$\w+)/s', '/(\$\w+)\s+instanceof\s+\\\\?' . $n . '/',
            '/@var\s+\\\\?' . $n . '\s+(\$\w+)/'] as $schema) {
            if (preg_match_all($schema, $testo, $v) > 0) {
                foreach ($v[1] as $variabile) {
                    $variabili[$variabile] = true;
                }
            }
        }
        if (preg_match_all('/' . $n . '::(create|forceCreate|insert|insertGetId|updateOrCreate|firstOrCreate|upsert|destroy)\(/', $testo, $w) > 0) {
            foreach ($w[1] as $metodo) {
                $trovate[] = $nome . '::' . $metodo;
            }
        }
        if (preg_match_all('/' . $n . '::(?:query|where\w*)\([^;]*?->(update|delete|insert|increment|decrement|forceDelete)\(/s', $testo, $w) > 0) {
            foreach ($w[1] as $metodo) {
                $trovate[] = $nome . '::…->' . $metodo;
            }
        }
    }
    foreach (array_keys($variabili) as $variabile) {
        if (preg_match_all('/' . preg_quote($variabile, '/') . '->(update|save|saveQuietly|delete|forceDelete|forceFill|increment|decrement|push|touch)\(/', $testo, $w) > 0) {
            foreach ($w[1] as $metodo) {
                $trovate[] = $variabile . '->' . $metodo;
            }
        }
    }

    return array_values(array_unique($trovate));
}

it('la cartella del seguito non scrive le tabelle in blocco, i modelli dei moduli, né i collegamenti', function () {
    $trovate = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Services/Assemblee/Seguito'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $testo = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/DB::table\([^;]*?->(update|insert|insertGetId|delete|upsert|increment|decrement)\(/s', $testo, $m) > 0) {
            $trovate[] = basename($file->getPathname()) . ': ' . implode(', ', $m[1]);
        }
        if (preg_match('/CollegamentoAllaDelibera::(create|query\(\)[^;]*->(delete|update))/s', $testo) === 1) {
            $trovate[] = basename($file->getPathname()) . ': scrive i collegamenti';
        }
        $moduli = sisScrittureNeiModuli($testo);
        if ($moduli !== []) {
            $trovate[] = basename($file->getPathname()) . ': ' . implode(', ', $moduli);
        }
    }
    expect($trovate)->toBe([]);
});

it('la guardia morde: una scrittura in blocco, un collegamento scritto a mano e le scritture sui modelli dei moduli si vedono; quelle sulla cosa da fare no', function () {
    $blocco = "DB::table('piani_rate')->where('id', 1)->update(['stato' => 'approvato']);";
    $collegamento = "CollegamentoAllaDelibera::create(['delibera_id' => 1]);";
    $modelli = <<<'PHP'
<?php
use App\Models\Gestionale\VersionePreventivo;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\Gestionale\PianoRate as Piano;
use App\Models\Evento;
use App\Models\Assemblee\CosaDaFareDellaDelibera;

final class Prova
{
    public function uno(VersionePreventivo $versione, CosaDaFareDellaDelibera $cosa): void
    {
        $versione->update(['stato' => 'approvata']);
        $cosa->update(['stato' => 'fatta']);
        foreach (RendicontoVersione::query()->whereNull('verbale_documento_id')->get() as $v) {
            $v->update(['verbale_documento_id' => 1]);
        }
        Piano::query()->whereKey(1)->update(['stato' => 'approvato']);
        $compito = Evento::query()->find(1);
        $compito->forceFill(['is_completed' => true])->save();
        \App\Models\Comunicazione::create(['subject' => 'Avviso']);
    }
}
PHP;
    expect(preg_match('/DB::table\([^;]*?->(update|insert|insertGetId|delete|upsert|increment|decrement)\(/s', $blocco))->toBe(1)
        ->and(preg_match('/CollegamentoAllaDelibera::(create|query\(\)[^;]*->(delete|update))/s', $collegamento))->toBe(1)
        ->and(sisScrittureNeiModuli($modelli))->toEqualCanonicalizing(['$versione->update', '$v->update', 'Piano::…->update', 'RendicontoVersione::…->update', '$compito->forceFill',
            'App\\Models\\Comunicazione::create']);
});

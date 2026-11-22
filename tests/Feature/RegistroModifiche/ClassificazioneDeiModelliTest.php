<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\CatalogoDelRegistro;
use App\Services\RegistroModifiche\Schede\SchedaNelRegistro;
use App\Traits\ContaNelGesto;
use App\Traits\RegistraLeModifiche;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §6 e §13 (1) del progetto, P4): **ogni** modello di `app/Models` è classificato. Usa il tratto, o è «solo gesto» con il tratto
 * leggero, o è fuori con la ragione; un modello nuovo senza classe fa rosso. E ogni colonna di una scheda registrata ha la sua parola della pagina, o è
 * dichiarata esclusa o scritta dal programma: una colonna nuova senza parola fa rosso, invece di finire nel registro con il nome della colonna.
 *
 * Che cosa non copre: che la parola sia quella giusta (la legge la prova a video); i modelli fuori da `app/Models`; le colonne aggiunte
 * da una migrazione che la suite non esegue.
 */

/** I modelli di `app/Models`, come nomi di classe. @return list<class-string> */
function cdmModelli(): array
{
    $modelli = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Models'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relativo = substr($file->getPathname(), strlen(app_path('Models')) + 1, -4);
        $classe = 'App\\Models\\' . str_replace('/', '\\', $relativo);
        if (class_exists($classe) && is_subclass_of($classe, \Illuminate\Database\Eloquent\Model::class) && ! (new ReflectionClass($classe))->isAbstract()) {
            $modelli[] = $classe;
        }
    }
    sort($modelli);

    return $modelli;
}

it('ogni modello di app/Models sta in un elenco solo: tratto, solo gesto o fuori', function () {
    $senza = [];
    $doppi = [];
    foreach (cdmModelli() as $classe) {
        $quanti = (int) isset(CatalogoDelRegistro::TRATTO[$classe]) + (int) isset(CatalogoDelRegistro::SOLO_GESTO[$classe])
            + (int) isset(CatalogoDelRegistro::FUORI[$classe]);
        if ($quanti === 0) {
            $senza[] = $classe;
        } elseif ($quanti > 1) {
            $doppi[] = $classe;
        }
    }

    expect($senza)->toBe([], 'Modelli senza classe nel registro: ' . implode(', ', $senza))
        ->and($doppi)->toBe([])
        ->and(count(cdmModelli()))->toBe(count(CatalogoDelRegistro::TRATTO) + count(CatalogoDelRegistro::SOLO_GESTO) + count(CatalogoDelRegistro::FUORI));
});

it('le schede usano il tratto, i modelli «solo gesto» il tratto leggero, quelli fuori nessuno dei due', function () {
    $sbagliati = [];
    foreach (cdmModelli() as $classe) {
        $tratti = class_uses_recursive($classe);
        $haTratto = isset($tratti[RegistraLeModifiche::class]);
        $haLeggero = isset($tratti[ContaNelGesto::class]);
        $atteso = isset(CatalogoDelRegistro::TRATTO[$classe]) ? 'tratto' : (isset(CatalogoDelRegistro::SOLO_GESTO[$classe]) ? 'leggero' : 'nessuno');
        $ha = $haTratto ? ($haLeggero ? 'tutti e due' : 'tratto') : ($haLeggero ? 'leggero' : 'nessuno');
        if ($ha !== $atteso) {
            $sbagliati[] = "{$classe}: atteso {$atteso}, ha {$ha}";
        }
    }

    expect($sbagliati)->toBe([]);
});

it('il salvataggio e l\'eliminazione delle schede passano dal tratto, nella stessa transazione', function () {
    $altri = [];
    foreach (array_keys(CatalogoDelRegistro::TRATTO) as $classe) {
        foreach (['save', 'delete'] as $metodo) {
            $dichiarato = (new ReflectionMethod($classe, $metodo))->getFileName();
            if (! str_ends_with($dichiarato, 'app/Traits/RegistraLeModifiche.php')) {
                $altri[] = "{$classe}::{$metodo} in {$dichiarato}";
            }
        }
    }

    expect($altri)->toBe([]);
});

it('ogni colonna di una scheda ha la sua parola, o è esclusa o del programma; e nessuna parola nomina una colonna che non c\'è', function () {
    $senzaParola = [];
    $colonneInventate = [];
    foreach (CatalogoDelRegistro::TRATTO as $classe => $descrizione) {
        $modello = new $classe();
        $colonne = Schema::getColumnListing($modello->getTable());
        $dichiarate = [...array_keys($descrizione::ETICHETTE), ...$descrizione::ESCLUSI, ...$descrizione::DEL_PROGRAMMA, ...SchedaNelRegistro::ESCLUSI_DI_SERIE];
        foreach ($colonne as $colonna) {
            if (! in_array($colonna, $dichiarate, true) && ! in_array($colonna, $modello->getHidden(), true)) {
                $senzaParola[] = $descrizione::TIPO . '.' . $colonna;
            }
        }
        foreach ([...array_keys($descrizione::ETICHETTE), ...$descrizione::ESCLUSI, ...$descrizione::DEL_PROGRAMMA, ...array_keys($descrizione::FORMATI)] as $c) {
            if (! in_array($c, $colonne, true)) {
                $colonneInventate[] = $descrizione::TIPO . '.' . $c;
            }
        }
    }

    expect($senzaParola)->toBe([], 'Colonne senza la parola della pagina: ' . implode(', ', $senzaParola))
        ->and($colonneInventate)->toBe([]);
});

it('le dichiarazioni si tengono: tipi unici, aree note, ripristinabili e dati della persona fra i campi con la parola, la parola mai il nome della colonna', function () {
    $tipi = [];
    $errori = [];
    foreach (CatalogoDelRegistro::TRATTO as $classe => $descrizione) {
        $tipi[] = $descrizione::TIPO;
        if ($descrizione::MODELLO !== $classe) {
            $errori[] = "{$descrizione}: MODELLO";
        }
        if (! in_array($descrizione::AREA, RegistroModifica::AREE, true)) {
            $errori[] = $descrizione . ': area ' . $descrizione::AREA;
        }
        foreach ([...$descrizione::RIPRISTINABILI, ...$descrizione::DATI_DELLA_PERSONA, ...$descrizione::SEGRETI] as $c) {
            if (! isset($descrizione::ETICHETTE[$c])) {
                $errori[] = $descrizione::TIPO . '.' . $c . ' senza parola';
            }
        }
        foreach ($descrizione::ETICHETTE as $colonna => $parola) {
            if ($parola === $colonna || str_contains($parola, '_')) {
                $errori[] = $descrizione::TIPO . '.' . $colonna . ': «' . $parola . '»';
            }
        }
    }
    foreach (CatalogoDelRegistro::SOLO_GESTO as $classe => $voce) {
        $tipi[] = $voce['tipo'];
        if (! in_array($voce['area'], RegistroModifica::AREE, true) || trim($voce['ragione']) === '') {
            $errori[] = $classe;
        }
    }
    foreach (CatalogoDelRegistro::FUORI as $classe => $ragione) {
        if (trim($ragione) === '') {
            $errori[] = "{$classe} fuori senza ragione";
        }
    }

    expect($errori)->toBe([])
        ->and(count($tipi))->toBe(count(array_unique($tipi)));
});

it('in contabilità, nei piani rate, negli esercizi, nelle gestioni, nelle voci e nei millesimi «Ripristina» non c\'è', function () {
    $conRipristina = [];
    foreach (CatalogoDelRegistro::TRATTO as $descrizione) {
        if (in_array($descrizione::AREA, ['contabilita', 'piani_rate', 'millesimi'], true) && $descrizione::RIPRISTINABILI !== []) {
            $conRipristina[] = $descrizione::TIPO;
        }
    }

    expect($conRipristina)->toBe([]);
});

it('le ragioni della classificazione non rimandano ai documenti di lavoro della tappa: sono testo del programma, e dicono il perché da sole', function () {
    $ragioni = [...array_column(CatalogoDelRegistro::SOLO_GESTO, 'ragione'), ...array_values(CatalogoDelRegistro::FUORI)];

    expect(array_values(array_filter($ragioni, fn (string $r) => preg_match('/progetto della tappa/u', $r) === 1)))->toBe([]);
});

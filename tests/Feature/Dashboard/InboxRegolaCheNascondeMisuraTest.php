<?php

/**
 * Il peso della regola che nasconde i compiti in attesa di «Ricordamelo» (`Evento::scopeCompitiAperti()`: la lista delle risposte in attesa, letta una volta
 * con l'indice su argomento e stato, e un `NOT IN`): con 5.000 compiti e 500 risposte, l'elenco della casella delle attività, i suoi conteggi e il numero
 * della barra laterale costano al più 50 ms in più delle stesse letture senza la regola. Misurato il 15/11/2026 su un Apple M1 Pro, SQLite in memoria:
 * l'elenco da 1,6 a 2,1 ms, i conteggi da 1,1 a 1,7, la barra da 1,3 a 1,8. Con una lettura delle risposte per ogni compito (un `whereNotExists`), su
 * SQLite, dove la colonna dell'evento non ha un indice suo, l'elenco costava 153 ms.
 */

use App\Models\Evento;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';

const IRN_MS_IN_PIU = 50.0;

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

it('con 5.000 compiti e 500 risposte la regola costa al più 50 ms in più nelle tre letture', function () {
    $adesso = now()->format('Y-m-d H:i:s');
    $categoria = (int) DB::table('categorie_evento')->where('name', 'Scadenze amministrative')->value('id');
    $righe = [];
    foreach (range(1, 5000) as $i) {
        $giorno = now()->subDays($i % 60)->format('Y-m-d H:i:s');
        $righe[] = ['title' => "Compito {$i}", 'start_time' => $giorno, 'end_time' => $giorno, 'created_by' => $this->admin->id, 'category_id' => $categoria, 'visibility' => 'hidden',
            'is_approved' => true, 'is_completed' => false, 'meta' => json_encode(['type' => 'controllo_incassi', 'requires_action' => true]), 'created_at' => $adesso, 'updated_at' => $adesso];
        if (count($righe) === 500) {
            DB::table('eventi')->insert($righe);
            $righe = [];
        }
    }
    $ids = DB::table('eventi')->orderBy('id')->limit(500)->pluck('id')->all();
    $risposte = [];
    foreach ($ids as $k => $id) {
        // Metà in attesa (il giorno nel futuro), metà già tornate.
        $risposte[] = ['condominio_id' => $this->condominio->id, 'argomento' => 'compito', 'evento_id' => $id, 'chiave' => 'compito|' . $id, 'stato' => 'ricordamelo',
            'promemoria_evento_id' => $id, 'dati' => json_encode([]), 'created_at' => $adesso, 'updated_at' => $adesso];
        if ($k % 2 === 0) {
            DB::table('eventi')->where('id', $id)->update(['start_time' => now()->addDays(10)->format('Y-m-d H:i:s')]);
        }
    }
    DB::table('risposte_promemoria')->insert($risposte);

    $senza = fn () => Evento::query()->whereJsonContains('meta->requires_action', true)->where('is_completed', false)
        ->where(fn ($q) => $q->where('visibility', '!=', 'private')->orWhereNull('visibility'));
    $letture = [
        'elenco' => fn ($q) => $q->orderBy('start_time')->limit(15)->get()->count(),
        'conteggi' => fn ($q) => $q->count(),
        'barra' => fn ($q) => $q->where('start_time', '<=', now())->count(),
    ];
    $tempo = function (callable $f): float {
        $f();
        $inizio = hrtime(true);
        foreach (range(1, 5) as $_) {
            $f();
        }

        return (hrtime(true) - $inizio) / 5 / 1e6;
    };
    foreach ($letture as $nome => $lettura) {
        $prima = $tempo(fn () => $lettura($senza()));
        $dopo = $tempo(fn () => $lettura(Evento::query()->compitiAperti()));
        fwrite(STDERR, sprintf("\n[misura] %s: %.1f ms senza la regola, %.1f ms con la regola", $nome, $prima, $dopo));
        expect($dopo - $prima)->toBeLessThan(IRN_MS_IN_PIU, $nome);
    }
    expect(Evento::query()->compitiAperti()->count())->toBe(4750);
});

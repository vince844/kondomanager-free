<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 686 (b), decisione 510: la guardia dei messaggi del piano rate. Nessun messaggio della creazione del piano, della presa d'atto, dell'approvazione e
 * del ricalcolo contiene il nome tecnico di un campo: un nome con il trattino basso, lo stesso nome con gli spazi al posto del trattino («fatture config»,
 * «gestione id»), la frase di serie di Laravel che nomina l'altro campo e il suo valore («… è richiesto quando tipo è straordinario.»), o una parola inglese
 * del codice («config», «id», «array», «string», …).
 *
 * Due parti: (1) senza rotte, ogni campo delle regole ha il suo nome a video in `ParoleDelPiano::ATTRIBUTI`, e nomi e frasi sono puliti — un campo nuovo
 * senza il suo nome fa fallire la prova; (2) dalle rotte vere, molte richieste sbagliate (vuote, con i tipi sbagliati, con gli id di un altro condominio, con
 * i testi troppo lunghi o troppo corti), e ogni messaggio che tornano si legge.
 *
 * Cosa non copre: i messaggi scritti a mano nel codice delle regole (`withValidator`, le regole personalizzate) che le richieste qui non fanno scattare —
 * sono frasi italiane per costruzione, provate dai loro test; le altre richieste del gestionale (le fatture, gli incassi), fuori dal perimetro della Coda 686;
 * la pagina, che mostra i messaggi (`PianiRateNewSpesaFuoriPreventivo.test.ts`).
 */

use App\Http\Requests\Gestionale\PianoRate\CreatePianoRateRequest;
use App\Http\Requests\Gestionale\PianoRate\ParoleDelPiano;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

/** I campi delle richieste vicine, scritti nei controller (la presa d'atto, l'approvazione, il ricalcolo). */
const PRMPS_CAMPI_NEI_CONTROLLER = ['accetta_scoperti', 'nota_scoperti', 'accetta_destinatari', 'nota_destinatari', 'approvato', 'numero_verbale', 'nota_approvazione',
    'data_delibera_assemblea', 'orphan_ids', 'orphan_ids.*'];

/** Le parole inglesi del codice, e le frasi di serie che nominano un altro campo. */
const PRMPS_PAROLE_DEL_CODICE = ['config', 'id', 'ids', 'array', 'string', 'boolean', 'integer', 'numeric', 'required', 'recurrence', 'frequency', 'interval',
    'until', 'null', 'true', 'false', 'enabled', 'orphan', 'by day'];

/** I difetti di un messaggio, o di un nome a video; vuoto se è in parole semplici. */
function prmpsDifetti(string $testo, array $chiavi): array
{
    $difetti = [];
    if (str_contains($testo, '_')) {
        $difetti[] = 'un nome con il trattino basso';
    }
    foreach (PRMPS_PAROLE_DEL_CODICE as $parola) {
        if (preg_match('/(^|[^\pL])' . preg_quote($parola, '/') . '($|[^\pL])/iu', $testo)) {
            $difetti[] = "la parola del codice «{$parola}»";
        }
    }
    foreach ($chiavi as $chiave) {
        // Il nome del campo con gli spazi al posto del trattino basso, l'ultimo pezzo dopo il punto: «fatture config», «gestione id», «tipo autorizzazione».
        $pezzo = collect(explode('.', $chiave))->reject(fn ($p) => $p === '*')->last();
        if ($pezzo !== null && str_contains($pezzo, '_') && mb_stripos($testo, str_replace('_', ' ', $pezzo)) !== false) {
            $difetti[] = "il nome del campo «" . str_replace('_', ' ', $pezzo) . "»";
        }
    }
    if (preg_match('/è richiest[oa] quando|quando tipo|tipo è (straordinario|ordinario)/iu', $testo)) {
        $difetti[] = 'la frase di serie che nomina un altro campo e il suo valore';
    }

    return $difetti;
}

/** Le chiavi delle regole della creazione del piano. */
function prmpsChiaviDelleRegole(): array
{
    return array_keys((new CreatePianoRateRequest())->rules());
}

it('ogni campo della creazione del piano e delle richieste vicine ha il suo nome a video', function () {
    $senzaNome = array_values(array_diff(array_merge(prmpsChiaviDelleRegole(), PRMPS_CAMPI_NEI_CONTROLLER), array_keys(ParoleDelPiano::ATTRIBUTI)));

    expect($senzaNome)->toBe([], 'Questi campi non hanno un nome a video in ParoleDelPiano::ATTRIBUTI: il messaggio di serie li nominerebbe con il nome tecnico.');
});

it('i nomi a video e le frasi delle regole sono in parole semplici', function () {
    $chiavi = array_merge(prmpsChiaviDelleRegole(), PRMPS_CAMPI_NEI_CONTROLLER);
    $testi = array_merge(
        collect(ParoleDelPiano::ATTRIBUTI)->mapWithKeys(fn ($v, $k) => ["nome di {$k}" => $v])->all(),
        collect(ParoleDelPiano::messaggi())->mapWithKeys(fn ($v, $k) => ["frase di {$k}" => $v])->all(),
    );
    $sporchi = collect($testi)->map(fn ($t) => prmpsDifetti($t, $chiavi))->filter()->all();

    expect($sporchi)->toBe([]);
});

/** Un condominio con una gestione, una voce e un piano in bozza: le rotte del piano hanno qualcosa da leggere. */
function prmpsScenario($t): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($t)
        ->tabella('Proprietà', ['A' => 600, 'B' => 400])
        ->titolare('A', 'ugo')->titolare('B', 'bice')
        ->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Pulizie', 120000, ['Proprietà' => 100])
        ->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', capitoli: true, bozza: true);
    app(CondominioService::class)->ensureDefaultConti($s->c);

    return $s;
}

it('dalle rotte vere: nessun messaggio della creazione del piano contiene un nome tecnico', function () {
    $s = prmpsScenario($this);
    $gestione = $s->gestioni['Ordinaria 2026']['g']->id;
    $voce = $s->voci['Pulizie']['conto']->id;
    $lungo = str_repeat('a', 300);

    $richieste = [
        [],
        ['tipo' => 'nessuno', 'gestione_id' => 999999, 'nome' => $lungo, 'metodo_distribuzione' => 'x', 'numero_rate' => 'dodici', 'giorno_scadenza' => 'cinque',
            'data_prima_scadenza' => 'domani', 'note' => ['x'], 'descrizione' => ['x'], 'genera_subito' => 'forse', 'recurrence_enabled' => 1, 'recurrence_interval' => 0,
            'recurrence_by_day' => 'lunedì', 'recurrence_until' => 'mai', 'capitoli_ids' => 'tutte', 'capitoli_config' => 'tutte', 'competenze_capitoli' => 'tutte',
            'saldi_config' => 'tutti', 'tipo_autorizzazione' => 'x', 'motivazione_autorizzazione' => 'ab', 'data_delibera_assemblea' => 'ieri'],
        ['tipo' => 'straordinario', 'gestione_id' => $gestione, 'nome' => 'Piano', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 3, 'fatture_config' => []],
        ['tipo' => 'straordinario', 'gestione_id' => $gestione, 'nome' => 'Piano', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 3, 'fatture_config' => 'tutte'],
        ['tipo' => 'straordinario', 'gestione_id' => 'uno', 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 3,
            'fatture_config' => [['id' => 'x'], ['id' => 999999, 'importo' => '10,00'], []], 'recurrence_enabled' => 1, 'recurrence_frequency' => 'ORARIA', 'recurrence_interval' => 'x'],
        ['tipo' => 'ordinario', 'gestione_id' => $gestione, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 3,
            'capitoli_ids' => ['x', 999999], 'capitoli_config' => [['id' => 'x', 'importo' => ['x'], 'note' => $lungo], ['importo' => '1,00'], ['id' => 999999]],
            'competenze_capitoli' => [['conto_id' => 'x', 'tratti' => []], ['conto_id' => $voce, 'tratti' => array_fill(0, 7, ['dal' => 'x', 'al' => 'y'])], ['tratti' => [[]]]],
            'saldi_config' => [['saldo_id' => 'x', 'ripartizioni' => 'x'], ['saldo_id' => 999999, 'ripartizioni' => [['anagrafica_id' => 'x'], ['importo' => ['x']]]], []],
            'accetta_scoperti' => 1, 'nota_scoperti' => 'corta', 'accetta_destinatari' => 'forse', 'nota_destinatari' => ['x']],
        ['tipo' => 'ordinario', 'gestione_id' => $gestione, 'nome' => 'Altro', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 3, 'accetta_scoperti' => 1,
            'accetta_destinatari' => 1],
    ];

    $messaggi = [];
    foreach ($richieste as $corpo) {
        $risposta = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), $corpo);
        expect($risposta->status())->toBe(422);
        foreach ($risposta->json('errors') as $campo => $frasi) {
            foreach ($frasi as $frase) {
                $messaggi["{$campo}: {$frase}"] = $frase;
            }
        }
    }

    // La prova vede davvero i messaggi delle regole che contano: quello del caso del forum e quelli dello scudo legale.
    expect(array_values($messaggi))->toContain('Scegli almeno una fattura da mettere a rate.')
        ->and(count($messaggi))->toBeGreaterThan(40);
    $chiavi = array_merge(prmpsChiaviDelleRegole(), PRMPS_CAMPI_NEI_CONTROLLER);
    expect(collect($messaggi)->map(fn ($m) => prmpsDifetti($m, $chiavi))->filter()->all())->toBe([]);
});

it('dalle rotte vere: l\'approvazione, il ricalcolo e la voce tolta rispondono senza nomi tecnici', function () {
    $s = prmpsScenario($this);
    $piano = $s->piano('Preventivo 2026');
    $voce = $s->voci['Pulizie']['conto']->id;
    $lungo = str_repeat('a', 600);

    $risposte = [
        $this->actingAs($s->admin)->putJson(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]), []),
        $this->actingAs($s->admin)->putJson(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]), ['approvato' => 1, 'numero_verbale' => $lungo, 'nota_approvazione' => $lungo]),
        $this->actingAs($s->admin)->putJson(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]), ['approvato' => 'forse', 'data_delibera_assemblea' => 'ieri']),
        $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $piano]),
            ['orphan_ids' => 'tutte', 'accetta_scoperti' => 1, 'nota_scoperti' => 'corta', 'accetta_destinatari' => 'forse']),
        $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $piano]),
            ['orphan_ids' => ['x', 999999], 'accetta_destinatari' => 1]),
        $this->actingAs($s->admin)->deleteJson(route('admin.gestionale.piani-rate.capitoli.detach', [$s->c, $s->e, $piano, $voce]),
            ['accetta_destinatari' => 1, 'nota_destinatari' => ['x']]),
    ];

    $messaggi = [];
    foreach ($risposte as $risposta) {
        expect($risposta->status())->toBe(422);
        foreach ($risposta->json('errors') as $campo => $frasi) {
            foreach ($frasi as $frase) {
                $messaggi["{$campo}: {$frase}"] = $frase;
            }
        }
    }

    expect(count($messaggi))->toBeGreaterThan(10);
    $chiavi = array_merge(prmpsChiaviDelleRegole(), PRMPS_CAMPI_NEI_CONTROLLER);
    expect(collect($messaggi)->map(fn ($m) => prmpsDifetti($m, $chiavi))->filter()->all())->toBe([]);
});

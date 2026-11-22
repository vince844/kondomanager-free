<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le parole dei sei mesi per agire, solo sui file e sulle frasi nuove: oltre alla lista delle lettere, mai «errore», «in ritardo» (per l'amministratore) né «deve
 * agire contro». Nelle liste di oggi queste tre parole farebbero rosso il widget della tesoreria, che scrive «in ritardo di N giorni» per una rata: qui si
 * guardano solo le costanti delle classi nuove e le frasi del riquadro, in tutti i suoi stati.
 */

use App\Services\Gestionale\Debiti\PassoProposto;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use App\Services\Gestionale\RispostePromemoria;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

const SMP_PAROLE = ['errore', 'in ritardo', 'deve agire contro', 'esigibile', 'ingiungibil', 'moros', 'diffidat', 'ingiunzion', 'decadut', 'termine scaduto', 'consegnata',
    'notificata', 'transazione'];

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function smpVietate(string $testo): array
{
    // «Consegnata» si dice solo con la consegna della PEC provata (la ricevuta del gestore, o il giorno scritto a mano con la prova): «consegnata per PEC il …».
    $testo = (string) preg_replace('/consegnat[ao] per PEC il \d{2}\/\d{2}\/\d{4}/u', ' ', $testo);
    $t = mb_strtolower(str_replace(\App\Services\Gestionale\Debiti\SeiMesi::TESTO_NORMA, ' ', $testo));

    return array_values(array_filter(SMP_PAROLE, fn ($p) => str_contains($t, $p)));
}

it('le costanti di testo delle classi nuove non hanno le parole', function () {
    $trovate = [];
    foreach ([PassoProposto::class, SeiMesiPerAgire::class, RispostePromemoria::class, \App\Http\Controllers\Gestionale\Dashboard\SeiMesiRisposteController::class,
        \App\Services\Gestionale\Debiti\FascicoloLegale::class, \App\Http\Controllers\Gestionale\Movimenti\FascicoloLegaleController::class] as $classe) {
        foreach ((new ReflectionClass($classe))->getReflectionConstants() as $c) {
            $v = $c->getValue();
            $testo = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) (is_scalar($v) ? $v : '');
            foreach (smpVietate($testo) as $p) {
                $trovate[] = $classe . '::' . $c->getName() . ": {$p}";
            }
        }
    }

    expect($trovate)->toBe([]);
});

it('le frasi del riquadro, con lettere, accordi, dispense, risposte e rendiconti, non hanno le parole', function () {
    $s = smaViaVerdi($this);
    smaLettera($s, 'bianchi', 'diffida', 'spedita', '2026-03-01', [1, 2, 3, 4, 5]);
    smaLettera($s, 'neri', 'sollecito', 'non_partita', '2026-05-10', [1, 2, 3, 4, 5]);
    smaAccordo($s, 'verdi', [1, 2]);
    smaRispondi($this, $s, ['tipo' => 'non_serve', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id, 'motivo' => 'assemblea già convocata']);
    smaRispondi($this, $s, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e']->id, 'anagrafica_id' => $s['persone']['rossi']->id,
        'giorno' => '2026-05-12', 'nota' => 'telefonata']);
    $testo = json_encode(smaRiquadro($s), JSON_UNESCAPED_UNICODE);

    expect(smpVietate($testo))->toBe([]);
});

it('la diffida per PEC con la ricevuta del gestore: il riquadro dice la consegna per PEC, e nessun\'altra parola vietata', function () {
    $s = smaViaVerdi($this);
    $riga = smaLettera($s, 'bianchi', 'diffida', 'inviata', '2026-05-10', [1, 2, 3, 4, 5], ['consegna' => ['giorno' => '2026-05-11', 'ora' => '09:02', 'a_mano' => false]]);
    $riga->forceFill(['canale' => 'pec', 'esito' => 'consegnata', 'ricevuta_il' => '2026-05-11'])->save();
    $testo = json_encode(smaRiquadro($s), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($testo)->toContain('Diffida consegnata per PEC il 11/05/2026')
        ->and(smpVietate($testo))->toBe([])
        ->and(smpVietate('La diffida è stata consegnata a mano'))->toBe(['consegnata']);
});

it('a video «la prossima azione», mai «il passo»: le frasi del riquadro, con la dispensa, le lettere, l\'accordo e la tolleranza, non lo dicono', function () {
    $s = smaViaVerdi($this);
    smaLettera($s, 'bianchi', 'diffida', 'spedita', '2026-03-01', [1, 2, 3]);
    smaLettera($s, 'neri', 'sollecito', 'spedita', '2026-04-01', [1, 2, 3, 4]);
    smaAccordo($s, 'verdi', [1, 2]);
    smaDispensa($s, [$s['e2025'], $s['e']], ['rossi']);
    $frasi = [];
    $r = smaRiquadro($s);
    // Ogni testo con uno spazio è una frase (i nomi delle chiavi e dei casi non ne hanno).
    array_walk_recursive($r, function ($v) use (&$frasi) {
        if (is_string($v) && str_contains($v, ' ')) {
            $frasi[] = $v;
        }
    });
    foreach ((new ReflectionClass(PassoProposto::class))->getReflectionConstants() as $c) {
        if (is_string($c->getValue()) && str_contains($c->getValue(), ' ')) {
            $frasi[] = $c->getValue();
        }
    }

    expect($frasi)->not->toBe([])
        ->and(array_values(array_filter($frasi, fn ($f) => preg_match('/\\bpass[oi]\\b/iu', $f) === 1)))->toBe([]);
});

/**
 * Le parole del lavoro che ha costruito questi file non devono restare nel codice né nelle prove: la guardia le cerca per impronta (md5 della parola intera,
 * in minuscolo), così la lista non le scrive.
 */
const SMP_IMPRONTE = ['24678308430b00333ea27b7db31b851a', '584ae71553e6ba57033a2f9e56d2f7a7', '0fa3c82b1dcd0b2c9efa017a7bedc7fc', '9a231ed25aaaa2d347129cb3a71e5058',
    '598cf5875cd151efae5f59867499180c', '4a18d948dcc2f5bacbd4c7021046c8a5', '0d197195f574d84378e6ba398bd92ae6', 'fb7409e10e0276b56fb96c2b410cc17e',
    '9c91ba4952ec7cc9395f270984df0a82', 'ac15b43402034ea143328b2fda1744e5', 'c548d6aa468d41b1e77e62d504527956'];

it('i file dei sei mesi per agire e delle risposte ai compiti dell\'Inbox, del codice e delle prove, non usano le parole del lavoro che li ha costruiti', function () {
    $file = [
        'app/Services/Gestionale/Debiti/PassoProposto.php', 'app/Services/Gestionale/Debiti/SeiMesiPerAgire.php', 'app/Services/Gestionale/RispostePromemoria.php',
        'app/Services/Gestionale/RegistroDelGesto.php', 'app/Models/Gestionale/RispostaPromemoria.php', 'app/Http/Controllers/Gestionale/Dashboard/SeiMesiRisposteController.php',
        'database/migrations/2026_11_15_140000_create_risposte_promemoria_table.php', 'database/settings/2026_11_15_200100_remove_passo_proposto_from_general_settings.php',
        'resources/js/lib/gestionale/debiti/seiMesiPerAgire.ts', ...glob(base_path('resources/js/components/gestionale/seiMesi/*')),
        'tests/Unit/Gestionale/Debiti/PassoPropostoTest.php', ...glob(base_path('tests/Feature/Gestionale/SeiMesi*.php')),
        'tests/Feature/Gestionale/RegistroLettereUltimePerTipoTest.php', 'tests/Feature/Gestionale/RendicontoCentottantaGiorniTest.php', 'tests/Feature/Gestionale/RendicontoInAgendaTest.php',
        'tests/Feature/Gestionale/RateScaduteSeiMesiDelTest.php', 'tests/Feature/Gestionale/PassoInterruttoreTest.php', 'tests/Feature/Gestionale/DispensaSollecitoTest.php',
        'tests/Feature/Impostazioni/ImpostazioniPassoPropostoTest.php', 'resources/js/pages/gestionale/dashboard/DashboardSeiMesi.test.ts',
        'resources/js/components/gestionale/lettere/LettereDialogTipoIniziale.test.ts', 'resources/js/lib/gestionale/debiti/seiMesiDel.test.ts',
        // Le risposte ai compiti dell'agenda nelle due Inbox.
        'app/Http/Controllers/Dashboard/ActionInboxController.php', 'app/Models/Evento.php', ...glob(base_path('resources/js/lib/inbox/*')),
        ...glob(base_path('resources/js/components/inbox/*')), ...glob(base_path('tests/Feature/Dashboard/Inbox*.php')),
        'resources/js/pages/dashboard/ActionInbox.vue', 'resources/js/pages/dashboard/ActionInbox.test.ts', 'resources/js/pages/gestionale/dashboard/Dashboard.vue',
        'resources/js/pages/gestionale/dashboard/DashboardCompitiPermessi.test.ts',
        // Le impostazioni del condominio, la salita dopo una lettera, le rate senza la data.
        'database/migrations/2026_11_15_200000_create_impostazioni_condominio_table.php', 'app/Models/ImpostazioniCondominio.php',
        'app/Http/Controllers/Gestionale/Struttura/ImpostazioniCondominioController.php', 'resources/js/pages/gestionale/struttura/ImpostazioniCondominio.vue',
        'resources/js/pages/gestionale/struttura/ImpostazioniCondominio.test.ts', 'resources/js/components/guides/ImpostazioniCondominioGuide.vue',
        'resources/js/components/guides/ImpostazioniCondominioGuide.test.ts', 'resources/js/layouts/gestionale/StrutturaLayout.test.ts',
        'resources/js/lib/gestionale/debiti/prossimaAzione.test.ts', 'tests/Feature/Condomini/ImpostazioniCondominioTest.php',
        'tests/Feature/Gestionale/PassoSalitaETolleranzaTest.php', 'tests/Feature/Impostazioni/ImpostazioniPassoPropostoTest.php',
        'app/Services/Gestionale/Debiti/SeiMesi.php',
        // L'avviso in alto delle due Inbox, una volta sola.
        'resources/js/pages/dashboard/ActionInboxAvviso.test.ts', 'resources/js/pages/gestionale/dashboard/DashboardAvviso.test.ts',
        // L'ora del promemoria nell'agenda; il bonifico segnalato che non resta in verifica.
        'tests/Feature/Dashboard/OraDelPromemoriaTest.php', 'app/Services/Gestionale/SegnalazioneDelBonifico.php',
        'database/migrations/2026_11_15_210000_sblocca_le_rate_segnalate_con_il_compito_chiuso.php', 'tests/Feature/Gestionale/BonificoSegnalatoChiusoTest.php',
        'resources/js/components/eventi/EventDetailsDialogSegnalazione.test.ts',
        // La lista dello studio dei sei mesi, nella dashboard dello studio.
        'app/Http/Controllers/Dashboard/SeiMesiStudioController.php', 'resources/js/lib/gestionale/debiti/seiMesiDelloStudio.ts',
        ...glob(base_path('tests/Feature/Dashboard/SeiMesiStudio*.php')),
        // La tesoreria a 30 giorni: il disponibile e da dove viene, il saldo previsto, le prove.
        'app/Services/Treasury/DisponibilitaDiOggi.php', ...glob(base_path('tests/Feature/Treasury/*.php')), ...glob(base_path('resources/js/lib/gestionale/tesoreria/*')),
        'resources/js/pages/gestionale/dashboard/components/DaDoveViene.vue', 'resources/js/pages/gestionale/dashboard/components/DaDoveViene.test.ts',
        'resources/js/pages/gestionale/dashboard/components/SaldoPrevistoCard.vue', 'resources/js/pages/gestionale/dashboard/components/SaldoPrevistoCard.test.ts',
        'resources/js/pages/gestionale/dashboard/components/GraficoSaldoPrevisto.vue', 'resources/js/pages/gestionale/dashboard/components/tesoreriaDiProva.ts',
        'resources/js/pages/gestionale/dashboard/components/TreasuryGuardianWidget.vue', 'resources/js/pages/gestionale/dashboard/components/TreasuryGuardianWidgetFondo.test.ts',
        'resources/js/pages/gestionale/dashboard/components/TreasuryGuardianWidgetAzioni.test.ts', 'resources/js/pages/gestionale/dashboard/DashboardFilaTesoreria.test.ts',
        'resources/js/pages/gestionale/dashboard/components/CreditiDaCompensareWidget.vue', 'resources/js/pages/gestionale/dashboard/components/CreditiDaCompensareWidget.test.ts',
        // L'Action inbox: la regola di «scaduto», la lettura aggregata, l'elenco, la mappa delle prossime azioni, il collegamento dei compiti, le segnalazioni.
        ...glob(base_path('app/Services/Gestionale/Inbox/*.php')), 'database/migrations/2026_11_15_230000_crea_i_compiti_delle_segnalazioni_aperte.php',
        ...glob(base_path('tests/Feature/Dashboard/ActionInbox*.php')), 'tests/Feature/Dashboard/IndirizzoDelCompitoTest.php',
        'resources/js/lib/inbox/actionInbox.ts', 'resources/js/lib/inbox/actionInbox.test.ts', 'resources/js/composables/useSeiMesiDelloStudio.ts',
        'resources/js/components/inbox/CardDellInbox.vue', 'resources/js/components/inbox/GraficoDellInbox.vue', 'resources/js/components/inbox/RigaDelCompito.vue',
        'resources/js/components/guides/ActionInboxGuide.vue', 'resources/js/components/guides/ActionInboxGuide.test.ts',
        'resources/js/pages/dashboard/DashboardSenzaSeiMesi.test.ts',
    ];
    $trovate = [];
    foreach ($file as $f) {
        $percorso = str_starts_with($f, '/') ? $f : base_path($f);
        expect(file_exists($percorso))->toBeTrue($f);
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower((string) file_get_contents($percorso)), $parole);
        foreach (array_unique($parole[0]) as $parola) {
            if (in_array(md5($parola), SMP_IMPRONTE, true)) {
                $trovate[] = str_replace(base_path() . '/', '', $percorso) . ': ' . $parola;
            }
        }
    }

    expect($trovate)->toBe([]);
});

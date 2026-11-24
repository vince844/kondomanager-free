<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\RegistroModifiche\CatalogoDelRegistro;
use Tests\Support\RegistroModifiche\LettoreDelleScritture;

/**
 * 1.12.0-beta.13 (Coda 679, §13 (2) del progetto, P5, decisione 518: «una guardia nella suite che fallisce se una scheda cambia senza scrivere nel
 * registro»). Le scritture in blocco, quelle che gli eventi dei modelli non vedono, su una tabella registrata (le schede, i modelli «solo gesto», le
 * pivot del gesto 7): **ogni scrittura** sta dentro il lavoro di una chiamata del registro che lo avvolge (`RegistroModifiche::durante`, `legame`,
 * `campoDellaScheda`, …: nella funzione passata, o nella stessa istruzione con una funzione freccia; o fra `apriGesto(` e `chiudiGesto(`), oppure il suo
 * metodo è in un elenco di eccezioni dichiarato per file e metodo, con **quante scritture** copre e la ragione. La guardia conta le scritture, non il
 * metodo: una scrittura in più accanto a una chiamata del registro fa rosso. Una scrittura sulle tabelle del registro stesso sta solo nei servizi che le
 * scrivono. Un'eccezione che non trova più le sue scritture, o ne trova un numero diverso, fa rosso anche lei: l'elenco non invecchia in silenzio.
 *
 * Che cosa non copre: la guardia legge il testo del codice, non le catene di chiamate (un metodo in eccezione «dentro il gesto di X» lo è perché lo dice
 * la ragione, non perché la guardia lo provi); una scrittura su una query tenuta in una variabile (`$query->delete()`); una scrittura in
 * blocco costruita in un modo che le espressioni non riconoscono; `saveQuietly()` e `withoutEvents()`.
 */

/** File e metodo => [quante scritture fuori dal lavoro di una chiamata del registro, la ragione]. */
const GDR_ECCEZIONI = [
    // Dentro un gesto di un altro metodo (la catena di chiamate la dice la ragione).
    'app/Actions/PianoRate/GenerateRateQuotesAction.php::execute' => [3, 'dentro il gesto di GeneratePianoRateAction::execute (gesto 2): le quote nascono a blocchi'],
    'app/Actions/PianoRate/GenerateRateQuotesAction.php::scriviDettaglio' => [1, 'dentro il gesto di GeneratePianoRateAction::execute (gesto 2)'],
    'app/Actions/PianoRate/RigeneraRateTenendoILegami.php::rigenera' => [2, 'dentro il gesto di RigeneraRateTenendoILegami::esegui (gesto 2)'],
    'app/Actions/PianoRate/SyncOrphanChaptersAction.php::execute' => [1, 'il programma aggancia i capitoli orfani dentro la generazione del piano (gesto 2)'],
    'app/Http/Controllers/Gestionale/PianiRate/PianoRateController.php::scriviCompetenzeCapitoli' => [1, 'dentro PianoRateController::store e la rigenerazione: le competenze nascono con i capitoli'],
    'app/Actions/Subentro/RegistraSubentroAction.php::applicaRighe' => [1, 'dentro il gesto di RegistraSubentroAction::execute (gesto 4)'],
    'app/Actions/Subentro/RegistraSubentroAction.php::apri' => [1, 'dentro il gesto di RegistraSubentroAction::execute (gesto 4)'],
    'app/Actions/Subentro/RegistraSubentroAction.php::apriSommando' => [1, 'dentro il gesto di RegistraSubentroAction::execute (gesto 4)'],
    'app/Actions/Subentro/RegistraSubentroAction.php::chiudi' => [1, 'dentro il gesto di RegistraSubentroAction::execute (gesto 4)'],
    'app/Actions/Subentro/RegistraSubentroAction.php::riassegnaBozze' => [2, 'dentro il gesto di RegistraSubentroAction::execute (gesto 4)'],
    'app/Services/Subentro/VociDaSpostare.php::sposta' => [2, 'dentro il gesto di RegistraSubentroAction::execute (gesto 4)'],
    'app/Services/Subentro/AccessoriAgliEredi.php::annulla' => [3, 'dentro il gesto di AnnullaPassaggioAction::execute (gesto 4)'],
    'app/Services/Gestionale/ScritturaDiEmissione.php::scrivi' => [1, 'dentro il gesto dell\'emissione (EmissioneRateController::store, gesto 2)'],
    'app/Services/Import/Livelli/LivelloSoggetti.php::collegaAlCondominio' => [1, 'dentro il gesto del livello dell\'importazione (ImportRunner, una riga per livello)'],
    'app/Services/Import/Livelli/LivelloTitolarita.php::commit' => [1, 'dentro il gesto del livello dell\'importazione (ImportRunner, una riga per livello)'],
    'app/Services/Assemblee/GuardieDiCancellazione.php::togliLePreparazioni' => [2, 'dentro il gesto dell\'eliminazione del condominio (CondominioController::destroy, gesto 3): le assemblee in preparazione se ne vanno con lui (R15)'],
    'app/Services/Assemblee/GuardieDiCancellazione.php::togliTuttoDelCondominioDiProva' => [13, 'dentro il gesto del condominio di prova (CreaCondominioDimostrativoAction::rimuovi, gesto 3): dalle foglie alla radice, per le chiavi restrict (R15), con la sala (le votazioni, le presenze, le dichiarazioni, le deleghe), il verbale (il registro di nomina e revoca, i verbali, il registro dei verbali; le versioni e le parti sono fuori dal registro) e le cose da fare delle delibere'],
    'app/Http/Controllers/Eventi/EventoController.php::eliminaEvento' => [1, 'dentro il gesto di EventoController::destroy (decisione 547)'],
    'app/Services/EventoService.php::convertToSingleEvent' => [1, 'dentro il gesto di EventoController::update (decisione 547)'],
    'app/Services/EventoService.php::syncRelationships' => [2, 'dentro il gesto di EventoController::store e update (decisione 547)'],
    // Il legame passa da un modello che scrive la sua riga.
    'app/Http/Controllers/Gestionale/Immobili/Anagrafiche/ImmobileAnagraficaController.php::update' => [3, 'passa dal modello pivot TitolaritaImmobile (`using`): Laravel 13 lancia i suoi eventi anche con `wherePivot` (lo prova AreaCondominioTest)'],
    'app/Http/Controllers/Gestionale/Immobili/Anagrafiche/ImmobileAnagraficaController.php::destroy' => [1, 'passa dal modello pivot TitolaritaImmobile (`using`): Laravel 13 lancia i suoi eventi anche con `wherePivot` (lo prova AreaCondominioTest)'],
    'app/Actions/Gestionale/Movimenti/StoreIncassoRateAction.php::execute' => [5, 'il legame quota–scrittura è dentro la scrittura dell\'incasso, che scrive la sua riga «Scrittura registrata» (§5)'],
    'app/Actions/Gestionale/Movimenti/StornoIncassoRateAction.php::execute' => [1, 'il legame quota–scrittura è dentro la scrittura dello storno, che scrive la sua riga (§5)'],
    'app/Actions/Gestionale/Rimborsi/RimborsaCreditoAction.php::execute' => [1, 'il legame quota–scrittura è dentro la scrittura del rimborso, che scrive la sua riga (§5)'],
    'app/Actions/Gestionale/Movimenti/GeneraDelegheF24Action.php::creaDelega' => [1, 'il legame è dentro la scrittura del versamento, che scrive la sua riga (§5)'],
    'app/Services/Gestionale/Accessori/VersamentoDegliEredi.php::registraDellErede' => [1, 'il legame quota–scrittura è dentro la scrittura del versamento, che scrive la sua riga (§5)'],
    'app/Services/Gestionale/PagamentoFornitoreService.php::registraPagamento' => [2, 'contatore tecnico `versione_allocazioni` (P23) e legame fattura–scrittura dentro la scrittura del pagamento (§5)'],
    'app/Services/Gestionale/PagamentoFornitoreService.php::aggiornaPagamento' => [2, 'contatore tecnico `versione_allocazioni` (P23) e legame fattura–scrittura dentro la scrittura del pagamento (§5)'],
    'app/Services/Gestionale/PagamentoFornitoreService.php::stornaPagamento' => [1, 'contatore tecnico `versione_allocazioni` (P23)'],
    'app/Services/Gestionale/FatturaPassivaService.php::registraFattura' => [1, 'il legame fattura–scrittura dentro la registrazione: la fattura scrive «Registrata», la scrittura la sua riga (§5)'],
    'app/Services/Gestionale/FatturaPassivaService.php::creaContoDinamicoSopravvenienza' => [2, 'la voce tecnica nasce con la fattura: la sua riga «creata» e quella della fattura'],
    'app/Services/Gestionale/FatturaPassivaService.php::creaDocumentoFattura' => [1, 'il programma dà la categoria «Fatture» al documento della fattura'],
    'app/Http/Controllers/Gestionale/PianiConti/Conti/ContoController.php::destroy' => [1, 'dentro l\'eliminazione della voce: la sua riga «eliminata» (il tratto)'],
    'app/Services/CondominioService.php::createDefaultGestione' => [1, 'la gestione di base nasce con il condominio (la sua riga «creata»)'],
    // Il programma, da solo (decisione 547 (2), P8, P23).
    'app/Services/Gestionale/F24/PromemoriaDelleRitenute.php::scrivi' => [1, 'il programma tiene i promemoria delle ritenute, uno per delega e il «Prepara le deleghe F24» (decisioni 547 (2) e 675, Coda 718)'],
    'app/Listeners/Gestionale/SyncScadenziarioWithFattura.php::handle' => [5, 'il programma crea i compiti della fattura (decisione 547 (2))'],
    'app/Services/Gestionale/EventiRataCondomino.php::crea' => [2, 'il programma crea gli eventi delle rate dei condòmini (decisione 547 (2))'],
    'app/Services/Gestionale/InboxService.php::createTask' => [1, 'il programma crea i compiti (decisione 547 (2))'],
    'app/Services/Gestionale/InboxService.php::creaPromemoria' => [1, 'il programma crea i promemoria (decisione 547 (2))'],
    'app/Services/Gestionale/Inbox/CompitoDaModerare.php::crea' => [1, 'il programma crea il compito di moderazione (decisione 547 (2))'],
    'app/Services/Assemblee/AgendaDellAssemblea.php::sincronizza' => [1, 'il programma tiene le voci dell\'agenda delle sedute di un\'assemblea (decisione 547 (2), §4.6 del progetto della tappa 2)'],
    'app/Services/Assemblee/Sala/FoglioDellaSala.php::stampato' => [1, 'il programma scrive l\'ora dell\'ultima stampa del foglio della sala: una colonna del programma, che da sola non fa una riga (P8)'],
    'app/Services/Gestionale/Inbox/CompitoDaModerare.php::aggiorna' => [1, 'il programma aggiorna il compito di moderazione (decisione 547 (2))'],
    'app/Services/Gestionale/Inbox/CompitoDellaSegnalazione.php::sincronizza' => [1, 'il programma tiene il compito della segnalazione (decisione 547 (2))'],
    'app/Services/Gestionale/Inbox/CompitoDellErrore.php::avvisato' => [1, 'il programma aggiunge il condominio di chi avvisa di nuovo al compito dell\'errore avvisato (decisione 547 (2))'],
    'app/Http/Controllers/Eventi/Utenti/PaymentReportingController.php::__invoke' => [1, 'il compito nasce dal programma alla segnalazione del condòmino (decisione 547 (2))'],
    'app/Http/Controllers/Eventi/Utenti/EventoController.php::store' => [1, 'decisione di Vincenzo del 19/11: l\'agenda personale del condòmino non entra nel registro (decisione 555)'],
    'app/Http/Controllers/Eventi/Utenti/EventoController.php::destroy' => [1, 'decisione di Vincenzo del 19/11: l\'agenda personale del condòmino non entra nel registro (decisione 555)'],
    'app/Services/Allegati/AllegatoService.php::completaPuliziaPerCondominio' => [1, 'gli allegati tolti con l\'elemento (decisione 547 (2), rischio 12)'],
    'app/Services/Allegati/AllegatoService.php::completaPuliziaPerElemento' => [1, 'gli allegati tolti con l\'elemento (decisione 547 (2), rischio 12)'],
    'app/Services/Allegati/AllegatoService.php::completaPuliziaPerUtente' => [1, 'gli allegati tolti con l\'utente (decisione 547 (2), rischio 12)'],
    'app/Services/Gestionale/VersioniDelRendiconto.php::elimina' => [1, 'i PDF che il programma genera, fuori dal tratto (P8): le versioni hanno il loro registro'],
    'app/Services/Gestionale/Debiti/AccordiRientro.php::segnali' => [1, 'campo del programma (`segnali`, P8)'],
    'app/Services/Gestionale/SaldoEsercizioService.php::sincronizzaLucchetti' => [2, 'campi del programma (`is_applicato`, `piano_rate_id`, P8), dentro la generazione del piano'],
    'app/Services/Gestionale/SaldoEsercizioService.php::rilasciaLucchetti' => [1, 'campi del programma (`is_applicato`, `piano_rate_id`, P8), dentro l\'eliminazione del piano'],
    'app/Actions/Cassa/CreateCassaBankAccountAction.php::execute' => [1, 'il programma toglie «Conto principale» agli altri conti quando se ne sceglie uno: la riga del conto scelto lo dice'],
    'app/Actions/Cassa/UpdateCassaAction.php::handleBancaUpdate' => [1, 'il programma toglie «Conto principale» agli altri conti quando se ne sceglie uno: la riga del conto scelto lo dice'],
    'app/Services/Installer/CreaAmministratore.php::esegui' => [1, 'l\'installazione: il primo amministratore nasce prima che ci sia qualcuno da registrare'],
    'app/Http/Controllers/Impostazioni/RestoreController.php::authorizeRecovery' => [1, 'tecnico: il limitatore dei tentativi (cache), non una tabella'],
    // Il metodo scrive la sua riga accanto alla scrittura, non intorno (la fotografia prima, la riga dopo): le scritture si contano.
    'app/Http/Controllers/Eventi/EventoController.php::store' => [2, 'i legami dell\'evento, poi la riga del gesto dell\'agenda nello stesso metodo (GestiDelRegistro::agenda, decisione 547)'],
    'app/Http/Controllers/Gestionale/Immobili/Anagrafiche/ImmobileAnagraficaController.php::store' => [1, 'passa dal modello pivot TitolaritaImmobile (`using`), che scrive la sua riga (lo prova AreaCondominioTest)'],
    'app/Http/Controllers/Gestionale/PianiConti/Conti/AggiornaTabellaController.php::__invoke' => [3, 'le tabelle della voce: la fotografia prima, la riga dopo con GestiDelRegistro::scriviTabelleDellaVoce() nello stesso metodo'],
    'app/Http/Controllers/Gestionale/PianiConti/Conti/AssociaTabellaController.php::__invoke' => [2, 'le tabelle della voce: la fotografia prima, la riga dopo con GestiDelRegistro::scriviTabelleDellaVoce() nello stesso metodo'],
    'app/Services/Gestionale/CreazioneDellaVoce.php::crea' => [2, 'le tabelle della voce nuova: la fotografia prima, la riga dopo con GestiDelRegistro::scriviTabelleDellaVoce() nello stesso metodo'],
    'app/Http/Controllers/Gestionale/PianiConti/Conti/ContoController.php::update' => [7, 'le tabelle della voce: la fotografia prima, la riga dopo con GestiDelRegistro::scriviTabelleDellaVoce() nello stesso metodo'],
    'app/Http/Controllers/Gestionale/PianiConti/Conti/DissociaTabellaController.php::__invoke' => [2, 'le tabelle della voce: la fotografia prima, la riga dopo con GestiDelRegistro::scriviTabelleDellaVoce() nello stesso metodo'],
    'app/Http/Controllers/Permissions/RevokePermissionFromUserController.php::__invoke' => [1, 'il permesso tolto all\'utente: la fotografia prima e AttiSulleUtenze::accessoCambiato() dopo, nella stessa transazione'],
    'app/Http/Controllers/Roles/RevokePermissionFromRoleController.php::__invoke' => [1, 'il permesso tolto al ruolo: i permessi di prima e AttiSulleUtenze::ruolo() dopo, nello stesso metodo'],
    'app/Http/Controllers/Roles/RoleController.php::destroy' => [2, 'gli utenti riassegnati al ruolo di serie, poi AttiSulleUtenze::ruolo() «eliminata», nella stessa transazione'],
    'app/Http/Controllers/Roles/RoleController.php::store' => [2, 'i permessi del ruolo nuovo, poi AttiSulleUtenze::ruolo() «creata», nello stesso metodo'],
    'app/Http/Controllers/Roles/RoleController.php::update' => [3, 'i permessi del ruolo: quelli di prima letti prima, poi AttiSulleUtenze::ruolo() «modificata», nello stesso metodo'],
    'app/Services/UserRegistrationService.php::register' => [1, 'il ruolo dell\'utente che si registra, poi AttiSulleUtenze::registrato(), nella stessa transazione'],
    'app/Services/UserService.php::createUser' => [2, 'il ruolo e i permessi dell\'utente nuovo, poi AttiSulleUtenze::creato(), nella stessa transazione'],
    'app/Services/UserService.php::updateUser' => [2, 'ruoli e permessi: la fotografia prima e AttiSulleUtenze::accessoCambiato() dopo, nella stessa transazione'],
    'app/Support/Comunicazioni/PubblicazioneProgrammata.php::pubblica' => [1, 'la pubblicazione programmata: l\'UPDATE condizionato (una volta sola anche con due processi) e il gesto «Pubblicata in bacheca» nella stessa transazione'],
];

/** I soli metodi che scrivono le tabelle del registro: la nascita, il sigillo, la verifica; l'oscuramento, il ripristino, la conservazione. */
const GDR_SCRITTORI_DEL_REGISTRO = [
    'app/Services/RegistroModifiche/RegistroModifiche.php::scriviLaRiga' => 'la nascita della riga: i suoi condomini, i suoi soggetti e la firma di nascita',
    'app/Services/RegistroModifiche/SigilloDelRegistro.php::unBlocco' => 'il sigillo dopo il commit (§3)',
    'app/Services/RegistroModifiche/VerificaDelRegistro.php::ricorda' => 'l\'esito della verifica nella riga della catena (P20)',
    // L'oscuramento, la conservazione e il ripristino (§10, §11, §12): i tre servizi che scrivono su righe già nate o le tolgono, sempre con `DB::table` (il modello lo rifiuta).
    'app/Services/RegistroModifiche/OscuramentoDelRegistro.php::oscura' => 'l\'oscuramento cambia solo i valori delle righe, con oscurata_il e oscurata_da (§10, P6)',
    'app/Services/Restore/RegistroNelRipristino.php::analizza' => 'il ripristino di una copia: le righe della copia senza sequenza prendono la versione sigillata del file (§12, P1)',
    'app/Services/Restore/RegistroNelRipristino.php::unBlocco' => 'il ripristino di una copia: le righe venute dopo rimesse, con condomini e soggetti, e gli oscuramenti riportati (§12, P1, P2)',
    'app/Services/Restore/RegistroNelRipristino.php::ricostruisciLaCatena' => 'il ripristino di una copia: la catena da MAX(sequenza) e dalla sua impronta (§12, P1)',
    'app/Services/RegistroModifiche/ConservazioneDelRegistro.php::conserva' => 'la conservazione toglie un prefisso della catena, con i suoi condomini e soggetti (§11, P13)',
];

const GDR_TABELLE_DEL_REGISTRO = ['registro_modifiche', 'registro_modifiche_condomini', 'registro_modifiche_soggetti', 'registro_modifiche_catena'];

/** Le scritture in blocco di app/ su una tabella registrata (o non detta) fuori da un metodo che scrive il registro. @return array<string, list<string>> */
function gdrScrittureFuori(): array
{
    $lettore = LettoreDelleScritture::daiModelli();
    $registrate = CatalogoDelRegistro::tabelleRegistrate();
    $trovate = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relativo = str_replace(base_path() . '/', '', $file->getPathname());
        foreach ($lettore->scritture((string) file_get_contents($file->getPathname())) as $s) {
            $tabelle = $s['tabelle'];
            $delRegistro = $tabelle !== null && array_intersect($tabelle, GDR_TABELLE_DEL_REGISTRO) !== [];
            $registrata = $tabelle === null || array_intersect($tabelle, $registrate) !== [];
            if (! $delRegistro && ! $registrata) {
                continue;
            }
            $chiave = $relativo . '::' . ($s['metodo'] ?? '(fuori da un metodo)');
            if ($delRegistro) {
                $trovate['registro'][$chiave][] = $relativo . ':' . $s['riga'];
            } elseif (! $s['coperta']) {
                $trovate['fuori'][$chiave][] = $relativo . ':' . $s['riga'];
            }
        }
    }

    return $trovate;
}

it('nessuna scrittura in blocco su una tabella registrata fuori dal lavoro di una chiamata del registro, salvo le eccezioni dichiarate con quante sono', function () {
    $fuori = gdrScrittureFuori()['fuori'] ?? [];
    $diverse = [];
    foreach ($fuori as $chiave => $righe) {
        $dichiarate = GDR_ECCEZIONI[$chiave][0] ?? 0;
        if (count($righe) !== $dichiarate) {
            $diverse[$chiave] = count($righe) . ' scritture, dichiarate ' . $dichiarate . ': ' . implode('; ', $righe);
        }
    }

    expect($diverse)->toBe([]);
});

it('le tabelle del registro si scrivono solo dai servizi dichiarati', function () {
    $registro = gdrScrittureFuori()['registro'] ?? [];

    expect(array_keys(array_diff_key($registro, GDR_SCRITTORI_DEL_REGISTRO)))->toBe([]);
});

it('ogni eccezione ha la ragione e trova ancora la sua scrittura: l\'elenco non invecchia in silenzio', function () {
    $trovate = gdrScrittureFuori();
    $stantie = array_keys(array_diff_key(GDR_ECCEZIONI, $trovate['fuori'] ?? []));
    $stantieDelRegistro = array_keys(array_diff_key(GDR_SCRITTORI_DEL_REGISTRO, $trovate['registro'] ?? []));
    $senzaRagione = array_keys(array_filter([...array_map(fn ($e) => $e[1], GDR_ECCEZIONI), ...GDR_SCRITTORI_DEL_REGISTRO], fn ($r) => mb_strlen(trim($r)) < 15));
    $senzaNumero = array_keys(array_filter(GDR_ECCEZIONI, fn ($e) => ! is_int($e[0]) || $e[0] < 1));

    expect($stantie)->toBe([])
        ->and($stantieDelRegistro)->toBe([])
        ->and($senzaRagione)->toBe([])
        ->and($senzaNumero)->toBe([]);
});

it('la guardia morde: le forme delle scritture in blocco sì, le scritture sull\'istanza e le letture no', function () {
    $lettore = new LettoreDelleScritture(['Rata' => 'rate', 'RataQuote' => 'rate_quote', 'Saldo' => 'saldi'],
        ['quote' => ['quote_tabella'], 'condomini' => ['condomini', 'anagrafica_condominio'], 'pianiRate' => ['piani_rate']]);

    $positivi = [
        "DB::table('rate')->where('id', 1)->update(['stato' => 'x']);" => ['rate'],
        "DB::table('rate')\n    ->whereIn('id', \$ids)\n    ->delete();" => ['rate'],
        "DB::table('rate')->insertGetId(['a' => 1]);" => ['rate'],
        "RataQuote::insert(\$righe);" => ['rate_quote'],
        "Saldo::where('piano_rate_id', 1)->update(['is_applicato' => false]);" => ['saldi'],
        "Rata::query()->whereKey(3)->delete();" => ['rate'],
        "\$tabella->quote()->whereNotIn('id', \$ids)->delete();" => ['quote_tabella'],
        "\$gestione->pianiRate()->delete();" => ['piani_rate'],
        "\$anagrafica->condomini()->sync(\$ids);" => ['condomini', 'anagrafica_condominio'],
        "\$utente->syncRoles(\$ruoli);" => ['model_has_roles', 'model_has_permissions', 'role_has_permissions'],
        "\$scrittura->forceDelete();" => null,
        "DB::statement('update rate set stato = 1');" => ['rate'],
        "\$q->where('a', 1)->delete();" => null,
    ];
    foreach ($positivi as $codice => $tabelle) {
        expect($lettore->tabelleScritte($codice))->toBe($tabelle, $codice);
    }

    $negativi = ["\$rata->delete();", "\$rata->update(['stato' => 'x']);", "Rata::where('id', 1)->get()->each->delete();", "DB::table('rate')->where('id', 1)->first();",
        "Rata::whereIn('id', \$ids)->pluck('id');", "\$this->disco()->delete(\$percorso);", "Cache::put('a', 1);", "\$condominio->update(\$dati);"];
    foreach ($negativi as $codice) {
        expect($lettore->tabelleScritte($codice))->toBeFalse($codice);
    }

    // Il metodo che la contiene, anche con una funzione passata alla query e con le graffe di una stringa.
    $sorgente = "<?php\nclass A {\n    public function uno() {\n        \$x = \"{\$y}\";\n        DB::transaction(function () {\n            DB::table('rate')\n"
        . "                ->where('id', 1)\n                ->delete();\n        });\n    }\n    public function due() { RegistroModifiche::gesto(\$g); DB::table('rate')->delete(); }\n}\n";
    $trovate = $lettore->scritture($sorgente);
    expect(array_map(fn ($s) => [$s['metodo'], $s['riga'], $s['chiamaIlRegistro'], $s['coperta']], $trovate))
        ->toBe([['uno', 6, false, false], ['due', 11, true, false]]);
});

it('la guardia conta le scritture, non il metodo: coperta solo dentro il lavoro di una chiamata del registro che lo avvolge', function () {
    $lettore = new LettoreDelleScritture(['Rata' => 'rate'], ['condomini' => ['condomini', 'anagrafica_condominio']]);
    $coperte = fn (string $corpo) => array_map(fn ($s) => [$s['testo'], $s['coperta']], $lettore->scritture("<?php
class A {
    public function m() {
{$corpo}
    }
}
"));

    // Positivi: la scrittura sta nel lavoro della chiamata.
    expect($coperte("RegistroModifiche::durante(\$g, function () {
    DB::table('rate')->where('id', 1)->delete();
});"))
        ->toBe([["DB::table('rate')->where('id', 1)->delete();", true]])
        ->and($coperte("RegistroModifiche::legame(\$a, 'condomini', 'Condomini', fn () => \$a->condomini()->sync(\$ids));")[0][1])->toBeTrue()
        ->and($coperte("RegistroModifiche::campoDellaScheda(\$v, 'tabelle', 'Tabelle', fn () => [], function () {
    Rata::where('a', 1)->update(['b' => 2]);
});")[0][1])->toBeTrue()
        ->and($coperte("RegistroModifiche::apriGesto(\$g);
DB::table('rate')->update(['x' => 1]);
RegistroModifiche::chiudiGesto();")[0][1])->toBeTrue();

    // Negativi: accanto a una chiamata del registro, ma fuori dal suo lavoro.
    expect($coperte("RegistroModifiche::legame(\$a, 'condomini', 'Condomini', fn () => \$a->condomini()->sync(\$ids));
DB::table('rate')->update(['x' => 1]);"))
        ->toBe([["RegistroModifiche::legame(\$a, 'condomini', 'Condomini', fn () => \$a->condomini()->sync(\$ids));", true], ["DB::table('rate')->update(['x' => 1]);", false]])
        ->and($coperte("RegistroModifiche::gesto(\$g);
DB::table('rate')->delete();")[0][1])->toBeFalse()
        ->and($coperte("DB::table('rate')->delete(); RegistroModifiche::durante(\$g, fn () => null);")[0][1])->toBeFalse()
        ->and($coperte("RegistroModifiche::apriGesto(\$g);
RegistroModifiche::chiudiGesto();
DB::table('rate')->delete();")[0][1])->toBeFalse()
        ->and($coperte("DB::transaction(function () {
    DB::table('rate')->delete();
});")[0][1])->toBeFalse();
});

/**
 * Decisione 554: una scheda creata e poi legata nello stesso metodo (un legame o un campo della scheda con `RegistroModifiche::`, un collegamento con
 * `attach`/`sync`, un aggiornamento) sta in una transazione del metodo. La «Creata» aspetta la fine della transazione in cui nasce: senza una transazione
 * del metodo, quella è il solo salvataggio, che si chiude subito dopo l'INSERT, e la scheda scrive «Creata» senza condomini e poi una riga del legame.
 *
 * Che cosa non copre: la catena di chiamate (un metodo che crea e un altro che lega); una transazione aperta da chi chiama il metodo (va fra le eccezioni,
 * con la ragione). Un legame su una scheda appena creata fuori da una transazione lo scrive anche il log, da `RegistroModifiche::legame()`.
 *
 * @param list<string> $modelli i nomi corti dei modelli con il tratto
 * @return list<string> i metodi
 */
function gdrCreateELegateFuori(string $sorgente, array $modelli): array
{
    $trovati = [];
    preg_match_all('/function\s+(\w+)\s*\([^)]*\)[^{;]*\{/', $sorgente, $metodi, PREG_OFFSET_CAPTURE);
    foreach ($metodi[0] as $i => [$testa, $inizio]) {
        $fine = $inizio + strlen($testa);
        for ($livello = 1; $livello > 0 && $fine < strlen($sorgente); $fine++) {
            $livello += match ($sorgente[$fine]) { '{' => 1, '}' => -1, default => 0 };
        }
        $corpo = substr($sorgente, $inizio + strlen($testa), $fine - $inizio - strlen($testa));
        if (preg_match('/DB::transaction\(|beginTransaction\(|RegistroModifiche::(nellaTransazione|durante|durantePiuTransazioni|apriGesto)\(/', $corpo) === 1) {
            continue;
        }
        preg_match_all('/(\$\w+)\s*=\s*(new\s+)?(?:\\\\?App\\\\Models\\\\(?:Gestionale\\\\)?)?(\w+)(?:::(?:query\(\)->)?(?:create|forceCreate|firstOrCreate|updateOrCreate)\(|(?<=\w)\()/',
            $corpo, $create, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($create as $c) {
            [$variabile, $nuovo, $modello] = [$c[1][0], ($c[2][0] ?? '') !== '', $c[3][0]];
            if (! in_array($modello, $modelli, true) || ($nuovo !== (($c[2][1] ?? -1) >= 0))) {
                continue;
            }
            $dopo = substr($corpo, $c[0][1] + strlen($c[0][0]));
            $v = preg_quote($variabile, '/');
            if ($nuovo) {
                // Con `new` la scheda nasce al primo `save()`: si guarda dopo quello.
                if (preg_match('/' . $v . '->save\(\)/', $dopo, $salvata, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $dopo = substr($dopo, $salvata[0][1] + strlen($salvata[0][0]));
            }
            if (preg_match('/' . $v . '->(update|save|fill|forceFill|increment|decrement)\(|' . $v . '->\w+\(\)->(attach|sync|syncWithoutDetaching|detach|updateExistingPivot)\(|'
                . 'RegistroModifiche::(legame|campoDellaScheda)\(\s*' . $v . '\b/', $dopo) === 1) {
                $trovati[] = $metodi[1][$i][0];
                break;
            }
        }
    }

    return $trovati;
}

/** File e metodo => la ragione: un metodo che crea e lega dentro una transazione aperta da chi lo chiama. */
const GDR_CREATE_E_LEGATE_ECCEZIONI = [];

it('una scheda creata e poi legata nello stesso metodo sta in una transazione del metodo (decisione 554)', function () {
    $modelli = array_map(fn ($c) => class_basename($c), array_keys(CatalogoDelRegistro::TRATTO));
    $trovati = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relativo = str_replace(base_path() . '/', '', $file->getPathname());
        foreach (gdrCreateELegateFuori((string) file_get_contents($file->getPathname()), $modelli) as $metodo) {
            $trovati[] = $relativo . '::' . $metodo;
        }
    }

    expect(array_values(array_diff($trovati, array_keys(GDR_CREATE_E_LEGATE_ECCEZIONI))))->toBe([]);
});

it('la guardia della «Creata» morde: create e poi legate fuori da una transazione sì; dentro una transazione, o un\'altra variabile, no', function () {
    $metodo = fn (string $corpo) => "<?php\nclass A {\n    public function store() {\n{$corpo}\n    }\n}\n";
    $modelli = ['Anagrafica', 'Comunicazione'];

    $positivi = [
        "\$a = Anagrafica::create(\$dati);\nRegistroModifiche::legame(\$a, 'condomini', 'Condomini', fn () => \$a->condomini()->attach(\$ids));",
        "\$a = Anagrafica::query()->create(\$dati);\n\$a->condomini()->sync(\$ids);",
        "\$c = \\App\\Models\\Comunicazione::create(\$dati);\n\$c->update(['stato' => 'x']);",
        "\$c = new Comunicazione(\$dati);\n\$c->save();\nRegistroModifiche::campoDellaScheda(\$c, 'x', 'X', fn () => [], fn () => null);",
    ];
    foreach ($positivi as $corpo) {
        expect(gdrCreateELegateFuori($metodo($corpo), $modelli))->toBe(['store'], $corpo);
    }

    $negativi = [
        "DB::transaction(function () use (\$dati) {\n\$a = Anagrafica::create(\$dati);\n\$a->condomini()->attach(\$ids);\n});",
        "RegistroModifiche::nellaTransazione(function () {\n\$a = Anagrafica::create(\$dati);\n\$a->condomini()->attach(\$ids);\n});",
        "\$a = Anagrafica::create(\$dati);\n\$b->condomini()->attach(\$ids);",
        "\$a = Anagrafica::create(\$dati);\nreturn \$a;",
        "\$f = Fornitore::create(\$dati);\n\$f->update(['x' => 1]);",
        "\$c = new Comunicazione(\$dati);\n\$c->forceFill(['natura' => 'x']);\n\$c->save();",
    ];
    foreach ($negativi as $corpo) {
        expect(gdrCreateELegateFuori($metodo($corpo), $modelli))->toBe([], $corpo);
    }
});

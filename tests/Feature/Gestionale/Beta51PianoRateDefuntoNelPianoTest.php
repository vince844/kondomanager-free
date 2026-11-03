<?php

/**
 * 1.11.0-beta.51, §6.2 (la parte della Coda 226) — il cancello della generazione dice il defunto.
 *
 * Quando un piano sta per essere generato (creazione, ricalcolo, «togli una voce») e il motore intesta una cifra diversa da zero a
 * una persona che ha una successione non annullata sull'unità con l'arretrato «agli eredi», il cancello (2) aggiunge una voce
 * `defunto_nel_piano`: il defunto, la data del decesso, la cifra che il motore calcola per lui, la parte di ogni erede per quota
 * e la strada della decisione 80, detta secondo ciò che il piano fa davvero dei saldi del Wallet (quattro varianti di frase, V1 … V4).
 * Fonte dell'atteso: decisione 78 (1), decisione 80, e il conto a mano scritto in ogni test (oracoli O-62-1 … O-62-15).
 *
 * Il cancello dopo i saldi manuali ritorna a ogni ricalcolo, con la frase che dice che i saldi ci sono già (V4: il defunto e tutti gli eredi
 * hanno un saldo che la generazione assorbe nelle quote, assorbito già dal piano o libero nel Wallet, e quei saldi sono davvero la parte:
 * la cifra per ognuno, nel verso giusto, a meno di un centesimo; con saldi di altra cifra, un saldo solo o un saldo che un altro piano ha
 * assorbito vale V3). Farlo tacere quando la parte netta del defunto è zero è della 1.11.1 (decisione 86.4). Il caso con un saldo già nel
 * Wallet ma un piano che non lo applica aggiunge alla frase la ragione per cui il saldo non entra. Alla creazione il piano non esiste
 * ancora, e la V2 dice che basta registrare i saldi e rimandare il modulo. Più successioni dello stesso defunto sulla stessa unità
 * dividono la cifra per ruolo, o dicono che la divisione la decide l'amministratore.
 *
 * Cosa NON copre: la forma di `titolarita_alla` con i soli defunti dalla rotta (il motore non è mai senza cambiamenti accanto alla
 * voce del defunto, nessuna rotta lo raggiunge): la fissa un test sul metodo che la costruisce.
 *
 * I test che cominciano con «guardia —» proteggono ciò che il cancello non deve rompere.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Services\Subentro\DefuntoNelPiano;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

// --- Le frasi attese (le tre varianti della strada, la parte comune) ---------------------------------------------------------

/** La parte comune dell'ordinaria generata dopo il decesso: Ugo € 394,52, Anna € 236,71 e Bruno € 157,81. */
function b51dComune(): string
{
    return 'La successione di Ugo (decesso del 1 maggio 2026) ha passato l\'arretrato agli eredi, ma questo piano calcola ancora per Ugo € 394,52 su Interno A: la competenza è anteriore al decesso e le rate nascono ora, fuori dall\'arretrato. Ne rispondono gli eredi, ciascuno per la sua quota (art. 754 c.c.): Anna € 236,71 e Bruno € 157,81.';
}

/**
 * V2: nessuno ha un saldo, ma il piano non applica i saldi del Wallet. Dalla 1.11.0-beta.53 la strada sono i saldi dal Wallet legati alla
 * successione: si fissa il pezzo della frase che lo dice, con le righe.
 */
function b51dV2(string $ugo = '€ 394,52', string $anna = '€ 236,71', string $bruno = '€ 157,81'): string
{
    return 'registra dal Wallet i saldi legati alla successione ('
        . $ugo . ' a credito di Ugo, ' . $anna . ' a debito di Anna e ' . $bruno . ' a debito di Bruno) e crea di nuovo il piano';
}

/** V2 alla creazione: il piano non esiste ancora (il cancello annulla la richiesta), e i saldi legati si registrano prima di rimandare il modulo. */
function b51dV2Creazione(string $ugo = '€ 394,52', string $anna = '€ 236,71', string $bruno = '€ 157,81'): string
{
    return 'adesso dal Wallet i saldi legati alla successione ('
        . $ugo . ' a credito di Ugo, ' . $anna . ' a debito di Anna e ' . $bruno . ' a debito di Bruno) e rimandi il modulo, il piano li assorbe';
}

/** V1: nessuno ha un saldo e il piano li applica: i saldi dal Wallet legati alla successione. */
function b51dV1(): string
{
    return 'Per passare la parte agli eredi registra dal Wallet i saldi legati alla successione del 1 maggio 2026, sulla stessa gestione: € 394,52 a credito di Ugo, € 236,71 a debito di Anna e € 157,81 a debito di Bruno; il piano li assorbe alla prossima generazione.';
}

/**
 * Quella che era V3 (qualcuno ha già un saldo su quella gestione e quell'unità): dalla 1.11.0-beta.53, se nessuna riga è legata alla successione e
 * qualcuno ha un saldo che non è la parte, il programma dice che non sa se quei saldi la comprendono, e indica i saldi legati. `$chi` è nella forma
 * di prima («Ugo ha già un saldo», «Ugo, Anna e Bruno hanno già un saldo»): se ne prendono i nomi e il numero.
 */
function b51dV3(string $chi): string
{
    $piu = str_contains($chi, ' hanno già un saldo');
    $nomi = str_replace([' hanno già un saldo', ' ha già un saldo'], '', $chi);

    return $nomi . ($piu ? ' hanno' : ' ha') . ' su quella gestione e quell\'unità saldi non legati alla successione, e il programma non sa se comprendono la parte';
}

/** V4 (decisione 86.4): il defunto e gli eredi hanno il loro saldo, quel saldo è la parte, e il piano lo assorbe nelle quote con questa generazione. */
function b51dV4(string $chi): string
{
    return 'I saldi ci sono già: ' . $chi . ' hanno un saldo su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.';
}

/** La ragione che si aggiunge a V3 quando il piano non applica i saldi: i saldi già nel Wallet non entrano nelle sue quote. */
function b51dNonApplica(): string
{
    return 'Questo piano non applica i saldi del Wallet: quelli già registrati non entrano nelle sue quote.';
}

// --- Gli scenari ------------------------------------------------------------------------------------------------------------

/**
 * Unità A da 1000 millesimi, ordinaria € 1.200,00 in dodici rate dal 5/1 (piano non ancora generato), Ugo proprietario dal 2019;
 * successione di Ugo il 1/5/2026, eredi Anna 60 % e Bruno 40 %, arretrato agli eredi. Nessuna rata emessa: l'arretrato non scrive righe.
 * `$applicaSaldi`: il flag del piano (la creazione lo scrive secondo lo stato del Wallet di quel momento).
 */
function b51dOrdinaria($test, bool $applicaSaldi = false, bool $conPiano = true, string $arretrato = 'eredi', array $eredi = ['anna' => 60, 'bruno' => 40], array $extra = []): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    if ($conPiano) {
        $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: $applicaSaldi);
    }
    $s->titolare('A', 'ugo');
    b51dSuccessione($s, $arretrato, $eredi, $extra);

    return $s;
}

function b51dSuccessione(Beta51Scenario $s, string $arretrato = 'eredi', array $eredi = ['anna' => 60, 'bruno' => 40], array $extra = []): Subentro
{
    return $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => $eredi, 'arretrato' => $arretrato, 'riferimento' => array_key_first($eredi)] + $extra);
}

/**
 * La straordinaria dell'O15 del laboratorio: ordinaria emessa fino al 30/4, straordinaria Lavori € 1.000,00 deliberata il
 * `$delibera` in quattro rate dal 5/6, successione di Ugo il 1/5. Il piano straordinario non è ancora generato.
 */
function b51dStraordinaria($test, string $delibera = '2026-02-10'): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
        ->gestione('Straordinaria', 'straordinaria')->voce('Straordinaria', 'Lavori', 100000, ['Proprietà' => 100])
        ->pianoStraordinario('Lavori 2026', 'Straordinaria', ['Lavori' => 100000], $delibera, 4, '2026-06-05', applicaSaldi: false)->titolare('A', 'ugo');
    $s->genera('Preventivo 2026');
    $s->emetti('Preventivo 2026', '2026-04-30');
    b51dSuccessione($s);

    return $s;
}

/** Le cifre per erede di una voce del defunto, per nome: ['Anna' => 23671, 'Bruno' => 15781]. */
function b51dEredi(array $voce): array
{
    return collect($voce['eredi'])->mapWithKeys(fn ($e) => [$e['nome'] => $e['cifra']])->all();
}

/** Le tre righe del Wallet che passano la parte agli eredi, a mano: Ugo a credito, i due eredi a debito (positivo = debito). */
function b51dSaldiDegliEredi(Beta51Scenario $s, string $gestione = 'Ordinaria 2026', int $ugo = 39452, int $anna = 23671, int $bruno = 15781): void
{
    $s->saldo('ugo', 'A', $gestione, -$ugo);
    $s->saldo('anna', 'A', $gestione, $anna);
    $s->saldo('bruno', 'A', $gestione, $bruno);
}

// --- O-62-1: la straordinaria deliberata prima del decesso ------------------------------------------------------------------

it('O-62-1 — straordinaria deliberata il 10/2, generata dopo la successione: il cancello nomina Ugo con € 1.000,00, la parte di Anna e Bruno, e dice che il piano non applica i saldi', function () {
    $s = b51dStraordinaria($this);
    $cancello = $s->cancello('Lavori 2026');

    // Conto a mano: la competenza dello straordinario è il giorno della delibera (10/2), titolare Ugo: 100000 a Ugo.
    // Per erede: 100000 × 60 % = 60000 e × 40 % = 40000, nessun resto. Il piano straordinario non applica i saldi se nessuno lo chiede: V2.
    $voce = Beta51Scenario::voceDelDefunto($cancello);
    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(100000)
        ->and($voce['cifra_formattata'])->toBe('€ 1.000,00')
        ->and(b51dEredi($voce))->toBe(['Anna' => 60000, 'Bruno' => 40000])
        ->and($voce['wallet'])->toBeTrue()
        ->and($voce['piano_applica_saldi'])->toBeFalse()
        ->and($voce['frase'])->toContain('€ 1.000,00')->toContain('€ 600,00')->toContain('€ 400,00')
        ->and($voce['frase'])->toContain(b51dV2('€ 1.000,00', '€ 600,00', '€ 400,00'))
        // Il cancello conserva il cambiamento del motore e aggiunge la voce, in questo ordine.
        ->and(array_column($cancello, 'motivo'))->toBe(['fuori_periodo', 'defunto_nel_piano']);
});

it('guardia — O-62-1: accettato il cancello, le quote della straordinaria sono Ugo 100000, Anna 0, Bruno 0 (come O15 del laboratorio)', function () {
    $s = b51dStraordinaria($this);
    $s->genera('Lavori 2026', null);

    expect($s->somme('Lavori 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 100000, 'anna' => 0, 'bruno' => 0]);
});

it('O-62-1, ricalcolo — la rotta del ricalcolo porta la voce del defunto nel flash del cancello, accanto a quella del motore', function () {
    $s = b51dStraordinaria($this);
    $s->genera('Lavori 2026', null);
    $r = $s->ricalcola('Lavori 2026', accetta: false);

    $voce = Beta51Scenario::voceDelDefunto($r->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(100000)->and(b51dEredi($voce))->toBe(['Anna' => 60000, 'Bruno' => 40000]);
});

it('guardia — O-62-1b: straordinaria deliberata il 20/5, dopo il decesso, è degli eredi: la cifra di Ugo è zero e il cancello non nomina nessun defunto', function () {
    $s = b51dStraordinaria($this, '2026-05-20');

    // Conto a mano: competenza = 20/5, titolari Anna e Bruno: a Ugo 0, agli eredi 60000 e 40000.
    expect(Beta51Scenario::voceDelDefunto($s->cancello('Lavori 2026')))->toBeNull();
    $s->genera('Lavori 2026', null);
    expect($s->somme('Lavori 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 60000, 'bruno' => 40000]);
});

// --- O-62-2: l'ordinaria generata la prima volta dopo il decesso ------------------------------------------------------------

it('O-62-2 — ordinaria generata la prima volta dopo il decesso (nessuna riga di arretrato): il cancello nomina Ugo con € 394,52 e la parte di Anna (€ 236,71) e Bruno (€ 157,81)', function () {
    $s = b51dOrdinaria($this);
    $sub = Subentro::latest('id')->firstOrFail();
    // Con l'ordinaria mai generata l'arretrato non scrive righe: la condizione è la scelta, non le righe (correzione 1 della 0.2).
    expect($sub->registro['arretrato']['scelta'])->toBe('eredi')->and($sub->registro['arretrato']['saldi'])->toBe([]);

    $cancello = $s->cancello('Preventivo 2026');

    // Conto a mano: Ugo 1/1–30/4 = 120 giorni su 365 → 120000 × 120/365 = 39452,05 → 39452. Per erede 39452 × 60 % = 23671,2 e × 40 % = 15780,8:
    // floor 23671 + 15780 = 39451, il centesimo di resto va al resto maggiore (Bruno, .8): 23671 e 15781.
    $voce = Beta51Scenario::voceDelDefunto($cancello);
    expect($voce)->not->toBeNull();
    expect($voce['defunto']['id'])->toBe($s->persona('ugo')->id)
        ->and($voce['defunto']['nome'])->toBe('Ugo')
        ->and($voce['decesso'])->toBe('2026-05-01')
        ->and($voce['immobile_id'])->toBe($s->unita['A']->id)
        ->and($voce['immobile_nome'])->toBe('Interno A')
        ->and($voce['passaggio_id'])->toBe($sub->id)
        ->and($voce['cifra'])->toBe(39452)
        ->and($voce['cifra_formattata'])->toBe('€ 394,52')
        ->and(b51dEredi($voce))->toBe(['Anna' => 23671, 'Bruno' => 15781])
        ->and(collect($voce['eredi'])->pluck('cifra_formattata')->all())->toBe(['€ 236,71', '€ 157,81'])
        ->and(collect($voce['eredi'])->pluck('id')->all())->toBe([$s->persona('anna')->id, $s->persona('bruno')->id])
        ->and($voce['wallet'])->toBeTrue()
        ->and($voce['piano_applica_saldi'])->toBeFalse()
        ->and($voce['con_un_saldo'])->toBe([])
        // Il motore ha il suo cambiamento (pro rata dei giorni) e la voce lo segue (misurato S8).
        ->and(array_column($cancello, 'motivo'))->toBe(['pro_rata_giorni', 'defunto_nel_piano']);
    expect($voce['frase'])->toContain(b51dComune())->toContain(b51dV2())
        // Con i saldi applicati la quota netta di Ugo è zero; il testo dice che il piano «calcola ancora per» lui, non che gli «intesta».
        ->and($voce['frase'])->not->toContain('intesta');
});

it('guardia — O-62-2: accettato il cancello, le quote sono Ugo 39452, Anna 48329, Bruno 32219 (misurato S8)', function () {
    $s = b51dOrdinaria($this);
    $s->genera('Preventivo 2026', null);

    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 39452, 'anna' => 48329, 'bruno' => 32219]);
});

it('O-62-2, creazione — dalla rotta di creazione con «genera subito» il cancello porta la voce del defunto, e con il Wallet vuoto dice V2 nella forma della creazione: il piano non esiste ancora, i saldi si registrano e si rimanda il modulo', function () {
    $s = b51dOrdinaria($this, conPiano: false);
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
    ]);

    $voce = Beta51Scenario::voceDelDefunto($r->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(39452)->and(b51dEredi($voce))->toBe(['Anna' => 23671, 'Bruno' => 15781])
        ->and($voce['piano_applica_saldi'])->toBeFalse()
        ->and($voce['frase'])->toContain(b51dV2Creazione())->not->toContain('crea di nuovo il piano')
        // Il cancello annulla la creazione: nessun piano nuovo (quello di prima non c'è, e non ne nasce uno).
        ->and(PianoRate::where('nome', 'Preventivo 2026')->count())->toBe(0);
});

it('O-62-2, togli una voce — la rotta che toglie una voce dal piano generato porta la voce del defunto, con la cifra del piano senza quella voce', function () {
    $s = b51dOrdinaria($this, conPiano: false);
    // Una seconda voce da € 240,00 e un piano che nomina le sue voci (si può togliere): generato prima del decesso non si può,
    // quindi si genera dopo, con il cancello accettato.
    $s->voce('Ordinaria 2026', 'Pulizia', 24000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', capitoli: true, applicaSaldi: false);
    $s->genera('Preventivo 2026', null);
    $pulizia = $s->voci['Pulizia']['conto']->id;

    $r = $this->actingAs($s->admin)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s->c->id, $s->e->id, $s->piani['Preventivo 2026']['p']->id, $pulizia]));

    // Tolta «Pulizia» restano € 1.200,00: la cifra di Ugo è quella di O-62-2, 39452.
    $voce = Beta51Scenario::voceDelDefunto($r->getSession()->get('destinatari_warning') ?? []);
    expect($r->getSession()->get('destinatari_warning_detach'))->toBe($pulizia);
    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(39452)->and(b51dEredi($voce))->toBe(['Anna' => 23671, 'Bruno' => 15781]);
});

// --- O-62-3 e O-62-4: la pertinenza e il centesimo di resto -----------------------------------------------------------------

it('O-62-3 — la pertinenza segue l\'unità: una voce per unità, ciascuna con il suo immobile e la sua cifra per erede', function () {
    $s = Beta51Scenario::nuovo($this)->pertinenza('BX', 'A')->tabella('Proprietà', ['A' => 900, 'BX' => 100])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: false)
        ->titolare('A', 'ugo')->titolare('BX', 'ugo');
    b51dSuccessione($s, extra: ['pertinenze' => ['BX']]);

    // Conto a mano: A: 120000 × 900/1000 = 108000 → × 120/365 = 35506,85 → 35507; BX: 12000 × 120/365 = 3945,21 → 3945. Somma 39452.
    // Per erede: A 35507 × 60 % = 21304,2 → 21304 e 14202,8 → 14203 (il resto va a Bruno); BX 3945 × 60 % = 2367 e 1578.
    // Anna 21304 + 2367 = 23671, Bruno 14203 + 1578 = 15781. La scelta dell'arretrato sta nel padre: la riga del box non la porta (capo()).
    $voci = collect($s->cancello('Preventivo 2026'))->where('motivo', 'defunto_nel_piano')->keyBy('immobile_nome');
    expect($voci->keys()->sort()->values()->all())->toBe(['Box BX', 'Interno A']);
    expect($voci['Interno A']['immobile_id'])->toBe($s->unita['A']->id)
        ->and($voci['Interno A']['cifra'])->toBe(35507)
        ->and(b51dEredi($voci['Interno A']))->toBe(['Anna' => 21304, 'Bruno' => 14203])
        ->and($voci['Box BX']['immobile_id'])->toBe($s->unita['BX']->id)
        ->and($voci['Box BX']['cifra'])->toBe(3945)
        ->and(b51dEredi($voci['Box BX']))->toBe(['Anna' => 2367, 'Bruno' => 1578]);
});

it('O-62-4 — tre eredi: il centesimo di resto va al primo dei resti maggiori pari, cioè a Bruno per l\'ordine del registro (sentinella dell\'ordine)', function () {
    $s = b51dOrdinaria($this, eredi: ['anna' => 33.34, 'bruno' => 33.33, 'carla' => 33.33]);

    // Conto a mano: 39452 × 0,3334 = 13153,2968; × 0,3333 = 13149,3516 (due volte). Floor 13153 + 13149 + 13149 = 39451, resta 1 centesimo:
    // i resti sono .2968, .3516 e .3516, i due maggiori sono pari e vince il primo in ordine (arsort stabile): Bruno. Somma 39452.
    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect(b51dEredi($voce))->toBe(['Anna' => 13153, 'Bruno' => 13150, 'Carla' => 13149])
        ->and(array_sum(b51dEredi($voce)))->toBe($voce['cifra']);
});

// --- O-62-5: la strada a mano della decisione 80 ----------------------------------------------------------------------------

it('O-62-5 (a) — nessun saldo di Ugo, Anna o Bruno e il piano applica i saldi: il Wallet è libero, V1, e la cifra non cambia', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['wallet'])->toBeTrue()->and($voce['piano_applica_saldi'])->toBeTrue()->and($voce['con_un_saldo'])->toBe([])
        ->and($voce['cifra'])->toBe(39452)
        ->and($voce['frase'])->toContain(b51dV1())
        // V1 non dice «non applica»: il piano il saldo lo assorbe.
        ->and($voce['frase'])->not->toContain('non applica i saldi del Wallet');
});

it('O-62-5 (b) — Ugo ha già un saldo manuale sulla stessa gestione, unità ed esercizio: la parte si regola fra le parti (V3), e il pregresso non cambia la cifra', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', 10000);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    // La cifra è la quota pura del motore, prima dei saldi: il pregresso di Ugo lo assorbe già l'arretrato (rischio dichiarato).
    expect($voce['wallet'])->toBeFalse()->and($voce['con_un_saldo'])->toBe(['Ugo'])->and($voce['cifra'])->toBe(39452)
        ->and($voce['frase'])->toContain(b51dV3('Ugo ha già un saldo'))
        ->and($voce['frase'])->not->toContain('registra un saldo manuale dal Wallet');
});

it('O-62-5 (c) — solo Anna, un\'erede, ha già un saldo: stessa strada fra le parti, e la frase nomina lei', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 5000);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['wallet'])->toBeFalse()->and($voce['con_un_saldo'])->toBe(['Anna'])
        ->and($voce['frase'])->toContain(b51dV3('Anna ha già un saldo'));
});

it('O-62-5 (d) — una riga di saldo con subentro_id (dell\'arretrato) non occupa il posto nel Wallet: strada libera, V1', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $sub = Subentro::latest('id')->firstOrFail();
    Saldo::create(['esercizio_id' => $s->e->id, 'condominio_id' => $s->c->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'immobile_id' => $s->unita['A']->id,
        'anagrafica_id' => $s->persona('ugo')->id, 'saldo_iniziale' => 7000, 'origine' => 'automatico', 'is_applicato' => false, 'subentro_id' => $sub->id]);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['wallet'])->toBeTrue()->and($voce['con_un_saldo'])->toBe([])->and($voce['frase'])->toContain(b51dV1());
});

it('O-62-5 (e) — un saldo di Ugo su un\'altra gestione non conta: strada libera, V1', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $s->gestione('Straordinaria', 'straordinaria');
    $s->saldo('ugo', 'A', 'Straordinaria', 7000);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['wallet'])->toBeTrue()->and($voce['con_un_saldo'])->toBe([])->and($voce['frase'])->toContain(b51dV1());
});

it('DT3 — la scelta del cancello coincide con FermataDopoScrivi::conUnSaldo per le stesse persone, gestione, unità ed esercizio', function () {
    // Fonte unica: il cancello chiama `conUnSaldo` (seconda fonte dichiarata, decisione 80), che non conta né le righe scritte da un passaggio né
    // quelle legate a un passaggio. Con un saldo non legato di Bruno il cancello dice che il programma non sa se comprende la parte.
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $persone = [$s->persona('ugo')->id, $s->persona('anna')->id, $s->persona('bruno')->id];
    $g = $s->gestioni['Ordinaria 2026']['g']->id;
    $u = $s->unita['A']->id;

    $libero = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect(FermataDopoScrivi::conUnSaldo($persone, $g, $u, $s->e->id))->toBe([]);
    expect($libero)->not->toBeNull();
    expect($libero['wallet'])->toBeTrue();

    $s->saldo('bruno', 'A', 'Ordinaria 2026', 3000);
    $occupato = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect(FermataDopoScrivi::conUnSaldo($persone, $g, $u, $s->e->id))->toBe([(int) $s->persona('bruno')->id]);
    expect($occupato)->not->toBeNull();
    expect($occupato['wallet'])->toBeFalse()
        ->and($occupato['frase'])->toContain(b51dV3('Bruno ha già un saldo'))
        ->and($occupato['frase'])->not->toContain('il programma non ne registra un secondo');
});

// --- O-62-6, O-62-7: dove la voce non c'è -----------------------------------------------------------------------------------

it('guardia — O-62-6: con l\'arretrato a nome del defunto le quote di Ugo restano sue per scelta: nessuna voce del defunto', function () {
    $s = b51dOrdinaria($this, arretrato: 'defunto');

    // Decisioni 66.1 e 69.2: la posizione di Ugo resta a Ugo, non c'è nessuna cifra da passare agli eredi.
    $cancello = $s->cancello('Preventivo 2026');
    expect(Beta51Scenario::voceDelDefunto($cancello))->toBeNull()
        ->and(array_column($cancello, 'motivo'))->toBe(['pro_rata_giorni']);
});

it('guardia — O-62-6: un legato, con l\'arretrato a nome del defunto, non ha la voce del defunto', function () {
    $s = b51dOrdinaria($this, arretrato: 'defunto', eredi: ['anna' => 100], extra: ['legato' => true]);
    expect(Subentro::latest('id')->firstOrFail()->legato())->toBeTrue();

    expect(Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026')))->toBeNull();
});

it('guardia — O-62-7: successione annullata, Ugo è titolare dal censimento: tutto suo, nessun cambiamento, il piano si genera senza presa d\'atto', function () {
    $s = b51dOrdinaria($this);
    $s->annulla("A")->assertRedirect();

    // Conto a mano: con la successione annullata Ugo è titolare per tutto l'anno: 120000 a lui, nessun cambiamento di titolare.
    expect($s->cancello('Preventivo 2026'))->toBe([])
        ->and($s->somme('Preventivo 2026', ['ugo']))->toBe(['ugo' => 120000]);
});

// --- O-62-8: i saldi manuali assorbiti dal piano ----------------------------------------------------------------------------

it('guardia — O-62-8: con il piano che applica i saldi e i tre saldi manuali del Wallet (Ugo −€ 394,52, Anna € 236,71, Bruno € 157,81) le quote nette sono quelle dell\'arretrato', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    b51dSaldiDegliEredi($s);
    $s->genera('Preventivo 2026', null);

    // Conto a mano: Ugo 39452 − 39452 = 0; Anna 48329 + 23671 = 72000 (= 60 % di 120000); Bruno 32219 + 15781 = 48000 (= 40 %).
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
});

// --- O-62-9: il credito del defunto (unitario) ------------------------------------------------------------------------------

it('O-62-9 — un credito del defunto (un\'entrata che supera la spesa nei suoi giorni): la cifra è negativa, si divide sul valore assoluto, la frase dice «un credito» e le righe del Wallet si rovesciano', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $piano = $s->piano('Preventivo 2026');

    // Conto a mano: −39452 × 60 % → −23671, × 40 % → −15781 (il segno si riapplica dopo la divisione sul valore assoluto).
    $voci = app(DefuntoNelPiano::class)->per($piano, [$s->persona('ugo')->id => [$s->unita['A']->id => -39452]], true);
    expect($voci)->toHaveCount(1);
    $voce = $voci[0];
    expect($voce['cifra'])->toBe(-39452)
        ->and(collect($voce['eredi'])->pluck('cifra')->all())->toBe([-23671, -15781])
        ->and($voce['frase'])->toContain('calcola ancora per Ugo un credito di € 394,52')
        ->and($voce['frase'])->toContain('Il credito passa agli eredi per quota: Anna € 236,71 e Bruno € 157,81')
        ->and($voce['frase'])->toContain('€ 394,52 a debito di Ugo, € 236,71 a credito di Anna e € 157,81 a credito di Bruno');
});

it('O-62-9, cifra zero — il motore intesta zero al defunto: nessuna voce', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $piano = $s->piano('Preventivo 2026');

    expect(app(DefuntoNelPiano::class)->per($piano, [$s->persona('ugo')->id => [$s->unita['A']->id => 0]], true))->toBe([]);
});

// --- O-62-10: il cancello già accettato e la forma di titolarita_alla -------------------------------------------------------

it('O-62-10 — cancello già accettato: titolarita_alla porta la nota e la chiave defunti [{anagrafica_id, immobile_id, cifra}], e il conteggio delle coppie non include la voce del defunto', function () {
    $s = b51dOrdinaria($this);
    $s->genera('Preventivo 2026', null, 'Letto, prima generazione');

    // Misurato S8 senza la chiave: risoluzione temporale, destinatari_cambiati vero, nota_cancello, coppie 1.
    $ta = $s->titolaritaAlla('Preventivo 2026');
    expect($ta)->toBeArray()->toHaveKey('defunti');
    expect($ta['coppie'])->toBe(1)
        ->and($ta['nota_cancello'])->toBe('Letto, prima generazione')
        ->and($ta['risoluzione'])->toBe('temporale')
        ->and($ta['destinatari_cambiati'])->toBeTrue()
        ->and($ta['defunti'])->toBe([['anagrafica_id' => $s->persona('ugo')->id, 'immobile_id' => $s->unita['A']->id, 'cifra' => 39452]]);
});

it('guardia — O-62-10: con la presa d\'atto e la nota dal ricalcolo il cancello non si riapre e il flash non porta il pannello', function () {
    $s = b51dOrdinaria($this);
    $s->genera('Preventivo 2026', null);
    $r = $s->ricalcola('Preventivo 2026');

    expect($r->getSession()->get('destinatari_warning'))->toBeNull()
        ->and($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 39452, 'anna' => 48329, 'bruno' => 32219]);
});

// --- O-62-11: un erede vende dopo la successione ----------------------------------------------------------------------------

it('O-62-11 — Anna vende la sua quota a Carla il 1/9: la voce nomina gli eredi della successione (art. 754 c.c.), non i titolari di oggi', function () {
    $s = b51dOrdinaria($this);
    $s->persona('carla');
    $s->passaggio('vendita', 'A', 'anna', 'carla', '2026-09-01');

    // La quota di Ugo resta quella dei suoi giorni (39452): la voce nomina chi ha ereditato, Anna e Bruno.
    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(39452)->and(b51dEredi($voce))->toBe(['Anna' => 23671, 'Bruno' => 15781]);
});

/**
 * Ugo è proprietario al 50 % e nudo proprietario al 50 % di A, con Elsa usufruttuaria: due successioni lo stesso giorno, ognuna sul suo
 * ruolo, con eredi diversi (Anna 30 % e Bruno 20 % per la proprietà, Carla 50 % per la nuda proprietà). `$nudaPrima`: l'ordine in cui si registrano.
 * La voce «Spese generali» pesa sul ruolo «proprietario» e per l'usufrutto va anche alla nuda: il motore intesta a Ugo 19726 per la riga della
 * proprietà e 19726 per quella della nuda, 39452 in tutto.
 */
function b51dDueRuoli($test, bool $nudaPrima, bool $applicaSaldi = false): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: $applicaSaldi)
        ->titolare('A', 'ugo', 'proprietario', 50)->titolare('A', 'ugo', 'nuda_proprietario', 50)->titolare('A', 'elsa', 'usufruttuario', 50);
    $proprieta = fn () => $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 30, 'bruno' => 20], 'arretrato' => 'eredi', 'riferimento' => 'anna', 'ruolo' => 'proprietario']);
    $nuda = fn () => $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['carla' => 50], 'arretrato' => 'eredi', 'riferimento' => 'carla', 'ruolo' => 'nuda_proprietario']);
    if ($nudaPrima) {
        $nuda();
        $proprieta();
    } else {
        $proprieta();
        $nuda();
    }

    return $s;
}

it('O-62-15 — due successioni non annullate dello stesso defunto sulla stessa unità (la proprietà e la nuda proprietà): la cifra si divide per ruolo con le righe del riparto, gli eredi di ognuna per quota, qualunque sia l\'ordine di registrazione', function (bool $nudaPrima) {
    $s = b51dDueRuoli($this, $nudaPrima, applicaSaldi: true);

    // Conto a mano: la voce vale 120000; la metà piena di Ugo 60000 × 120/365 = 19726,03 → 19726, e lo stesso per la nuda: 39452 in tutto. Per ruolo:
    // la proprietà a Anna e Bruno (30 e 20 su 50): 19726 × 30/50 = 11835,6 e × 20/50 = 7890,4 → 11836 e 7890 (il resto al primo dei resti maggiori);
    // la nuda proprietà a Carla (50 su 50): 19726.
    $voci = collect($s->cancello('Preventivo 2026'))->where('motivo', 'defunto_nel_piano')->values();
    expect($voci)->toHaveCount(1);
    expect($voci[0]['defunto']['nome'])->toBe('Ugo')
        ->and($voci[0]['cifra'])->toBe(39452)
        ->and($voci[0]['parti_da_decidere'])->toBeFalse()
        ->and(b51dEredi($voci[0]))->toBe(['Anna' => 11836, 'Bruno' => 7890, 'Carla' => 19726])
        ->and(array_sum(b51dEredi($voci[0])))->toBe(39452)
        ->and($voci[0]['passaggi_ids'])->toHaveCount(2)
        ->and($voci[0]['frase'])->toStartWith('Le successioni di Ugo (decesso del 1 maggio 2026) hanno passato l\'arretrato agli eredi, ma questo piano calcola ancora per Ugo € 394,52 su Interno A: '
            . 'la competenza è anteriore al decesso e le rate nascono ora, fuori dall\'arretrato. '
            . 'La cifra è divisa per ruolo, come nel riparto del piano: € 197,26 per la proprietà e € 197,26 per la nuda proprietà. '
            . 'Ne rispondono gli eredi, ciascuno per la sua quota (art. 754 c.c.): Anna € 118,36, Bruno € 78,90 e Carla € 197,26. '
            . 'Per passare la parte agli eredi registra dal Wallet i saldi legati')
        // Dalla 1.11.0-beta.53 la strada sono i saldi dal Wallet legati alla successione, con le righe di ognuno.
        ->and($voci[0]['frase'])->toContain('sulla stessa gestione: € 394,52 a credito di Ugo, € 118,36 a debito di Anna, € 78,90 a debito di Bruno e € 197,26 a debito di Carla; il piano li assorbe alla prossima generazione.');
})->with(['la proprietà e poi la nuda' => [false], 'la nuda e poi la proprietà' => [true]]);

it('O-62-15, l\'esito vero — registrati i saldi della divisione per ruolo, il ricalcolo li assorbe: ognuno paga la sua quota dell\'anno (Anna 36000, Bruno 24000, Carla 60000) e Ugo 0; il cancello poi dice che i saldi ci sono già', function (bool $nudaPrima) {
    $s = b51dDueRuoli($this, $nudaPrima, applicaSaldi: true);
    $s->genera('Preventivo 2026', null);
    // Prima dei saldi le quote sono quelle del motore: Ugo 39452 per i suoi 120 giorni, e gli eredi i 245 giorni dopo (Anna 24164, Bruno 16110, Carla 40274).
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno', 'carla']))->toBe(['ugo' => 39452, 'anna' => 24164, 'bruno' => 16110, 'carla' => 40274]);

    $s->saldo('ugo', 'A', 'Ordinaria 2026', -39452);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 11836);
    $s->saldo('bruno', 'A', 'Ordinaria 2026', 7890);
    $s->saldo('carla', 'A', 'Ordinaria 2026', 19726);
    $voce = Beta51Scenario::voceDelDefunto($s->ricalcola('Preventivo 2026', accetta: false)->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['frase'])->toContain(b51dV4('Ugo, Anna, Bruno e Carla'))->not->toContain('si regola fra le parti');

    // Conto a mano: 24164 + 11836 = 36000 (30 % di 120000), 16110 + 7890 = 24000 (20 %), 40274 + 19726 = 60000 (50 %), 39452 − 39452 = 0.
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno', 'carla']))->toBe(['ugo' => 0, 'anna' => 36000, 'bruno' => 24000, 'carla' => 60000]);
})->with(['la proprietà e poi la nuda' => [false], 'la nuda e poi la proprietà' => [true]]);

it('O-62-15, la divisione da decidere — se un pezzo della cifra non si attribuisce a una successione (qui una riga senza la riga di titolarità), la voce nomina tutte le successioni con i loro eredi e dice che la divisione la decide l\'amministratore: nessuna parte per erede, nessuna riga del Wallet', function (?array $righe) {
    $s = b51dDueRuoli($this, false, applicaSaldi: true);
    $ugo = $s->persona('ugo')->id;
    $unita = $s->unita['A']->id;
    $righe ??= [];
    $righe = array_map(fn (array $r) => $r + ['anagrafica_id' => $ugo, 'immobile_id' => $unita], $righe);

    $voci = app(DefuntoNelPiano::class)->per($s->piano('Preventivo 2026'), [$ugo => [$unita => 39452]], true, $righe);

    expect($voci)->toHaveCount(1);
    $voce = $voci[0];
    expect($voce['parti_da_decidere'])->toBeTrue()
        ->and($voce['cifra'])->toBe(39452)
        ->and(collect($voce['eredi'])->pluck('nome')->all())->toBe(['Anna', 'Bruno', 'Carla'])
        ->and(collect($voce['eredi'])->pluck('cifra')->unique()->all())->toBe([null])
        ->and(collect($voce['eredi'])->pluck('cifra_formattata')->unique()->all())->toBe([null])
        ->and($voce['frase'])->toBe('Le successioni di Ugo (decesso del 1 maggio 2026) hanno passato l\'arretrato agli eredi, ma questo piano calcola ancora per Ugo € 394,52 su Interno A: '
            . 'la competenza è anteriore al decesso e le rate nascono ora, fuori dall\'arretrato. '
            . 'Ugo ha su quest\'unità più di una successione (la proprietà, con gli eredi Anna e Bruno; la nuda proprietà, con l\'erede Carla) e il programma non sa quale parte della cifra spetti a ciascuna: '
            . 'la divisione fra gli eredi la decide l\'amministratore, e il programma non ne propone le parti né le righe del Wallet.');
})->with([
    'una riga senza la riga di titolarità' => [[['tipo' => 'riparto', 'anagrafica_immobile_id' => 1, 'ruolo_risolto' => 'proprietario', 'importo' => 19726], ['tipo' => 'ad_personam', 'anagrafica_immobile_id' => null, 'ruolo_risolto' => null, 'importo' => 19726]]],
    'nessun dettaglio del riparto' => [null],
    'i pezzi non sommano alla cifra' => [[['tipo' => 'riparto', 'anagrafica_immobile_id' => 1, 'ruolo_risolto' => 'proprietario', 'importo' => 19726], ['tipo' => 'riparto', 'anagrafica_immobile_id' => 2, 'ruolo_risolto' => 'nuda_proprietario', 'importo' => 19000]]],
]);

// --- O-62-12, O-62-13, O-62-14: i saldi del Wallet entrano in un piano solo se il piano li applica ---------------------

it('O-62-12 — straordinaria dell\'O15: i tre saldi registrati DOPO la generazione non entrano nel ricalcolo, e il cancello al ricalcolo non promette che il piano li assorba', function () {
    $s = b51dStraordinaria($this);

    // Prima generazione, come la fa la creazione senza saldi: il cancello dice V2 (nessuno ha un saldo, il piano non li applica).
    $primo = Beta51Scenario::voceDelDefunto($s->cancello('Lavori 2026'));
    expect($primo)->not->toBeNull();
    expect($primo['frase'])->toContain(b51dV2('€ 1.000,00', '€ 600,00', '€ 400,00'));

    $s->genera('Lavori 2026', null);
    b51dSaldiDegliEredi($s, 'Straordinaria', 100000, 60000, 40000);
    $r = $s->ricalcola('Lavori 2026', accetta: false);

    // Conto a mano (GeneratePianoRateAction, match dei saldi): forza nulla, il piano non possiede saldi, il tipo è straordinario → i saldi non si applicano.
    // Misurato S1: le quote restano Ugo 100000, Anna 0, Bruno 0 prima e dopo, e i tre saldi restano non applicati.
    $voce = Beta51Scenario::voceDelDefunto($r->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['piano_applica_saldi'])->toBeFalse()->and($voce['cifra'])->toBe(100000)
        // Ora i tre hanno già un saldo: per la precedenza delle varianti dice V3 (fra le parti), e dice anche perché quei saldi non
        // entrano in questo piano. In nessun caso dice V1 («il piano lo assorbe»): è la promessa che il piano non mantiene.
        ->and($voce['wallet'])->toBeFalse()->and($voce['con_un_saldo'])->toBe(['Ugo', 'Anna', 'Bruno'])
        ->and($voce['frase'])->toContain(b51dV3('Ugo, Anna e Bruno hanno già un saldo'))->toContain(b51dNonApplica())
        ->and($voce['frase'])->not->toContain('il piano lo assorbe alla prossima generazione')
        ->and($voce['frase'])->not->toContain('registra un saldo manuale dal Wallet');
    $s->ricalcola('Lavori 2026');
    expect($s->somme('Lavori 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 100000, 'anna' => 0, 'bruno' => 0])
        ->and(Saldo::where('gestione_id', $s->gestioni['Straordinaria']['g']->id)->pluck('is_applicato')->map(fn ($x) => (bool) $x)->all())->toBe([false, false, false]);
});

it('O-62-13 — ordinaria con applica_saldi falso (Wallet vuoto alla creazione): i saldi registrati dopo non entrano nelle quote del ricalcolo (misurato S2)', function () {
    $s = b51dOrdinaria($this, applicaSaldi: false);
    $s->genera('Preventivo 2026', false);
    b51dSaldiDegliEredi($s);
    $r = $s->ricalcola('Preventivo 2026', accetta: false);

    // applica_saldi falso persistito alla creazione → il match dà falso. Quote invariate: Ugo 39452, Anna 48329, Bruno 32219.
    $voce = Beta51Scenario::voceDelDefunto($r->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['piano_applica_saldi'])->toBeFalse()
        ->and($voce['frase'])->toContain(b51dV3('Ugo, Anna e Bruno hanno già un saldo'))->toContain(b51dNonApplica())
        ->and($voce['frase'])->not->toContain('il piano lo assorbe alla prossima generazione');
    $s->ricalcola('Preventivo 2026');
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 39452, 'anna' => 48329, 'bruno' => 32219])
        ->and($s->piano('Preventivo 2026')->applica_saldi)->toBeFalse();
});

it('guardia — O-62-13: per applicare i saldi bisogna registrarli prima e creare di nuovo il piano; dalla rotta di creazione le quote nette sono Ugo 0, Anna 72000, Bruno 48000', function () {
    $s = b51dOrdinaria($this, conPiano: false);
    b51dSaldiDegliEredi($s);
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il Wallet è già registrato',
    ]);
    $r->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Preventivo 2026')->firstOrFail();
    $somma = fn (string $chi) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->sum('rate_quote.importo');

    expect($piano->applica_saldi)->toBeTrue()
        ->and([$somma('ugo'), $somma('anna'), $somma('bruno')])->toBe([0, 72000, 48000]);
});

it('O-62-14 — il piano applica i saldi: il cancello dice V1 (il piano assorbe il saldo manuale), e dopo i tre saldi le quote nette sono quelle di O-62-8 (DT6: il booleano coincide con l\'esito vero)', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['wallet'])->toBeTrue()->and($voce['piano_applica_saldi'])->toBeTrue()->and($voce['frase'])->toContain(b51dV1());

    // L'esito vero: registrati i tre saldi, il ricalcolo li porta nelle quote.
    b51dSaldiDegliEredi($s);
    $s->genera('Preventivo 2026', null);
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
});

// --- DT4: la divisione per erede è quella dell'arretrato --------------------------------------------------------------------

it('DT4 — la parte di ogni erede è la stessa divisione che dà l\'arretrato: € 394,52 passati agli eredi diventano 23671 e 15781 nelle righe dell\'arretrato e nella voce del cancello', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: false)
        ->titolare('A', 'ugo');
    // Tutte le rate emesse a Ugo: i suoi 120 giorni su 365 valgono 39452, e l'arretrato li passa agli eredi (misurato: totale 39452).
    $s->genera('Preventivo 2026', false);
    $s->emetti('Preventivo 2026', '2026-12-31')->assertSessionHasNoErrors();
    $sub = b51dSuccessione($s);
    expect($sub->registro['arretrato']['totale'])->toBe(39452);

    $righe = Saldo::whereIn('id', $sub->registro['arretrato']['saldi'])->where('saldo_iniziale', '>', 0)->orderBy('anagrafica_id')
        ->pluck('saldo_iniziale', 'anagrafica_id')->map(fn ($x) => (int) $x)->all();
    expect($righe)->toBe([$s->persona('anna')->id => 23671, $s->persona('bruno')->id => 15781]);

    $voci = app(DefuntoNelPiano::class)->per($s->piano('Preventivo 2026'), [$s->persona('ugo')->id => [$s->unita['A']->id => 39452]], true);
    expect($voci)->toHaveCount(1);
    expect(b51dEredi($voci[0]))->toBe(['Anna' => 23671, 'Bruno' => 15781]);
});

// --- 86.4: il cancello dopo i saldi manuali già assorbiti ---------------------------------------------------------------------

it('86.4 — dopo i tre saldi manuali assorbiti dal piano il cancello ritorna a ogni ricalcolo con la cifra pura del motore, e la frase dice che i saldi ci sono già, senza «intesta» e senza chiedere di registrarli né di regolare la parte fra le parti', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    b51dSaldiDegliEredi($s);
    $s->genera('Preventivo 2026', null);

    // Il piano ha assorbito i tre saldi: sono is_applicato e le quote nette sono quelle dell'arretrato (O-62-8).
    expect(Saldo::where('gestione_id', $s->gestioni['Ordinaria 2026']['g']->id)->where('is_applicato', true)->count())->toBe(3)
        ->and($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);

    // Conto a mano: la cifra è la quota pura del motore, prima dei saldi (39452 a Ugo per i suoi 120 giorni); le quote nette sono già
    // Ugo 0, Anna 72000, Bruno 48000, ma il cancello ritorna lo stesso, e dice la verità: il piano «calcola ancora per Ugo» (non «intesta»)
    // e i saldi ci sono già, assorbiti nelle quote. Non dice «la parte si regola fra le parti»: sarebbe un secondo regolamento a mano di
    // soldi che il piano ha già spostato (decisione 86.4).
    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['cifra'])->toBe(39452)
        ->and($voce['wallet'])->toBeFalse()->and($voce['piano_applica_saldi'])->toBeTrue()->and($voce['con_un_saldo'])->toBe(['Ugo', 'Anna', 'Bruno'])
        ->and(b51dEredi($voce))->toBe(['Anna' => 23671, 'Bruno' => 15781])
        ->and($voce['frase'])->toContain(b51dComune())->toContain(b51dV4('Ugo, Anna e Bruno'))
        ->and($voce['frase'])->not->toContain('intesta')
        ->and($voce['frase'])->not->toContain('si regola fra le parti')
        ->and($voce['frase'])->not->toContain('registra un saldo manuale dal Wallet')
        ->and($voce['frase'])->not->toContain('il piano lo assorbe alla prossima generazione')
        ->and($voce['frase'])->not->toContain('non applica i saldi');

    // Dalla rotta del ricalcolo, senza la presa d'atto: lo stesso, nel flash del pannello.
    $r = $s->ricalcola('Preventivo 2026', accetta: false);
    $dalRotta = Beta51Scenario::voceDelDefunto($r->getSession()->get('destinatari_warning') ?? []);
    expect($dalRotta)->not->toBeNull();
    expect($dalRotta['frase'])->toBe($voce['frase']);
});

it('86.4 — solo Anna ha un saldo e il piano l\'ha assorbito: Ugo e Bruno no, la parte non è passata per intero e la frase resta quella di prima, «si regola fra le parti» con il nome di Anna', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 23671);
    $s->genera('Preventivo 2026', null);

    // Il saldo di Anna è del piano (is_applicato), ma Ugo e Bruno non ne hanno: nella quota netta di Ugo ci sono ancora i suoi 39452.
    expect(Saldo::where('gestione_id', $s->gestioni['Ordinaria 2026']['g']->id)->where('is_applicato', true)->count())->toBe(1)
        ->and($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 39452, 'anna' => 72000, 'bruno' => 32219]);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['con_un_saldo'])->toBe(['Anna'])
        ->and($voce['frase'])->toContain(b51dV3('Anna ha già un saldo'))
        ->and($voce['frase'])->not->toContain('I saldi ci sono già');
});

it('86.4 — i tre saldi sono nel Wallet ma registrati dopo la generazione, liberi: il ricalcolo li assorbe, e il cancello dice che ci sono già; accettato, le quote nette sono quelle di O-62-8', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $s->genera('Preventivo 2026', null);
    // Le quote del piano sono nate prima dei saldi.
    DB::table('rate_quote')->update(['created_at' => '2026-01-01 00:00:00']);
    b51dSaldiDegliEredi($s);

    // I tre saldi non sono is_applicato: le quote del piano sono ancora quelle del motore (Ugo 39452, Anna 48329, Bruno 32219).
    expect(Saldo::where('gestione_id', $s->gestioni['Ordinaria 2026']['g']->id)->where('is_applicato', true)->count())->toBe(0)
        ->and($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 39452, 'anna' => 48329, 'bruno' => 32219]);

    // Il ricalcolo che il cancello ferma assorbirebbe i tre saldi: la frase non invita a regolare la parte a mano una seconda volta.
    $voce = Beta51Scenario::voceDelDefunto($s->ricalcola('Preventivo 2026', accetta: false)->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['con_un_saldo'])->toBe(['Ugo', 'Anna', 'Bruno'])
        ->and($voce['frase'])->toContain(b51dV4('Ugo, Anna e Bruno'))
        ->and($voce['frase'])->not->toContain('si regola fra le parti')
        ->and($voce['frase'])->not->toContain('registra un saldo manuale dal Wallet');

    // E dice il vero: accettato il cancello, il ricalcolo li porta nelle quote. Conto a mano: 39452 − 39452 = 0; 48329 + 23671 = 72000; 32219 + 15781 = 48000.
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000])
        ->and(Saldo::where('gestione_id', $s->gestioni['Ordinaria 2026']['g']->id)->where('is_applicato', true)->count())->toBe(3);
});

it('86.4 — i tre saldi sono stati assorbiti da un altro piano della stessa gestione: per questo piano non «ci sono già», la frase è quella di prima', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $s->pianoOrdinario('Altro piano', 'Ordinaria 2026');
    b51dSaldiDegliEredi($s);
    $s->genera('Preventivo 2026', null);
    // I tre saldi sono is_applicato, ma di un altro piano: le quote di questo non li comprendono più.
    DB::table('saldi')->where('gestione_id', $s->gestioni['Ordinaria 2026']['g']->id)->update(['piano_rate_id' => $s->piani['Altro piano']['p']->id]);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['con_un_saldo'])->toBe(['Ugo', 'Anna', 'Bruno'])
        ->and($voce['frase'])->toContain(b51dV3('Ugo, Anna e Bruno hanno già un saldo'))
        ->and($voce['frase'])->not->toContain('I saldi ci sono già');
});

it('86.4 — saldi della 1.7.x e della 1.8.x, senza piano e con is_applicato falso, scritti prima delle quote del piano: il piano li ha assorbiti (stesso criterio del conguaglio), e il cancello dice che ci sono già', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    b51dSaldiDegliEredi($s);
    $s->genera('Preventivo 2026', null);
    // Com'erano i saldi di allora: la colonna `piano_rate_id` non c'era e la migrazione ha lasciato `is_applicato` falso.
    DB::table('saldi')->where('gestione_id', $s->gestioni['Ordinaria 2026']['g']->id)->update(['piano_rate_id' => null, 'is_applicato' => false, 'created_at' => '2025-12-01 00:00:00']);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['frase'])->toContain(b51dV4('Ugo, Anna e Bruno'))->not->toContain('si regola fra le parti');
});

it('86.4 — Ugo e Anna hanno il saldo assorbito dal piano e Bruno lo ha registrato dopo, libero: la generazione che il cancello ferma assorbe anche il suo, e i saldi ci sono già', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', -39452);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 23671);
    $s->genera('Preventivo 2026', null);
    DB::table('rate_quote')->update(['created_at' => '2026-01-01 00:00:00']);
    $s->saldo('bruno', 'A', 'Ordinaria 2026', 15781);
    // Un saldo di Bruno su un'altra gestione non conta: il Wallet ne tiene uno per gestione, e questo piano non lo assorbe.
    $s->gestione('Straordinaria', 'straordinaria');
    $s->saldo('bruno', 'A', 'Straordinaria', 5000);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['con_un_saldo'])->toBe(['Ugo', 'Anna', 'Bruno'])
        ->and($voce['frase'])->toContain(b51dV4('Ugo, Anna e Bruno'));
});

it('86.4 — Ugo e Anna hanno il saldo assorbito dal piano e Bruno ne ha uno solo su un\'altra gestione: Bruno non ha un saldo qui, la parte non è passata per intero e la frase resta quella di prima', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', -39452);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 23671);
    $s->genera('Preventivo 2026', null);
    $s->gestione('Straordinaria', 'straordinaria');
    $s->saldo('bruno', 'A', 'Straordinaria', 5000);

    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();
    expect($voce['con_un_saldo'])->toBe(['Ugo', 'Anna'])
        ->and($voce['frase'])->toContain(b51dV3('Ugo e Anna hanno già un saldo'))
        ->and($voce['frase'])->not->toContain('I saldi ci sono già');
});

// --- 86.4, dalla rotta: i saldi liberi che la generazione assorbe ---------------------------------------------------------------

it('86.4, dalla rotta — V1 prima dei saldi; registrati i tre saldi, il ricalcolo senza la presa d\'atto dice V4 e non V3 («si regola fra le parti»), anche per i saldi della 1.7.x e della 1.8.x', function (bool $dellaVecchiaVersione) {
    $s = b51dOrdinaria($this, applicaSaldi: true);

    // Il cancello della prima generazione: nessuno ha un saldo, il piano li applica.
    $prima = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($prima['frase'])->toContain(b51dV1());

    $s->genera('Preventivo 2026', null);
    b51dSaldiDegliEredi($s);
    if ($dellaVecchiaVersione) {
        // Com'erano i saldi di allora: senza piano, `is_applicato` falso, scritti prima delle quote.
        DB::table('saldi')->where('gestione_id', $s->gestioni['Ordinaria 2026']['g']->id)->update(['piano_rate_id' => null, 'is_applicato' => false, 'created_at' => '2025-12-01 00:00:00']);
    }

    $r = $s->ricalcola('Preventivo 2026', accetta: false);
    $voce = Beta51Scenario::voceDelDefunto($r->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['piano_applica_saldi'])->toBeTrue()
        ->and($voce['frase'])->toContain(b51dV4('Ugo, Anna e Bruno'))
        ->and($voce['frase'])->not->toContain('si regola fra le parti');

    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'anna', 'bruno']))->toBe(['ugo' => 0, 'anna' => 72000, 'bruno' => 48000]);
})->with(['registrati adesso' => [false], 'della 1.7.x e della 1.8.x' => [true]]);

it('86.4, dalla creazione — con il Wallet vuoto la V2 dice di registrare i saldi e rimandare il modulo; registrati, il cancello dice V4 e non V3, e con la presa d\'atto il piano nasce con le quote nette Ugo 0, Anna 72000, Bruno 48000', function () {
    $s = b51dOrdinaria($this, conPiano: false);
    $corpo = fn (array $in = []) => [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
    ] + $in;
    $crea = fn (array $in = []) => $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), $corpo($in));

    $voce = Beta51Scenario::voceDelDefunto($crea()->getSession()->get('destinatari_warning') ?? []);
    expect($voce['frase'])->toContain(b51dV2Creazione())
        ->and(PianoRate::where('nome', 'Preventivo 2026')->count())->toBe(0);

    // Come dice la frase: i tre saldi, poi il modulo di nuovo, ancora senza la presa d'atto.
    b51dSaldiDegliEredi($s);
    $voce = Beta51Scenario::voceDelDefunto($crea()->getSession()->get('destinatari_warning') ?? []);
    expect($voce)->not->toBeNull();
    expect($voce['piano_applica_saldi'])->toBeTrue()
        ->and($voce['frase'])->toContain(b51dV4('Ugo, Anna e Bruno'))
        ->and($voce['frase'])->not->toContain('si regola fra le parti')
        ->and(PianoRate::where('nome', 'Preventivo 2026')->count())->toBe(0);

    $crea(['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: i saldi sono nel Wallet'])->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Preventivo 2026')->firstOrFail();
    $somma = fn (string $chi) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->sum('rate_quote.importo');
    expect([$somma('ugo'), $somma('anna'), $somma('bruno')])->toBe([0, 72000, 48000]);
});

// --- 86.4: i saldi che entrano sono la parte? (riporti, cifre, verso) ----------------------------------------------------------

it('86.4, i riporti non sono la parte — Ugo 34 %, Anna 33 % e Bruno 33 %, successione di Ugo con gli eredi Anna e Bruno (17 + 17): i riporti del consuntivo registrati dopo (Ugo +€ 50,00, Anna +€ 30,00, Bruno −€ 20,00) non passano la parte, e la frase resta V3 prima e dopo la generazione', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: true)
        ->titolare('A', 'ugo', 'proprietario', 34)->titolare('A', 'anna', 'proprietario', 33)->titolare('A', 'bruno', 'proprietario', 33);
    b51dSuccessione($s, 'eredi', ['anna' => 17, 'bruno' => 17]);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', 5000);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 3000);
    $s->saldo('bruno', 'A', 'Ordinaria 2026', -2000);

    // Conto a mano: la quota di Ugo 120000 × 34 % = 40800 × 120/365 = 13413,7 → 13414, divisa a metà fra Anna e Bruno: 6707 ciascuno.
    $prima = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($prima)->not->toBeNull();
    expect($prima['cifra'])->toBe(13414)->and(b51dEredi($prima))->toBe(['Anna' => 6707, 'Bruno' => 6707])
        ->and($prima['frase'])->toContain(b51dV3('Ugo, Anna e Bruno hanno già un saldo'))->not->toContain('I saldi ci sono già');

    // Generato il piano i tre riporti sono assorbiti dalle quote (is_applicato), ma non sono la parte: Ugo ha +5000 invece di −13414.
    $s->genera('Preventivo 2026', null);
    expect(Saldo::where('gestione_id', $s->gestioni['Ordinaria 2026']['g']->id)->where('is_applicato', true)->count())->toBe(3);
    $dopo = Beta51Scenario::voceDelDefunto($s->ricalcola('Preventivo 2026', accetta: false)->getSession()->get('destinatari_warning') ?? []);
    expect($dopo)->not->toBeNull();
    expect($dopo['frase'])->toContain(b51dV3('Ugo, Anna e Bruno hanno già un saldo'))->not->toContain('I saldi ci sono già')->not->toContain('Non serve registrare altro');
});

it('86.4, la parte entro un centesimo e nel verso giusto — il resto di un centesimo passa, due centesimi o il verso opposto no', function () {
    $s = b51dOrdinaria($this, applicaSaldi: true);
    b51dSaldiDegliEredi($s);
    $anna = Saldo::where('anagrafica_id', $s->persona('anna')->id)->firstOrFail();
    $dice = fn () => Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'))['frase'];

    // Anna 23671 + 1 centesimo: il resto di una divisione, passa. + 2: no. Il verso opposto (un credito di € 236,71): no.
    $anna->update(['saldo_iniziale' => 23672]);
    expect($dice())->toContain(b51dV4('Ugo, Anna e Bruno'));
    $anna->update(['saldo_iniziale' => 23673]);
    expect($dice())->toContain(b51dV3('Ugo, Anna e Bruno hanno già un saldo'))->not->toContain('I saldi ci sono già');
    $anna->update(['saldo_iniziale' => -23671]);
    expect($dice())->toContain(b51dV3('Ugo, Anna e Bruno hanno già un saldo'))->not->toContain('I saldi ci sono già');
    // Il saldo di Ugo: la cifra intera a credito. Con la metà no.
    $anna->update(['saldo_iniziale' => 23671]);
    Saldo::where('anagrafica_id', $s->persona('ugo')->id)->update(['saldo_iniziale' => -19726]);
    expect($dice())->toContain(b51dV3('Ugo, Anna e Bruno hanno già un saldo'))->not->toContain('I saldi ci sono già');
});

// --- La forma di titolarita_alla con i soli defunti e la regola dei saldi -----------------------------------------------------

it('O-62-10 — la forma di titolarita_alla: con i soli defunti ha la forma del cancello (coppie zero) più la chiave defunti; con i cambiamenti del motore le coppie non contano le voci del defunto; senza niente è atemporale e senza la chiave', function () {
    $defunti = [['immobile_id' => 8, 'defunto' => ['id' => 5, 'nome' => 'Ugo'], 'cifra' => 39452, 'frase' => 'non entra nella forma'],
        ['immobile_id' => 9, 'defunto' => ['id' => 5, 'nome' => 'Ugo'], 'cifra' => 3945]];
    $cambiamento = ['immobile_id' => 8, 'tipologia' => 'proprietario', 'conto_id' => 3, 'motivo' => 'pro_rata_giorni'];

    // Il motore non ha cambiamenti: la forma è quella del cancello, con le coppie a zero (le voci del defunto non sono coppie).
    expect(GeneratePianoRateAction::titolaritaAlla([], $defunti, 'Letto, solo il defunto'))->toBe([
        'risoluzione' => 'temporale', 'destinatari_cambiati' => true, 'nota_cancello' => 'Letto, solo il defunto', 'coppie' => 0,
        'defunti' => [['anagrafica_id' => 5, 'immobile_id' => 8, 'cifra' => 39452], ['anagrafica_id' => 5, 'immobile_id' => 9, 'cifra' => 3945]],
    ]);
    // Con un cambiamento del motore le coppie sono la sua (unità, ruolo), una, e la chiave defunti si aggiunge.
    expect(GeneratePianoRateAction::titolaritaAlla([$cambiamento, $cambiamento + ['conto_id' => 4]], $defunti, null))
        ->toMatchArray(['risoluzione' => 'temporale', 'destinatari_cambiati' => true, 'nota_cancello' => null, 'coppie' => 1])
        ->toHaveKey('defunti');
    // Senza defunti la forma è quella di sempre: nessuna chiave in più (i test che la fissano per intero non la conoscono).
    expect(GeneratePianoRateAction::titolaritaAlla([$cambiamento], [], 'Letto'))->toBe(['risoluzione' => 'temporale', 'destinatari_cambiati' => true, 'nota_cancello' => 'Letto', 'coppie' => 1])
        ->and(GeneratePianoRateAction::titolaritaAlla([], [], null))->toBe(['risoluzione' => 'atemporale']);
});

it('saldiSiApplicano — la regola estratta dal corpo della generazione dà lo stesso esito del match di prima, nell\'ordine: forza, saldi già posseduti, straordinario, scelta persistita, flag di gestione', function () {
    $s = b51dOrdinaria($this, applicaSaldi: false);
    $azione = app(GeneratePianoRateAction::class);
    $gestione = $s->gestioni['Ordinaria 2026']['g'];
    $piano = fn (array $campi) => $s->piano('Preventivo 2026')->forceFill($campi);
    $esito = fn (PianoRate $p, ?bool $forza = null) => $azione->saldiSiApplicano($p, $gestione->fresh(), $forza);

    // a) L'override del chiamante vince su tutto, in un verso e nell'altro.
    expect($esito($piano(['tipo' => 'straordinario', 'applica_saldi' => false]), true))->toBeTrue()
        ->and($esito($piano(['tipo' => 'ordinario', 'applica_saldi' => true]), false))->toBeFalse();
    // d) Senza override: la scelta persistita alla creazione; c) lo straordinario non assorbe se nessuno lo chiede.
    expect($esito($piano(['tipo' => 'ordinario', 'applica_saldi' => true])))->toBeTrue()
        ->and($esito($piano(['tipo' => 'ordinario', 'applica_saldi' => false])))->toBeFalse()
        ->and($esito($piano(['tipo' => 'straordinario', 'applica_saldi' => true])))->toBeFalse();
    // e) Il vecchio flag di gestione, per i piani senza la scelta persistita.
    $gestione->forceFill(['saldo_applicato' => true])->save();
    expect($esito($piano(['tipo' => 'ordinario', 'applica_saldi' => null])))->toBeTrue();
    $gestione->forceFill(['saldo_applicato' => false])->save();
    expect($esito($piano(['tipo' => 'ordinario', 'applica_saldi' => null])))->toBeFalse();
    // b) Il piano che possiede già dei saldi li tiene, anche straordinario e anche con la scelta persistita falsa: un ricalcolo non li perde.
    $saldo = $s->saldo('ugo', 'A', 'Ordinaria 2026', 5000);
    $saldo->forceFill(['piano_rate_id' => $s->piani['Preventivo 2026']['p']->id])->save();
    expect($esito($piano(['tipo' => 'straordinario', 'applica_saldi' => false])))->toBeTrue()
        ->and($esito($piano(['tipo' => 'ordinario', 'applica_saldi' => false]), false))->toBeFalse();
});

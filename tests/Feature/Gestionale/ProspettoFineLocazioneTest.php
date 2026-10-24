<?php

/**
 * 1.11.0-beta.50 — il prospetto degli oneri accessori dopo la fine di una locazione, con la scelta del conguaglio (decisioni 32,
 * 77, 79 e le scelte tecniche del progetto nel verbale `piano_esecutivo_beta50.md`). Il servizio (`ProspettoOneriAccessori`); la
 * stampa sta in `ProspettoFineLocazioneStampaTest`.
 *
 * Il prospetto è il documento con cui il proprietario regola con il conduttore le spese a carico dell'inquilino. Con la .50 la fine
 * di una locazione senza un nuovo inquilino, e il cambio d'inquilino, hanno le due voci «Scrivi il conguaglio» e «Non scriverlo»
 * (campo `conguaglio`, registro `conguaglio.scelta`), e il prospetto deve dire quello che è successo davvero:
 * - con «Scrivi» i giorni dopo l'uscita li ha presi con il conguaglio chi sta al posto dell'inquilino, e il prospetto li dice
 *   «senza conduttore», «pagata da» quella persona (decisione 32, la strada B);
 * - con «Non scriverlo» le rate restano a chi esce (verso il condominio niente cambia), e il prospetto dice i giorni dopo l'uscita
 *   da restituirgli fra le parti (strada A′: decisioni 32, 77 e 73.1; non la «rinuncia» delle decisioni 43 e 46);
 * - un inquilino registrato dopo (il cambio in due passi) rimborsa i suoi giorni a chi li ha pagati: Ugo con «Scrivi», Ines con
 *   «Non scriverlo»;
 * - dopo «Annulla il conguaglio» le parti hanno regolato fra loro, e il prospetto torna a 365 giorni (decisioni 43 e 46);
 * - il rinnovo con la stessa persona fonde le righe: con «Non scriverlo» Ines torna a una riga sola;
 * - con un coinquilino che resta la scelta non c'è (decisione 79): i giorni dopo l'uscita non si dividono fra i conduttori, restano
 *   nelle rate di chi esce con la nota «chi li paga lo decidono le parti», senza nomi (scelta del verbale, 11/10/2026 notte);
 * - le fini senza la scelta nel registro (registrate prima della .50, o con il piano ricalcolabile e poi emesse senza ricalcolo,
 *   decisione 40) si leggono con la strada A′, con la frase «nessun conguaglio»;
 * - il box locato insieme legge la scelta nel registro del padre.
 *
 * Le cifre sono fatte a mano, con il calcolo accanto, e mai copiate dall'uscita del programma. La regola della coppia: i resti
 * maggiori, il centesimo a chi ha il resto più grande, a pari resto a chi esce (`ProRataTemporis`); i pezzi dopo un secondo
 * passaggio si dividono di nuovo per giorni sul pezzo (due giri). Le cifre del prospetto sono esatte su questa regola. € 1.200,00,
 * 30 % «Inquilino» = 36000; Ines esce il 1/7: 1/1–30/6 = 31 + 28 + 31 + 30 + 31 + 30 = 181 giorni, 1/7–31/12 = 184;
 * 36000 × 181 / 365 = 17852,05 e 36000 × 184 / 365 = 18147,95 → 17852 + 18147 = 35999, il centesimo al resto ,95: 17852 / 18148.
 *
 * Ogni test prima controlla che il piano dia le cifre a mano (lo scenario è quello giusto), poi il prospetto. Il riassunto di un
 * conduttore è [totale, nelle sue rate, da rimborsare, giorni delle voci]; «da rimborsare» negativo vuol dire che gli si deve.
 *
 * Prima della .50 questi test erano rossi in due modi, e solo in questi: (a) la registrazione con il campo `conguaglio` era rifiutata
 * (fuori dalla successione era vietato: è il campo nuovo della decisione 77) — è il caso di tutti i test che mandano la scelta; (b)
 * l'asserzione sul prospetto (Ines a 365 giorni) — i test del coinquilino e della fine senza scelta, che non mandano il campo. Quattro
 * erano già giusti con il prospetto di prima, perché ignorava la fine senza nuovo inquilino, e fanno da guardia al prospetto nuovo,
 * che la legge: «Annulla il conguaglio» torna a 365 giorni, i due controlli dell'annullamento del passaggio, il rinnovo con «Non
 * scriverlo» in una riga sola (più il controllo del cambio in un passo con «Scrivi», già giusto prima a parte il campo).
 *
 * Il ripiego di prima e la coppia della fine sullo stesso pagatore (le due voci «pagata da Ugo» che `accorpa()` fonderebbe) è un
 * rischio scritto dalla misura del prospetto e misurato qui: le voci restano due.
 *
 * Cosa NON copre: la stampa (in `ProspettoFineLocazioneStampaTest`); il pannello e le frasi «tornano a …», la fermata dopo «Scrivi»
 * (la vendita dopo la fine), il rifiuto senza la scelta (le altre aree della .50); la forma delle righe della sezione di chi esce con
 * la strada A′ (tre righe o una con la restituzione nel piede: va nel mockup per Vincenzo) — si guarda solo il riassunto; chi si
 * nomina come informazione per la restituzione senza un nuovo inquilino («fra le parti» o «di solito il proprietario»); il già versato
 * «della persona»; più voci o più piani; lo
 * straordinario; gli esercizi non solari e il pareggio del centesimo sul 2028; il laboratorio (`Decisione32Test` va riscritto sul
 * campo vero, fuori dal commit).
 *
 * ➕ Revisione dei test della Fase 1 (11/10/2026), in fondo al file: il già versato «dell'unità» (D8: il difetto da € 18,15 della misura)
 *   e la voce su due tabelle, casi misurati e non portati nei test; il pari resto a chi esce (la regola della coppia, mai esercitata); il
 *   «pagata da» dopo la fermata della vendita (S7 della revisione delle misure); la testa del prospetto che dice la fine e la scelta (la
 *   mutazione che la toglieva sopravviveva). Prima della .50 erano rossi per il campo `conguaglio`.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariProspettoLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

// --- (1) e (2) La fine senza un nuovo inquilino, con le due voci, sulle cinque forme -----------------------------------------

it('fine senza nuovo inquilino con «Scrivi il conguaglio», sulle cinque forme: il prospetto dà a Ines i suoi 181 giorni nelle sue rate (€ 178,52) e i 184 dopo l\'uscita senza conduttore, pagati da chi sta al posto dell\'inquilino', function (string $forma, array $titolari, array $alPosto, array $mai) {
    $s = pflScenario($titolari);
    pflSeiRateEmesse($this, $s);
    expect(pflNetti($s))->toBe(pflNettiAttesi($forma)['prima']);

    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi');
    // La coppia: 18148 dalle rate di Ines a chi sta al posto dell'inquilino (le cifre in `pflNettiAttesi`).
    expect(pflNetti($s))->toBe(pflNettiAttesi($forma)['scrivi']);

    $p = pflProspetto($s);
    // Ines: 36000 nelle rate − 18148 di coppia = 17852 «nelle sue rate», i suoi 181 giorni; niente da rimborsare.
    expect(pflConduttori($p))->toBe(['Ines Moretti' => [17852, 17852, 0, 181]])
        // I 184 giorni dopo l'uscita, 18148, senza conduttore: li ha pagati con la coppia chi sta al posto dell'inquilino.
        ->and($p['senza_conduttore']['totale'])->toBe(18148)
        ->and(collect($p['senza_conduttore']['voci'])->every(fn ($v) => (int) $v['giorni'] === 184))->toBeTrue('ogni voce senza conduttore copre i 184 giorni dopo l\'uscita: ' . json_encode(pflSenza($p), JSON_UNESCAPED_UNICODE))
        // 17852 + 18148 = 36000.
        ->and($p['totale_inquilino'])->toBe(36000);
    $pagatori = implode(' | ', pflPagatori($p['senza_conduttore']['voci']));
    foreach ($alPosto as $nome) {
        expect($pagatori)->toContain($nome);
    }
    foreach ([...$mai, 'Ines Moretti'] as $nome) {
        expect($pagatori)->not->toContain($nome);
    }
})->with(pflForme());

it('fine senza nuovo inquilino con «Non scriverlo» (strada A′), sulle cinque forme: le rate restano a Ines, e il prospetto le dà i suoi 181 giorni (€ 178,52) e € 181,48 dei giorni dopo l\'uscita da restituirle', function (string $forma, array $titolari) {
    $s = pflScenario($titolari);
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');
    // Verso il condominio niente cambia: nessuna coppia.
    expect(pflNetti($s))->toBe(pflNettiAttesi($forma)['prima']);

    $p = pflProspetto($s);
    // Ines: la sua parte è dei suoi 181 giorni, 17852; nelle sue rate ce ne sono 36000; da rimborsare 17852 − 36000 = −18148, cioè
    // 18148 da restituirle.
    expect(pflConduttori($p))->toBe(['Ines Moretti' => [17852, 36000, -18148, 181]])
        // I 184 giorni dopo l'uscita, senza conduttore: li ha pagati Ines nelle sue rate.
        ->and(pflSenza($p))->toBe([['Ines Moretti', 18148, 184]])
        ->and($p['totale_inquilino'])->toBe(36000);
})->with(pflForme());

// --- (3) Il cambio d'inquilino in due passi: la fine con la scelta, poi l'inizio di Luca --------------------------------------

it('cambio d\'inquilino in due passi, fine di Ines il 1/7 con la scelta e Luca dal 1/7 o dal 1/9: Luca rimborsa i suoi giorni a Ugo con «Scrivi», a Ines con «Non scriverlo»', function (string $scelta, string $dal) {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', $scelta);
    pflInizio($this, $s, 'Luca Ferri', $dal);

    $scrivi = $scelta === 'scrivi';
    // L'inizio su un piano fermo non sposta denaro (decisione 45): Luca rimborsa con il prospetto.
    expect(pflNetti($s))->toBe($scrivi ? ['Ines Moretti' => 17852, 'Ugo Rinaldi' => 102148] : ['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000]);

    // Ines come nella fine da sola: [17852, 17852, 0, 181] con «Scrivi», [17852, 36000, −18148, 181] con «Non scriverlo».
    $ines = $scrivi ? [17852, 17852, 0, 181] : [17852, 36000, -18148, 181];
    $chiHaPagato = $scrivi ? 'Ugo Rinaldi' : 'Ines Moretti';
    if ($dal === '2026-07-01') {
        // Luca 1/7–31/12, 184 giorni: tutti i 18148 dopo l'uscita, da rimborsare; nessun giorno senza conduttore.
        $luca = [18148, 0, 18148, 184];
        $senza = [];
    } else {
        // Luca 1/9–31/12 = 30 + 31 + 30 + 31 = 122 giorni; luglio e agosto 31 + 31 = 62, senza conduttore. Il pezzo dopo l'uscita si
        // divide per giorni: 18148 × 122 / 184 = 12032,91 e 18148 × 62 / 184 = 6115,09 → 12032 + 6115 = 18147, il centesimo al resto
        // ,91: Luca 12033, senza conduttore 6115. (Su un giro solo, 36000 × 122 / 365 = 12032,88 e 36000 × 62 / 365 = 6115,07: le
        // stesse cifre.)
        $luca = [12033, 0, 12033, 122];
        $senza = [[$chiHaPagato, 6115, 62]];
    }
    $p = pflProspetto($s);
    expect(pflConduttori($p))->toBe(['Ines Moretti' => $ines, 'Luca Ferri' => $luca])
        ->and(pflPagatori(pflSezione($p, 'Luca Ferri')['voci']))->toBe([$chiHaPagato])
        ->and(pflSenza($p))->toBe($senza)
        ->and($p['totale_inquilino'])->toBe(36000);
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere'])->with(['Luca dal 1/7' => '2026-07-01', 'Luca dal 1/9' => '2026-09-01']);

// --- (4) Il cambio d'inquilino in un passo, con le due voci al posto della casella (decisione 77.2) --------------------------

it('cambio d\'inquilino in un passo il 1/7: con «Non scriverlo» è la strada A′ (Luca rimborsa a Ines i suoi 184 giorni), con «Scrivi» Luca li ha pagati con il conguaglio', function (string $scelta) {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', $scelta);

    $p = pflProspetto($s);
    if ($scelta === 'scrivi') {
        // La coppia: Ines 18148 a credito, Luca 18148 a debito (36000 − 18148 = 17852 a Ines).
        expect(pflNetti($s))->toBe(['Ines Moretti' => 17852, 'Luca Ferri' => 18148, 'Ugo Rinaldi' => 84000]);
        // Luca: 18148 per 184 giorni, pagati con il conguaglio — niente nelle sue rate, niente da rimborsare.
        expect(pflConduttori($p))->toBe(['Ines Moretti' => [17852, 17852, 0, 181], 'Luca Ferri' => [18148, 0, 0, 184]])
            ->and(pflSezione($p, 'Luca Ferri')['con_il_conguaglio'])->toBe(18148);
    } else {
        // Nessuna coppia: le rate restano a Ines.
        expect(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000]);
        // Ines come nella fine senza nuovo inquilino con «Non scriverlo»; Luca deve i suoi 184 giorni, 18148, a chi li ha pagati: Ines.
        expect(pflConduttori($p))->toBe(['Ines Moretti' => [17852, 36000, -18148, 181], 'Luca Ferri' => [18148, 0, 18148, 184]])
            ->and(pflPagatori(pflSezione($p, 'Luca Ferri')['voci']))->toBe(['Ines Moretti']);
    }
    expect($p['senza_conduttore']['totale'])->toBe(0)->and($p['totale_inquilino'])->toBe(36000);
})->with(['Non scriverlo' => 'non_scrivere', 'Scrivi, controllo' => 'scrivi']);

// --- (5) Dopo «Annulla il conguaglio»: 365 giorni (decisioni 43 e 46) -----------------------------------------------------------

it('fine senza nuovo inquilino con «Scrivi», poi «Annulla il conguaglio»: le parti hanno regolato fra loro, la coppia non c\'è più, e il prospetto torna a Ines per 365 giorni (decisioni 43 e 46)', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    $fine = pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi');
    expect(pflNetti($s))->toBe(['Ines Moretti' => 17852, 'Ugo Rinaldi' => 102148]);

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $fine]),
        ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro i giorni dopo l\'uscita'])->assertSessionHasNoErrors();

    // Senza la coppia: Ines 36000, Ugo 84000. Il prospetto: Ines 36000 per 365 giorni nelle sue rate, nessuna restituzione, nessun
    // giorno senza conduttore (non la strada A′: qui le parti hanno dichiarato di aver regolato).
    expect(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000]);
    $p = pflProspetto($s);
    expect(pflConduttori($p))->toBe(['Ines Moretti' => [36000, 36000, 0, 365]])
        ->and(pflSenza($p))->toBe([]);
});

it('controllo — la fine senza nuovo inquilino annullata dallo storico: il prospetto torna a Ines per 365 giorni, con tutte e due le voci', function (?string $scelta) {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    $fine = pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', $scelta);
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $fine]), ['nota_annullamento' => 'Registrata per sbaglio: la annullo'])
        ->assertSessionHasNoErrors();

    expect(Subentro::whereKey($fine->id)->exists())->toBeFalse()
        ->and(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000])
        ->and(pflConduttori(pflProspetto($s)))->toBe(['Ines Moretti' => [36000, 36000, 0, 365]]);
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere']);

// --- (6) Il rinnovo con la stessa persona ---------------------------------------------------------------------------------------

it('rinnovo con la stessa persona, fine di Ines il 1/7 con «Non scriverlo» e inizio di Ines lo stesso giorno: il prospetto fonde le righe, Ines torna a una riga sola, € 360,00 per 365 giorni nelle sue rate', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');
    pflInizio($this, $s, 'Ines Moretti', '2026-07-01');

    expect(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000]);
    $p = pflProspetto($s);
    // 181 + 184 = 365 giorni suoi, 36000 tutti nelle sue rate: niente «pagata da Ines» nella sezione di Ines, niente da restituirle.
    expect(pflConduttori($p))->toBe(['Ines Moretti' => [36000, 36000, 0, 365]])
        ->and(collect(pflSezione($p, 'Ines Moretti')['voci'])->map(fn ($v) => [$v['conto'], $v['modo'], $v['pagato_da'] ?? null, (int) $v['importo'], $v['giorni']])->all())
        ->toBe([['Spese generali', 'rate', null, 36000, 365]])
        ->and(pflSenza($p))->toBe([]);
});

it('rinnovo con la stessa persona, fine di Ines il 1/7 con «Scrivi» e inizio di Ines lo stesso giorno: Ines rimborsa a Ugo i 184 giorni che lui ha preso con il conguaglio (€ 181,48)', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi');
    pflInizio($this, $s, 'Ines Moretti', '2026-07-01');

    expect(pflNetti($s))->toBe(['Ines Moretti' => 17852, 'Ugo Rinaldi' => 102148]);
    $p = pflProspetto($s);
    // Ines 365 giorni, 36000: 17852 nelle sue rate (36000 − 18148 di coppia) e 18148 da rimborsare a Ugo. Con il prospetto il denaro
    // torna all'oracolo: Ines 17852 + 18148 = 36000, Ugo 102148 − 18148 = 84000.
    expect(pflConduttori($p))->toBe(['Ines Moretti' => [36000, 17852, 18148, 365]])
        ->and(pflPagatori(pflSezione($p, 'Ines Moretti')['voci']))->toBe(['Ugo Rinaldi'])
        ->and(pflSenza($p))->toBe([]);
});

it('rientro della stessa persona il 1/10, dopo la fine del 1/7, con le due voci: Ines ha i suoi 273 giorni; luglio–settembre restano a chi li ha pagati, e nella sua sezione nessuna voce «pagata da Ines»', function (string $scelta) {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', $scelta);
    pflInizio($this, $s, 'Ines Moretti', '2026-10-01');

    // Il pezzo dopo l'uscita, 18148 per 184 giorni, diviso per giorni: 1/7–30/9 = 31 + 31 + 30 = 92, 1/10–31/12 = 31 + 30 + 31 = 92:
    // 18148 × 92 / 184 = 9074 esatti per parte. Ines: 181 + 92 = 273 giorni, 17852 + 9074 = 26926.
    $p = pflProspetto($s);
    if ($scelta === 'scrivi') {
        // Nelle sue rate 17852; i suoi giorni da ottobre li ha pagati Ugo con il conguaglio: 9074 da rimborsargli. Luglio–settembre
        // restano a Ugo, senza conduttore.
        expect(pflConduttori($p))->toBe(['Ines Moretti' => [26926, 17852, 9074, 273]])
            ->and(pflSenza($p))->toBe([['Ugo Rinaldi', 9074, 92]]);
    } else {
        // Nelle sue rate 36000; i suoi giorni da ottobre sono già nelle sue rate; luglio–settembre, 9074, sono da restituirle:
        // 26926 − 36000 = −9074.
        expect(pflConduttori($p))->toBe(['Ines Moretti' => [26926, 36000, -9074, 273]])
            ->and(pflSenza($p))->toBe([['Ines Moretti', 9074, 92]])
            ->and(pflPagatori(pflSezione($p, 'Ines Moretti')['voci']))->not->toContain('Ines Moretti');
    }
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere']);

// --- (7) Il coinquilino che resta (decisione 79.2) ----------------------------------------------------------------------------

it('coinquilino che resta: Ines e Dora inquiline al 50 %, Ines esce il 1/7: il prospetto divide la parte dell\'inquilino con la regola del piano, € 119,34 e € 240,66, e dice chi rimborsa chi, senza «lo decidono le parti»', function (string $scelta) {
    // Coda 245 (1.11.0-beta.55): con un coinquilino che resta la fine ha la scelta e la regola del piano (prima: nessuna scelta, i € 90,74 dopo l'uscita nelle rate
    // di Ines con la nota «chi li paga lo decidono le parti», e Ines e Dora a € 180,00 ciascuna). Le cifre: pesi Ines 50 × 181 = 9050, Dora 50 × 365 = 18250;
    // 36000 × 9050/27300 = 11934,07 → Ines 11934, Dora 24066. Nelle loro rate 18000 ciascuna. Con «Scrivi» la coppia (Ines − 6066, Dora + 6066) porta ognuna
    // alla sua parte: niente da rimborsare. Con «Non scriverlo» Ines ha pagato 6066 più della sua parte, e Dora glieli rimborsa.
    $s = pflScenario(inquilini: [['Ines Moretti', 50], ['Dora Conti', 50]]);
    pflSeiRateEmesse($this, $s);
    expect(pflNetti($s))->toBe(['Dora Conti' => 18000, 'Ines Moretti' => 18000, 'Ugo Rinaldi' => 84000]);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', $scelta);

    $p = pflProspetto($s);
    $ines = pflSezione($p, 'Ines Moretti');
    $dora = pflSezione($p, 'Dora Conti');
    // I giorni sono quelli di conduzione: Ines 181, Dora 365.
    expect(pflConduttori($p))->toBe($scelta === 'scrivi'
            ? ['Dora Conti' => [24066, 18000, 0, 365], 'Ines Moretti' => [11934, 18000, 0, 181]]
            : ['Dora Conti' => [24066, 18000, 6066, 365], 'Ines Moretti' => [11934, 18000, -6066, 181]])
        ->and([(int) $ines['con_il_conguaglio'], (int) $dora['con_il_conguaglio']])->toBe($scelta === 'scrivi' ? [-6066, 6066] : [0, 0])
        ->and(collect($dora['regola_del_piano']['rimborsi'])->map(fn ($r) => [$r['verso'], $r['nome'], (int) $r['importo']])->all())->toBe($scelta === 'scrivi' ? [] : [['a', 'Ines Moretti', 6066]])
        // Nessuno paga «al posto dell'inquilino»: c'è Dora tutto l'anno.
        ->and($p['senza_conduttore']['totale'])->toBe(0)
        ->and($p['totale_inquilino'])->toBe(36000)
        ->and(pflPagatori($ines['voci']))->toBe([])
        ->and(pflPagatori($dora['voci']))->toBe([]);
    foreach (pflFrasi($p) as $f) {
        expect($f)->not->toContain('lo decidono le parti');
    }
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere']);

// --- (8) Le fini senza la scelta nel registro --------------------------------------------------------------------------------

it('fine senza scelta nel registro, piano ricalcolabile alla fine e poi emesso tutto senza ricalcolo (decisione 40): il prospetto è la strada A′, con la frase «nessun conguaglio»', function () {
    $s = pflScenario();
    pflGenera($this, $s);
    // Nessuna rata a giornale: le due strade danno le stesse cifre e la scelta non si chiede (decisione 32, condizione 1).
    $fine = pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', null);
    expect($fine->registro)->not->toHaveKey('conguaglio');
    // Poi le dodici rate emesse senza ricalcolare: chi emette così ha lasciato le rate a Ines.
    pflEmetti($this, $s, '2026-12-31', '2026-07-02');
    expect(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000]);

    $p = pflProspetto($s);
    // Le cifre della strada A′: Ines 17852 per 181 giorni, 36000 nelle sue rate, 18148 da restituirle; i 184 giorni dopo l'uscita
    // senza conduttore, pagati da lei.
    expect(pflConduttori($p))->toBe(['Ines Moretti' => [17852, 36000, -18148, 181]])
        ->and(pflSenza($p))->toBe([['Ines Moretti', 18148, 184]]);
    // La frase sua: non c'è stata una scelta, quindi «nessun conguaglio», non «non scritto».
    $frasi = implode(' | ', pflFrasi($p));
    expect(mb_strtolower($frasi))->toContain('nessun conguaglio')
        ->and($frasi)->not->toContain('non è stato scritto');
});

it('fine senza nuovo inquilino registrata prima della .50 con sei rate emesse (registro senza la scelta e senza piani presi): il prospetto è la strada A′, con la frase «nessun conguaglio»', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    // Con il codice nuovo la fine si registra con una scelta; il registro si riporta poi com'era prima della .50.
    $fine = pflComePrimaDellaCinquanta(pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere'));
    expect($fine->registro)->not->toHaveKey('conguaglio')
        ->and(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000]);

    $p = pflProspetto($s);
    expect(pflConduttori($p))->toBe(['Ines Moretti' => [17852, 36000, -18148, 181]])
        ->and(pflSenza($p))->toBe([['Ines Moretti', 18148, 184]]);
    $frasi = implode(' | ', pflFrasi($p));
    expect(mb_strtolower($frasi))->toContain('nessun conguaglio')
        ->and($frasi)->not->toContain('non è stato scritto');
});

// --- Il ripiego di prima e la coppia della fine sullo stesso pagatore ---------------------------------------------------------

// Rischio della misura del prospetto (Fase 1 della .50, non misurato allora): la voce «pagata da Ugo» del ripiego (nessun inquilino
// registrato) e quella «pagata da Ugo» con la coppia della fine hanno la stessa chiave in `accorpa()`, e si fonderebbero perdendo la
// dicitura della fine. Le cifre restano giuste anche fuse: qui si guardano le cifre e che le due voci restino due.
it('Ines inquilina dal 1/3, fine il 1/7 con «Scrivi»: i 59 giorni di gennaio e febbraio pagati da Ugo per ripiego e i 184 dopo l\'uscita presi da Ugo con la coppia restano due voci senza conduttore, € 58,19 e € 181,48', function () {
    // Ines entra con un inizio locazione registrato prima della generazione (una riga censita dal 1/3 senza un passaggio vale da
    // sempre: D7, decisione 23).
    $s = pflScenario(inquilini: []);
    pflInizio($this, $s, 'Ines Moretti', '2026-03-01');
    pflSeiRateEmesse($this, $s);
    // La generazione: Ines dal 1/3 al 31/12, 31 + 30 + 31 + 30 + 31 + 31 + 30 + 31 + 30 + 31 = 306 giorni, 36000 × 306 / 365 = 30180,82;
    // Ugo per ripiego 1/1–28/2, 59 giorni, 36000 × 59 / 365 = 5819,18 → 30180 + 5819 = 35999, il centesimo al resto ,82: Ines 30181,
    // Ugo 5819 + 84000 = 89819.
    expect(pflNetti($s))->toBe(['Ines Moretti' => 30181, 'Ugo Rinaldi' => 89819]);

    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi');
    // La coppia: i 30181 di Ines divisi sui suoi 306 giorni, 1/3–30/6 = 122 e 1/7–31/12 = 184: 30181 × 122 / 306 = 12032,95 e
    // 30181 × 184 / 306 = 18148,05 → 12032 + 18148 = 30180, il centesimo al resto ,95: Ines 12033, a Ugo 18148. (Il piano generato
    // dopo: Ines 122 giorni, 36000 × 122 / 365 = 12032,88 → 12033; le stesse cifre.)
    expect(pflNetti($s))->toBe(['Ines Moretti' => 12033, 'Ugo Rinaldi' => 107967]);

    $p = pflProspetto($s);
    expect(pflConduttori($p))->toBe(['Ines Moretti' => [12033, 12033, 0, 122]])
        // 5819 + 18148 = 23967; 12033 + 23967 = 36000.
        ->and($p['senza_conduttore']['totale'])->toBe(23967)
        ->and(pflSenza($p))->toEqualCanonicalizing([['Ugo Rinaldi', 5819, 59], ['Ugo Rinaldi', 18148, 184]]);
});

// --- Il box locato insieme: una scelta sola, letta nel registro del padre ------------------------------------------------------

it('box locato insieme, di Rita: la fine di Ines con il box spuntato e una scelta sola vale anche per il box, e il prospetto del box legge la scelta del padre', function (string $scelta) {
    // Interno 1: 900 millesimi su 1000 → 108000; 30 % all'inquilino 32400, 70 % 75600 a Ugo. Box 12: 100 su 1000 → 12000; 3600 e 8400
    // a Rita.
    $s = pflScenario(box: true, titolariBox: [['Rita Bassi', 'proprietario', 100]]);
    pflSeiRateEmesse($this, $s);
    expect(pflNetti($s))->toBe(['Ines Moretti' => 32400, 'Ugo Rinaldi' => 75600])
        ->and(pflNetti($s, $s['box']))->toBe(['Ines Moretti' => 3600, 'Rita Bassi' => 8400]);

    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', $scelta, [$s['box']]);

    // Interno 1: 32400 × 181 / 365 = 16066,85 e 32400 × 184 / 365 = 16333,15 → 16066 + 16333 = 32399, il centesimo al resto ,85
    // (di Ines): 16067 / 16333. Box: 3600 × 181 / 365 = 1785,21 e 3600 × 184 / 365 = 1814,79 → 1785 + 1814 = 3599, il centesimo al
    // resto ,79: 1785 / 1815.
    $pu = pflProspetto($s);
    $pb = pflProspetto($s, $s['box']);
    if ($scelta === 'scrivi') {
        // Ugo 75600 + 16333 = 91933; Rita 8400 + 1815 = 10215.
        expect(pflNetti($s))->toBe(['Ines Moretti' => 16067, 'Ugo Rinaldi' => 91933])
            ->and(pflNetti($s, $s['box']))->toBe(['Ines Moretti' => 1785, 'Rita Bassi' => 10215])
            ->and(pflConduttori($pu))->toBe(['Ines Moretti' => [16067, 16067, 0, 181]])
            ->and(pflSenza($pu))->toBe([['Ugo Rinaldi', 16333, 184]])
            ->and(pflConduttori($pb))->toBe(['Ines Moretti' => [1785, 1785, 0, 181]])
            ->and(pflSenza($pb))->toBe([['Rita Bassi', 1815, 184]]);
    } else {
        expect(pflNetti($s))->toBe(['Ines Moretti' => 32400, 'Ugo Rinaldi' => 75600])
            ->and(pflNetti($s, $s['box']))->toBe(['Ines Moretti' => 3600, 'Rita Bassi' => 8400])
            // 16067 − 32400 = −16333; 1785 − 3600 = −1815.
            ->and(pflConduttori($pu))->toBe(['Ines Moretti' => [16067, 32400, -16333, 181]])
            ->and(pflSenza($pu))->toBe([['Ines Moretti', 16333, 184]])
            ->and(pflConduttori($pb))->toBe(['Ines Moretti' => [1785, 3600, -1815, 181]])
            ->and(pflSenza($pb))->toBe([['Ines Moretti', 1815, 184]]);
    }
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere']);

// --- Aggiunti nella revisione dei test della Fase 1 (11/10/2026): le mutazioni che sopravvivevano e un caso del verbale
// -------------------

// Il già versato «dell'unità» (decisione 17, D8). La misura del prospetto della Fase 1 aveva trovato qui un difetto da € 18,15 (Ines
// 14252 invece di 16067: la restituzione e il pezzo dopo l'uscita al lordo del versato); il prospetto della .50 lo corregge, e
// questo test lo presidia: la mutazione «il pezzo dopo l'uscita al lordo» sopravviveva ai test di prima. NON copre il già versato
// «della persona» (non misurato).
it('fine senza nuovo inquilino con € 120,00 già versati dall\'unità sulla voce (D8): il pezzo dopo l\'uscita è al netto del versato, come la coppia — con «Scrivi» € 163,33 a Ugo, con «Non scriverlo» € 163,33 da restituire a Ines, che resta con € 160,67', function (string $scelta) {
    $s = pflScenario();
    pflVersatoDellUnita($s, 12000);
    pflSeiRateEmesse($this, $s);
    // Il versato dell'unità sconta il lordo prima della divisione per ruolo (D8): 120000 − 12000 = 108000; 30 % a Ines = 32400, 70 % a
    // Ugo = 75600 (32400 + 75600 = 108000).
    expect(pflNetti($s))->toBe(['Ines Moretti' => 32400, 'Ugo Rinaldi' => 75600]);

    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', $scelta);
    // La parte di Ines sui suoi giorni: 32400 × 181/365 = 16066,85 e 32400 × 184/365 = 16333,15 → 16066 + 16333 = 32399, il centesimo al
    // resto ,85: 16067 / 16333. Al lordo e sul versato tornano le stesse cifre: 36000 → 17852 / 18148; 3600 × 181/365 = 1785,21 e
    // 3600 × 184/365 = 1814,79 → 1785 + 1814 = 3599, il centesimo al resto ,79: 1785 / 1815; 17852 − 1785 = 16067, 18148 − 1815 = 16333.
    $p = pflProspetto($s);
    if ($scelta === 'scrivi') {
        // La coppia sul netto: 16333 a Ugo, 75600 + 16333 = 91933; Ines 32400 − 16333 = 16067.
        expect(pflNetti($s))->toBe(['Ines Moretti' => 16067, 'Ugo Rinaldi' => 91933])
            ->and(pflConduttori($p))->toBe(['Ines Moretti' => [16067, 16067, 0, 181]])
            ->and(pflSenza($p))->toBe([['Ugo Rinaldi', 16333, 184]]);
    } else {
        // Nessuna coppia. Da restituirle il netto: 16067 − 32400 = −16333 (al lordo sarebbe −18148, e Ines resterebbe con 14252).
        expect(pflNetti($s))->toBe(['Ines Moretti' => 32400, 'Ugo Rinaldi' => 75600])
            ->and(pflConduttori($p))->toBe(['Ines Moretti' => [16067, 32400, -16333, 181]])
            ->and(pflSenza($p))->toBe([['Ines Moretti', 16333, 184]]);
    }
    expect($p['totale_inquilino'])->toBe(32400);
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere']);

// La voce su due tabelle, caso misurato dal prospetto della Fase 1 e non portato nei test. La coppia somma la voce sulle due tabelle e
// arrotonda una volta (DV1, decisione 59); il prospetto fa lo stesso. NON copre il centesimo fra le due tabelle (a pari resto: lo si
// guarda solo come somma).
it('fine senza nuovo inquilino con la voce su due tabelle (€ 100,00 tutta sull\'inquilino, a metà): la voce si divide una volta, Ines € 49,59 e € 50,41 dopo l\'uscita, con tutte e due le voci', function (string $scelta) {
    $s = pflScenario();
    pflVoceSuDueTabelle($s);
    pflSeiRateEmesse($this, $s);
    // 10000 × 50/100 per tabella: due righe da 5000 nelle rate di Ines; Ugo non ha niente.
    expect(pflNetti($s))->toBe(['Ines Moretti' => 10000]);

    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', $scelta);
    // La voce sommata: 10000 × 181/365 = 4958,90 e 10000 × 184/365 = 5041,10 → 4958 + 5041 = 9999, il centesimo al resto ,90: Ines
    // 4959, dopo l'uscita 5041 (non 2 × 2521 = 5042, che si avrebbe arrotondando tabella per tabella: 5000 × 184/365 = 2520,55).
    $p = pflProspetto($s);
    $ines = pflSezione($p, 'Ines Moretti');
    $chi = $scelta === 'scrivi' ? 'Ugo Rinaldi' : 'Ines Moretti';
    expect($ines['totale'])->toBe(4959)
        ->and($ines['nelle_sue_rate'])->toBe($scelta === 'scrivi' ? 4959 : 10000)
        ->and($ines['da_rimborsare'])->toBe($scelta === 'scrivi' ? 0 : -5041)
        ->and(collect($ines['voci'])->filter(fn ($v) => $v['giorni'] !== null)->every(fn ($v) => (int) $v['giorni'] === 181))->toBeTrue()
        ->and($p['senza_conduttore']['totale'])->toBe(5041)
        ->and(collect($p['senza_conduttore']['voci'])->every(fn ($v) => (int) $v['giorni'] === 184 && $v['pagato_da'] === $chi))->toBeTrue(json_encode(pflSenza($p)))
        ->and($p['totale_inquilino'])->toBe(10000);
    if ($scelta === 'scrivi') {
        expect(pflNetti($s))->toBe(['Ines Moretti' => 4959, 'Ugo Rinaldi' => 5041]);
    }
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere']);

// A pari resto il centesimo va a chi esce: è la regola della coppia scritta nelle misure, e nessun test la esercitava (in tutti gli
// altri casi i resti sono diversi). La mutazione «a pari resto a chi entra» nella divisione del prospetto sopravviveva: il prospetto
// avrebbe dato a Ines un centesimo meno della coppia. NON copre il pareggio sul 2028 (anno di 366 giorni).
it('pari resto: Ines inquilina dal 2/1 (364 giorni) esce il 3/7, 182 giorni prima e 182 dopo, e la sua parte (€ 359,01) è dispari: il centesimo a chi esce, Ines € 179,51 e € 179,50 dopo l\'uscita', function (string $scelta) {
    $s = pflScenario(inquilini: []);
    pflInizio($this, $s, 'Ines Moretti', '2026-01-02');
    pflSeiRateEmesse($this, $s);
    // La generazione, un giro solo: Ines 2/1–31/12 = 364 giorni, 36000 × 364/365 = 35901,37; Ugo per ripiego il 1/1, 36000 × 1/365 =
    // 98,63 → 35901 + 98 = 35999, il centesimo al resto ,63: Ugo 99, Ines 35901. Ugo 84000 + 99 = 84099.
    expect(pflNetti($s))->toBe(['Ines Moretti' => 35901, 'Ugo Rinaldi' => 84099]);

    pflFine($this, $s, 'Ines Moretti', null, '2026-07-03', $scelta);
    // I giorni di Ines: 2/1–2/7 = 30 + 28 + 31 + 30 + 31 + 30 + 2 = 182; 3/7–31/12 = 29 + 31 + 30 + 31 + 30 + 31 = 182. 35901 × 182/364 =
    // 17950,5 per parte: pari resto, il centesimo a chi esce. Ines 17951, dopo l'uscita 17950.
    $p = pflProspetto($s);
    if ($scelta === 'scrivi') {
        // La coppia: 17950 a Ugo, 84099 + 17950 = 102049.
        expect(pflNetti($s))->toBe(['Ines Moretti' => 17951, 'Ugo Rinaldi' => 102049])
            ->and(pflConduttori($p))->toBe(['Ines Moretti' => [17951, 17951, 0, 182]]);
    } else {
        // 17951 − 35901 = −17950.
        expect(pflNetti($s))->toBe(['Ines Moretti' => 35901, 'Ugo Rinaldi' => 84099])
            ->and(pflConduttori($p))->toBe(['Ines Moretti' => [17951, 35901, -17950, 182]]);
    }
    // Senza conduttore il 1/1 di Ugo (99) e i 182 giorni dopo l'uscita (17950): 18049; 17951 + 18049 = 36000. (Con «Scrivi» le due voci
    // sono di Ugo: è il caso di `accorpa()` del test del ripiego, qui si guarda solo il totale.)
    expect($p['senza_conduttore']['totale'])->toBe(18049)->and($p['totale_inquilino'])->toBe(36000);
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere']);

// Il «pagata da» dopo una fermata (S7 della revisione delle misure, adottata nel verbale della .50 con le altre sue raccomandazioni, e
// messa nella stima del prospetto): la fermata non sposta denaro, quindi per i giorni che copre ha pagato Ugo con la coppia — o Elsa, se
// le parti hanno regolato la fermata a mano. Il prospetto nomina tutti e due per quei giorni, e solo Ugo per gli altri. NON copre la
// frase parola per parola, il pannello dell'inizio (la cifra 3058 che resta da regolare: area della fermata), la fermata con un
// conduttore già registrato (S1).
it('«pagata da» dopo la fermata della vendita: fine di Ines il 1/7 con «Scrivi», Ugo vende a Elsa il 1/10, Luca entra il 1/11 — per i giorni coperti dalla fermata il prospetto nomina Ugo ed Elsa, per luglio–settembre solo Ugo', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi');
    pflVendita($this, $s, 'Ugo Rinaldi', 'Elsa Conti', '2026-10-01');
    // La vendita divide la parte «Proprietario» di Ugo, 84000, per giorni: 1/1–30/9 = 273, 1/10–31/12 = 92; 84000 × 273/365 = 62826,85 e
    // 84000 × 92/365 = 21173,15 → 62826 + 21173 = 83999, il centesimo al resto ,85: Ugo 62827, Elsa 21173. La coppia della fine, 18148,
    // resta a Ugo (la fermata non sposta denaro): 62827 + 18148 = 80975.
    expect(pflNetti($s))->toBe(['Elsa Conti' => 21173, 'Ines Moretti' => 17852, 'Ugo Rinaldi' => 80975]);
    pflInizio($this, $s, 'Luca Ferri', '2026-11-01');

    $p = pflProspetto($s);
    // Luca 1/11–31/12 = 30 + 31 = 61 giorni; senza conduttore 1/7–31/10 = 31 + 31 + 30 + 31 = 123. Il pezzo dopo l'uscita, 18148 per 184
    // giorni: 18148 × 61/184 = 6016,46 e 18148 × 123/184 = 12131,54 → 6016 + 12131 = 18147, il centesimo al resto ,54: Luca 6016, senza
    // conduttore 12132.
    expect(pflConduttori($p))->toBe(['Ines Moretti' => [17852, 17852, 0, 181], 'Luca Ferri' => [6016, 0, 6016, 61]])
        ->and($p['senza_conduttore']['totale'])->toBe(12132)
        ->and($p['totale_inquilino'])->toBe(36000);
    $testo = fn (array $voce) => implode(' ', pflFrasi($voce));
    // I giorni di Luca sono tutti dopo la vendita: li ha pagati Ugo con la coppia, o Elsa se avete regolato la fermata.
    $luca = collect(pflSezione($p, 'Luca Ferri')['voci'])->map($testo)->implode(' | ');
    expect($luca)->toContain('Ugo Rinaldi')->toContain('Elsa Conti');
    // Senza conduttore: luglio–settembre (92 giorni) solo Ugo; ottobre (31 giorni), coperto dalla fermata, Ugo o Elsa.
    $senza = collect($p['senza_conduttore']['voci']);
    expect((int) $senza->reject(fn ($v) => str_contains($testo($v), 'Elsa Conti'))->sum('giorni'))->toBe(92, json_encode(pflSenza($p), JSON_UNESCAPED_UNICODE))
        ->and((int) $senza->filter(fn ($v) => str_contains($testo($v), 'Elsa Conti') && str_contains($testo($v), 'Ugo Rinaldi'))->sum('giorni'))->toBe(31);
});

// La testa del prospetto: la frase fissa sui modi dice che «pagata da …» è di un titolare «perché nei giorni del piano nessun inquilino
// era registrato», e per i giorni dopo l'uscita non è vero (rischio scritto dalla misura del prospetto). La testa deve dire la fine e la
// scelta: è la controparte dei due test delle fini senza scelta qui sopra («nessun conguaglio», non «non è stato scritto»). La mutazione
// che toglie le frasi della fine dalla testa sopravviveva. NON copre la frase parola per parola (il mockup per Vincenzo).
it('la testa del prospetto dice la fine del 01/07/2026 e la scelta: «non scritto» con «Non scriverlo», scritto con «Scrivi»', function (string $scelta) {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', $scelta);

    $testa = collect(pflProspetto($s)['intestazione'])->filter(fn (string $f) => str_contains($f, '01/07/2026'))->implode(' | ');
    expect($testa)->not->toBe('', 'la testa del prospetto non nomina la fine del 01/07/2026');
    if ($scelta === 'scrivi') {
        expect($testa)->toContain('scritto')->not->toMatch('/non (è stato )?scritto/u');
    } else {
        expect($testa)->toMatch('/non (è stato )?scritto/u');
    }
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere']);

<?php

/**
 * 1.11.0-beta.50 — la fermata dopo «Scrivi il conguaglio» (decisione 77 punto 3, allargata dalla 79 e precisata l'11/10/2026
 * notte; la strada a mano della decisione 80; verbale `docs/piano_esecutivo_beta50.md`, «Le scelte tecniche del progetto»).
 *
 * Il fatto: alla fine della locazione di Ines il 1/7, senza un nuovo inquilino e con «Scrivi il conguaglio», chi paga al posto
 * dell'inquilino (Ugo, il proprietario) prende i 184 giorni dopo l'uscita: la coppia è Ines −18148, Ugo +18148. Se poi un
 * passaggio abbassa il peso di Ugo fra chi sta al posto dell'inquilino (vendita, costituzione, estinzione, successione con
 * l'arretrato al defunto e «Scrivi»), la parte di quei giorni dal passaggio in poi non passa da sola: senza la fermata andrebbe a
 * chi entra in silenzio, € 90,74 in meno nel caso misurato. Il programma non la scrive, registra il passaggio, conserva la
 * fermata nel registro e la dice con la persona, la cifra e la strada a mano: il saldo manuale dal Wallet solo se la persona non
 * ha già un saldo su quella gestione, altrimenti «si regola fra le parti» (decisione 80). La cifra conta solo i giorni dopo il
 * passaggio in cui non c'è un conduttore registrato (correzione S1 della revisione).
 *
 * LE CIFRE, fatte a mano (regole scritte nelle misure della .50): € 1.200,00, 30 % «Inquilino» = 36000, 70 % «Proprietario» =
 * 84000; il 2026 ha 365 giorni. Giorni: 1/1–30/6 = 31+28+31+30+31+30 = 181; 1/7–30/9 = 31+31+30 = 92; 1/10–31/12 = 31+30+31 = 92;
 * 1/7–31/12 = 184; 1/1–30/4 = 120; 1/5–31/12 = 245; 1/10–31/10 = 31; 1/11–31/12 = 61.
 * - la coppia della fine (regola della coppia: resti maggiori, il centesimo al resto più grande, a pari resto a chi esce):
 *   36000 × 181/365 = 17852,05 → 17852 a Ines; 36000 × 184/365 = 18147,95 → 18148 dopo l'uscita (17852 + 18147 = 35999, il
 *   centesimo al resto ,95). Coppia: Ines −18148, Ugo +18148;
 * - la vendita del 1/10, parte del «Proprietario»: 84000 × 273/365 = 62827,40 → Ugo 62827; 84000 × 92/365 = 21172,60 → Elsa
 *   21173 (il centesimo al resto ,60);
 * - la fermata: la coppia ridivisa per giorni con la titolarità dopo il passaggio, meno quella scritta. 18148 × 92/184 = 9074,00 a
 *   Ugo (1/7–30/9) e 9074,00 a chi entra (1/10–31/12): la fermata è 9074, esatta, senza arrotondamento.
 *
 * Tolleranza: le cifre della fermata e dei netti sono esatte sulla regola della coppia. Il piano generato dopo i passaggi
 * (decisione 21) può differire di un centesimo per unità: dove serve è dichiarato accanto alla cifra.
 *
 * Che cosa copre, dalle rotte vere (anteprima, registrazione, emissione, annullamento, Wallet): la fermata con la vendita il 1/10,
 * la vendita datata prima e registrata dopo, la costituzione, l'estinzione dell'usufrutto, la successione con l'arretrato al
 * defunto e «Scrivi»; il registro che la conserva e lo storico che la dice; la frase della decisione 80 nei due rami (Wallet / fra
 * le parti) e la strada del Wallet seguita fino all'oracolo; la coppia già assorbita da un piano (letta da `is_applicato` e
 * `piano_rate_id`); nessuna fermata con la riserva, la successione con l'arretrato agli eredi, la successione con l'arretrato al
 * defunto e «Non scriverlo», «Non scriverlo» alla fine, il conguaglio della fine annullato; i giorni coperti da un conduttore
 * registrato (S1 della revisione, Luca dal 1/9 e dal 1/11); l'annullamento dall'ultimo (decisione 27). Aggiunti nella revisione
 * dei test della Fase 1, ognuno per una mutazione che i test di prima lasciavano vivere: il verso delle due righe del Wallet nella
 * frase (a credito di chi resta a carico, a debito di chi riceve); la cifra con i centesimi (vendita il 15/10 e il 1/12,
 * arrotondamento per difetto e per eccesso); la comproprietà al 50 % (la fermata è la riga di chi esce, non la coppia dell'unità);
 * la coppia di una successione con «Scrivi», che non è una fine e non fa fermare nessuno.
 * ➕ Fusione della Fase 1 (11/10/2026), dalle scelte di progetto del verbale («Le scelte di progetto sulle questioni aperte dai
 * test»): la fermata è un motivo del cancello, con la spunta (prende la mutazione M15, che sopravviveva); la decisione 80 quando il
 * saldo sulla gestione ce l'ha chi riceve (Elsa): niente Wallet, «fra le parti».
 *
 * Che cosa NON copre: il rinnovo e l'inizio dopo una fermata (S7) — sono in `InizioDopoScriviTest.php`; il coinquilino che resta
 * (decisione 79 punto 2, area della fine locazione); le pertinenze (il box con la fine spuntata), l'unità mista e la persona con
 * due righe (i pesi «per persona con tutte le sue righe», correzione (4) della revisione delle misure), due fini di fila,
 * l'ordine inverso (vendita registrata prima della fine con data dopo: la coppia della fine per tratti, area della fine), la
 * vendita della nuda proprietà, più gestioni o più piani con voci dell'inquilino, gli esercizi non solari e il 2028; l'avviso
 * dell'annullamento sul saldo manuale scritto per la fermata (Coda 244); il prospetto degli oneri accessori dopo una fermata
 * (area del prospetto); la pagina Vue del pannello: si guarda la risposta dell'anteprima e lo storico come li calcola il server;
 * MySQL.
 *
 * Presuppone che la fine locazione senza nuovo inquilino accetti il campo `conguaglio` (la scelta della decisione 77, nuova
 * nella .50, area della fine locazione). La chiave del registro `fermata_dopo_scrivi` (con `persona_id`, `cifra`, `gestione_id`,
 * `immobile_id`) è quella scelta nelle misure.
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/FermataDopoScriviHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Il punto di partenza di quasi tutti i casi: piano generato, sei rate emesse (gennaio–giugno, il piano non si ricalcola più),
 * fine di Ines il 1/7 con «Scrivi il conguaglio». Controlla la coppia scritta: Ines −18148, Ugo +18148 (chi paga al posto
 * dell'inquilino è il primo anello dopo l'inquilino: Ugo sull'unità piena, Ugo usufruttuario sull'unità in usufrutto).
 */
function fds50DopoScrivi($test, array $titolari = [['Ugo', 'proprietario', 100]]): array
{
    $s = fds50Scenario($titolari);
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    // 36000 × 184/365 = 18147,95 → 18148 (vedi in testa).
    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148]);

    return $s;
}

/** La fermata del passaggio registrato: [persona_id => somma delle cifre]. */
function fds50CifrePerPersona(Subentro $passaggio): array
{
    $out = [];
    foreach (fds50Fermate($passaggio) as $f) {
        $out[(int) $f['persona_id']] = ($out[(int) $f['persona_id']] ?? 0) + (int) $f['cifra'];
    }

    return $out;
}

// --- Si ferma: i passaggi che abbassano il peso di chi ha preso la coppia -----------------------------------------------------

it('vendita il 1/10 dopo «Scrivi»: si registra, la parte di Elsa (€ 90,74) non si scrive e si dice con persona, cifra e strada del Wallet; il registro la conserva', function () {
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));

    // Il passaggio è registrato, e il denaro non si muove da solo: la coppia della fine resta com'era.
    expect($vendita->tipo_passaggio)->toBe('vendita')
        ->and(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        // Netti: Ines 36000 − 18148 = 17852; Ugo 62827 (1/1–30/9 del «Proprietario») + 18148 (la coppia) = 80975; Elsa 21173.
        // 17852 + 80975 + 21173 = 120000.
        ->and(fds50Netti($s))->toBe(['Elsa' => 21173, 'Ines' => 17852, 'Ugo' => 80975])
        // La fermata nel registro: 18148 × 92/184 = 9074, a carico di Ugo, sulla gestione e sull'unità della coppia.
        ->and(fds50CifrePerPersona($vendita))->toBe([(int) $s['persone']['Ugo']->id => 9074])
        ->and(collect(fds50Fermate($vendita))->pluck('gestione_id')->unique()->values()->all())->toBe([(int) $s['g']->id])
        ->and(collect(fds50Fermate($vendita))->pluck('immobile_id')->unique()->values()->all())->toBe([(int) $s['unita']->id]);
    // Il pannello: 9074 → € 90,74, Ugo, e il Wallet (né Ugo né Elsa hanno un saldo sulla gestione: le righe delle coppie non contano).
    fds50DiceLaFermata($anteprima, '€ 90,74', 'Ugo', 'Elsa');
});

it('vendita datata 1/5 e registrata dopo la fine del 1/7: la fermata legge la coppia di Ugo, non le date, ed è tutta la coppia, € 181,48', function () {
    $s = fds50DopoScrivi($this);
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'));

    // Dal 1/5 il proprietario è Elsa: tutti i 184 giorni dopo l'uscita sono suoi, 18148 × 184/184 = 18148.
    // Netti: Elsa 84000 × 245/365 = 56383,56, Ugo 84000 × 120/365 = 27616,44 → Elsa 56384, Ugo 27616 (il centesimo al resto ,56);
    // Ugo 27616 + 18148 = 45764; Ines 17852. 56384 + 45764 + 17852 = 120000.
    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(fds50Netti($s))->toBe(['Elsa' => 56384, 'Ines' => 17852, 'Ugo' => 45764])
        ->and(fds50CifrePerPersona($vendita))->toBe([(int) $s['persone']['Ugo']->id => 18148]);
    fds50DiceLaFermata($anteprima, '€ 181,48', 'Ugo', 'Elsa');
});

it('costituzione d\'usufrutto a Elsa il 1/10 dopo «Scrivi»: l\'usufruttuaria sta al posto dell\'inquilino, € 90,74 si fermano come nella vendita', function () {
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $costituzione] = fds50Registra($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'));

    // L'ordinaria dal 1/10 all'usufruttuaria (la legge, decisione 31.5): Elsa 84000 × 92/365 = 21172,60 → 21173, Ugo 62827. La
    // coppia della fine resta a Ugo: 62827 + 18148 = 80975. La fermata: 18148 × 92/184 = 9074.
    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(fds50Netti($s))->toBe(['Elsa' => 21173, 'Ines' => 17852, 'Ugo' => 80975])
        ->and(fds50CifrePerPersona($costituzione))->toBe([(int) $s['persone']['Ugo']->id => 9074]);
    fds50DiceLaFermata($anteprima, '€ 90,74', 'Ugo', 'Elsa');
});

it('estinzione dell\'usufrutto di Ugo il 1/10 dopo «Scrivi»: Bice torna piena e sta al posto dell\'inquilino, € 90,74 si fermano', function () {
    $s = fds50DopoScrivi($this, [['Ugo', 'usufruttuario', 100], ['Bice', 'nuda_proprietario', 100]]);
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $estinzione] = fds50Registra($this, $s, fds50ModuloEstinzione($s, 'Ugo', '2026-10-01'));

    // Sull'unità in usufrutto il «Proprietario» è della nuda, tutto l'anno: Bice 84000. La parte dell'inquilino dopo l'uscita è
    // dell'usufruttuario (art. 1004 c.c.): la coppia Ugo +18148. Ugo non ha quote nel piano: l'estinzione non ha conguaglio, e
    // i netti non cambiano. Dal 1/10 al posto dell'inquilino c'è Bice: 18148 × 92/184 = 9074.
    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(fds50Netti($s))->toBe(['Bice' => 84000, 'Ines' => 17852, 'Ugo' => 18148])
        ->and(fds50CifrePerPersona($estinzione))->toBe([(int) $s['persone']['Ugo']->id => 9074]);
    fds50DiceLaFermata($anteprima, '€ 90,74', 'Ugo', 'Bice');
});

it('successione di Ugo il 1/10 con l\'arretrato al defunto e «Scrivi»: Anna e Bruno stanno al posto dell\'inquilino, la fermata è € 90,74', function () {
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $successione] = fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-10-01'));

    // La coppia ridivisa: Ugo 92 giorni, Anna 50 % × 92 = 46, Bruno 46 → 18148 × 92/184 = 9074, 18148 × 46/184 = 4537 + 4537.
    // La fermata è la parte degli eredi, 4537 + 4537 = 9074, esatta. (Il verbale scrive 9073: è lo scarto rispetto al piano
    // generato dopo, Ugo 80975 − 71902; con la tolleranza dichiarata di un centesimo per unità, 9074 e 9073 dicono la stessa cosa.
    // Il piano generato dopo, un giro solo su 120000: Ugo 120000 × (0,7 × 273 + 0,3 × 92)/365 = 71901,37, Anna e Bruno
    // 120000 × 46/365 = 15123,29, Ines 17852,05; il centesimo che avanza al resto ,37: Ugo 71902.)
    // Netti: Ugo 62827 + 18148 = 80975; gli eredi insieme 21173 (84000 × 92/365 = 21172,60), divisi fra loro dalla successione.
    $netti = fds50Netti($s);
    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and($netti['Ugo'] ?? null)->toBe(80975)
        ->and($netti['Ines'] ?? null)->toBe(17852)
        ->and(($netti['Anna'] ?? 0) + ($netti['Bruno'] ?? 0))->toBe(21173)
        ->and(fds50CifrePerPersona($successione))->toBe([(int) $s['persone']['Ugo']->id => 9074]);
    fds50DiceLaFermata($anteprima, '€ 90,74', 'Ugo', 'Anna');
    // Gli eredi sono due: la frase del Wallet nomina anche Bruno (revisione dei test della Fase 1).
    expect(collect(fds50Con($anteprima, '€ 90,74'))->contains(fn (string $t) => str_contains($t, 'Wallet') && str_contains($t, 'Bruno')))->toBeTrue();
});

it('lo storico della vendita dopo «Scrivi» dice la fermata: € 90,74 restati a carico di Ugo', function () {
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];

    // 18148 × 92/184 = 9074 → € 90,74. Lo storico la legge dal registro: anni dopo, «perché Ugo ha ancora quella parte?» ha una
    // risposta. Si guarda la voce del passaggio senza `annullabile`: gli avvisi dell'annullamento si leggono solo annullando, e la
    // frase deve stare anche nello storico.
    $voce = fds50Storico($s, $vendita);
    unset($voce['annullabile']);
    $conLaCifra = fds50Con($voce, '€ 90,74');
    expect($conLaCifra)->not->toBeEmpty('Lo storico della vendita non dice la fermata.')
        ->and(collect($conLaCifra)->contains(fn (string $t) => str_contains($t, 'Ugo')))->toBeTrue();
});

it('la fermata è un motivo del cancello, con la spunta: il motivo dice la persona e la cifra (€ 90,74, Ugo), e senza «Ho letto» e la nota il passaggio del 1/10 dopo «Scrivi» non si registra', function (string $caso) {
    // Scelte di progetto della Fase 1 (verbale, «Le scelte di progetto sulle questioni aperte dai test»): la fermata è un motivo del
    // cancello, con la spunta, come ogni situazione che cambia l'esito di un passaggio (i `$motivi` di `AnteprimaPassaggio`). Prende la
    // mutazione M15 della revisione dei test della Fase 1 (la fermata senza la spunta), che sopravviveva. Due casi: nella vendita il
    // cancello ha già un altro motivo (le quote emesse a Ugo), quindi conta il motivo della fermata; nell'estinzione Ugo, usufruttuario,
    // non ha quote nel piano, e la fermata è il solo motivo: conta che chieda la spunta. Il testo del motivo è libero: la cifra e la
    // persona.
    $estinzione = $caso === 'estinzione';
    $s = fds50DopoScrivi($this, $estinzione ? [['Ugo', 'usufruttuario', 100], ['Bice', 'nuda_proprietario', 100]] : [['Ugo', 'proprietario', 100]]);
    fds50Emetti($this, $s, '2026-09-30');
    $modulo = $estinzione ? fds50ModuloEstinzione($s, 'Ugo', '2026-10-01') : fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01');
    $anteprima = fds50Anteprima($this, $s, $modulo);

    // 18148 × 92/184 = 9074 → € 90,74, a carico di Ugo (vedi i primi test).
    $motivi = collect($anteprima['cancello']['motivi'] ?? []);
    expect($anteprima['cancello']['richiesto'] ?? null)->toBeTrue('La fermata non chiede la spunta: ' . $motivi->implode(' | '))
        ->and($motivi->contains(fn (string $m) => str_contains($m, '€ 90,74') && str_contains($m, 'Ugo')))
        ->toBeTrue('Nessun motivo del cancello dice la fermata (€ 90,74, Ugo): ' . $motivi->implode(' | '));

    // Il cancello sul server: senza la presa d'atto la registrazione si rifiuta, e non si scrive niente.
    if (($anteprima['ordinaria']['impronta'] ?? null) !== null) {
        $modulo += ['ordinaria_impronta' => $anteprima['ordinaria']['impronta']];
    }
    $prima = Subentro::where('immobile_id', $s['unita']->id)->count();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $modulo)->assertSessionHasErrors('nota_cancello');
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe($prima)
        ->and(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148]);
})->with([
    'vendita a Elsa (il cancello ha anche le quote emesse a Ugo)' => ['vendita'],
    'estinzione dell\'usufrutto di Ugo (la fermata è il solo motivo)' => ['estinzione'],
]);

// --- La strada a mano: la frase della decisione 80 ----------------------------------------------------------------------------

it('decisione 80, la strada seguita: con il saldo manuale dal Wallet che la frase indica (Ugo −9074, Elsa +9074) i netti arrivano all\'oracolo del piano generato dopo', function () {
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));
    foreach ([['Ugo', -9074], ['Elsa', 9074]] as [$chi, $importo]) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo,
        ])->assertSessionHasNoErrors();
    }

    // L'oracolo, il piano generato dopo la fine e la vendita (decisione 21), un giro solo su 120000:
    // Ugo 120000 × (0,7 × 273 + 0,3 × 92)/365 = 120000 × 218,7/365 = 71901,37; Elsa 120000 × 92/365 = 30246,58; Ines 120000 × 0,3 × 181/365
    // = 17852,05. 71901 + 30246 + 17852 = 119999: il centesimo al resto ,58, Elsa 30247. Con la strada a mano: Ugo 80975 − 9074 = 71901,
    // Elsa 21173 + 9074 = 30247. Uguali, senza bisogno della tolleranza.
    expect(fds50Netti($s))->toBe(['Elsa' => 30247, 'Ines' => 17852, 'Ugo' => 71901]);
});

it('decisione 80, l\'altro ramo: Ugo ha già un saldo (€ 50,00 di riporto) sulla gestione, il Wallet rifiuterebbe la sua riga, e la frase dice che la parte si regola fra le parti, senza il Wallet', function () {
    $s = fds50Scenario();
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['Ugo']->id, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000, 'origine' => 'manuale', 'is_applicato' => false]);
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    $fine = fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    expect(fds50Coppia($s, $fine))->toBe(['Ines' => -18148, 'Ugo' => 18148]);
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));

    // La fermata c'è, la stessa: 9074.
    expect(fds50CifrePerPersona($vendita))->toBe([(int) $s['persone']['Ugo']->id => 9074]);
    $conLaCifra = fds50Con($anteprima, '€ 90,74');
    expect($conLaCifra)->not->toBeEmpty()
        ->and(collect($conLaCifra)->contains(fn (string $t) => str_contains($t, 'Ugo') && str_contains($t, 'fra le parti')))
        ->toBeTrue('La frase della fermata non dice che la parte si regola fra le parti: ' . implode(' | ', $conLaCifra))
        ->and(collect($conLaCifra)->contains(fn (string $t) => str_contains($t, 'Wallet')))
        ->toBeFalse('La frase indica il Wallet, che per Ugo rifiuta la riga: ' . implode(' | ', $conLaCifra));

    // Controllo, già giusto prima della .50: il Wallet rifiuta davvero la riga di Ugo (una sola riga per persona, gestione, unità ed
    // esercizio, le righe delle coppie escluse). È il motivo della decisione 80.
    $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone']['Ugo']->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => -9074,
    ])->assertSessionHasErrors(['anagrafica_id' => 'Esiste già un saldo per questa anagrafica in questa gestione.']);
});

it('decisione 80, il saldo ce l\'ha chi riceve: Elsa ha già un saldo (€ 50,00) sulla gestione e sull\'unità, il Wallet rifiuterebbe la sua riga, e la frase dice che la parte si regola fra le parti, senza il Wallet', function () {
    // Scelte di progetto della Fase 1 (verbale, decisione 80, «la persona»): il Wallet si indica solo se la strada a mano si può fare
    // davvero, cioè se nessuna delle due persone della riga (chi resta a carico, Ugo, e chi riceve, Elsa) ha già un saldo su quella
    // gestione, unità ed esercizio; altrimenti «fra le parti». Con il Wallet passerebbe solo la riga a credito di Ugo, e i € 90,74 che
    // Elsa deve sparirebbero. Il saldo di Elsa si scrive dopo l'emissione, prima della vendita: non entra nel piano.
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['Elsa']->id, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000, 'origine' => 'manuale', 'is_applicato' => false]);
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));

    // La fermata c'è, la stessa: 18148 × 92/184 = 9074, a carico di Ugo.
    expect(fds50CifrePerPersona($vendita))->toBe([(int) $s['persone']['Ugo']->id => 9074]);
    $conLaCifra = fds50Con($anteprima, '€ 90,74');
    expect($conLaCifra)->not->toBeEmpty()
        ->and(collect($conLaCifra)->contains(fn (string $t) => str_contains($t, 'Ugo') && str_contains($t, 'fra le parti')))
        ->toBeTrue('La frase della fermata non dice che la parte si regola fra le parti: ' . implode(' | ', $conLaCifra))
        ->and(collect($conLaCifra)->contains(fn (string $t) => str_contains($t, 'Wallet')))
        ->toBeFalse('La frase indica il Wallet, che per Elsa rifiuta la riga: ' . implode(' | ', $conLaCifra));

    // Controllo, già giusto prima della .50: il Wallet rifiuta davvero la riga di Elsa (una sola riga per persona, gestione, unità ed
    // esercizio, le righe delle coppie escluse).
    $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone']['Elsa']->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 9074,
    ])->assertSessionHasErrors(['anagrafica_id' => 'Esiste già un saldo per questa anagrafica in questa gestione.']);
});

// --- La coppia già assorbita da un piano ---------------------------------------------------------------------------------------

it('la coppia di Ugo già assorbita da un secondo piano della gestione (is_applicato e piano_rate_id): la vendita si ferma lo stesso, € 90,74', function () {
    $s = fds50DopoScrivi($this);
    // Un secondo piano della stessa gestione, «Conguaglio 2026», con una voce sola tutta al proprietario e i saldi applicati: la
    // generazione assorbe le righe della coppia della fine.
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    $extra = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $s['conto']->piano_conto_id, 'nome' => 'Extra', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 1200]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $extra->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $secondo = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Conguaglio 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-09-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);
    $secondo->capitoli()->attach($extra->id, ['importo' => 1200, 'note' => 'Voce del secondo piano']);
    fds50Genera($s, $secondo);

    // Il presupposto, letto come dice il verbale: la riga di Ugo è assorbita (`is_applicato`) dal secondo piano (`piano_rate_id`).
    $rigaUgo = Saldo::where('subentro_id', $s['fine']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->firstOrFail();
    expect((bool) $rigaUgo->is_applicato)->toBeTrue()
        ->and((int) $rigaUgo->piano_rate_id)->toBe((int) $secondo->id);

    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));

    // Assorbita o no, la parte dei giorni dal 1/10 è la stessa: 18148 × 92/184 = 9074. La riga assorbita non si tocca.
    expect(fds50CifrePerPersona($vendita))->toBe([(int) $s['persone']['Ugo']->id => 9074])
        ->and((int) $rigaUgo->fresh()->saldo_iniziale)->toBe(18148)
        ->and((bool) $rigaUgo->fresh()->is_applicato)->toBeTrue();
    fds50DiceLaFermata($anteprima, '€ 90,74', 'Ugo', 'Elsa');
});

// --- Aggiunti nella revisione dei test della Fase 1: le mutazioni che i test di sopra lasciavano vivere
// ----------------

it('la cifra con i centesimi segue la regola della coppia: vendita il 15/10 (€ 76,93) e il 1/12 (€ 30,58), e la strada del Wallet arriva all\'oracolo', function (string $il, string $emessoFino, int $cifra, string $cifraEuro, array $oggi, array $oracolo) {
    // Nei casi di sopra la fermata è sempre un numero intero (9074, 18148): arrotondare per difetto o per eccesso dava le stesse
    // cifre, e le due mutazioni (round → floor, round → ceil) sopravvivevano. Qui la coppia ridivisa ha i centesimi.
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, $emessoFino);
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', $il));

    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(fds50Netti($s))->toBe($oggi)
        ->and(fds50CifrePerPersona($vendita))->toBe([(int) $s['persone']['Ugo']->id => $cifra]);
    fds50DiceLaFermata($anteprima, $cifraEuro, 'Ugo', 'Elsa');

    // La strada a mano seguita: i netti arrivano al piano generato dopo i due passaggi, esatti (in queste due date la regola della
    // coppia e quella della generazione danno lo stesso centesimo: la tolleranza non serve).
    foreach ([['Ugo', -$cifra], ['Elsa', $cifra]] as [$chi, $importo]) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo,
        ])->assertSessionHasNoErrors();
    }
    expect(fds50Netti($s))->toBe($oracolo);
})->with([
    // Vendita il 15/10: 1/1–14/10 = 273 + 14 = 287 giorni a Ugo, 15/10–31/12 = 17 + 30 + 31 = 78 a Elsa; dopo l'uscita di Ines Ugo
    // ha 1/7–14/10 = 184 − 78 = 106 giorni. Fermata, regola della coppia sui 18148: Elsa 18148 × 78/184 = 7693,17, Ugo 18148 ×
    // 106/184 = 10454,83; 7693 + 10454 = 18147, il centesimo al resto più grande (,83) va a Ugo: Elsa 7693 (per eccesso sarebbe
    // 7694). Senza la fermata, la vendita sugli 84000 del «Proprietario»: Elsa 84000 × 78/365 = 17950,68, Ugo 84000 × 287/365 =
    // 66049,32 → Elsa 17951, Ugo 66049; Ugo 66049 + 18148 = 84197; 84197 + 17951 + 17852 = 120000. Oracolo, un giro solo su 120000:
    // Ugo 0,7 × 287 + 0,3 × 106 = 232,7 → 120000 × 232,7/365 = 76504,11; Elsa 78 → 25643,84; Ines 0,3 × 181 = 54,3 → 17852,05;
    // 76504 + 25643 + 17852 = 119999, il centesimo a ,84: Elsa 25644. Con la strada a mano: Ugo 84197 − 7693 = 76504, Elsa 17951 +
    // 7693 = 25644.
    'vendita il 15/10: € 76,93' => ['2026-10-15', '2026-10-14', 7693, '€ 76,93',
        ['Elsa' => 17951, 'Ines' => 17852, 'Ugo' => 84197], ['Elsa' => 25644, 'Ines' => 17852, 'Ugo' => 76504]],
    // Vendita il 1/12: 1/1–30/11 = 334 giorni a Ugo, 1/12–31/12 = 31 a Elsa; dopo l'uscita Ugo ha 184 − 31 = 153 giorni. Fermata:
    // Elsa 18148 × 31/184 = 3057,54, Ugo 18148 × 153/184 = 15090,46; 3057 + 15090 = 18147, il centesimo a ,54: Elsa 3058 (per
    // difetto sarebbe 3057). Senza la fermata: Elsa 84000 × 31/365 = 7134,25, Ugo 84000 × 334/365 = 76865,75 → Ugo 76866, Elsa
    // 7134; Ugo 76866 + 18148 = 95014; 95014 + 7134 + 17852 = 120000. Oracolo: Ugo 0,7 × 334 + 0,3 × 153 = 279,7 → 91956,16; Elsa
    // 31 → 10191,78; Ines 17852,05; 91956 + 10191 + 17852 = 119999, il centesimo a ,78: Elsa 10192. Con la strada a mano: Ugo
    // 95014 − 3058 = 91956, Elsa 7134 + 3058 = 10192.
    'vendita il 1/12: € 30,58' => ['2026-12-01', '2026-11-30', 3058, '€ 30,58',
        ['Elsa' => 7134, 'Ines' => 17852, 'Ugo' => 95014], ['Elsa' => 10192, 'Ines' => 17852, 'Ugo' => 91956]],
]);

it('comproprietà, Ugo e Zoe al 50 %: Ugo vende la sua metà il 1/10, la fermata è la sua riga ridivisa, € 45,37; la riga di Zoe non c\'entra', function () {
    // La mutazione che sopravviveva: la fermata letta su tutte le righe a debito della coppia dell'unità invece che su quelle di chi
    // esce. Con un proprietario solo è la stessa cosa; con due no.
    $s = fds50Scenario([['Ugo', 'proprietario', 50], ['Zoe', 'proprietario', 50]]);
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    $fine = fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    // La coppia della fine, per persona e per giorno dopo l'uscita: Ugo 0,5 × 184 = 92, Zoe 92; 18148 × 92/184 = 9074,00 ciascuno.
    expect(fds50Coppia($s, $fine))->toBe(['Ines' => -18148, 'Ugo' => 9074, 'Zoe' => 9074]);
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));

    // La fermata: la riga di Ugo, 9074, per i giorni di Elsa: 9074 × 92/184 = 4537,00, esatta. Zoe resta proprietaria: niente.
    // Netti dopo la registrazione. Il «Proprietario» 84000 al 50 %: 42000 a testa. La vendita sulla metà di Ugo: Ugo 42000 ×
    // 273/365 = 31413,70, Elsa 42000 × 92/365 = 10586,30 → Ugo 31414, Elsa 10586. Ugo 42000 − 10586 + 9074 = 40488; Zoe 42000 +
    // 9074 = 51074; Ines 17852. 40488 + 51074 + 10586 + 17852 = 120000.
    expect(fds50CifrePerPersona($vendita))->toBe([(int) $s['persone']['Ugo']->id => 4537])
        ->and(fds50Coppia($s, $fine))->toBe(['Ines' => -18148, 'Ugo' => 9074, 'Zoe' => 9074])
        ->and(fds50Netti($s))->toBe(['Elsa' => 10586, 'Ines' => 17852, 'Ugo' => 40488, 'Zoe' => 51074]);
    fds50DiceLaFermata($anteprima, '€ 45,37', 'Ugo', 'Elsa');
    expect(collect(fds50Con($anteprima, '€ 45,37'))->contains(fn (string $t) => str_contains($t, 'Zoe')))->toBeFalse();

    // La strada a mano, fino all'oracolo del piano generato dopo, un giro solo su 120000. Pesi (giorni × quota × ruolo):
    // Ugo 0,5 × (0,7 × 273 + 0,3 × 92) = 109,35 → 35950,68; Zoe 0,5 × (0,7 × 365 + 0,3 × 184) = 155,35 → 51073,97;
    // Elsa 0,5 × (0,7 × 92 + 0,3 × 92) = 46 → 15123,29; Ines 0,3 × 181 = 54,3 → 17852,05. 35950 + 51073 + 15123 + 17852 =
    // 119998, i due centesimi ai resti ,97 e ,68: Zoe 51074, Ugo 35951. Con la strada a mano: Ugo 40488 − 4537 = 35951, Elsa
    // 10586 + 4537 = 15123. Uguali, senza tolleranza.
    foreach ([['Ugo', -4537], ['Elsa', 4537]] as [$chi, $importo]) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo,
        ])->assertSessionHasNoErrors();
    }
    expect(fds50Netti($s))->toBe(['Elsa' => 15123, 'Ines' => 17852, 'Ugo' => 35951, 'Zoe' => 51074]);
});

it('la coppia di una successione con «Scrivi» non è una fine locazione: l\'erede che vende la sua metà non ha nessuna fermata', function () {
    // La mutazione che sopravviveva: la condizione senza il controllo che la coppia venga da una fine locazione. Il registro della
    // successione ha anche lui `conguaglio.scelta` = `scritto` (decisione 72), e senza quel controllo la riga a debito di un erede
    // diventava una «fermata dopo Scrivi» con una cifra da spostare a mano che nessuno deve.
    $s = fds50Scenario();
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    // Ines esce il 1/7 con «Non scriverlo»: nessuna coppia della fine, quindi nessuna fermata possibile per la regola.
    $fine = fds50Fine($this, $s, Subentro::NON_SCRIVERE_IL_CONGUAGLIO);
    expect(fds50Coppia($s, $fine))->toBe([]);
    fds50Emetti($this, $s, '2026-07-31');
    // Ugo muore il 1/8: Anna e Bruno al 50 %, l'arretrato a suo nome e «Scrivi il conguaglio» (decisione 72).
    $successione = fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-08-01'))['subentro'];
    // Il presupposto, senza cifre (le cifre della successione non sono l'oggetto di questo test): Bruno ha una riga a debito scritta
    // dalla successione, cioè proprio il tipo di riga che la condizione della fermata legge.
    expect(DB::table('saldi')->where('anagrafica_id', $s['persone']['Bruno']->id)->where('saldo_iniziale', '>', 0)
        ->whereIn('subentro_id', Subentro::conAnnullati()->where('id', $successione->id)->orWhere('subentro_padre_id', $successione->id)->pluck('id'))->exists())
        ->toBeTrue('Lo scenario non mette a debito di Bruno una riga della successione: il test non proverebbe niente.');
    fds50Emetti($this, $s, '2026-09-30');
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Bruno', 'Elsa', '2026-10-01', 50))['subentro'];

    expect(fds50Fermate($vendita))->toBe([]);
});

// --- Non si ferma -------------------------------------------------------------------------------------------------------------

it('riserva d\'usufrutto il 1/10 dopo «Scrivi»: Ugo resta usufruttuario e al posto dell\'inquilino, nessuna fermata e nessuno scarto', function () {
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $riserva] = fds50Registra($this, $s, fds50ModuloRiserva($s, 'Ugo', 'Elsa', '2026-10-01'));

    // Le ordinarie restano a chi vende con la riserva (decisione 27.6): Ugo 84000 + 18148 = 102148; Ines 17852; Elsa, nuda, niente.
    expect(fds50Fermate($riserva))->toBe([])
        ->and(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(fds50Netti($s))->toBe(['Ines' => 17852, 'Ugo' => 102148])
        ->and(fds50Con($anteprima, '€ 90,74'))->toBe([]);
});

it('successione il 1/10 con l\'arretrato agli eredi dopo «Scrivi»: l\'arretrato porta già la coppia, nessuna fermata', function () {
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    $successione = fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI, null, '2026-10-01'))['subentro'];

    // Con l'arretrato agli eredi tutta la posizione di Ugo passa ad Anna e Bruno per quota: Ugo 62827 + 18148 = 80975, più i giorni
    // degli eredi 21173 → 102148, al 50 %: 51074 ciascuno. Ines 17852. 51074 + 51074 + 17852 = 120000.
    expect(fds50Fermate($successione))->toBe([])
        ->and(fds50Netti($s))->toBe(['Anna' => 51074, 'Bruno' => 51074, 'Ines' => 17852]);
});

it('successione il 1/10 con l\'arretrato al defunto e «Non scriverlo»: la posizione di Ugo resta com\'è, nessuna fermata (nota datata sulla decisione 79)', function () {
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    $successione = fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO, '2026-10-01'))['subentro'];

    // Nessun conguaglio della successione: le tre bozze di ottobre–dicembre (3 × 7000 = 21000) passano all'erede di riferimento,
    // Anna (decisione 65 punto 3); Ugo tiene le nove rate emesse (9 × 7000 = 63000) e la coppia della fine: 63000 + 18148 = 81148.
    // Ines 17852. 21000 + 81148 + 17852 = 120000.
    expect(fds50Fermate($successione))->toBe([])
        ->and(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(fds50Netti($s))->toBe(['Anna' => 21000, 'Ines' => 17852, 'Ugo' => 81148]);
});

it('«Non scriverlo» alla fine e poi la vendita il 1/10: nessuna coppia, nessuna fermata', function () {
    $s = fds50Scenario();
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    $fine = fds50Fine($this, $s, Subentro::NON_SCRIVERE_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));

    // Le rate restano a Ines (36000, strada A′); Ugo 62827, Elsa 21173 (la vendita). 36000 + 62827 + 21173 = 120000.
    expect(fds50Coppia($s, $fine))->toBe([])
        ->and(fds50Fermate($vendita))->toBe([])
        ->and(fds50Netti($s))->toBe(['Elsa' => 21173, 'Ines' => 36000, 'Ugo' => 62827])
        ->and(fds50Con($anteprima, '€ 90,74'))->toBe([]);
});

it('il conguaglio della fine annullato («le parti hanno regolato») e poi la vendita il 1/10: nessuna fermata', function () {
    $s = fds50DopoScrivi($this);
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $s['fine']]), [
        'nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro con il deposito cauzionale',
    ])->assertSessionHasNoErrors();
    expect(fds50Coppia($s, $s['fine']))->toBe([]);
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));

    // Senza le righe della coppia: Ines 36000, Ugo 62827, Elsa 21173.
    expect(fds50Fermate($vendita))->toBe([])
        ->and(fds50Netti($s))->toBe(['Elsa' => 21173, 'Ines' => 36000, 'Ugo' => 62827])
        ->and(fds50Con($anteprima, '€ 90,74'))->toBe([]);
});

// --- I giorni coperti da un conduttore registrato (S1 della revisione) -----------------------------------------------------

it('S1 — Luca inquilino dal 1/9 o dal 1/11, poi la vendita il 1/10: la fermata conta solo i giorni dopo la vendita senza un conduttore', function (string $lucaDal, ?int $cifra, string $cifraEuro) {
    $s = fds50DopoScrivi($this);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', $lucaDal));
    fds50Emetti($this, $s, '2026-09-30');
    ['anteprima' => $anteprima, 'subentro' => $vendita] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));

    // Il denaro non si muove: Luca entra su un piano fermo e non ha quote (rimborsa con il prospetto). Ugo 80975, Elsa 21173, Ines 17852.
    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(fds50Netti($s))->toBe(['Elsa' => 21173, 'Ines' => 17852, 'Ugo' => 80975])
        ->and(fds50CifrePerPersona($vendita))->toBe($cifra === null ? [] : [(int) $s['persone']['Ugo']->id => $cifra]);
    if ($cifra === null) {
        // Nessuna cifra da fermare: chi seguisse una strada a mano caricherebbe a Elsa € 90,74 che non deve.
        expect(fds50Con($anteprima, $cifraEuro))->toBe([]);
    } else {
        fds50DiceLaFermata($anteprima, $cifraEuro, 'Ugo', 'Elsa');
    }
})->with([
    // Luca dal 1/9: dal 1/10 al 31/12 c'è sempre un conduttore. L'oracolo (piano generato dopo i tre passaggi, un giro solo):
    // Elsa 120000 × 0,7 × 92/365 = 21172,60, Ugo 120000 × (0,7 × 273 + 0,3 × 62)/365 = 68942,47, Luca 120000 × 0,3 × 122/365 =
    // 12032,88, Ines 17852,05; 21172 + 68942 + 12032 + 17852 = 119998, i due centesimi ai resti ,88 e ,60: Elsa 21173, come dà la
    // vendita. Lo scarto è zero: nessuna fermata.
    'Luca dal 1/9: nessuna fermata' => ['2026-09-01', null, '€ 90,74'],
    // Luca dal 1/11: senza conduttore solo ottobre, 31 giorni. La coppia divisa in tre tratti, Ugo 92, Elsa 31, Luca 61: 18148 ×
    // 92/184 = 9074,00; × 31/184 = 3057,54; × 61/184 = 6016,46; 9074 + 3057 + 6016 = 18147, il centesimo al resto ,54: Elsa 3058. La
    // fermata è 3058 (Luca rimborsa a Ugo i suoi 6016 con il prospetto). (revisione dei test della Fase 1) Il piano generato dopo i
    // tre passaggi, un giro solo, dà un centesimo diverso: Elsa 0,7 × 92 + 0,3 × 31 = 73,7 → 24230,14; Luca 0,3 × 61 = 18,3 → 6016,44;
    // Ugo 218,7 → 71901,37; Ines 17852,05; 119999, il centesimo a ,44: Luca 6017, Elsa 24230 − 21173 = 3057. È la tolleranza
    // dichiarata di un centesimo per unità: il test resta esatto sulla regola della coppia.
    'Luca dal 1/11: € 30,58, solo ottobre' => ['2026-11-01', 3058, '€ 30,58'],
]);

// --- L'annullamento dall'ultimo (decisione 27) -----------------------------------------------------------------------------

it('annullare dall\'ultimo funziona dopo una fermata: la fine non si annulla prima della vendita; annullata la vendita torna la coppia di Ugo, annullata la fine tutto torna come prima', function () {
    $s = fds50DopoScrivi($this);
    fds50Emetti($this, $s, '2026-09-30');
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    expect(fds50CifrePerPersona($vendita))->toBe([(int) $s['persone']['Ugo']->id => 9074]);

    // La fine non è l'ultimo passaggio dell'unità: si rifiuta, e il messaggio nomina la vendita.
    $rifiuto = $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $s['fine']]), ['nota_annullamento' => 'Provo ad annullare la fine per prima'])
        ->assertUnprocessable()->json('errors.passaggio.0');
    expect($rifiuto)->toContain('Si annulla prima quello');

    // La vendita: tornano le bozze di Ugo, la coppia della fine resta. Ines 17852, Ugo 84000 + 18148 = 102148.
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $vendita]), ['nota_annullamento' => 'Rogito non stipulato, annullo la vendita'])
        ->assertSessionHasNoErrors();
    expect(fds50Netti($s))->toBe(['Ines' => 17852, 'Ugo' => 102148])
        ->and(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148]);

    // Poi la fine: la coppia sparisce, Ines 36000 e Ugo 84000.
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $s['fine']]), ['nota_annullamento' => 'Disdetta ritirata, annullo la fine'])
        ->assertSessionHasNoErrors();
    expect(fds50Netti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000])
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->count())->toBe(0);
});

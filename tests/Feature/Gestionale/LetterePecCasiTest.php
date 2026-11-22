<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13: le lettere per PEC, i casi che contano.
 *
 * - «PEC e carta»: la seconda azione prende la lettera della prima solo se è la stessa lettera (il testo, il termine, il conto, la gestione, le rate, la
 *   persona); con scelte nuove parte una lettera nuova, con le scelte nuove.
 * - Una PEC già partita oggi non parte due volte: dopo un collegamento caduto, rifacendo l'anteprima o premendo di nuovo; chi non è stato provato torna in
 *   «Manda le altre»; un errore che non è del trasporto (un indirizzo scritto male) fa una riga «non partita» con la frase, non un errore della pagina.
 * - L'avviso di mancata consegna si legge e si registra: la lettera non è consegnata, il termine non corre, la riga offre di mandarla di nuovo o di stamparla.
 * - La ricevuta vale dall'ora della PEC, non dal giorno; la consegna scritta a mano lo stesso giorno non prima dell'ora della PEC; la ricevuta che conferma la
 *   consegna scritta a mano non chiede il perché.
 * - Le prove della lettera non mostrano il PDF della PEC; la prova della consegna scritta a mano si dice «prova della consegna».
 * - Le PEC non partite si rimandano in blocco o si stampano per la posta, e le rate scadute le contano.
 * - Una PEC rimasta «in partenza» da più di un quarto d'ora si rimanda dalla riga.
 * - La PEC cambiata nella scheda toglie il giorno in cui la persona aveva comunicato quella di prima, e il messaggio lo dice.
 *
 * Nessuna PEC vera: la casella e il trasporto sono finti ({@see LetterePec}).
 */

use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Services\Posta\ConsegnaDellaPec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/LetterePecScenario.php';

beforeEach(function () {
    Storage::fake('local');
    $this->user = LetterePec::utente();
    LetterePec::conLaCasella();
});

function lpcDiffida(array $s, array $altro = []): array
{
    return lttScelte($s, array_merge(['tipo' => 'diffida', 'testo' => 'Diffida a [nome].', 'termine_forma' => 'giorni'], $altro));
}

function lpcRegistro(array $s, string $chi = 'ugo'): array
{
    return app(RegistroLettere::class)->perPersona($s['c'], $s['persone'][$chi]);
}

/** Un avviso di mancata consegna inventato, sul modello delle regole tecniche: il tipo `errore-consegna` con il suo errore. */
function lpcMancataConsegna(string $a, string $oggetto, string $giorno, string $ora): UploadedFile
{
    $eml = str_replace(base64_encode('CONSEGNA: ' . $oggetto), base64_encode('AVVISO DI MANCATA CONSEGNA: ' . $oggetto),
        LetterePec::ricevuta($a, $oggetto, $giorno, $ora, 'errore-consegna'));
    // L'errore del gestore, al posto di «nessuno»: nel file il daticert.xml è in base64.
    $eml = preg_replace_callback('/(filename="daticert.xml"\r\nContent-Transfer-Encoding: base64\r\n\r\n)([A-Za-z0-9+\/=\r\n]+)(\r\n--confine--)/', function ($m) {
        $xml = str_replace('errore="nessuno"', 'errore="no-dest"', base64_decode(str_replace("\r\n", '', $m[2])));

        return $m[1] . chunk_split(base64_encode($xml), 76, "\r\n") . $m[3];
    }, $eml);

    return UploadedFile::fake()->createWithContent('mancata-consegna.eml', $eml);
}

it('«PEC e carta»: la PEC dopo la stampa con un altro testo e un altro termine è una lettera nuova, con le scelte nuove; e al contrario', function () {
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $a = lpcDiffida($s, ['testo' => 'TESTO-A per [nome].', 'persone' => [$ugo], 'canali' => [$ugo => 'pec_e_carta'], 'viste' => [$ugo]]);
    $b = lpcDiffida($s, ['testo' => 'TESTO-B per [nome].', 'termine' => 30, 'persone' => [$ugo], 'canali' => [$ugo => 'pec_e_carta'], 'viste' => [$ugo]]);

    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), $a)->assertOk();
    $this->travelTo('2026-03-20 16:00:00');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $b)->assertOk()->assertJsonPath('partite', 1);
    $stampa = lttRighe($s, 'ugo')->firstWhere('canale', RegistroInvio::CANALE_STAMPA);
    $pec = LetterePec::righePec($s, 'ugo')->sole();
    expect($pec->dati['testo'])->toBe('TESTO-B per Ugo.')
        ->and($pec->dati['termine'])->toBe(30)
        ->and($pec->dati['lettera'])->not->toBe($stampa->dati['lettera'])
        ->and(hash('sha256', LetterePec::$mandate[0]['messaggio']->getAttachments()[0]->getBody()))->toBe($pec->impronta)
        ->and($pec->impronta)->not->toBe($stampa->impronta);

    // Il giorno dopo, prima la PEC con il testo A e poi la stampa con il testo B: la copia su carta è una lettera nuova.
    $this->travelTo('2026-03-21 10:00:00');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $a)->assertOk()->assertJsonPath('partite', 1);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), $b)->assertOk();
    $oggi = lttRighe($s, 'ugo')->filter(fn ($r) => $r->emesso_il->format('Y-m-d') === '2026-03-21')->values();
    expect($oggi->pluck('canale')->all())->toBe(['pec', 'stampa'])
        ->and($oggi[1]->dati['testo'])->toBe('TESTO-B per Ugo.')
        ->and($oggi[1]->dati['lettera'])->not->toBe($oggi[0]->dati['lettera']);
});

it('il collegamento cade: chi non è stato provato torna in «Manda le altre»; una PEC già partita oggi non parte due volte, e l\'anteprima lo dice', function () {
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $bice = $s['persone']['bice']->id;
    $scelte = lpcDiffida($s, ['canali' => [$ugo => 'pec', $bice => 'pec'], 'viste' => [$ugo, $bice]]);

    // Bice viene prima (l'ordine dei nomi): il collegamento cade, Ugo non è provato.
    LetterePec::$errori = ['Connection could not be established with host "ssl://smtps.pec.esempio.it:465": stream_socket_client(): Unable to connect'];
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte)->assertOk()->json();
    expect($r)->toMatchArray(['partite' => 0, 'non_partite' => 1, 'non_provate' => 1, 'restanti' => ['persone' => [$ugo]]]);

    // «Manda le altre»: parte Ugo. Poi, rifatta l'anteprima, «Manda 2 PEC»: Ugo non riceve la seconda, Bice sì.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), array_merge($scelte, ['viste' => [$ugo]]))->assertOk()->assertJsonPath('partite', 1);
    $anteprima = collect($this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lpcDiffida($s))->json('persone'))->keyBy('nome');
    expect($anteprima['Ugo']['pec_partita_oggi'])->toBe('11:00')
        ->and($anteprima['Bice']['pec_partita_oggi'])->toBeNull();
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte)->assertOk()->json();
    expect($r['partite'])->toBe(1)
        ->and($r['gia_partite'])->toBe(['Ugo'])
        ->and($r['frase'])->toBe('1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta. A Ugo è già partita oggi alle 11:00: non parte due '
            . 'volte nello stesso giorno, la trovi nella sua corrispondenza.')
        ->and(LetterePec::righePec($s, 'ugo')->pluck('stato')->all())->toBe([RegistroInvio::STATO_INVIATA])
        ->and(LetterePec::righePec($s, 'bice')->pluck('stato')->all())->toBe([RegistroInvio::STATO_NON_PARTITA, RegistroInvio::STATO_INVIATA]);
});

it('un errore che non è del trasporto (l\'indirizzo PEC scritto male) fa una riga «non partita» con la frase; le altre partono', function () {
    $s = LetterePec::scenario($this);
    $s['persone']['bice']->forceFill(['pec' => 'bice@@pec.it'])->saveQuietly();
    $scelte = lpcDiffida($s, ['canali' => [$s['persone']['ugo']->id => 'pec', $s['persone']['bice']->id => 'pec']]);

    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte)->assertOk()->json();
    $bice = LetterePec::righePec($s, 'bice')->sole();
    expect($r)->toMatchArray(['partite' => 1, 'non_partite' => 1])
        ->and($bice->stato)->toBe(RegistroInvio::STATO_NON_PARTITA)
        ->and(lpcRegistro($s, 'bice')[0]['righe'][0]['pec']['frase'])->toEndWith('L\'indirizzo PEC della persona non è scritto bene: correggilo nella sua scheda, poi mandala di nuovo.');
});

it('l\'avviso di mancata consegna si registra: la diffida non è consegnata, il termine non corre, la riga offre di mandarla di nuovo o di stamparla', function () {
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpcDiffida($s, ['persone' => [$ugo], 'canali' => [$ugo => 'pec']]))->assertOk();
    $riga = LetterePec::righePec($s, 'ugo')->sole();
    $this->travelTo('2026-03-21 12:00:00');

    $this->actingAs($this->user)->post(lttRotta($s, 'ricevuta', ['invio' => $riga->id]), ['ricevuta' => lpcMancataConsegna('ugo@pec.it', $riga->dati['oggetto'], '21/03/2026', '11:30')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message.message', 'È l\'avviso di mancata consegna: la PEC non è arrivata nella casella di ugo@pec.it. La diffida non è consegnata e il termine '
            . 'non corre: mandala di nuovo per PEC o stampala per la posta.');
    $riga->refresh();
    expect($riga->esito)->toBe(RegistroInvio::ESITO_NON_CONSEGNATA)
        ->and($riga->ricevuta_il->format('Y-m-d'))->toBe('2026-03-21')
        ->and(collect($riga->dati['eventi'])->last())->toMatchArray(['evento' => 'mancata_consegna']);
    $lettera = lpcRegistro($s)[0];
    expect($lettera['righe'][0]['pec'])->toMatchArray(['badge' => 'Non consegnata', 'provata' => false, 'ripetibile' => true, 'stampabile' => true])
        ->and($lettera['righe'][0]['pec']['frase'])->toBe('Non consegnata: l\'avviso di mancata consegna del gestore (Gestore PEC di prova), del 21/03 alle 11:30, dice '
            . 'che la PEC non è arrivata nella casella di ugo@pec.it.')
        ->and($lettera['termine_dalla_consegna'])->toBeNull()
        ->and($lettera['prove'][0])->toMatchArray(['nome' => 'mancata-consegna.eml', 'della_consegna' => true, 'rimovibile' => false]);
    $ultima = app(RegistroLettere::class)->ultimePerPersone($s['c']->id, [$ugo], '2026-03-21')[$ugo];
    expect($ultima['per_tipo']['diffida'])->toMatchArray(['stato' => RegistroInvio::STATO_NON_PARTITA, 'pec' => 'non_consegnata'])
        ->and($ultima['conta'])->toBeNull();

    // Il preavviso (dopo dodici ore) non è la mancata consegna: la frase lo dice.
    expect(\App\Services\Posta\RicevutaPec::daiDati('<postacert tipo="preavviso-errore-consegna" errore="no-dest"><dati><data zona="+0100"><giorno>21/03/2026</giorno>'
        . '<ora>10:00</ora></data></dati></postacert>')->tipoInParole())->toBe('il preavviso di mancata consegna: la PEC non è ancora arrivata, e il gestore la sta '
        . 'ancora provando');
});

it('la ricevuta vale dall\'ora della PEC: quella delle 09:01 non è della PEC delle 11:00 dello stesso giorno; a mano lo stesso, e la conferma della ricevuta non chiede il perché', function () {
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpcDiffida($s, ['persone' => [$ugo], 'canali' => [$ugo => 'pec']]))->assertOk();
    $riga = LetterePec::righePec($s, 'ugo')->sole();
    $this->travelTo('2026-03-20 15:00:00');
    $rotta = lttRotta($s, 'ricevuta', ['invio' => $riga->id]);

    $this->actingAs($this->user)->post($rotta, ['ricevuta' => UploadedFile::fake()->createWithContent('r.eml', LetterePec::ricevuta('ugo@pec.it', $riga->dati['oggetto'], '20/03/2026', '09:01'))])
        ->assertSessionHasErrors(['ricevuta' => 'La ricevuta dice il 20/03/2026 alle 09:01, prima della PEC, partita il 20/03/2026 alle 11:00: controlla il file.']);
    $pdf = fn () => UploadedFile::fake()->createWithContent('ricevuta.pdf', '%PDF-1.4 la ricevuta in PDF');
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => $pdf(), 'giorno' => '2026-03-20', 'ora' => '10:30'])
        ->assertSessionHasErrors(['ora' => 'La consegna non può essere prima della PEC, partita il 20/03/2026 alle 11:00.']);
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => $pdf(), 'giorno' => '2026-03-20', 'ora' => '11:00'])->assertSessionHasNoErrors();

    // La ricevuta del gestore dice 11:01: conferma la consegna scritta a mano, senza il perché, e scrive la sua ora.
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => UploadedFile::fake()->createWithContent('r.eml', LetterePec::ricevuta('ugo@pec.it', $riga->dati['oggetto'], '20/03/2026', '11:01'))])
        ->assertSessionHasNoErrors();
    $riga->refresh();
    expect($riga->dati['consegna'])->toMatchArray(['ora' => '11:01', 'a_mano' => false])
        ->and(collect($riga->dati['eventi'])->last())->toMatchArray(['evento' => 'ricevuta', 'perche' => RegistroLettere::CONFERMA_DELLA_RICEVUTA]);
    $prove = lpcRegistro($s)[0]['prove'];
    expect(collect($prove)->firstWhere('nome', 'ricevuta.pdf'))->toMatchArray(['della_consegna' => true, 'consegna_a_mano' => true]);
});

it('«PEC e carta», prima la stampa: fra le prove non c\'è il PDF della PEC', function () {
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $scelte = lpcDiffida($s, ['persone' => [$ugo], 'canali' => [$ugo => 'pec_e_carta'], 'viste' => [$ugo]]);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), $scelte)->assertOk();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte)->assertOk()->assertJsonPath('partite', 1);

    $lettera = lpcRegistro($s)[0];
    expect($lettera['prove'])->toBe([])
        ->and($lettera['pdf'])->not->toBeNull();
});

it('le PEC non partite: le rate scadute le contano; «Rimanda le PEC non partite» le rifà in blocco; «Stampale per la posta» le stampa con le cifre di oggi', function () {
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $bice = $s['persone']['bice']->id;
    LetterePec::$errori = ['550 5.1.1 Mailbox unavailable', '550 5.1.1 Mailbox unavailable'];
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpcDiffida($s, ['canali' => [$ugo => 'pec', $bice => 'pec']]))->assertOk()->assertJsonPath('non_partite', 2);

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
    expect($props['pec_da_rimandare'])->toBe(2)->and(app(EmissioneLettere::class)->pecDaRimandare($s['c']))->toHaveCount(2);

    // Bice si stampa per la posta, dalla sua riga: la lettera nuova ha le cifre di oggi, e la PEC non partita non si conta più.
    $suaPec = LetterePec::righePec($s, 'bice')->sole();
    $this->actingAs($this->user)->post(lttRotta($s, 'pec-non-partite.stampa'), ['invii' => [$suaPec->id]])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(lttRighe($s, 'bice')->last())->toMatchArray(['canale' => RegistroInvio::CANALE_STAMPA, 'stato' => RegistroInvio::STATO_STAMPATA])
        ->and(lttRighe($s, 'bice')->last()->dati['rifatta_da'])->toBe($suaPec->id)
        ->and(app(EmissioneLettere::class)->pecDaRimandare($s['c']))->toHaveCount(1);

    // Ugo si rimanda in blocco.
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'pec-non-partite'))->assertOk()->json();
    expect($r)->toMatchArray(['partite' => 1, 'non_partite' => 0])
        ->and(LetterePec::righePec($s, 'ugo')->last()->stato)->toBe(RegistroInvio::STATO_INVIATA)
        ->and(app(EmissioneLettere::class)->pecDaRimandare($s['c']))->toHaveCount(0);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec-non-partite'))->assertStatus(422)->assertJsonPath('message', EmissioneLettere::NESSUNA_PEC_DA_RIMANDARE);
});

it('una PEC rimasta «in partenza» da più di un quarto d\'ora si manda di nuovo dalla riga; una appena partita no', function () {
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpcDiffida($s, ['persone' => [$ugo], 'canali' => [$ugo => 'pec']]))->assertOk();
    $riga = LetterePec::righePec($s, 'ugo')->sole();
    $riga->forceFill(['stato' => RegistroInvio::STATO_IN_CODA])->save();
    expect(lpcRegistro($s)[0]['righe'][0]['pec']['ripetibile'])->toBeFalse();

    $this->travelTo('2026-03-20 10:20:00');
    $pec = lpcRegistro($s)[0]['righe'][0]['pec'];
    expect($pec['ripetibile'])->toBeTrue()
        ->and($pec['frase'])->toContain('si è fermata mentre la mandava');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec-di-nuovo', ['invio' => $riga->id]))->assertOk()->assertJsonPath('partite', 1);
});

it('chi ha solo la PEC e non apre le impostazioni: la frase gli dice di chiedere la casella', function () {
    LetterePec::senzaCasella();
    $s = LetterePec::scenario($this);
    $s['persone']['bice']->update(['email' => null]);
    $senza = \App\Models\User::factory()->create();
    $senza->assignRole('admin');

    $bice = collect($this->actingAs($senza)->postJson(lttRotta($s, 'anteprima'), lttScelte($s))->json('persone'))->firstWhere('nome', 'Bice');
    expect($bice['frase_solo_pec'])->toBe(EmissioneLettere::SOLO_PEC_CHIEDI_LA_CASELLA)
        ->and(EmissioneLettere::SOLO_PEC_CHIEDI_LA_CASELLA)->toBe('Ha solo la PEC: chiedi a chi gestisce le impostazioni di impostare la casella PEC dello studio, o stampala.');
});

it('la PEC cambiata nella scheda toglie il giorno in cui la persona aveva comunicato quella di prima, e il messaggio lo dice', function () {
    $this->user->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::EDIT_ANAGRAFICHE->value, 'guard_name' => 'web']));
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo'];
    $campi = ['nome' => $ugo->nome, 'indirizzo' => 'Via Roma 1, Milano', 'condomini' => [$s['c']->id], 'pec_comunicata_il' => '2026-03-12'];

    $this->actingAs($this->user)->put(route('admin.anagrafiche.update', $ugo), $campi + ['pec' => 'ugo.nuova@pec.it'])->assertSessionHasNoErrors()
        ->assertSessionHas('message.message', fn ($m) => str_contains($m, 'La PEC è cambiata: il giorno «PEC comunicata dalla persona il» era della PEC di prima, ed è stato tolto.'));
    expect($ugo->refresh()->pec_comunicata_il)->toBeNull();

    // Con la PEC nuova e il giorno nuovo nello stesso salvataggio, il giorno resta.
    $this->actingAs($this->user)->put(route('admin.anagrafiche.update', $ugo), array_merge($campi, ['pec' => 'ugo.terza@pec.it', 'pec_comunicata_il' => '2026-03-19']))
        ->assertSessionHasNoErrors();
    expect(substr((string) $ugo->refresh()->pec_comunicata_il, 0, 10))->toBe('2026-03-19');
});

it('«Stampa N lettere» dice le righe scritte, per registrare la spedizione subito dalla finestra', function () {
    $s = LetterePec::scenario($this);
    $r = $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lpcDiffida($s, ['canali' => [$s['persone']['ugo']->id => 'stampa', $s['persone']['bice']->id => 'stampa']]))
        ->assertOk();

    $righe = RegistroInvio::where('condominio_id', $s['c']->id)->where('canale', RegistroInvio::CANALE_STAMPA)->orderBy('id')->get();
    expect($righe)->toHaveCount(2)
        ->and((string) $r->headers->get('X-Lettere-Invii'))->toBe($righe->map(fn ($x) => $x->id . ':' . $x->anagrafica_id)->implode(','));
});

it('le rotte delle PEC non partite con una riga di un altro condominio: 404, e niente si stampa', function () {
    $s = LetterePec::scenario($this);
    $altro = \App\Models\Condominio::factory()->create();
    $riga = RegistroInvio::create(['condominio_id' => $altro->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'tipo' => 'diffida', 'canale' => RegistroInvio::CANALE_PEC,
        'documentabile_type' => \App\Models\Anagrafica::class, 'documentabile_id' => $s['persone']['ugo']->id, 'destinatario' => 'ugo@pec.it', 'dati' => ['lettera' => 'x'],
        'stato' => RegistroInvio::STATO_NON_PARTITA, 'emesso_il' => now()]);

    $this->actingAs($this->user)->post(lttRotta($s, 'pec-non-partite.stampa'), ['invii' => [$riga->id]])->assertNotFound();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec-non-partite'))->assertStatus(422)->assertJsonPath('message', EmissioneLettere::NESSUNA_PEC_DA_RIMANDARE);
    expect(RegistroInvio::where('condominio_id', $s['c']->id)->count())->toBe(0);
});

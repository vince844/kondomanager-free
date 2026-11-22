<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\RatificaDelloSforo;
use App\Services\Gestionale\RispostePromemoria;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R12 del progetto della tappa 2 con la decisione 561): la ratifica si lega alla delibera della riunione. La fattura in sforo diventa
 * approvata, `dati_extra.ratifica_assembleare` tiene le chiavi di oggi e copia il giorno della riunione e il numero del verbale, la delibera sta nel
 * collegamento; i compiti «Assemblee da convocare» e «Ratifiche in assemblea» si chiudono con la frase del programma; il memo della fattura si chiude; una
 * riga sola sulla fattura. La fattura già aperta al pagamento con la nota (il pagamento di oggi) si ratifica lo stesso, una volta.
 *
 * Le ratifiche di prima (decisione 561): una fattura ratificata con la nota prima della 1.12, senza la chiave `urgenza` che lo sblocco di
 * oggi scrive, resta ratificata: non aspetta niente, non va fra i punti proposti né nella card, e si lega a una delibera solo con il clic («Collega la
 * delibera»), una volta.
 *
 * Che cosa non copre: le due finestre e la rotta della ratifica (sono delle pagine; `approvaSforo` e il pagamento non cambiano, decisione 561).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

function vr12CompitoDelloSforo($s, $fattura, string $tipo): Evento
{
    $e = Evento::create(['title' => $tipo, 'start_time' => now(), 'end_time' => now()->addHour(), 'visibility' => 'hidden', 'is_approved' => true,
        'meta' => ['type' => $tipo, 'requires_action' => true, 'context' => ['fattura_id' => $fattura->id]]]);
    $e->condomini()->attach($s->c->id);

    return $e;
}

it('la fattura in sforo: approvata, le chiavi di oggi più il giorno e il verbale, i due compiti chiusi con la frase, una riga sola', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $convocare = vr12CompitoDelloSforo($s, $fattura, 'convocazione_urgenza');
    $ratifiche = vr12CompitoDelloSforo($s, $fattura, 'ratifica_sforo');
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Ratifica', 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id], $s->admin);
    $d = ViaRoma12::delibera($s, '2026-11-18', 'Ratifica della spesa urgente', 'approvata', '42');
    $prima = ViaRoma12::ultimaRiga();

    RatificaDelloSforo::ratifica($fattura, $d, 'Approvata all\'unanimità', $s->admin);

    $f = $fattura->fresh();
    $righe = ViaRoma12::righe($prima);
    expect($f->stato_approvazione)->toBe('approvata')
        ->and($f->dati_extra['ratifica_assembleare'])->toMatchArray(['note' => 'Approvata all\'unanimità', 'approvato_da' => $s->admin->id,
            'deliberata_il' => '2026-11-18', 'numero_verbale' => '42'])
        ->and($f->dati_extra['ratifica_assembleare'])->toHaveKey('approvato_il')
        ->and(CollegamentiDelleDelibere::delibera($f, 'ratifica')->id)->toBe($d->id)
        ->and((bool) $convocare->fresh()->is_completed)->toBeTrue()->and((bool) $ratifiche->fresh()->is_completed)->toBeTrue()
        ->and(RispostaPromemoria::query()->where('chiave', RispostePromemoria::chiaveDelCompito($convocare->id))->value('nota'))
            ->toBe('Chiuso dalla delibera del 18/11/2026, punto 1 (verbale n. 42)')
        ->and($memo->fresh()->stato->value)->toBe('deliberato')
        ->and($righe)->toHaveCount(1)
        ->and($righe[0]->oggetto_tipo)->toBe('fattura_passiva')
        ->and($righe[0]->riepilogo)->toBe('Fattura n. 45 di Idraulica Bianchi ratificata con delibera del 18/11/2026, punto 1 (verbale n. 42); chiusi i compiti «Assemblee da convocare» e «Ratifiche in assemblea»')
        ->and($righe[0]->rimando)->toMatchArray(['tipo' => 'delibera', 'id' => $d->id]);
});

it('la fattura già aperta al pagamento con la nota si ratifica una volta: tiene la nota di prima e prende il giorno della riunione', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s, '46', 90000, 'approvata');
    // Lo sblocco per l'urgenza di oggi (`approva-sforo`) scrive la chiave `urgenza`: senza, è una ratifica fatta prima della 1.12.
    $fattura->update(['dati_extra' => ['ratifica_assembleare' => ['note' => 'Urgenza: perdita in cantina', 'approvato_il' => '2026-11-12T10:00:00+00:00',
        'approvato_da' => $s->admin->id, 'urgenza' => true]]]);
    $d = ViaRoma12::delibera($s, '2026-11-18', 'Ratifica della perdita', 'approvata', '42');

    expect(RatificaDelloSforo::daRatificare($s->c)->pluck('id')->all())->toBe([$fattura->id]);
    RatificaDelloSforo::ratifica($fattura->fresh(), $d, null, $s->admin);
    $extra = $fattura->fresh()->dati_extra['ratifica_assembleare'];
    expect($extra['note'])->toBe('Urgenza: perdita in cantina')->and($extra['approvato_il'])->toBe('2026-11-12T10:00:00+00:00')
        ->and($extra['deliberata_il'])->toBe('2026-11-18')
        ->and(RatificaDelloSforo::daRatificare($s->c)->all())->toBe([])
        ->and(fn () => RatificaDelloSforo::ratifica($fattura->fresh(), $d, null, $s->admin))->toThrow(ValidationException::class);
});

it('una ratifica di prima resta ratificata: fuori dai sospesi, dai punti proposti e dalla card; «Collega la delibera» la lega una volta', function () {
    $s = ViaRoma12::nuovo($this);
    $f = ViaRoma12::fatturaInSforo($s, '12', 90000, 'approvata');
    $f->update(['dati_extra' => ['ratifica_assembleare' => ['note' => 'Ratificata nell\'assemblea del 10/05/2025', 'approvato_il' => '2025-05-12T10:00:00+00:00',
        'approvato_da' => $s->admin->id]]]);

    $programma = collect(\App\Services\Assemblee\ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'];
    $pagina = \App\Http\Controllers\Assemblee\RatificaPerLePagine::perLaPagina($f->fresh());
    $riga = \App\Http\Controllers\Assemblee\RatificaPerLePagine::perLaRiga($f->fresh(), []);
    expect(array_column($programma, 'chiave_fonte'))->not->toContain('fattura:' . $f->id)
        ->and(RatificaDelloSforo::aspettaLaRatifica($f->fresh()))->toBeFalse()
        ->and(RatificaDelloSforo::daRatificare($s->c)->all())->toBe([])
        ->and($pagina['stato'])->toBe('ratificata')
        ->and($pagina['sbloccata'])->toBeNull()
        ->and($pagina['in_assemblea'])->toBeNull()
        ->and($pagina['di_prima'])->toBe(['il' => '12/05/2025', 'nota' => 'Ratificata nell\'assemblea del 10/05/2025'])
        ->and($riga['aspetta_la_ratifica'])->toBeFalse()->and($riga['ratifica'])->toBe('ratificata')
        ->and(MemoriaDelCondominio::perLaDashboard($s->c)['totale'])->toBe(0)
        ->and(\App\Services\Assemblee\PropostaDelPunto::perLaScheda($f->fresh())['testo'])->toStartWith('Spesa di € 900,00');

    // «Collega la delibera»: la ratifica di prima si lega alla riunione, tiene la sua nota e prende il giorno della riunione; una volta sola.
    $d = ViaRoma12::delibera($s, '2025-05-10', 'Ratifica della spesa di Idraulica Bianchi', 'approvata', '3');
    RatificaDelloSforo::ratifica($f->fresh(), $d, null, $s->admin);
    $extra = $f->fresh()->dati_extra['ratifica_assembleare'];
    expect(CollegamentiDelleDelibere::delibera($f->fresh(), 'ratifica')->id)->toBe($d->id)
        ->and($extra['note'])->toBe('Ratificata nell\'assemblea del 10/05/2025')->and($extra['deliberata_il'])->toBe('2025-05-10')
        ->and(fn () => RatificaDelloSforo::ratifica($f->fresh(), $d, null, $s->admin))->toThrow(ValidationException::class);
});

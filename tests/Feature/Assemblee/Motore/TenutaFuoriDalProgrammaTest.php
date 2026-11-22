<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\DeliberaAMano;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R16 del progetto della tappa 2; §13 rilievo 8): Andrea prepara nel programma l'ordinaria del 15/12 con il memo della fattura n. 45,
 * la tiene su carta, poi registra la ratifica sul punto: la delibera nasce lì, non in una riunione nuova. L'assemblea diventa «registrata a mano» con
 * l'origine «programma»; i memo dei punti deliberati si chiudono, quelli dei punti non trattati tornano in attesa con «non trattato il …»; una riga sola.
 * Un punto **rinviato** non è deciso: il suo memo torna in attesa con «rinviato il …», e il compito torna se il giorno è davanti. Un memo «per questa
 * assemblea» che non è entrato nell'ordine del giorno torna «per la prossima».
 *
 * Che cosa non copre: il menu dell'intestazione della pagina; la sala (tappa 3).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('tenuta su carta: una riga sola, la delibera sul punto, il memo non trattato torna con la frase', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $ratifica = MemoriaDelCondominio::salva($s->c, ['testo' => 'Ratifica della spesa urgente', 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id], $s->admin);
    $portone = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    $a = ViaRoma12::ordinaria($s, [MemoriaDelCondominio::comeProposta($ratifica), MemoriaDelCondominio::comeProposta($portone)]);
    $punti = $a->punti()->get();
    $prima = ViaRoma12::ultimaRiga();

    StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, [$punti[0]->id => ['esito' => 'approvata']], '2026-11-18');

    $a->refresh();
    expect(ViaRoma12::righe($prima)->pluck('riepilogo')->all())
            ->toBe(['Assemblea ordinaria del 15/12/2026 tenuta su carta: 1 delibera registrata a mano, 1 punto non trattato'])
        ->and($a->stato->value)->toBe('registrata_a_mano')->and($a->origine->value)->toBe('programma')
        ->and($a->data->format('Y-m-d'))->toBe('2026-11-18')
        ->and($a->delibere()->count())->toBe(1)
        ->and($a->delibere()->first()->deliberata_il->format('Y-m-d'))->toBe('2026-11-18')
        ->and($ratifica->fresh()->stato->value)->toBe('deliberato')
        ->and($portone->fresh()->stato->value)->toBe('in_attesa');
    $proposto = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'da_te')['punti'];
    expect($proposto[0]['frase'])->toContain('non trattato il 18/11/2026');

    // Un punto rinviato non è deciso: la delibera «Rinviata» nasce sul punto, e il memo resta in attesa, con la frase del rinvio.
    $dopo = DeliberaAMano::registraSulPunto($punti[1], ['esito' => 'rinviata'], $s->admin);
    $proposto = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'da_te')['punti'];
    expect($dopo->assemblea_id)->toBe($a->id)->and($portone->fresh()->stato->value)->toBe('in_attesa')
        ->and($proposto[0]['frase'])->toContain('rinviato il 18/11/2026');
});

it('«Rinviata» sul punto: il memo torna in attesa con la frase del rinvio, e il suo compito torna se il giorno è davanti', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $s = ViaRoma12::nuovo($this);
    $portone = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone', 'ricordami_dal' => '2026-12-01'], $s->admin);
    $a = ViaRoma12::ordinaria($s, [MemoriaDelCondominio::comeProposta($portone)]);
    $punto = $a->punti()->first();
    expect((bool) \App\Models\Evento::query()->find($portone->fresh()->evento_id)->is_completed)->toBeTrue();

    StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, [$punto->id => ['esito' => 'rinviata']], '2026-11-18');

    $daTe = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'da_te')['punti'];
    $aperti = \App\Models\Evento::query()->where('meta->type', 'memo_assemblea')->where('is_completed', false)->count();
    expect($portone->fresh()->stato->value)->toBe('in_attesa')
        ->and(array_column($daTe, 'memo_id'))->toBe([$portone->id])
        ->and($daTe[0]['frase'])->toContain('rinviato il 18/11/2026')
        ->and($aperti)->toBe(1)
        ->and($a->delibere()->first()->esito->value)->toBe('rinviata');
});

it('il memo «per questa assemblea» non messo nell\'ordine del giorno torna «per la prossima» quando l\'assemblea è tenuta fuori', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Sostituzione del portone', 'assemblea_id' => $a->id], $s->admin);
    $primaDaTe = collect(ProposteDellOrdineDelGiorno::per($s->c, $a))->firstWhere('chiave', 'da_te')['punti'];

    StatiDellAssemblea::tenutaFuoriDalProgramma($a->fresh(), $s->admin, [], '2026-11-18');

    $b = ViaRoma12::ordinaria($s);
    $dopoDaTe = collect(ProposteDellOrdineDelGiorno::per($s->c, $b))->firstWhere('chiave', 'da_te')['punti'];
    expect(count($primaDaTe))->toBe(1)
        ->and($memo->fresh()->stato->value)->toBe('in_attesa')->and($memo->fresh()->assemblea_id)->toBeNull()
        ->and(array_column($dopoDaTe, 'memo_id'))->toBe([$memo->id])
        ->and(MemoriaDelCondominio::perLaDashboard($s->c)['totale'])->toBe(1);
});

it('un memo legato a un\'assemblea che non è più in preparazione si propone lo stesso per la prossima', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Sostituzione del portone', 'assemblea_id' => $a->id], $s->admin);
    // Un dato scritto prima della correzione: l'assemblea è registrata e il memo la ricorda ancora.
    \Illuminate\Support\Facades\DB::table('assemblee')->where('id', $a->id)->update(['stato' => 'registrata_a_mano']);

    $daTe = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'da_te')['punti'];
    expect(array_column($daTe, 'memo_id'))->toBe([$memo->id]);
});

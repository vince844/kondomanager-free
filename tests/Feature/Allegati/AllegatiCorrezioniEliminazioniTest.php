<?php

/**
 * # Allegati, il giro di correzioni: ciò che sparisce con un utente, un condominio o un elemento (Coda 460)
 *
 * Le chiavi esterne di `segnalazioni`, `comunicazioni` e `documenti` verso `users` e `condomini` sono in cascata:
 * quando si elimina un utente o un condominio il database cancella le righe da sé, e l'evento `deleting` dei
 * model di segnalazioni e comunicazioni non parte. La regola resta quella del progetto: **una riga di allegato
 * non sopravvive al suo elemento, e un file non sopravvive alla sua riga**. Quindi:
 *
 * - si elimina un utente: spariscono, righe e file, gli allegati delle segnalazioni e delle comunicazioni che
 *   aveva scritto (anche quelli caricati da altri), e i file degli allegati che aveva caricato altrove (la riga
 *   la porta via la cascata su `documenti.created_by`); gli allegati degli altri sugli elementi degli altri restano;
 * - si elimina un condominio: spariscono, righe e file, gli allegati delle sue segnalazioni; una comunicazione
 *   che vale anche per un altro palazzo resta, con i suoi allegati;
 * - si elimina una segnalazione con file in attesa: la sua voce «allegato» dell'Inbox non resta aperta.
 *
 * Cosa non copre: l'eliminazione del condominio dimostrativo (ha una strada sua), i documenti dell'archivio di un
 * utente eliminato (comportamento di prima, fuori da questo compito).
 */

use App\Models\Condominio;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->carla = algUtente([$this->aurora->id], 'Carla Neri');
    $this->disco = app(ArchivioDocumenti::class)->disco();
});

/** Il percorso sul disco di ogni allegato dato, letto prima di eliminare. */
function algcPercorsi(array $ids): array
{
    return DB::table('documenti')->whereIn('id', $ids)->pluck('path', 'id')->all();
}

test('eliminare un utente porta via righe e file degli allegati dei suoi elementi, e i file che aveva caricato altrove', function () {
    $diMario = algSegnalazione($this->aurora, $this->mario);
    $diCarla = algSegnalazione($this->aurora, $this->carla, ['subject' => 'Perdita in garage']);
    $comunicazioneDiMario = algComunicazione([$this->aurora], $this->mario, ['subject' => 'Assemblea del cortile']);

    $suaFoto = algAllegatoSalvato($diMario, $this->mario, 'cancello.jpg');
    $preventivoDelloStudio = algAllegatoSalvato($diMario, $this->admin, 'preventivo.pdf');
    $sullaSegnalazioneDiCarla = algAllegatoSalvato($diCarla, $this->mario, 'garage-visto-da-mario.jpg');
    $nellaComunicazione = algAllegatoSalvato($comunicazioneDiMario, $this->admin, 'convocazione.pdf');
    $diCarlaResta = algAllegatoSalvato($diCarla, $this->carla, 'garage.jpg');

    $spariscono = [$suaFoto, $preventivoDelloStudio, $sullaSegnalazioneDiCarla, $nellaComunicazione];
    $percorsi = algcPercorsi([...$spariscono, $diCarlaResta]);

    $this->actingAs($this->admin)->delete(route('utenti.destroy', $this->mario));
    expect(User::find($this->mario->id))->toBeNull('L\'utente non è stato eliminato: lo scenario non prova niente');

    expect(DB::table('documenti')->whereIn('id', $spariscono)->count())->toBe(0, 'Sono rimaste righe di allegati senza il loro elemento');
    foreach ($spariscono as $id) {
        expect($this->disco->exists($percorsi[$id]))->toBeFalse("Il file dell'allegato {$id} è rimasto sul disco senza la sua riga");
    }
    expect(DB::table('documenti')->where('id', $diCarlaResta)->exists())->toBeTrue()
        ->and($this->disco->exists($percorsi[$diCarlaResta]))->toBeTrue();
});

test('eliminare un condominio porta via righe e file degli allegati delle sue segnalazioni; una comunicazione di due palazzi resta', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $foto = algAllegatoSalvato($s, $this->mario, 'cancello.jpg');
    $preventivo = algAllegatoSalvato($s, $this->admin, 'preventivo.pdf');

    $paola = algUtente([$this->borea->id], 'Paola Bassi');
    $sBorea = algSegnalazione($this->borea, $paola, ['subject' => 'Perdita in garage']);
    $diBorea = algAllegatoSalvato($sBorea, $paola, 'garage.jpg');
    $c = algComunicazione([$this->aurora, $this->borea], $this->admin);
    $convocazione = algAllegatoSalvato($c, $this->admin, 'convocazione.pdf');

    $percorsi = algcPercorsi([$foto, $preventivo, $diBorea, $convocazione]);

    $this->actingAs($this->admin)->delete(route('condomini.destroy', $this->aurora));
    expect(Condominio::find($this->aurora->id))->toBeNull('Il condominio non è stato eliminato: lo scenario non prova niente');

    expect(DB::table('documenti')->whereIn('id', [$foto, $preventivo])->count())->toBe(0)
        ->and($this->disco->exists($percorsi[$foto]))->toBeFalse()
        ->and($this->disco->exists($percorsi[$preventivo]))->toBeFalse();

    expect(DB::table('documenti')->whereIn('id', [$diBorea, $convocazione])->count())->toBe(2)
        ->and($this->disco->exists($percorsi[$diBorea]))->toBeTrue()
        ->and($this->disco->exists($percorsi[$convocazione]))->toBeTrue();
});

test('eliminare una segnalazione con file in attesa chiude la sua voce nell\'Inbox', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    algCarica($this, $this->mario, $s, [algFoto()])->assertSessionHasNoErrors();

    $aperte = fn () => DB::table('eventi')
        ->where('tipo', 'allegato')
        ->where('eventable_type', $s->getMorphClass())
        ->where('eventable_id', $s->id)
        ->where('is_completed', false)
        ->count();
    expect($aperte())->toBe(1, 'Il caricamento in attesa doveva aprire la voce: lo scenario non prova niente');

    $this->actingAs($this->admin)->delete(route('admin.segnalazioni.destroy', $s));
    expect(DB::table('segnalazioni')->where('id', $s->id)->exists())->toBeFalse()
        ->and($aperte())->toBe(0);
});

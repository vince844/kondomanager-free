<?php

/**
 * beta.53 — «Pertinenza di»: la forma del legame.
 *
 * Questo file fissa la **cardinalità** e ciò che il legame non deve fare. La ricerca che l'ha
 * deciso sta in `docs/pertinenze_condominiali.md` e `docs/pertinenze_vendita_locazione.md`.
 *
 * ## Perché una chiave esterna e non la molti-a-molti che c'era
 *
 * L'art. 817 c.c. chiede il requisito soggettivo — i due beni allo **stesso proprietario** — e da
 * lì discende che una pertinenza ha un solo bene principale. «Il box condiviso da due unità», che
 * il vecchio codice invocava, è comproprietà del box fra due persone e vive in
 * `anagrafica_immobile`; se invece il box è comune a un gruppo di unità non è una pertinenza, è un
 * bene ex art. 1117 c.c. la cui spesa si ripartisce con una tabella a perimetro ristretto.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre l'interfaccia — il campo, il sottorigo nell'elenco, l'avviso di titolari divergenti —
 * che arriva dopo. E non copre le **date di validità del legame**, che non esistono: arriveranno
 * col primo calcolo che le legge, per la regola «ogni nuova data deve nascere con il suo lettore».
 */

use App\Models\Condominio;
use App\Models\Immobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function unita(Condominio $c, string $nome): Immobile
{
    return Immobile::create([
        'condominio_id' => $c->id,
        'nome'          => $nome,
        'interno'       => $nome,
        'descrizione'   => 'Unità di prova',
    ]);
}

it('un appartamento raccoglie le sue pertinenze, e ciascuna ne ha una sola', function () {
    $c = Condominio::factory()->create();
    $appartamento = unita($c, 'Interno 3');

    foreach (['Box 12', 'Cantina 7', 'Soffitta 3'] as $nome) {
        unita($c, $nome)->update(['pertinenza_di_immobile_id' => $appartamento->id]);
    }

    expect($appartamento->pertinenze()->count())->toBe(3);

    $box = Immobile::where('nome', 'Box 12')->first();
    expect($box->pertinenzaDi->id)->toBe($appartamento->id);
});

it('cancellare l\'appartamento non cancella il box: il legame si scioglie e l\'unità resta', function () {
    // ⚠️ È il motivo di `nullOnDelete()` invece di `cascadeOnDelete()`. Un box è un'unità
    // immobiliare a tutti gli effetti — ha titolari, millesimi, rate e saldi propri — e non deve
    // sparire perché sparisce l'unità a cui era collegato. La vecchia pivot aveva **entrambe** le
    // chiavi in cascade.
    $c = Condominio::factory()->create();
    $appartamento = unita($c, 'Interno 3');
    $box = unita($c, 'Box 12');
    $box->update(['pertinenza_di_immobile_id' => $appartamento->id]);

    $appartamento->delete();

    $box->refresh();
    expect($box->exists)->toBeTrue()
        ->and($box->pertinenza_di_immobile_id)->toBeNull();
});

it('il caso Tognoli: il principale può stare fuori dal condominio, e si scrive in chiaro', function () {
    // L'art. 9 co. 5 L. 122/1989 consente di cedere un parcheggio solo con contestuale
    // destinazione a pertinenza di altra unità **nello stesso comune** — che può stare in un altro
    // condominio o non essere gestita dal programma. Senza questo campo l'amministratore
    // lascerebbe vuoto, che è l'informazione opposta.
    $c = Condominio::factory()->create();
    $box = unita($c, 'Box 12');

    $box->update(['pertinenza_di_esterna' => 'Via Roma 14, int. 5 — foglio 12 part. 340 sub 7']);

    expect($box->pertinenza_di_immobile_id)->toBeNull()
        ->and($box->haUnPrincipale())->toBeTrue();
});

it('un\'unità senza legame non ne ha uno, e non è un errore', function () {
    // L'assenza è uno stato legittimo e frequente: box venduto a terzi, unità principale non
    // gestita, o semplicemente un appartamento che pertinenze non ne ha.
    $box = unita(Condominio::factory()->create(), 'Box 12');

    expect($box->haUnPrincipale())->toBeFalse()
        ->and($box->pertinenzaDi)->toBeNull()
        ->and($box->pertinenze()->count())->toBe(0);
});

it('la vecchia pivot molti-a-molti non esiste più', function () {
    // Era vuota e senza scrittori, e la sua cardinalità permetteva di scrivere dati che il diritto
    // non consente. Con lei se ne va `quota_possesso`, che fra un'unità e la sua pertinenza non
    // significa niente: la quota esiste fra persone e unità.
    expect(Schema::hasTable('immobile_pertinenza'))->toBeFalse();
});

it('il legame non entra in nessuna delle colonne che il motore legge', function () {
    // ⚠️ **La garanzia che dimensiona tutta la funzione.** Il legame è presentazione: non sposta
    // millesimi, riparto, saldi, rate né quorum. Se un giorno un calcolo iniziasse a leggerlo,
    // questo test non lo prenderebbe — ma la sua esistenza dice che non deve succedere per caso.
    $c = Condominio::factory()->create();
    $appartamento = unita($c, 'Interno 3');
    $box = unita($c, 'Box 12');
    $box->update(['pertinenza_di_immobile_id' => $appartamento->id]);

    // Le quote millesimali del box restano sue: nessuna somma, nessun travaso sul principale.
    $tabella = \App\Models\Tabella::create([
        'condominio_id' => $c->id, 'nome' => 'GENERALE', 'quota' => 'millesimi',
    ]);
    DB::table('quote_tabella')->insert([
        ['tabella_id' => $tabella->id, 'immobile_id' => $appartamento->id, 'valore' => 800, 'created_at' => now(), 'updated_at' => now()],
        ['tabella_id' => $tabella->id, 'immobile_id' => $box->id, 'valore' => 200, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(DB::table('quote_tabella')->where('immobile_id', $box->id)->value('valore'))
        ->toEqual(200.0);
});

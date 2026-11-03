<?php

use App\Services\Gestionale\Duplicati\CollisioneUnicaFattura;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * ⚠️ **Il motivo per cui questo test esiste come test Unit, senza database.**
 * `UniqueConstraintViolationException::$index` porta il nome dell'indice **solo su MySQL**;
 * su SQLite (`tests/TestCase.php` impone quel driver a ogni test Feature) è sempre `null`, e
 * la stessa informazione arriva in `$columns` invece. I due rami di `rilevata()` sono quindi
 * **mutuamente esclusivi per driver**: nessun test Feature può mai far scattare il ramo `index`,
 * che però è l'unico che decide in produzione. Trovato dalla revisione avversariale della
 * beta.13 — prima di questa estrazione quel ramo aveva copertura zero.
 *
 * Qui l'eccezione si costruisce a mano, come farebbe il driver MySQL vero, e si passa la
 * proprietà pubblica `$index` direttamente: non serve né SQLite né MySQL per provarlo.
 */
function eccezioneConIndice(?string $index, array $columns = []): UniqueConstraintViolationException
{
    $e = new UniqueConstraintViolationException(
        connectionName: 'mysql',
        sql: 'insert into `fatture_passive` ...',
        bindings: [],
        previous: new \Exception('Duplicate entry for key'),
    );
    $e->setIndex($index)->setColumns($columns);

    return $e;
}

it('riconosce il nome attuale dell indice, come lo restituisce MySQL', function () {
    $e = eccezioneConIndice('unique_ft_condominio');

    expect(CollisioneUnicaFattura::rilevata($e))->toBeTrue();
});

it('riconosce anche il vecchio nome dell indice, per la finestra di deploy prima della migrazione', function () {
    $e = eccezioneConIndice('unique_ft');

    expect(CollisioneUnicaFattura::rilevata($e))->toBeTrue();
});

it('riconosce dalle colonne quando il driver non porta il nome dell indice — il caso SQLite', function () {
    $e = eccezioneConIndice(null, ['fornitore_id', 'condominio_id', 'numero_documento', 'data_documento']);

    expect(CollisioneUnicaFattura::rilevata($e))->toBeTrue();
});

it('non si fa ingannare da un indice unico DIVERSO sulla stessa tabella', function () {
    // Per esempio numero_protocollo, se un giorno diventasse unico: un falso positivo qui
    // mostrerebbe all'utente un messaggio di dominio plausibile ma sbagliato.
    $e = eccezioneConIndice('fatture_passive_numero_protocollo_unique');

    expect(CollisioneUnicaFattura::rilevata($e))->toBeFalse();
});

it('non si fa ingannare da colonne che si sovrappongono solo in parte', function () {
    $e = eccezioneConIndice(null, ['numero_documento', 'condominio_id']);

    expect(CollisioneUnicaFattura::rilevata($e))->toBeFalse();
});

it('CONTROPROVA: senza il vecchio nome nell elenco, la finestra di deploy tornerebbe a mostrare l errore grezzo', function () {
    // Non tocca il codice di produzione: verifica solo che l'elenco INDICI contenga
    // davvero entrambi i nomi, cosi' una futura rimozione disattenta di uno dei due
    // fa fallire questo test invece di scoprirsi in produzione durante un deploy.
    expect(CollisioneUnicaFattura::INDICI)
        ->toContain('unique_ft_condominio')
        ->toContain('unique_ft');
});

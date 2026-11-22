<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\ContoCorrente;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use Illuminate\Database\Eloquent\Model;

/**
 * Un conto corrente: di una cassa del condominio (il riquadro «Dettagli istituto di credito» di `CasseEdit.vue`) o di un fornitore. L'IBAN è un campo che si
 * vede, non segreto. Compare nella «Storia» del suo proprietario (P22).
 */
final class ContoCorrenteNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = ContoCorrente::class;

    public const AREA = 'persone';

    public const TIPO = 'conto_corrente';

    public const PAROLA = 'Conto corrente';

    public const ETICHETTE = [
        'intestatario' => 'Intestatario conto',
        'iban' => 'IBAN',
        'swift' => 'BIC / SWIFT',
        'istituto' => 'Nome banca / filiale',
        'indirizzo' => 'Indirizzo e civico',
        'comune' => 'Comune',
        'provincia' => 'Provincia',
        'cap' => 'CAP',
        'predefinito' => 'Conto principale',
        'tipo' => 'Tipologia conto',
    ];

    public const FORMATI = [
        'predefinito' => 'si_no',
        'tipo' => ['scelta', ['ordinario' => 'Ordinario / Condominiale', 'dedicato' => 'Dedicato (Lavori Straordinari)', 'postale' => 'Postale',
            'contabilita_speciale' => 'Contabilità speciale', 'estero' => 'Estero', 'altro' => 'Altro']],
    ];

    /** Il proprietario e la nazione li scrive il programma; le note non sono in nessuna pagina. */
    public const ESCLUSI = ['note'];

    public const DEL_PROGRAMMA = ['contable_type', 'contable_id', 'nazione'];

    public const DATI_DELLA_PERSONA = ['intestatario', 'iban', 'indirizzo', 'comune', 'provincia', 'cap'];

    public function nome(Model $modello): string
    {
        $iban = (string) $modello->getAttribute('iban');
        $di = $this->proprietario($modello);
        $suo = $di instanceof Fornitore ? ' del fornitore ' . $di->ragione_sociale : ($di instanceof Cassa ? ' della risorsa «' . $di->nome . '»' : '');

        return 'Conto corrente' . ($iban !== '' ? ' ' . $iban : '') . $suo;
    }

    public function areaDi(Model $modello): string
    {
        return $modello->getAttribute('contable_type') === Cassa::class ? 'contabilita' : 'persone';
    }

    public function condomini(Model $modello): array
    {
        $di = $this->proprietario($modello);
        if ($di instanceof Cassa) {
            return (new CassaNelRegistro())->condomini($di);
        }

        return $di instanceof Fornitore ? (new FornitoreNelRegistro())->condomini($di) : [];
    }

    public function scheda(Model $modello): array
    {
        $di = $this->proprietario($modello);
        if ($di instanceof Cassa) {
            return [CassaNelRegistro::TIPO, (int) $di->getKey()];
        }

        return $di instanceof Fornitore ? [FornitoreNelRegistro::TIPO, (int) $di->getKey()] : parent::scheda($modello);
    }

    public function soggetti(Model $modello): array
    {
        return $modello->getAttribute('contable_type') === Fornitore::class ? [['fornitore', (int) $modello->getAttribute('contable_id')]] : [];
    }

    private function proprietario(Model $modello): ?Model
    {
        $classe = $modello->getAttribute('contable_type');
        if (! is_string($classe) || ! in_array($classe, [Fornitore::class, Cassa::class], true)) {
            return null;
        }

        return $classe::query()->find($modello->getAttribute('contable_id'));
    }
}

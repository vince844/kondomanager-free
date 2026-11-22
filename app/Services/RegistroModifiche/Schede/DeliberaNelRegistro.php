<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Enums\Assemblee\EsitoDelibera;
use App\Models\Assemblee\Delibera;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Una delibera (§6.1 e §6.2): compare nella «Storia» dell'assemblea. Che cosa ha deciso, e le note dell'annullamento e dell'impugnazione, possono nominare
 * persone (§13, rilievo 18).
 */
final class DeliberaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Delibera::class;

    public const AREA = 'assemblee';

    public const TIPO = 'delibera';

    public const PAROLA = 'Delibera';

    public const ETICHETTE = [
        'votazione' => 'Votazione',
        'oggetto' => 'Su che cosa',
        'testo' => 'Che cosa ha deciso',
        'esito' => 'Esito',
        'deliberata_il' => 'Deliberata il',
        'importo' => 'Importo',
        'revocata_il' => 'Revocata il',
        'revocata_da_delibera_id' => 'Revocata dalla delibera',
        'annullata_il' => 'Annullata il',
        'annullata_nota' => 'L\'annullamento',
        'impugnata_il' => 'Impugnata il',
        'impugnata_nota' => 'L\'impugnazione',
        'dati' => 'Che cosa ha cambiato l\'assemblea, la delega',
    ];

    public const FORMATI = [
        'votazione' => 'numero',
        'oggetto' => ['scelta', ['rendiconto' => 'Il rendiconto', 'riparto' => 'Lo stato di riparto']],
        'esito' => ['scelta', [EsitoDelibera::APPROVATA->value => 'Approvata', EsitoDelibera::APPROVATA_CON_MODIFICHE->value => 'Approvata con modifiche',
            EsitoDelibera::RESPINTA->value => 'Respinta', EsitoDelibera::RINVIATA->value => 'Rinviata', EsitoDelibera::SENZA_VOTO->value => 'Senza voto']],
        'deliberata_il' => 'data',
        'importo' => 'euro',
        'revocata_il' => 'data',
        'revocata_da_delibera_id' => ['chiave', Delibera::class, '@nome'],
        'annullata_il' => 'data',
        'impugnata_il' => 'data',
    ];

    /** Il condominio, la riunione e il punto vengono dalla pagina (§13, rilievo 23). */
    public const ESCLUSI = ['condominio_id', 'assemblea_id', 'punto_id'];

    /** «Che cosa ha cambiato l'assemblea» (in `dati`) è un testo della persona, come le note: può nominare qualcuno. */
    public const DATI_DELLA_PERSONA = ['testo', 'annullata_nota', 'impugnata_nota', 'dati'];

    /** La conferma del riepilogo «Che cosa cambia nel gestionale» ha la sua riga sull'assemblea. */
    public const DEL_PROGRAMMA = ['seguito_confermato_il', 'seguito_confermato_da'];

    /** «Delibera del 22/10/2026, punto 1». */
    public function nome(Model $modello): string
    {
        $grezzi = $modello->getAttributes();
        $giorno = FormatoDelValore::testo($grezzi['deliberata_il'] ?? null, 'data');
        $numero = ($grezzi['punto_id'] ?? null) === null ? null : DB::table('assemblee_punti')->where('id', $grezzi['punto_id'])->value('numero');

        return 'Delibera' . ($giorno !== null ? ' del ' . $giorno : '') . ($numero !== null ? ', punto ' . (int) $numero : '');
    }

    public function scheda(Model $modello): array
    {
        return [AssembleaNelRegistro::TIPO, (int) $modello->getAttribute('assemblea_id')];
    }
}

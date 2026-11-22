<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Enums\Assemblee\AChiMemo;
use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\User;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Una cosa da fare dopo una delibera (il seguito delle delibere): compare nella «Storia» dell'assemblea della sua delibera. Creata, fatta con la data e la
 * nota, «Non serve più» con il motivo: una riga per gesto. Il testo, la nota e il motivo sono dati della persona; la scheda che l'ha eseguita, il compito e
 * chi l'ha fatta li scrive il programma.
 */
final class CosaDaFareNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = CosaDaFareDellaDelibera::class;

    public const AREA = 'assemblee';

    public const TIPO = 'cosa_da_fare_della_delibera';

    public const PAROLA = 'Cosa da fare dopo una delibera';

    public const ETICHETTE = [
        'tipo' => 'Che cosa',
        'testo' => 'Il testo',
        'entro_il' => 'Entro il',
        'a_chi' => 'A chi',
        'assegnato_a' => 'Il collaboratore',
        'stato' => 'Stato',
        'fatta_il' => 'Fatta il',
        'nota' => 'La nota',
        'chiusa_motivo' => 'Perché non serve più',
        'documento_id' => 'Il documento',
    ];

    public const FORMATI = [
        'tipo' => ['scelta', [TipoCosaDaFare::CREA_PIANO_STRAORDINARIO->value => 'Crea il piano straordinario',
            TipoCosaDaFare::PREPARA_RIPIANAMENTO->value => 'Prepara il piano di ripianamento', TipoCosaDaFare::REGISTRA_DISPENSA->value => 'Registra la dispensa',
            TipoCosaDaFare::SCRIVI_REGOLA->value => 'Scrivi la regola', TipoCosaDaFare::RIFAI_VERSIONE->value => 'Correggi e ferma una versione nuova',
            TipoCosaDaFare::RIMETTI_PIANO->value => 'Rimetti il piano come il preventivo approvato',
            TipoCosaDaFare::AVVISA_CONDOMINI->value => 'Avvisa i condòmini', TipoCosaDaFare::FUORI_DAL_PROGRAMMA->value => 'Fuori dal programma',
            TipoCosaDaFare::DOPO_IL_RIFIUTO->value => 'Respinta: che cosa fai?']],
        'entro_il' => 'data',
        'a_chi' => ['scelta', [AChiMemo::ME->value => 'A me', AChiMemo::STUDIO->value => 'A tutto lo studio', AChiMemo::COLLABORATORE->value => 'A un collaboratore']],
        'assegnato_a' => ['chiave', User::class, 'name'],
        'stato' => ['scelta', [StatoCosaDaFare::DA_FARE->value => 'Da fare', StatoCosaDaFare::FATTA->value => 'Fatta', StatoCosaDaFare::NON_SERVE->value => 'Non serve più']],
        'fatta_il' => 'data',
        'documento_id' => ['chiave', \App\Models\Documento::class, 'name'],
    ];

    /** Il condominio e la delibera vengono dalla pagina. */
    public const ESCLUSI = ['condominio_id', 'delibera_id'];

    /** Il compito, chi l'ha fatta e la scheda che l'ha eseguita li scrive il programma. */
    public const DEL_PROGRAMMA = ['evento_id', 'fatta_da', 'oggetto_tipo', 'oggetto_id'];

    public const DATI_DELLA_PERSONA = ['testo', 'nota', 'chiusa_motivo'];

    public function nome(Model $modello): string
    {
        return 'Cosa da fare dopo la delibera';
    }

    /**
     * Le righe della cosa dicono **quale cosa** e **che cosa è successo**, non l'elenco dei dati cambiati. Creata: «Crea il piano straordinario», entro il
     * 15/01/2027 (la riga del registro dice già «Creata»). Con lo stato cambiato: «Crea il piano straordinario: fatta il 21/11/2026», «Avvisa i condòmini:
     * non serve più (il motivo)», «…: di nuovo da fare». Un cambiamento senza lo stato (il giorno, la nota) resta l'elenco dei dati.
     */
    public function riepilogo(Model $modello, string $azione): ?string
    {
        $grezzi = $modello->getAttributes();
        if ($azione === 'creata') {
            $entro = FormatoDelValore::testo($grezzi['entro_il'] ?? null, 'data');

            return '«' . self::fraseDellaCosa($grezzi) . '»' . ($entro !== null ? ', entro il ' . $entro : '');
        }
        if ($azione !== 'modificata' || ! $modello->wasChanged('stato')) {
            return null;
        }
        $cosa = self::parolaDellaCosa($grezzi);

        return match ($grezzi['stato'] ?? null) {
            StatoCosaDaFare::FATTA->value => $cosa . ': fatta il ' . (FormatoDelValore::testo($grezzi['fatta_il'] ?? null, 'data') ?? 'giorno non scritto'),
            StatoCosaDaFare::NON_SERVE->value => $cosa . ': non serve più' . (($motivo = trim((string) ($grezzi['chiusa_motivo'] ?? ''))) !== '' ? ' (' . $motivo . ')' : ''),
            StatoCosaDaFare::DA_FARE->value => $cosa . ': di nuovo da fare',
            default => null,
        };
    }

    /** Il testo della cosa su una riga sola, corto. @param array<string, mixed> $grezzi */
    private static function fraseDellaCosa(array $grezzi): string
    {
        $testo = trim(preg_replace('/\s+/u', ' ', (string) ($grezzi['testo'] ?? '')) ?? '');

        return mb_strlen($testo) > 60 ? rtrim(mb_substr($testo, 0, 59)) . '…' : $testo;
    }

    /** Quale cosa: la parola del tipo («Crea il piano straordinario»); per le due scritte dall'amministratore il suo testo. @param array<string, mixed> $grezzi */
    private static function parolaDellaCosa(array $grezzi): string
    {
        $tipo = TipoCosaDaFare::tryFrom((string) ($grezzi['tipo'] ?? ''));
        if ($tipo === null || in_array($tipo, [TipoCosaDaFare::FUORI_DAL_PROGRAMMA, TipoCosaDaFare::DOPO_IL_RIFIUTO], true)) {
            return self::fraseDellaCosa($grezzi) !== '' ? self::fraseDellaCosa($grezzi) : ($tipo?->parola() ?? 'Cosa da fare');
        }

        return $tipo->parola();
    }

    /** Nella «Storia» dell'assemblea della delibera. */
    public function scheda(Model $modello): array
    {
        $assemblea = DB::table('delibere')->where('id', $modello->getAttribute('delibera_id'))->value('assemblea_id');

        return [AssembleaNelRegistro::TIPO, (int) $assemblea];
    }
}

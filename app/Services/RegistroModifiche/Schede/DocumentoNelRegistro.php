<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Anagrafica;
use App\Models\Documento;
use App\Models\Fornitore;
use Illuminate\Database\Eloquent\Model;

/**
 * Il documento (`lang/it/documenti.php`, `DocumentiEdit.vue`). P8: il tratto vale solo per i documenti dell'archivio e per quelli caricati da una
 * persona, non per i PDF che il programma genera (lettere, fogli, fascicoli, versioni del rendiconto): quelli stanno già nel registro degli invii o nelle
 * versioni. Il file e la sua sostituzione sono le versioni di prima (§9).
 */
final class DocumentoNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Documento::class;

    public const AREA = 'documenti';

    public const TIPO = 'documento';

    public const PAROLA = 'Documento';

    /** I proprietari dei documenti che carica una persona; tutti gli altri (registro degli invii, versioni del rendiconto, fascicolo) li genera il programma. */
    public const PROPRIETARI_DI_UNA_PERSONA = [
        \App\Models\Anagrafica::class,
        \App\Models\Fornitore::class,
        \App\Models\Immobile::class,
        \App\Models\Segnalazione::class,
        \App\Models\Comunicazione::class,
        \App\Models\Gestionale\FatturaPassiva::class,
        \App\Models\Gestionale\DispensaSeiMesi::class,
        \App\Models\Gestionale\AccordoRientro::class,
    ];

    public const ETICHETTE = [
        'name' => 'Nome documento',
        'description' => 'Descrizione documento',
        'is_published' => 'Visibilità documento',
        'is_approved' => 'Approvazione',
    ];

    public const FORMATI = [
        'is_published' => ['scelta', ['0' => 'Bozza', '1' => 'Pubblicato']],
        'is_approved' => ['scelta', ['0' => 'Non approvato', '1' => 'Approvato']],
    ];

    /** Nessuno la scrive. */
    public const ESCLUSI = ['is_private'];

    /** Il file e il suo proprietario (la sostituzione è una versione di prima, §9); chi ha moderato l'allegato. */
    public const DEL_PROGRAMMA = ['path', 'mime_type', 'file_size', 'documentable_id', 'documentable_type', 'moderato_da', 'moderato_at'];

    public const RIPRISTINABILI = ['name', 'description'];

    public function registra(Model $modello): bool
    {
        $tipo = $modello->getAttribute('documentable_type');

        return $tipo === null || $tipo === '' || in_array($tipo, self::PROPRIETARI_DI_UNA_PERSONA, true);
    }

    public function nome(Model $modello): string
    {
        return 'Documento «' . $modello->getAttribute('name') . '»';
    }

    public function condomini(Model $modello): array
    {
        $tipo = $modello->getAttribute('documentable_type');
        if ($tipo === null || $tipo === '') {
            if (! $modello->exists) {
                return [];
            }

            return $modello->condomini()->orderBy('condomini.id')->pluck('condomini.nome', 'condomini.id')
                ->mapWithKeys(fn ($nome, $id) => [(int) $id => (string) $nome])->all();
        }
        $proprietario = $this->proprietario($modello);
        if ($proprietario === null) {
            return [];
        }
        if ($proprietario instanceof Anagrafica) {
            return (new AnagraficaNelRegistro())->condomini($proprietario);
        }
        if ($proprietario instanceof Fornitore) {
            return (new FornitoreNelRegistro())->condomini($proprietario);
        }

        return parent::condomini($proprietario);
    }

    public function soggetti(Model $modello): array
    {
        return match ($modello->getAttribute('documentable_type')) {
            Anagrafica::class => [['anagrafica', (int) $modello->getAttribute('documentable_id')]],
            Fornitore::class => [['fornitore', (int) $modello->getAttribute('documentable_id')]],
            default => [],
        };
    }

    private function proprietario(Model $modello): ?Model
    {
        $tipo = $modello->getAttribute('documentable_type');

        return in_array($tipo, self::PROPRIETARI_DI_UNA_PERSONA, true) ? $tipo::query()->find($modello->getAttribute('documentable_id')) : null;
    }
}

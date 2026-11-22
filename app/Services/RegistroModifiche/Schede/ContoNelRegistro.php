<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Fornitore;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Illuminate\Database\Eloquent\Model;

/** Una voce del preventivo (`ModalModificaConto.vue`), con l'importo preventivato: compare nella «Storia» della gestione (P22). */
final class ContoNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Conto::class;

    public const AREA = 'piani_rate';

    public const TIPO = 'voce';

    public const PAROLA = 'Voce';

    public const ETICHETTE = [
        'parent_id' => 'Capitolo padre',
        'nome' => 'Nome voce',
        'descrizione' => 'Descrizione',
        'tipo' => 'Tipo di movimento',
        'importo' => 'Importo preventivato',
        'note' => 'Note',
        'codice' => 'Codice',
        'default_fornitore_id' => 'Fornitore suggerito',
        'tipo_spesa' => 'Natura spesa (fiscale)',
        'origine_decisionale' => 'Origine dell\'incarico',
        'is_capitolo' => 'È un capitolo di spesa?',
        'richiede_gia_versato' => 'Voce di spesa da esercizio precedente',
    ];

    public const FORMATI = [
        'parent_id' => ['chiave', Conto::class, 'nome'],
        'tipo' => ['scelta', ['spesa' => 'Spesa (uscita)', 'entrata' => 'Entrata']],
        'importo' => 'euro',
        'default_fornitore_id' => ['chiave', Fornitore::class, 'ragione_sociale'],
        'tipo_spesa' => ['scelta', ['standard' => 'Standard (Beni/Servizi)', 'professionista' => 'Professionista (Rit. Acconto)',
            'lavori' => 'Lavori edili (Bonus/Ristr.)', 'utenza' => 'Utenza (Luce/Gas/Acqua)']],
        'origine_decisionale' => ['scelta', ['gestione_corrente' => 'Intervento d\'urgenza (art. 1135 c.c.)', 'delibera_assembleare' => 'Delibera assembleare']],
        'is_capitolo' => 'si_no',
        'richiede_gia_versato' => 'si_no',
    ];

    /** Mai scritte da una pagina. */
    public const ESCLUSI = ['destinazione_type', 'destinazione_id'];

    /** Il piano della pagina, sempre attiva, il conto contabile dedotto, la ripartizione che la pagina manda sempre uguale, la voce tecnica. */
    public const DEL_PROGRAMMA = ['piano_conto_id', 'attivo', 'conto_contabile_id', 'tipo_ripartizione', 'is_tecnico'];

    public function nome(Model $modello): string
    {
        $codice = trim((string) $modello->getAttribute('codice'));

        return 'Voce ' . ($codice !== '' ? '[' . $codice . '] ' : '') . '«' . $modello->getAttribute('nome') . '»';
    }

    public function condomini(Model $modello): array
    {
        $piano = PianoConto::query()->find($modello->getAttribute('piano_conto_id'));

        return $piano === null ? [] : parent::condomini($piano);
    }

    public function scheda(Model $modello): array
    {
        $gestione = PianoConto::query()->whereKey($modello->getAttribute('piano_conto_id'))->value('gestione_id');

        return $gestione === null ? parent::scheda($modello) : [GestioneNelRegistro::TIPO, (int) $gestione];
    }
}

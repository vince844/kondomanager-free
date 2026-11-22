<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Anagrafica;
use App\Models\AnagraficaFornitore;
use App\Models\Fornitore;
use Illuminate\Database\Eloquent\Model;

/** Il rappresentante di un fornitore (pivot con modello): una riga «collegata»/«scollegata» nella «Storia» del fornitore, e in quella della persona. */
final class AnagraficaFornitoreNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = AnagraficaFornitore::class;

    public const AREA = 'persone';

    public const TIPO = 'rappresentante_fornitore';

    public const PAROLA = 'Rappresentante del fornitore';

    public const ETICHETTE = [
        'anagrafica_id' => 'Anagrafica',
        'ruolo' => 'Ruolo',
    ];

    public const FORMATI = [
        'anagrafica_id' => ['chiave', Anagrafica::class, 'nome'],
        'ruolo' => ['scelta', ['titolare' => 'Titolare', 'amministrativo' => 'Amministrativo', 'commerciale' => 'Commerciale', 'tecnico' => 'Tecnico',
            'referente' => 'Referente', 'altro' => 'Altro']],
    ];

    /** Il fornitore viene dall'indirizzo della pagina; «referente principale» non lo scrive nessuna pagina. */
    public const ESCLUSI = ['fornitore_id', 'referente_principale'];

    public const NELLA_STORIA_DEI_SOGGETTI = true;

    public function nome(Model $modello): string
    {
        $persona = Anagrafica::query()->whereKey($modello->getAttribute('anagrafica_id'))->value('nome');
        $fornitore = Fornitore::query()->whereKey($modello->getAttribute('fornitore_id'))->value('ragione_sociale');

        return 'Rappresentante ' . $persona . ' del fornitore ' . $fornitore;
    }

    public function condomini(Model $modello): array
    {
        $fornitore = Fornitore::query()->find($modello->getAttribute('fornitore_id'));

        return $fornitore === null ? [] : (new FornitoreNelRegistro())->condomini($fornitore);
    }

    public function scheda(Model $modello): array
    {
        return ['fornitore', (int) $modello->getAttribute('fornitore_id')];
    }

    public function soggetti(Model $modello): array
    {
        return [['anagrafica', (int) $modello->getAttribute('anagrafica_id')], ['fornitore', (int) $modello->getAttribute('fornitore_id')]];
    }

    public function azioneDi(string $evento): string
    {
        return match ($evento) {
            'creata' => 'collegata',
            'eliminata' => 'scollegata',
            default => $evento,
        };
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\AmministrazionePunto;
use App\Enums\Assemblee\ConduttorePunto;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\MateriaPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Esercizio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\Tabella;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Un punto dell'ordine del giorno (§6.1 e §6.2): compare nella «Storia» dell'assemblea. Le due frasi sono dati della persona (possono nominare chi deve,
 * chi ha ricevuto, chi vende: §13 rilievo 18); le persone fra i suoi oggetti sono i soggetti della riga, così l'oscuramento le trova.
 */
final class PuntoNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = PuntoDellOrdineDelGiorno::class;

    public const AREA = 'assemblee';

    public const TIPO = 'punto';

    public const PAROLA = 'Punto';

    public const ETICHETTE = [
        'numero' => 'Numero',
        'testo' => 'Il punto',
        'testo_pubblico' => 'La frase per la bacheca',
        'materia' => 'Di che cosa parla',
        'maggioranza' => 'Maggioranza',
        'maggioranza_libera' => 'L\'altra maggioranza',
        'amministrazione' => 'Ordinaria o straordinaria',
        'conduttore' => 'Il conduttore',
        'ambito' => 'Per chi',
        'palazzina_id' => 'Palazzina',
        'scala_id' => 'Scala',
        'tabella_id' => 'Tabella del punto',
        'esercizio_id' => 'Esercizio',
        'importo' => 'Importo',
        'dati' => 'I contenuti dell\'avviso',
    ];

    public const FORMATI = [
        'numero' => 'numero',
        'materia' => ['scelta', self::MATERIE],
        'maggioranza' => ['scelta', self::MAGGIORANZE],
        'amministrazione' => ['scelta', [AmministrazionePunto::ORDINARIA->value => 'Ordinaria amministrazione',
            AmministrazionePunto::STRAORDINARIA->value => 'Straordinaria amministrazione']],
        'conduttore' => ['scelta', [ConduttorePunto::VOTA->value => 'Vota al posto del proprietario', ConduttorePunto::INTERVIENE->value => 'Interviene senza voto']],
        'ambito' => ['scelta', [AmbitoAssemblea::CONDOMINIO->value => 'Tutto il condominio', AmbitoAssemblea::PALAZZINA->value => 'La palazzina',
            AmbitoAssemblea::SCALA->value => 'La scala']],
        'palazzina_id' => ['chiave', Palazzina::class, 'name'],
        'scala_id' => ['chiave', Scala::class, 'name'],
        'tabella_id' => ['chiave', Tabella::class, 'nome'],
        'esercizio_id' => ['chiave', Esercizio::class, 'nome'],
        'importo' => 'euro',
    ];

    /** Le parole delle materie (le stesse di {@see MateriaPunto}). */
    private const MATERIE = [
        'rendiconto' => 'Rendiconto', 'preventivo' => 'Preventivo', 'lavori' => 'Lavori', 'innovazioni' => 'Innovazioni',
        'innovazioni_agevolate' => 'Innovazioni agevolate (art. 1120 c. 2)', 'impianti_singoli' => 'Modalità per gli impianti dei singoli (art. 1122-bis c. 3)',
        'videosorveglianza' => 'Videosorveglianza sulle parti comuni (art. 1122-ter)', 'progetti_territorio' => 'Progetti del territorio (art. 1135 c. 3)',
        'ratifica' => 'Ratifica di una spesa', 'comunicazione_urgenza' => 'Comunicazione dei lavori urgenti', 'dispensa' => 'Dispensa dei sei mesi',
        'accordo' => 'Accordo di rientro', 'regole' => 'Regole del recupero dei crediti', 'fondo' => 'Fondo', 'nomina' => 'Nomina o revoca dell\'amministratore',
        'liti' => 'Liti', 'tabelle' => 'Tabelle millesimali', 'rettifica_tabelle' => 'Rettifica delle tabelle (art. 69 disp. att.)', 'riparto' => 'Criteri di riparto',
        'regolamento' => 'Regolamento', 'sito_internet' => 'Sito internet del condominio (art. 71-ter disp. att.)', 'riscaldamento' => 'Riscaldamento e condizionamento',
        'destinazione_uso' => 'Modifica della destinazione d\'uso', 'tutela_destinazione_uso' => 'Tutela della destinazione d\'uso (art. 1117-quater)',
        'comunicazione' => 'Comunicazione', 'delega' => 'Delega all\'amministratore', 'varie' => 'Varie ed eventuali', 'altro' => 'Altro',
    ];

    /** Le parole delle maggioranze (le stesse di {@see Maggioranza}). */
    private const MAGGIORANZE = [
        'ordinaria' => 'Ordinaria', 'meta_del_valore' => 'Metà del valore', 'due_terzi' => 'Due terzi del valore', 'quattro_quinti' => 'Quattro quinti',
        'unanimita' => 'Unanimità', 'libera' => 'Un\'altra maggioranza', 'senza_voto' => 'Senza voto',
    ];

    /** L'assemblea viene dall'indirizzo della pagina (§13, rilievo 23). */
    public const ESCLUSI = ['assemblea_id'];

    /** Da quale sospeso nasce: lo scrive il programma (§5). */
    public const DEL_PROGRAMMA = ['fonte', 'chiave_fonte'];

    public const DATI_DELLA_PERSONA = ['testo', 'testo_pubblico'];

    /** «Punto 4 — Sostituzione del portone d'ingresso». */
    public function nome(Model $modello): string
    {
        $grezzi = $modello->getAttributes();
        $testo = trim(preg_replace('/\s+/u', ' ', (string) ($grezzi['testo'] ?? '')) ?? '');
        if (mb_strlen($testo) > 80) {
            $testo = rtrim(mb_substr($testo, 0, 79)) . '…';
        }

        return 'Punto ' . (int) ($grezzi['numero'] ?? 0) . ($testo !== '' ? ' — ' . $testo : '');
    }

    /** Un punto nato da un memo, tolto dall'ordine del giorno: il memo torna nella memoria (§6.3). */
    public function riepilogo(Model $modello, string $azione): ?string
    {
        return $azione === 'eliminata' && ($modello->getAttributes()['fonte'] ?? null) === 'memo' ? 'Torna nella memoria del condominio.' : null;
    }

    public function condomini(Model $modello): array
    {
        return AssembleeNelRegistroComune::condominiDellAssemblea($modello->getAttribute('assemblea_id'));
    }

    public function scheda(Model $modello): array
    {
        return [AssembleaNelRegistro::TIPO, (int) $modello->getAttribute('assemblea_id')];
    }

    /** Le persone fra i suoi oggetti (§6.2, «Le persone»). */
    public function soggetti(Model $modello): array
    {
        if ($modello->getKey() === null) {
            return [];
        }

        return DB::table('assemblee_punti_oggetti')->where('punto_id', $modello->getKey())->where('oggetto_tipo', 'anagrafica')
            ->pluck('oggetto_id')->map(fn ($id) => ['anagrafica', (int) $id])->values()->all();
    }
}

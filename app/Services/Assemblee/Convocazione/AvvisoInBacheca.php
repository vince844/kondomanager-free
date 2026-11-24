<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Comunicazione;
use App\Services\Assemblee\UnitaDelPunto;
use App\Services\Gestionale\Lettere\TestiLettere;

/**
 * L'avviso dell'assemblea in bacheca (decisione 492 (3), §4.6 del progetto della tappa 2): la pagina della nuova comunicazione si apre già scritta, con le date,
 * il luogo e **la frase pubblica** di ogni punto (D5: nessun dato di una persona; la frase specifica sta solo nell'avviso per persona), e con «Questo avviso
 * non vale come convocazione». La comunicazione si salva per la sua strada (moderazione, registro, avvisi): qui solo il testo proposto e il ritorno, che lega
 * la comunicazione all'assemblea (`assemblee.comunicazione_id`).
 *
 * **Il testo del punto non va mai in bacheca**: la leggono anche gli inquilini e il portale (Cass. 29323/2022). Un punto senza la frase per la bacheca ha
 * una riga neutra con la sua materia («6. Una questione legale del condominio»), e la frase `manca_la_frase` dice a quale punto manca.
 */
final class AvvisoInBacheca
{
    public const NON_VALE = 'Questo avviso non vale come convocazione: la convocazione arriva a ognuno per raccomandata, PEC o a mano.';

    /** La riga neutra di un punto senza la frase per la bacheca: la sua materia, senza nomi. */
    private const NEUTRE = [
        'rendiconto' => 'Il rendiconto',
        'preventivo' => 'Il preventivo',
        'lavori' => 'Lavori nelle parti comuni',
        'innovazioni' => 'Innovazioni nelle parti comuni',
        'innovazioni_agevolate' => 'Innovazioni agevolate nelle parti comuni',
        'impianti_singoli' => 'Le modalità per gli impianti dei singoli condòmini',
        'videosorveglianza' => 'La videosorveglianza delle parti comuni',
        'progetti_territorio' => 'Un progetto del territorio che riguarda l\'edificio',
        'ratifica' => 'Ratifica di una spesa',
        'comunicazione_urgenza' => 'Comunicazione di lavori urgenti',
        'dispensa' => 'Dispensa dall\'agire entro sei mesi per la riscossione dei crediti',
        'accordo' => 'Un accordo di rientro',
        'regole' => 'Regole del recupero dei crediti',
        'fondo' => 'Un fondo del condominio',
        'nomina' => 'Nomina o revoca dell\'amministratore',
        'liti' => 'Una questione legale del condominio',
        'tabelle' => 'Tabelle millesimali',
        'rettifica_tabelle' => 'Rettifica delle tabelle millesimali',
        'riparto' => 'Criteri di riparto delle spese',
        'regolamento' => 'Il regolamento di condominio',
        'sito_internet' => 'Il sito internet del condominio',
        'riscaldamento' => 'Riscaldamento e condizionamento',
        'destinazione_uso' => 'Modifica della destinazione d\'uso di parti comuni',
        'tutela_destinazione_uso' => 'L\'uso delle parti comuni secondo la loro destinazione',
        'comunicazione' => 'Una comunicazione dell\'amministratore',
        'delega' => 'Una delega all\'amministratore',
        'varie' => 'Varie ed eventuali',
        'altro' => 'Un altro argomento',
    ];

    public function __construct(private readonly EmissioneDelleConvocazioni $emissione) {}

    /**
     * Il testo proposto per la pagina della nuova comunicazione, e la frase dei punti senza la frase per la bacheca (null se tutti l'hanno).
     *
     * @return array{subject: string, description: string, condomini_ids: list<int>, assemblea_id: int, manca_la_frase: ?string}
     */
    public function precompilata(Assemblea $assemblea): array
    {
        $avviso = $this->emissione->avviso($assemblea, ['nome' => '', 'indirizzo' => '', 'unita' => [], 'punti' => null]);
        $assemblea->loadMissing(['punti.scala', 'punti.palazzina', 'punti.tabella', 'condominio', 'tabella']);
        // L'assemblea dei condòmini di un gruppo (Coda 734, scena 8 della bozza): la prima riga dice il gruppo.
        $delGruppo = UnitaDelPunto::gruppoDellAssemblea($assemblea) !== null ? 'della tabella «' . ($assemblea->tabella?->nome ?? '') . '» ' : '';
        $righe = ['È convocata l\'assemblea ' . mb_strtolower($assemblea->tipo?->parola() ?? 'ordinaria') . ' dei condòmini ' . $delGruppo . 'del '
            . TestiLettere::condominioConNome((string) $assemblea->condominio->nome) . ':'];
        foreach ($avviso['sedute'] as $s) {
            $righe[] = '— ' . $s['nome'] . ': ' . $s['quando'] . (filled($s['dove'] ?? null) ? ', ' . $s['dove'] : '');
        }
        $righe[] = '';
        $righe[] = 'Ordine del giorno:';
        foreach ($assemblea->punti as $p) {
            $frase = trim((string) $p->testo_pubblico);
            if ($frase === '') {
                $frase = self::rigaNeutra($p);
            }
            // Chi decide il punto della sola scala o palazzina, e quello del gruppo di una tabella (Coda 734): «(decidono i condòmini della tabella «Box»)»,
            // come la riga sotto il punto nell'avviso.
            $gruppo = UnitaDelPunto::gruppoProprio($p, $assemblea);
            $parte = ($p->ambito === AmbitoAssemblea::SCALA && $p->scala !== null) || ($p->ambito === AmbitoAssemblea::PALAZZINA && $p->palazzina !== null)
                ? UnitaDelPunto::parteDi($p) : null;
            if ($parte !== null || $gruppo !== null) {
                $frase .= ' (decidono ' . UnitaDelPunto::condomini($parte, $gruppo) . ')';
            }
            $righe[] = $p->numero . '. ' . $frase;
        }
        $righe[] = '';
        $righe[] = self::NON_VALE;

        return [
            'subject' => 'Assemblea ' . mb_strtolower($assemblea->nome()),
            'description' => implode("\n", $righe),
            'condomini_ids' => [(int) $assemblea->condominio_id],
            'assemblea_id' => (int) $assemblea->id,
            'manca_la_frase' => self::mancaLaFrase($assemblea),
        ];
    }

    /**
     * «Al punto 6 manca la frase per la bacheca: il programma scrive solo «Una questione legale del condominio».»; con più punti una riga sola. Null se ogni
     * punto ha la sua frase.
     */
    public static function mancaLaFrase(Assemblea $assemblea): ?string
    {
        $senza = $assemblea->punti()->orderBy('numero')->get()->filter(fn (PuntoDellOrdineDelGiorno $p) => trim((string) $p->testo_pubblico) === '')->values();
        if ($senza->isEmpty()) {
            return null;
        }
        if ($senza->count() === 1) {
            return 'Al punto ' . $senza[0]->numero . ' manca la frase per la bacheca: il programma scrive solo «' . self::rigaNeutra($senza[0]) . '».';
        }

        return 'Ai punti ' . ConvocatiDellAssemblea::elenco($senza->map(fn ($p) => (string) $p->numero)->all())
            . ' manca la frase per la bacheca: il programma scrive solo la materia di ogni punto.';
    }

    public static function rigaNeutra(PuntoDellOrdineDelGiorno $punto): string
    {
        return self::NEUTRE[$punto->materia?->value ?? 'altro'] ?? self::NEUTRE['altro'];
    }

    /** Il ritorno: la comunicazione salvata si lega all'assemblea del suo condominio, se non ha già un avviso. */
    public function collega(Comunicazione $comunicazione, int $assembleaId): void
    {
        $assemblea = Assemblea::query()->find($assembleaId);
        if ($assemblea === null || $assemblea->comunicazione_id !== null
            || ! $comunicazione->condomini()->where('condomini.id', $assemblea->condominio_id)->exists()) {
            return;
        }
        $assemblea->update(['comunicazione_id' => $comunicazione->id]);
    }
}

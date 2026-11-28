<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Models\Gestionale\Conto;
use App\Services\Riparto\SpeseNegativeNelPiano;
use Illuminate\Support\Facades\DB;

/**
 * Le parole del blocco dei piani approvati su una voce del piano dei conti (`Conto::pianiCheBloccanoLaVoce`): chi la tiene, in quale piano, e la
 * strada che dalla pagina si fa davvero, cioè riportare prima il piano in bozza dalla sua pagina, con quello che costa.
 *
 * Riportare un piano in bozza azzera il numero del verbale e la nota dell'approvazione; con delle rate emesse la pagina tiene spento l'interruttore
 * finché le emissioni non sono annullate, e l'annullamento di un'emissione rifiuta una rata con un incasso o un credito usato o rimborsato, e la rata
 * emessa prima di un passaggio di titolarità registrato prima della 1.11.0-beta.42 che ha preso il piano nel conguaglio (`EmissioneRateController::destroy`).
 * Un passaggio di prima della 1.11.0-beta.37 dal programma non si annulla (`AnnullaPassaggioAction::motivoBlocco`): lì la bozza non è una strada, e la
 * frase dice che cosa resta.
 *
 * Le usano il rifiuto della modifica e dell'eliminazione della voce, della voce nuova sotto un capitolo tenuto e delle tabelle della voce (`ContoController`,
 * `CreateContoRequest`, i controller delle tabelle), la scheda della voce (`ContoResource`) e le frasi della voce di spesa scritta con il meno
 * (`VoceDiSpesaNegativa`). La pagina del piano dei conti compone le stesse parole con i collegamenti ai piani (`ContiNew.vue`).
 *
 * Qui anche la frase dell'ultima voce di un piano (`ultimaVoceDelPiano`), che vale per ogni piano, in bozza o approvato: la dicono il cestino del piano
 * e, dalla Coda 489, l'eliminazione della voce dal piano dei conti.
 */
final class BloccoDellaVoce
{
    /** Che cosa di una voce che un piano approvato tiene non si cambia: quello che il motore legge per ripartirla. */
    public const COSA_NON_SI_CAMBIA = 'l\'importo, il padre, il tipo, la tabella e le percentuali';

    /**
     * Il rifiuto della modifica di una voce che un piano approvato tiene, o del suo spostamento sotto un capitolo che un piano approvato tiene: lo
     * stesso per l'importo, il padre, il tipo, la tabella e le percentuali; per il capitolo che torna voce dice che non torna voce.
     *
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti?: list<string>}>  $tengonoLaVoce  `Conto::pianiCheBloccanoLaVoce` della voce
     * @param  string|null  $capitolo  il capitolo padre nuovo, se un piano che blocca lo tiene
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti?: list<string>}>  $tengonoIlCapitolo  `Conto::pianiCheBloccanoLaVoce` del capitolo
     * @param  bool  $tornaVoce  la modifica porta un capitolo a essere una voce
     */
    public static function rifiutoDellaModifica(array $tengonoLaVoce, ?string $capitolo = null, array $tengonoIlCapitolo = [], bool $tornaVoce = false): string
    {
        $premesse = [];
        if ($tengonoLaVoce !== []) {
            $premesse[] = $tornaVoce
                ? 'il capitolo non torna una voce finché ' . self::chi($tengonoLaVoce, 'il capitolo', false) . ' ' . self::nelPiano($tengonoLaVoce)
                : self::COSA_NON_SI_CAMBIA . ' della voce non si cambiano finché ' . self::chi($tengonoLaVoce) . ' ' . self::nelPiano($tengonoLaVoce);
        }
        if ($capitolo !== null && $tengonoIlCapitolo !== []) {
            $premesse[] = "la voce non si sposta sotto «{$capitolo}» finché il capitolo è " . self::nelPiano($tengonoIlCapitolo);
        }

        return self::frase('Modifica inibita: ' . implode(', e ', $premesse), array_merge($tengonoLaVoce, $tengonoIlCapitolo), 'modifica la voce', 'la voce resta com\'è');
    }

    /**
     * Il rifiuto della modifica di una voce se un piano approvato la tiene (`Conto::pianiCheBloccanoLaVoce`), nullo se nessuno la tiene: lo usano le
     * richieste che cambiano la tabella o le percentuali di una voce senza passare dal suo salvataggio.
     */
    public static function rifiutoPerLaVoce(Conto $voce): ?string
    {
        $tengonoLaVoce = Conto::pianiCheBloccanoLaVoce([(int) $voce->id])[(int) $voce->id] ?? [];

        return $tengonoLaVoce === [] ? null : self::rifiutoDellaModifica($tengonoLaVoce);
    }

    /**
     * Il rifiuto di una voce nuova sotto un capitolo che un piano approvato tiene: il piano addebiterebbe anche lei.
     *
     * @param  string  $capitolo  il capitolo padre scelto
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti?: list<string>}>  $tengonoIlCapitolo  `Conto::pianiCheBloccanoLaVoce` del capitolo
     */
    public static function rifiutoDellaVoceNuova(string $capitolo, array $tengonoIlCapitolo): string
    {
        return self::frase("Modifica inibita: una voce nuova non si aggiunge sotto «{$capitolo}» finché il capitolo è " . self::nelPiano($tengonoIlCapitolo),
            $tengonoIlCapitolo, 'crea la voce', 'il capitolo resta com\'è');
    }

    /**
     * Il rifiuto dell'eliminazione di una voce che un piano approvato tiene.
     *
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti?: list<string>}>  $tengonoLaVoce  `Conto::pianiCheBloccanoLaVoce`
     */
    public static function rifiutoDellEliminazione(array $tengonoLaVoce): string
    {
        return self::frase('Eliminazione inibita: la voce non si elimina finché ' . self::chi($tengonoLaVoce, 'la voce', false) . ' ' . self::nelPiano($tengonoLaVoce),
            $tengonoLaVoce, 'elimina la voce', 'la voce resta');
    }

    /**
     * Il rifiuto di lasciare un piano rate senza voci (Coda 438, decisione 192): un piano senza voci, al ricalcolo, prende tutte le voci che nessun altro
     * piano del suo esercizio ha già, senza impegnarle. Lo dicono il cestino del piano (`PianoRateController::detachCapitolo`, «Impossibile togliere la
     * voce») e, dalla Coda 489 (decisione 685 (3)), l'eliminazione dal piano dei conti della voce che è l'ultima riga di un piano, anche in bozza
     * (`ContoController::destroy`, «Eliminazione inibita»).
     *
     * @param  string  $impossibile  l'apertura della frase
     * @param  string  $poi  che cosa fare dopo aver aggiunto l'altra voce: «e poi togli questa», «e poi togli questa dal piano ed eliminala»
     */
    public static function ultimaVoceDelPiano(string $voce, string $piano, bool $approvato, string $impossibile = 'Impossibile togliere la voce', string $poi = 'e poi togli questa'): string
    {
        return sprintf(
            '%s: «%s» è l\'ultima voce del piano rate «%s». Un piano senza voci, al ricalcolo, prende tutte le voci della gestione '
            . 'che nessun altro piano del suo esercizio ha già. Se deve chiedere un\'altra voce, aggiungila prima con «Sincronizza», se è libera, %s; '
            . 'se il piano non serve più, %seliminalo dall\'elenco dei piani rate.',
            $impossibile, $voce, $piano, $poi, $approvato ? 'riportalo in bozza dalla sua pagina ed ' : ''
        );
    }

    /**
     * Il rifiuto dell'eliminazione di una voce con delle righe di fattura: la fattura le tiene, e riportare un piano in bozza non le toglie.
     */
    public static function rifiutoDelleRigheDiFattura(int $righe): string
    {
        return 'Eliminazione inibita: la voce ha ' . ($righe === 1 ? '1 riga di fattura registrata' : "{$righe} righe di fattura registrate")
            . ', e una voce con delle righe di fattura non si elimina: la voce resta.';
    }

    /**
     * I movimenti a giornale che tengono le voci: le scritture con una riga sulla voce, finché non sono annullate. Annullata vuol dire nello stato
     * «annullata» (l'incasso stornato, con le sue compensazioni) o con una figlia che la storna (`TipoMovimentoContabile::storniDiScrittura`). Le
     * scritture di annullamento non contano: gli storni dell'elenco e la rettifica che storna un incasso (una `rettifica` con il padre annullato).
     * Una coppia stornata si annulla, e senza la voce non cambia niente. Elenco esplicito, non «comincia per storno»: la compensazione con il
     * credito (`storno_credito`) è una figlia viva dell'incasso, e la rettifica di un riallineamento dei crediti è figlia di una compensazione viva.
     * Eliminata la voce, la riga del giornale resta senza voce (`righe_scritture.voce_spesa_id` va a null): la spesa resterebbe nei costi e
     * uscirebbe dal consuntivo per voce e dal riparto.
     *
     * @param  list<int>  $vociIds
     * @return array{voci: int, movimenti: int}
     */
    public static function movimentiAGiornale(array $vociIds): array
    {
        if ($vociIds === []) {
            return ['voci' => 0, 'movimenti' => 0];
        }

        $storni = TipoMovimentoContabile::storniDiScrittura();
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as s', 's.id', '=', 'rs.scrittura_id')
            ->whereIn('rs.voce_spesa_id', $vociIds)
            ->whereNull('s.deleted_at')
            ->where('s.stato', '!=', 'annullata')
            ->where(fn ($q) => $q->whereNull('s.tipo_movimento')->orWhereNotIn('s.tipo_movimento', $storni))
            ->whereNot(fn ($q) => $q->where('s.tipo_movimento', TipoMovimentoContabile::RETTIFICA->value)
                ->whereExists(fn ($p) => $p->selectRaw('1')->from('scritture_contabili as p')->whereColumn('p.id', 's.scrittura_padre_id')->where('p.stato', 'annullata')))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('scritture_contabili as st')
                ->whereColumn('st.scrittura_padre_id', 's.id')->whereIn('st.tipo_movimento', $storni)->whereNull('st.deleted_at'));

        return [
            'voci' => (clone $righe)->distinct()->count('rs.voce_spesa_id'),
            'movimenti' => (clone $righe)->distinct()->count('s.id'),
        ];
    }

    /**
     * Il rifiuto di una voce sotto un capitolo dell'altro tipo: un'entrata va sotto un capitolo di entrata, una spesa sotto uno di spesa. La frase dice
     * la strada che passa: cambiare il tipo del capitolo dalla sua scheda, se si cambia (nessun piano approvato lo tiene e non ha voci del suo tipo,
     * che finirebbero sotto un capitolo dell'altro), o creare la voce fuori dal capitolo, che passa sempre.
     *
     * @param  string  $tipo  il tipo della voce nuova
     * @param  string  $capitolo  il nome del capitolo scelto
     * @param  bool  $tipoDelCapitoloSiCambia  il tipo del capitolo si può portare a quello della voce
     */
    public static function rifiutoDelTipoSottoIlCapitolo(string $tipo, string $capitolo, bool $tipoDelCapitoloSiCambia): string
    {
        [$della, $delCapitolo, $etichetta] = Conto::segnoDelTipo($tipo) === 1 ? ['spesa', 'entrata', 'Spesa (uscita)'] : ['entrata', 'spesa', 'Entrata'];

        return "Una voce di {$della} va sotto un capitolo di {$della}, e «{$capitolo}» è un capitolo di {$delCapitolo}: "
            . ($tipoDelCapitoloSiCambia ? "cambia il tipo del capitolo in «{$etichetta}» con «Modifica» dalla sua scheda, o crea la voce fuori dal capitolo." : 'crea la voce fuori dal capitolo.');
    }

    /** Il rifiuto dell'eliminazione di una voce con dei movimenti a giornale. */
    public static function rifiutoDeiMovimentiAGiornale(int $movimenti): string
    {
        return 'Eliminazione inibita: la voce ha ' . self::movimenti($movimenti) . ': ' . ($movimenti === 1 ? 'stornalo' : 'stornali')
            . ' prima, o lascia la voce.';
    }

    /** Il rifiuto dell'eliminazione del piano dei conti quando delle sue voci hanno dei movimenti a giornale. */
    public static function rifiutoDelPianoDeiContiPerIMovimenti(int $voci, int $movimenti): string
    {
        return 'Eliminazione inibita: ' . ($voci === 1 ? '1 voce del piano dei conti ha ' : "{$voci} voci del piano dei conti hanno ")
            . self::movimenti($movimenti) . ': ' . ($movimenti === 1 ? 'stornalo' : 'stornali') . ' prima, o lascia il piano dei conti.';
    }

    private static function movimenti(int $n): string
    {
        return $n === 1 ? '1 movimento a giornale' : "{$n} movimenti a giornale";
    }

    /**
     * Il rifiuto dell'eliminazione del piano dei conti quando delle sue voci hanno righe di fattura.
     */
    public static function rifiutoDelPianoDeiContiPerLeRighe(int $voci): string
    {
        return 'Eliminazione inibita: ' . ($voci === 1 ? '1 voce del piano dei conti ha' : "{$voci} voci del piano dei conti hanno")
            . ' righe di fattura registrate, e una voce con delle righe di fattura non si elimina: il piano dei conti resta.';
    }

    /**
     * Che cosa dice la scheda della voce che un piano approvato tiene: le parole del rifiuto della modifica, senza «Modifica inibita».
     *
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti?: list<string>}>  $tengonoLaVoce  `Conto::pianiCheBloccanoLaVoce`
     */
    public static function dellaScheda(array $tengonoLaVoce): string
    {
        return self::frase(ucfirst(self::COSA_NON_SI_CAMBIA) . ' della voce non si cambiano finché ' . self::chi($tengonoLaVoce) . ' ' . self::nelPiano($tengonoLaVoce),
            $tengonoLaVoce, 'modifica la voce', 'la voce resta com\'è');
    }

    /**
     * Chi il piano tiene: «la voce è», «il suo capitolo «Manutenzione» è», «la voce e il suo capitolo «Manutenzione» sono», «i suoi capitoli
     * «Impianti» e «Manutenzione» sono».
     *
     * @param  list<array{capitolo: ?string}>  $bloccano  `Conto::pianiCheBloccanoLaVoce`
     * @param  string  $lei  come si chiama la voce: «la voce», «il sottoconto»
     * @param  bool  $conSoggetto  falso se la frase ha già detto di chi parla: «è», «essa e il suo capitolo «Manutenzione» sono»
     */
    public static function chi(array $bloccano, string $lei = 'la voce', bool $conSoggetto = true): string
    {
        $capitoli = array_values(array_unique(array_filter(array_column($bloccano, 'capitolo'), fn ($c) => $c !== null)));
        $dellaVoce = array_filter($bloccano, fn (array $p) => $p['capitolo'] === null) !== [];
        $suoi = match (count($capitoli)) {
            0 => null,
            1 => "il suo capitolo «{$capitoli[0]}»",
            default => 'i suoi capitoli ' . SpeseNegativeNelPiano::elenco(array_map(fn (string $c) => "«{$c}»", $capitoli)),
        };

        return match (true) {
            $suoi === null => $conSoggetto ? "{$lei} è" : 'è',
            $dellaVoce => ($conSoggetto ? $lei : (str_starts_with($lei, 'la ') ? 'essa' : 'esso')) . " e {$suoi} sono",
            default => $suoi . (count($capitoli) === 1 ? ' è' : ' sono'),
        };
    }

    /**
     * «nel piano «Ordinario 2026», approvato», «nei piani «A» e «B», approvati»; un piano delle fatture che tiene una voce a € 0 anche in bozza (Coda 742):
     * «nel piano «Tetto a rate», in bozza», «nei piani «A», approvato, e «B», in bozza».
     *
     * @param  list<array{id: int, nome: string, bozza?: bool}>  $bloccano
     */
    public static function nelPiano(array $bloccano): string
    {
        $piani = self::piani($bloccano);
        $inBozza = array_filter($piani, fn (array $p) => (bool) ($p['bozza'] ?? false));
        if (count($piani) === 1) {
            return "nel piano «{$piani[0]['nome']}», " . ($inBozza === [] ? 'approvato' : 'in bozza');
        }
        if ($inBozza === [] || count($inBozza) === count($piani)) {
            return 'nei piani ' . self::nomi($piani) . ($inBozza === [] ? ', approvati' : ', in bozza');
        }

        return 'nei piani ' . SpeseNegativeNelPiano::elenco(array_map(fn (array $p) => "«{$p['nome']}», " . (($p['bozza'] ?? false) ? 'in bozza' : 'approvato'), $piani));
    }

    /**
     * La frase intera: la premessa, poi la strada della bozza e quello che costa; se un passaggio di prima della 1.11.0-beta.37 ferma l'annullamento
     * delle emissioni di uno dei piani, al posto della strada che cosa resta.
     *
     * @param  string  $premessa  «… non si cambiano finché il suo capitolo «Manutenzione» è nel piano «Ordinario 2026», approvato»
     * @param  list<array{id: int, nome: string, movimenti?: list<string>}>  $bloccano
     * @param  string  $poi  che cosa fare dopo la bozza: «modifica la voce»
     * @param  string  $resta  che cosa resta se la bozza non è una strada: «la voce resta com'è»
     */
    public static function frase(string $premessa, array $bloccano, string $poi, string $resta): string
    {
        if (array_filter($bloccano, fn (array $p) => ($p['fattura'] ?? null) !== null) !== []) {
            return self::fraseDelPianoDelleFatture($premessa, $bloccano, $poi, $resta);
        }
        $piani = self::piani($bloccano);
        $uno = count($piani) === 1;
        $fermi = self::fermi($bloccano);
        if ($fermi !== []) {
            return $premessa . ', e un passaggio di titolarità registrato da una versione più vecchia, che dal programma non si annulla, ferma l\'annullamento delle emissioni '
                . (count($fermi) < count($piani) ? 'di ' . self::nomi($fermi) : ($uno ? 'del piano' : 'dei piani')) . ": {$resta}.";
        }

        return $premessa . ': ' . ($uno ? 'riporta prima il piano in bozza dalla sua pagina' : 'riporta prima i piani in bozza dalle loro pagine') . ", poi {$poi}. "
            . ($uno ? 'Riportandolo' : 'Riportandoli') . ' in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, '
            . self::primaDelleEmissioni($piani) . '.';
    }

    /**
     * Coda 742: la voce vale € 0 nel preventivo e un piano delle fatture la mette a rate. Riportare il piano in bozza non la libera (la voce a € 0 resta ferma
     * anche in bozza): la strada è togliere la fattura dal piano, e in un piano delle fatture una fattura sola non si stacca, quindi si elimina il piano
     * (dalla bozza, annullando prima le emissioni se ce ne sono), come per lo storno della fattura (`FatturaPassiva::viaDalPiano`). I piani che tengono la
     * voce in un altro modo (con la sua riga o quella di un capitolo) dicono la strada di sempre.
     *
     * @param  list<array{id: int, nome: string, fattura?: ?string, bozza?: bool, movimenti?: list<string>}>  $bloccano
     */
    private static function fraseDelPianoDelleFatture(string $premessa, array $bloccano, string $poi, string $resta): string
    {
        $diFatture = [];
        foreach ($bloccano as $p) {
            if (($p['fattura'] ?? null) !== null) {
                $diFatture[$p['id']] ??= $p;
            }
        }
        $diFatture = array_values($diFatture);
        $altri = array_values(array_filter(self::piani($bloccano), fn (array $p) => ! in_array($p['id'], array_column($diFatture, 'id'), true)));
        $fermi = self::fermi($diFatture);
        $numeri = array_values(array_unique(array_map(fn (array $p) => 'n. ' . $p['fattura'], $diFatture)));
        $uno = count($diFatture) === 1;
        $frase = $premessa . ': la voce vale € 0 nel preventivo e la sua spesa è a rate ' . ($uno ? 'in quel piano' : 'in quei piani') . ' con '
            . (count($numeri) === 1 ? 'la fattura ' : 'le fatture ') . SpeseNegativeNelPiano::elenco($numeri) . ': cambiata, il preventivo potrebbe chiederla una seconda volta. ';
        if ($fermi !== []) {
            return $frase . 'Un passaggio di titolarità registrato da una versione più vecchia, che dal programma non si annulla, ferma l\'annullamento delle '
                . 'emissioni di ' . self::nomi($fermi) . ": {$resta}.";
        }
        $tutteInBozza = array_filter($diFatture, fn (array $p) => ! ($p['bozza'] ?? false)) === [];
        $frase .= 'Togli prima la fattura dal piano: in un piano delle fatture una fattura sola non si stacca, quindi ' . ($tutteInBozza
            ? ($uno ? 'elimina il piano dalla sua pagina' : 'elimina i piani dalle loro pagine')
            : ($uno ? 'riporta il piano in bozza dalla sua pagina ed eliminalo' : 'riporta i piani in bozza dalle loro pagine ed eliminali')
                . ' (con delle rate emesse, ' . self::primaDelleEmissioni($diFatture) . ')');
        if ($altri !== []) {
            $frase .= '; riporta in bozza anche ' . self::nomi($altri) . ' dalla ' . (count($altri) === 1 ? 'sua pagina' : 'loro pagine');
        }

        return $frase . ", poi {$poi}.";
    }

    /**
     * I piani che hanno un passaggio di prima della 1.11.0-beta.37 dopo l'emissione: dal programma non si riportano in bozza.
     *
     * @param  list<array{id: int, nome: string, movimenti?: list<string>}>  $bloccano
     * @return list<array{id: int, nome: string, movimenti?: list<string>}>
     */
    public static function fermi(array $bloccano): array
    {
        return array_values(array_filter(self::piani($bloccano), fn (array $p) => in_array('passaggio_senza_registro', $p['movimenti'] ?? [], true)));
    }

    /**
     * Un piano per id, nell'ordine dato.
     *
     * @param  list<array{id: int, nome: string}>  $bloccano
     * @return list<array{id: int, nome: string}>
     */
    public static function piani(array $bloccano): array
    {
        return array_values(array_column($bloccano, null, 'id'));
    }

    /**
     * Che cosa annullare per riportare in bozza un piano con delle rate emesse: i movimenti che l'annullamento di un'emissione rifiuta e i passaggi
     * registrati dopo l'emissione, da registrare di nuovo dopo la correzione; si nominano i piani che li hanno, se non li hanno tutti. Un passaggio
     * seguito da altre rate emesse chiede di annullare prima quelle (`AnnullaPassaggioAction`), e la frase lo dice come lo dice `destroy`.
     *
     * @param  list<array{id: int, nome: string, movimenti?: list<string>}>  $piani  un piano per id
     */
    private static function primaDelleEmissioni(array $piani): string
    {
        $con = fn (array $tipi) => array_values(array_filter($piani, fn (array $p) => array_intersect($p['movimenti'] ?? [], $tipi) !== []));
        $conMovimenti = $con(['incasso', 'credito']);
        $conPassaggi = $con(['passaggio']);
        if ($conMovimenti === [] && $conPassaggi === []) {
            return 'prima vanno annullate le emissioni';
        }

        $movimenti = null;
        if ($conMovimenti !== []) {
            $tipi = array_merge(...array_map(fn (array $p) => $p['movimenti'], $conMovimenti));
            $cosa = implode(' e ', array_values(array_filter([
                in_array('incasso', $tipi, true) ? 'gli incassi registrati' : null,
                in_array('credito', $tipi, true) ? 'i crediti usati o rimborsati' : null,
            ])));
            $movimenti = $cosa . ' ' . (count($conMovimenti) === count($piani) ? 'sulle rate' : 'sulle rate di ' . self::nomi($conMovimenti));
        }
        $passaggi = $conPassaggi === [] ? null : 'ciascuno dallo storico della sua unità, i passaggi di titolarità registrati dopo l\'emissione'
            . (count($conPassaggi) === count($piani) ? '' : ' delle rate di ' . self::nomi($conPassaggi));
        $cosaAnnullare = match (true) {
            $movimenti === null => ", {$passaggi}",
            $passaggi === null => " {$movimenti}",
            default => " {$movimenti} e, {$passaggi}",
        };

        return "prima vanno annullati{$cosaAnnullare} (da registrare di nuovo dopo), poi le emissioni"
            . ($passaggi === null ? '' : '; se dopo un passaggio sono state emesse altre rate, il suo annullamento chiede di annullare prima quelle');
    }

    /** ««A» e «B»», ««A», «B» e «C»». @param list<array{nome: string}> $piani */
    private static function nomi(array $piani): string
    {
        return SpeseNegativeNelPiano::elenco(array_map(fn (array $p) => "«{$p['nome']}»", $piani));
    }
}

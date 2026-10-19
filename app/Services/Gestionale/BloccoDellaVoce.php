<?php

namespace App\Services\Gestionale;

use App\Models\Gestionale\Conto;
use App\Services\Riparto\SpeseNegativeNelPiano;

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
     * Il rifiuto dell'eliminazione di una voce con delle righe di fattura: la fattura le tiene, e riportare un piano in bozza non le toglie.
     */
    public static function rifiutoDelleRigheDiFattura(int $righe): string
    {
        return 'Eliminazione inibita: la voce ha ' . ($righe === 1 ? '1 riga di fattura registrata' : "{$righe} righe di fattura registrate")
            . ', e una voce con delle righe di fattura non si elimina: la voce resta.';
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
     * «nel piano «Ordinario 2026», approvato», «nei piani «A» e «B», approvati».
     *
     * @param  list<array{id: int, nome: string}>  $bloccano
     */
    public static function nelPiano(array $bloccano): string
    {
        $piani = self::piani($bloccano);

        return count($piani) === 1 ? "nel piano «{$piani[0]['nome']}», approvato" : 'nei piani ' . self::nomi($piani) . ', approvati';
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
        $piani = self::piani($bloccano);
        $uno = count($piani) === 1;
        $fermi = self::fermi($bloccano);
        if ($fermi !== []) {
            return $premessa . ', e un passaggio di titolarità registrato prima della 1.11.0-beta.37, che dal programma non si annulla, ferma l\'annullamento delle emissioni '
                . (count($fermi) < count($piani) ? 'di ' . self::nomi($fermi) : ($uno ? 'del piano' : 'dei piani')) . ": {$resta}.";
        }

        return $premessa . ': ' . ($uno ? 'riporta prima il piano in bozza dalla sua pagina' : 'riporta prima i piani in bozza dalle loro pagine') . ", poi {$poi}. "
            . ($uno ? 'Riportandolo' : 'Riportandoli') . ' in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, '
            . self::primaDelleEmissioni($piani) . '.';
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

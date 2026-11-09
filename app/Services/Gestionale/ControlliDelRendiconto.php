<?php

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Esercizio;

/**
 * I controlli del rendiconto dell'esercizio, tutti insieme, e il loro esito in una riga: i sei della situazione patrimoniale
 * ({@see StatoPatrimonialePaginaService}), le registrazioni oltre il confine dell'esercizio ({@see AnnoACavallo}), «i costi tornano con il riepilogo»
 * (la riga che riconcilia del riepilogo per competenza, {@see RiepilogoPerCompetenza}), «i crediti tornano con le posizioni» (la riga dei crediti del
 * § 9, {@see PosizioniDeiCondomini}) e le fatture con voci di un'altra gestione (la lista della situazione per gestione).
 *
 * NESSUNA CIFRA PROPRIA. Ogni numero viene dalla scheda che lo mostra: qui si confronta, si etichetta e si conta. L'esito dice quanti tornano, quanti no
 * e quanti sono da leggere, e sotto una riga fissa dice che cosa i controlli non verificano. Il programma non dice mai che il rendiconto va bene.
 */
final class ControlliDelRendiconto
{
    public const RIGA_FISSA = 'I controlli verificano che i numeri del programma tornino fra loro. Non verificano il saldo con l\'estratto conto della banca, né che ogni fattura e ogni entrata sia stata registrata.';

    /** Dopo la riga fissa, per chi legge il foglio: che cosa vogliono dire i due esiti che non sono «torna». */
    public const RIGA_ESITI = '«Non torna»: due numeri del programma non coincidono, e la riga del controllo dice perché; non è la prova di un ammanco. '
        .'«Da leggere»: il programma non può decidere da solo.';

    public function __construct(
        private readonly StatoPatrimonialePaginaService $pagina,
        private readonly RiepilogoPerCompetenza $riepilogo,
        private readonly PosizioniDeiCondomini $posizioni,
        private readonly AnnoACavallo $annoACavallo,
        private readonly CommissioniSenzaVoce $commissioni,
    ) {}

    /**
     * Chi ha già letto la situazione o il riepilogo li passa, così non si leggono due volte nella stessa richiesta.
     *
     * @param  array{verso?:?string, tipo?:?string, colore?:?string}  $filtri  i filtri dell'elenco dell'anno a cavallo
     * @param  array{pagina?:int, per_pagina?:int}  $pagine  la pagina dell'elenco dell'anno a cavallo; senza, la prima
     * @return array{controlli: list<array<string,mixed>>, esito: array<string,mixed>, anno_a_cavallo: array<string,mixed>, pagina: array<string,mixed>, riepilogo: array<string,mixed>, conteggi: array{posizioni:int, rate_non_pagate:int}}
     */
    public function perEsercizio(Condominio $condominio, Esercizio $esercizio, ?string $oggi = null, array $filtri = [], ?array $pagina = null, ?array $riepilogo = null, array $pagine = []): array
    {
        $oggi = $oggi ?? DateHelper::oggiUtente();
        $pagina ??= $this->pagina->costruisci($condominio, $esercizio, $oggi);
        $riepilogo ??= $this->riepilogo->perEsercizio($condominio, $esercizio, $oggi);
        $posizioni = $this->posizioni->posizioni($condominio, $esercizio, $oggi);
        $anno = $this->annoACavallo->perEsercizio($condominio, $esercizio, $filtri, $pagine['pagina'] ?? 1, $pagine['per_pagina'] ?? AnnoACavallo::RIGHE_ELENCO);

        // Gli storni senza voce di registrazioni di un esercizio chiuso: le righe e quante si assegnano le dice l'elenco, con la sua regola. Si guarda se
        // ci sono righe, non la loro somma: due storni che si annullano ci sono lo stesso.
        $storni = VoceNelloStorno::righeSenzaVoce((int) $esercizio->id)->exists()
            ? self::storniDellElenco($this->commissioni->perEsercizio($condominio, $esercizio)) : null;
        $controlli = [...$pagina['controlli'], $this->annoACavallo($anno), self::costi($riepilogo['riconciliazione'], $storni),
            self::crediti($posizioni['riga_crediti'], $posizioni['riga_anticipi']), $this->gestioni($pagina['per_gestione']['fatture_altra_gestione'])];

        return ['controlli' => $controlli, 'esito' => self::esito($controlli), 'anno_a_cavallo' => $anno, 'pagina' => $pagina, 'riepilogo' => $riepilogo,
            // Le righe delle due stampe dei condòmini, dalla stessa lettura: il fascicolo le conta prima di stampare (il tempo e la memoria del totale).
            'conteggi' => ['posizioni' => count($posizioni['righe']), 'rate_non_pagate' => $posizioni['rate_non_pagate']]];
    }

    /**
     * «N tornano, M non tornano, K da leggere», con la riga fissa e una riga per controllo.
     *
     * @param  list<array<string,mixed>>  $controlli
     */
    public static function esito(array $controlli): array
    {
        $conta = fn (string $e) => count(array_filter($controlli, fn ($c) => $c['esito'] === $e));
        $tornano = $conta('quadra');
        $non = $conta('non_quadra');
        $leggere = $conta('segnalazione');

        return [
            'tornano' => $tornano,
            'non_tornano' => $non,
            'da_leggere' => $leggere,
            'frase' => $tornano.($tornano === 1 ? ' torna' : ' tornano').', '.$non.($non === 1 ? ' non torna' : ' non tornano').', '.$leggere.' da leggere',
            'riga_fissa' => self::RIGA_FISSA,
            'riga_esiti' => self::RIGA_ESITI,
            'righe' => array_map(fn ($c) => ['id' => $c['id'], 'nome' => $c['nome'], 'esito' => $c['esito'], 'breve' => $c['breve'] ?? null], $controlli),
        ];
    }

    private static function euro(int $c): string
    {
        return '€ '.number_format($c / 100, 2, ',', '.');
    }

    /**
     * Le registrazioni fuori da ogni esercizio, quando prevalgono su quelle nel periodo: i due numeri e le due cause possibili. La stessa frase sta in R2.
     *
     * @param  array{fuori:int, nel_periodo:int}  $f
     */
    public static function fraseFuoriDaOgniEsercizio(array $f): string
    {
        return 'Scritte in questo esercizio con una data che non sta in nessun esercizio del condominio: '.$f['fuori'].'; con la data nel suo periodo: '.$f['nel_periodo']
            .' (le aperture non contano; le fatture registrate come pregresse non contano fra quelle fuori). Le cause possibili sono due: le date dell\'esercizio sono sbagliate, oppure sono '
            .'registrazioni di un esercizio che nel programma non c\'è.';
    }

    /** Le registrazioni oltre il confine: un conteggio, rosso se una è in rosso (cambia la competenza; una fattura registrata come pregressa solo vista dall'esercizio della sua data), da leggere se sposta solo denaro. */
    private function annoACavallo(array $a): array
    {
        $fuori = $a['fuori_da_ogni_esercizio']['prevale'];
        $effetti = [$a['effetto']['A']['frase'], $a['effetto']['B']['frase']];
        $nota = match (true) {
            $fuori => implode(' ', array_filter([self::fraseFuoriDaOgniEsercizio($a['fuori_da_ogni_esercizio']), ...$effetti])),
            $a['numero'] === 0 => 'Nessuna registrazione ha la data del fatto in un esercizio e sta in un altro.',
            default => implode(' ', array_filter([
                'Scritte in questo esercizio con la data fuori dal suo periodo: '.$a['versi']['A']['numero'].' ('.$a['versi']['A']['rosse'].' in rosso). '
                    .'Con la data di questo esercizio e scritte in un altro: '.$a['versi']['B']['numero'].' ('.$a['versi']['B']['rosse'].' in rosso).',
                ...$effetti,
            ])),
        };

        return [
            'id' => 'AC',
            'nome' => 'Registrazioni oltre il confine dell\'esercizio',
            'titolo' => 'Registrazioni con la data del fatto in un esercizio e scritte in un altro, nei due versi',
            'sinistra' => $a['numero'],
            'destra' => 0,
            'esito' => $fuori || $a['rosse'] > 0 ? 'non_quadra' : ($a['numero'] > 0 ? 'segnalazione' : 'quadra'),
            'nota' => $nota,
            'rimedio' => $a['rimedi'] === [] ? null : implode(' ', $a['rimedi']),
            'azione' => $fuori ? 'esercizio' : ($a['numero'] > 0 ? 'anno-a-cavallo' : null),
        ];
    }

    /**
     * Gli storni senza voce di registrazioni di un esercizio chiuso nell'elenco delle commissioni e degli storni senza voce ({@see CommissioniSenzaVoce}):
     * quante righe, quante si assegnano, e la loro somma senza il segno.
     *
     * @param  array{commissioni: list<array<string, mixed>>, storni_assegnabili: array{numero: int, importo: int}}  $elenco
     * @return array{righe: int, assegnabili: int, senza_segno: int}
     */
    public static function storniDellElenco(array $elenco): array
    {
        $righe = array_filter($elenco['commissioni'], fn ($c) => $c['natura'] === 'registrazione');

        return ['righe' => count($righe), 'assegnabili' => (int) $elenco['storni_assegnabili']['numero'],
            'senza_segno' => (int) array_sum(array_map(fn ($c) => abs((int) $c['importo']), $righe))];
    }

    /**
     * Le righe degli storni e la loro somma senza il segno, quando la somma con il segno non le dice: storni di segno diverso (lo storno di una
     * regolazione toglie un costo, quello di un incasso da terzi lo aggiunge), che si possono anche annullare. Altrimenti niente.
     *
     * @param  array{righe: int, assegnabili: int, senza_segno: int}  $storni
     */
    public static function righeESommaSenzaSegno(array $storni, int $somma): ?string
    {
        return $storni['senza_segno'] !== abs($somma) ? $storni['righe'].' righe, '.self::euro($storni['senza_segno']).' senza il segno' : null;
    }

    /**
     * I costi dell'esercizio a giornale = spese per capitolo meno entrate + commissioni senza voce + fatture contestate (la riga che riconcilia del riepilogo),
     * più gli storni senza voce di registrazioni di un esercizio chiuso, quando ci sono righe (anche se la loro somma è zero): fra i costi non classificati,
     * e fuori dal riparto finché non prendono una voce. Le righe e quante si assegnano le dice l'elenco delle commissioni e degli storni senza voce
     * (`$storni`, {@see storniDellElenco()}, decisione 255, D1); chi non lo passa ha la frase senza il perché, se la somma non è zero. Gli altri costi non
     * classificati sono costi a cui manca la voce: non tornano.
     *
     * @param  array{righe: int, assegnabili: int, senza_segno: int}|null  $storni
     */
    public static function costi(array $r, ?array $storni = null): array
    {
        $somma = (int) ($r['storni_senza_voce'] ?? 0);
        $destra = $r['capitoli_netti'] + $r['commissioni_senza_voce'] + $r['contestate'] + $somma;
        $torna = $r['costi'] === $destra;
        $assegnabili = $storni['assegnabili'] ?? 0;
        $altri = ($storni['righe'] ?? 0) - $assegnabili;
        $perche = match (true) {
            $storni === null => 'fra i costi non classificati',
            $assegnabili > 0 && $altri > 0 => 'fra i costi non classificati, e fuori dal riparto finché non prendono una voce: '
                .($assegnabili === 1 ? '1 si assegna' : $assegnabili.' si assegnano').' e '.$altri.' no, e l\'elenco delle commissioni e degli storni senza voce dice perché',
            $assegnabili > 0 => 'fra i costi non classificati, e fuori dal riparto finché non prendono una voce: '
                .($assegnabili === 1 ? '1 si assegna' : $assegnabili.' si assegnano').' nell\'elenco delle commissioni e degli storni senza voce',
            default => 'fra i costi non classificati, e fuori dal riparto: qui non si assegnano, e l\'elenco delle commissioni e degli storni senza voce dice perché',
        };
        $righe = $storni === null ? null : self::righeESommaSenzaSegno($storni, $somma);
        $eq = 'Costi dell\'esercizio '.self::euro($r['costi']).($torna ? ' = ' : ' ≠ ').'spese per capitolo meno entrate '.self::euro($r['capitoli_netti'])
            .' + commissioni senza voce '.self::euro($r['commissioni_senza_voce']).' + fatture contestate '.self::euro($r['contestate'])
            .($somma !== 0 || ($storni['righe'] ?? 0) > 0
                ? ' + storni di registrazioni di un esercizio chiuso, senza voce, '.self::euro($somma).' ('.($righe === null ? '' : $righe.'; ').$perche.')' : '').'.';
        $nonClassificati = $r['non_classificati'] - $somma;
        $resto = array_filter([
            $nonClassificati !== 0 ? 'costi non classificati '.self::euro($nonClassificati).' (righe di costo senza voce che non sono commissioni)' : null,
            $r['differenza'] !== 0 ? self::euro($r['differenza']).' di spese del giornale che non stanno in nessuna riga del riepilogo' : null,
        ]);

        return [
            'id' => 'CR',
            'nome' => 'I costi tornano con il riepilogo',
            'titolo' => 'Costi dell\'esercizio = spese per capitolo meno entrate + commissioni senza voce + fatture contestate (+ gli storni senza voce di un esercizio chiuso, se ci sono)',
            'sinistra' => $r['costi'],
            'destra' => $destra,
            'esito' => $torna ? 'quadra' : 'non_quadra',
            'nota' => $torna ? $eq : $eq.' La differenza: '.implode('; ', $resto).'.',
            'rimedio' => $torna ? null
                : 'Nel riepilogo finanziario la riga «Costi non classificati» dice quante scritture hanno un costo senza voce: apri le scritture nel Libro '
                    .'Giornale; la voce si dà stornando la registrazione e rifacendola con la voce. Una spesa che non sta in nessuna riga è su una voce di una '
                    .'gestione non agganciata a questo esercizio.',
            'azione' => $torna ? null : 'riepilogo',
        ];
    }

    /**
     * I crediti verso i condòmini = posizioni senza gli anticipi + spese anticipate per singole unità; gli anticipi ricevuti = il loro conto (le due righe
     * del § 9). Le due letture vengono dallo stesso giornale con lo stesso taglio: una differenza dice che una delle due non legge più come l'altra.
     */
    public static function crediti(array $c, array $a): array
    {
        $torna = $c['differenza'] === 0 && $a['differenza'] === 0;
        $eq = 'Crediti verso i condòmini '.self::euro($c['crediti']).($c['differenza'] === 0 ? ' = ' : ' ≠ ').'posizioni senza gli anticipi '.self::euro($c['posizioni'])
            .' + spese anticipate per singole unità '.self::euro($c['spese_anticipate']).'. Anticipi ricevuti '.self::euro($a['anticipi'])
            .($a['differenza'] === 0 ? ' = ' : ' ≠ ').'conto «Anticipi ricevuti dai condòmini» '.self::euro($a['conto']).'.';
        $differenze = array_filter([
            $c['differenza'] !== 0 ? 'differenza dei crediti '.self::euro($c['differenza']) : null,
            $a['differenza'] !== 0 ? 'differenza degli anticipi '.self::euro($a['differenza']) : null,
        ]);

        return [
            'id' => 'CP',
            'nome' => 'I crediti tornano con le posizioni',
            'titolo' => 'Crediti verso i condòmini = posizioni dei condòmini senza gli anticipi + spese anticipate per singole unità',
            'sinistra' => $c['crediti'],
            'destra' => $c['posizioni'] + $c['spese_anticipate'],
            'esito' => $torna ? 'quadra' : 'non_quadra',
            'nota' => $torna ? $eq : $eq.' '.ucfirst(implode('; ', $differenze)).'.',
            'rimedio' => $torna ? null
                : 'Le posizioni e la situazione leggono lo stesso giornale alla stessa data: una differenza è una riga del conto dei condòmini che la lettura per '
                    .'persona non riconosce. Apri il mastrino dei crediti verso i condòmini dalla situazione patrimoniale e cerca la riga senza persona o senza rata.',
            'azione' => $torna ? null : 'condomini',
        ];
    }

    /** Le fatture dell'esercizio con voci di una gestione diversa da quella scelta: si segnalano, non si rifiutano. */
    private function gestioni(array $fatture): array
    {
        $n = count($fatture);

        return [
            'id' => 'GE',
            'nome' => 'Voci e gestione delle fatture',
            'titolo' => 'Le voci di ogni fattura sono della gestione scelta per la fattura',
            'sinistra' => $n,
            'destra' => 0,
            'esito' => $n === 0 ? 'quadra' : 'segnalazione',
            'nota' => $n === 0 ? 'Ogni fattura dell\'esercizio ha le voci della gestione scelta.'
                : ($n === 1 ? 'Una fattura ha' : $n.' fatture hanno').' voci di una gestione diversa da quella scelta ('.implode(', ', $fatture).'): il costo sta nel blocco '
                    .'della voce, il debito e il pagato nel blocco della gestione della fattura.',
            'rimedio' => $n === 0 ? null : 'Se la gestione scelta per la fattura è sbagliata, correggila dalla fattura; se è voluto, non c\'è niente da fare.',
            'azione' => null,
        ];
    }
}

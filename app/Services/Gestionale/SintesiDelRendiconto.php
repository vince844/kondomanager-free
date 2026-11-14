<?php

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use Carbon\CarbonImmutable;

/**
 * La sintesi del rendiconto dell'esercizio: lo stato in una riga, l'esito dei controlli, quattro riquadri (il deliberato e il preventivo, i costi
 * dell'esercizio con la loro percentuale e la parte per capitolo, le quote pagate sulle emesse, la liquidità secondo la contabilità), le fasce delle
 * spese che non stanno in nessun capitolo e il prospetto della gestione con il risultato e i suoi due termini. Un array solo, per la pagina e per il
 * frontespizio del fascicolo.
 *
 * UNA FONTE PER NUMERO. Il deliberato, le quote, i costi, il risultato e le fasce sono quelli del riepilogo finanziario ({@see RiepilogoPerCompetenza});
 * la liquidità quella della situazione patrimoniale ({@see StatoPatrimonialePaginaService}); l'esito quello dei controlli ({@see ControlliDelRendiconto}),
 * letti una volta sola nella stessa richiesta. Qui non si somma niente che la scheda di provenienza non mostri già, tranne il preventivo netto (le
 * spese meno le entrate dei totali del riepilogo) e la percentuale.
 *
 * IL RIQUADRO DEL CONGUAGLIO. Le cifre del condominio della scheda «Conguaglio», dalla stessa lettura ({@see ConguaglioDelRendiconto}): fatta qui,
 * passata ai controlli, mai rifatta nella stessa richiesta. Sopra la soglia di peso ({@see ConguaglioDelRendiconto::SOGLIA_RIQUADRO}) la lettura qui
 * non si fa, e il riquadro dice di aprire la scheda.
 */
final class SintesiDelRendiconto
{
    public const FRASE_LIQUIDITA = 'Secondo la contabilità: non è il saldo dell\'estratto conto della banca, e il confronto è un controllo tuo.';

    public function __construct(private readonly ControlliDelRendiconto $controlli, private readonly CommissioniSenzaVoce $commissioni,
        private readonly ConguaglioDelRendiconto $conguaglio) {}

    /**
     * Chi ha già letto i controlli (il fascicolo) li passa, così non si leggono due volte nella stessa richiesta; la lettura del conguaglio la passa
     * con loro, o da sola.
     *
     * @return array<string,mixed>
     */
    public function perEsercizio(Condominio $condominio, Esercizio $esercizio, ?string $oggi = null, ?array $controlli = null, ?array $conguaglio = null): array
    {
        $oggi = $oggi ?? DateHelper::oggiUtente();
        $conguaglio ??= $controlli['conguaglio'] ?? null;
        $peso = null;
        // Chi passa i controlli senza la lettura (il frontespizio del fascicolo) non mostra il riquadro: la lettura qui non si fa.
        if ($conguaglio === null && $controlli === null) {
            $peso = $this->conguaglio->peso($esercizio);
            $conguaglio = $peso <= ConguaglioDelRendiconto::soglia() ? $this->conguaglio->leggi($esercizio) : null;
        }
        $c = $controlli ?? $this->controlli->perEsercizio($condominio, $esercizio, $oggi, [], null, null, [], $conguaglio);
        $r = $c['riepilogo'];
        $p = $c['pagina'];
        $q = $r['quote']['totale'];

        $preventivo = $this->preventivoNetto($r['blocchi']);
        $deliberato = $q['deliberate'];
        // Il preventivo netto è delle sole gestioni ordinarie (una straordinaria ha solo il deliberato); il deliberato e i costi sono di tutte. La
        // percentuale sta sul deliberato; sul preventivo solo quando non ci sono gestioni straordinarie, che i costi comprenderebbero e lui no.
        $soloOrdinarie = collect($r['blocchi'])->contains(fn ($b) => $b['gestione']['tipo'] === 'straordinaria');
        // La percentuale dei costi sta sul deliberato delle voci: le spese per singole unità chieste con le rate non sono costi, e non la abbassano.
        $base = $q['deliberate_voci'] ?? ($deliberato !== null ? $deliberato : ($soloOrdinarie ? null : $preventivo));
        $costi = $r['risultato']['costi'];
        $fuori = $r['fuori_capitoli'];
        $fasce = [];
        $storni = (int) ($r['riconciliazione']['storni_senza_voce'] ?? 0);
        // Gli storni senza voce di registrazioni di un esercizio chiuso: la fascia c'è quando ci sono righe, non quando la somma non è zero (due storni
        // che si annullano ci sono lo stesso).
        $conStorni = VoceNelloStorno::righeSenzaVoce((int) $esercizio->id)->exists();
        // L'elenco delle commissioni e degli storni senza voce, letto una volta se una delle due fasce lo chiede.
        $elenco = $fuori['commissioni_senza_voce']['consuntivo'] !== 0 || $conStorni ? $this->commissioni->perEsercizio($condominio, $esercizio) : null;
        if ($fuori['commissioni_senza_voce']['consuntivo'] !== 0) {
            // Quante se ne possono assegnare lo dice l'elenco, con la sua regola ({@see CommissioniSenzaVoce}): senza nessuna, la fascia non invita.
            $daAssegnare = $elenco['assegnabili']['numero'];
            $importo = self::euro($fuori['commissioni_senza_voce']['consuntivo']);
            $fasce[] = ['tipo' => 'commissioni', 'importo' => $fuori['commissioni_senza_voce']['consuntivo'], 'numero' => $fuori['commissioni_senza_voce']['numero'],
                'da_assegnare' => $daAssegnare,
                'frase' => $daAssegnare > 0
                    ? $importo.' di commissioni senza voce: non stanno in nessun capitolo, e il conguaglio non le divide.'
                    : $importo.' di commissioni senza voce, che qui non si assegnano: non stanno in nessun capitolo, e il conguaglio non le divide. L\'elenco dice perché.'];
        }
        if ($conStorni) {
            // Gli storni senza voce di registrazioni di un esercizio chiuso (decisione 255, D1): stanno fra i costi non classificati, e si assegnano nello
            // stesso elenco, con la sua regola. Con segni diversi la frase dice anche le righe e la somma senza il segno.
            $dellElenco = ControlliDelRendiconto::storniDellElenco($elenco);
            $daAssegnare = $dellElenco['assegnabili'];
            $quanti = ControlliDelRendiconto::righeESommaSenzaSegno($dellElenco, $storni);
            $chi = self::euro($storni).' di storni di registrazioni di un esercizio chiuso, senza voce'.($quanti === null ? '' : ' ('.$quanti.')');
            $fasce[] = ['tipo' => 'storni', 'importo' => $storni, 'numero' => $dellElenco['righe'], 'da_assegnare' => $daAssegnare,
                'frase' => $daAssegnare > 0
                    ? $chi.': non stanno in nessun capitolo, e il conguaglio non li divide.'
                    : $chi.', che qui non si assegnano: non stanno in nessun capitolo, e il conguaglio non li divide. L\'elenco dice perché.'];
        }
        if ($fuori['contestate']['consuntivo'] !== 0) {
            $fasce[] = ['tipo' => 'contestate', 'importo' => $fuori['contestate']['consuntivo'], 'numero' => $fuori['contestate']['numero'],
                'frase' => self::euro($fuori['contestate']['consuntivo']).' di fatture contestate: stanno fuori dai capitoli e dentro i debiti e il risultato. Se una è dovuta, stornala e registrala di nuovo come approvata: così passa nel suo capitolo.'];
        }

        return [
            'esercizio' => $r['esercizio'],
            'stato' => [
                'finito' => $r['finito'],
                'alla' => $r['alla'],
                'frase' => $r['finito']
                    ? 'Esercizio finito al '.CarbonImmutable::parse($r['alla'])->format('d/m/Y').': sono alla data di fine la situazione patrimoniale, le fatture aperte, il pagato '
                        .'e le quote dei piani; costi, quote registrate, risultato ed entrate e uscite per cassa contano le registrazioni dell\'esercizio, anche con la data dopo la fine; '
                        .'i vincoli dei fondi sono a oggi.'
                    : 'Esercizio aperto: i numeri sono a oggi e cambiano a ogni registrazione.',
                // La stessa cosa per chi legge il frontespizio: la data in una frase, e quello che è di oggi.
                'frase_stampa' => $r['finito']
                    ? 'Le cifre sono quelle dell\'esercizio finito il '.CarbonImmutable::parse($r['alla'])->format('d/m/Y').'. I vincoli dei fondi sono invece di oggi.'
                    : 'Esercizio aperto: le cifre sono a oggi e cambiano a ogni registrazione.',
            ],
            'esito' => $c['esito'],
            'riquadri' => [
                'deliberato' => ['deliberato' => $deliberato, 'preventivo' => $preventivo, 'solo_ordinarie' => $soloOrdinarie,
                    'ricostruito' => $r['avvertenze']['deliberato_ricostruito'] !== null],
                'costi' => [
                    'costi' => $costi,
                    'per_capitolo' => $r['riconciliazione']['capitoli_netti'],
                    'permille' => self::permille($costi, $base),
                    // La base detta con il suo nome: il deliberato delle voci quando le spese personali chieste con le rate lo fanno diverso dal deliberato
                    // con le rate (il riquadro accanto), e la sua cifra.
                    'base' => $base === null ? null : ($deliberato === null ? 'preventivo' : ($base !== $deliberato ? 'deliberato_voci' : 'deliberato')),
                    'base_importo' => $base,
                ],
                'quote' => ['pagate' => $q['pagate'], 'emesse' => $q['emesse']],
                'liquidita' => ['liquidita' => $p['sintesi']['liquidita'], 'alla' => $p['data'], 'frase' => self::FRASE_LIQUIDITA],
                'conguaglio' => $this->conguaglio->riquadro($esercizio, $conguaglio, $peso),
            ],
            'fasce' => $fasce,
            'prospetto' => [
                'preventivo' => $preventivo,
                'preventivo_solo_ordinarie' => $soloOrdinarie,
                'deliberato' => $deliberato,
                // Perché il deliberato non c'è, se c'è una gestione con i piani di prima del riparto: la frase del riepilogo, una sola.
                'avvertenza_deliberato' => $r['avvertenze']['deliberato'],
                // Quali piani, rifatti con i dati di oggi, non tornano: per la pagina (il frontespizio non li nomina).
                'avvertenza_deliberato_piani' => $r['avvertenze']['deliberato_piani'],
                // Coda 557: il deliberato dei piani di prima ricostruito, con la sua frase.
                'avvertenza_deliberato_ricostruito' => $r['avvertenze']['deliberato_ricostruito'],
                'quote_piani' => $q['emesse'],
                'quote_pagate' => $q['pagate'],
                'quote_non_pagate' => $q['non_pagate'],
                'costi' => $costi,
                'quote_giornale' => $r['risultato']['quote_emesse'],
                'risultato' => $r['risultato']['risultato'],
                'raccordo_quote' => $r['raccordo_quote'],
                'in_fondi' => $p['sintesi']['in_fondi'],
                'spese' => self::speseLegate($r),
            ],
        ];
    }

    /**
     * Le tre cifre delle spese che il fascicolo dice in tre posti, legate in una frase per chi legge il frontespizio: le spese dei capitoli del
     * riepilogo, meno le entrate da terzi, sono quelle da dividere fra i condòmini; con le spese non ancora divise (fuori dai capitoli) sono i costi
     * dell'esercizio. Nulla quando non c'è niente da legare, o quando le cifre non si sommano (la differenza della riconciliazione).
     */
    public static function speseLegate(array $riepilogo): ?string
    {
        $spese = (int) array_sum(array_map(fn ($b) => (int) ($b['totale_spese']['consuntivo'] ?? 0), $riepilogo['blocchi']));
        // Le entrate da terzi nette, come le conta la riconciliazione: l'incassato meno quello che sulle stesse voci è un costo.
        $entrate = (int) array_sum(array_map(fn ($b) => (int) ($b['totale_entrate']['incassato'] ?? 0) - (int) ($b['totale_entrate']['consuntivo'] ?? 0), $riepilogo['blocchi']));
        $netti = (int) $riepilogo['riconciliazione']['capitoli_netti'];
        $nd = ParoleDelConguaglio::nonDivise(0, array_map(fn ($f) => (int) ($f['consuntivo'] ?? 0), array_intersect_key($riepilogo['fuori_capitoli'], ParoleDelConguaglio::NON_DIVISE)));
        $costi = (int) $riepilogo['risultato']['costi'];
        if ($spese - $entrate !== $netti || $netti + $nd['importo'] !== $costi || ($entrate === 0 && $nd['importo'] === 0)) {
            return null;
        }

        return 'Spese del riepilogo finanziario '.self::euro($spese).' − entrate da terzi '.self::euro($entrate).' = '.self::euro($netti).' da dividere fra i condòmini'
            .($nd['importo'] === 0
                ? ': sono i costi dell\'esercizio.'
                : '; con '.self::euro($nd['importo']).' di spese non ancora divise ('.implode(', ', $nd['cosa']).') i costi sono '.self::euro($costi).'.');
    }

    /**
     * I controlli detti a chi legge il frontespizio: quanti tornano e quanti no, chi li spiega, che cosa non sono. Senza «ammanco» e senza le righe dei
     * controlli, che sono nella loro pagina.
     *
     * @param  array{tornano:int, non_tornano:int, da_leggere:int, non_calcolati:int}  $esito
     */
    public static function controlliPerChiLegge(array $esito): string
    {
        $pezzi = [$esito['tornano'].($esito['tornano'] === 1 ? ' torna' : ' tornano')];
        if ($esito['non_tornano'] > 0) {
            $pezzi[] = $esito['non_tornano'].($esito['non_tornano'] === 1 ? ' non torna' : ' non tornano');
        }
        if ($esito['da_leggere'] > 0) {
            $pezzi[] = $esito['da_leggere'].' da leggere';
        }
        $daSpiegare = $esito['non_tornano'] + $esito['da_leggere'];
        $frase = 'I controlli: '.implode(', ', $pezzi)
            .($daSpiegare === 0 ? '' : ' (l\'amministratore '.($daSpiegare === 1 ? 'lo' : 'li').' spiegherà in assemblea)');
        if ($esito['non_calcolati'] > 0) {
            $frase .= '; '.$esito['non_calcolati'].($esito['non_calcolati'] === 1 ? ' non è calcolato: legge' : ' non sono calcolati: leggono').' il conguaglio, che non è in questo fascicolo';
        }

        return $frase.'. Sono quadrature interne dei numeri; non sostituiscono il confronto con l\'estratto conto della banca.';
    }

    /** La percentuale in millesimi interi, arrotondata al più vicino: 4.131,00 su 4.110,00 → 1005 (100,5 %); 2 su 3 → 667. Senza una base, nessuna. */
    public static function permille(int $valore, ?int $base): ?int
    {
        if ($base === null || $base <= 0) {
            return null;
        }

        return intdiv(2 * $valore * 1000 + ($valore < 0 ? -$base : $base), 2 * $base);
    }

    /** Il preventivo netto: le spese meno le entrate dei totali del riepilogo; null se una gestione non lo conserva. */
    private function preventivoNetto(array $blocchi): ?int
    {
        $totale = 0;
        foreach ($blocchi as $b) {
            if ($b['gestione']['tipo'] === 'straordinaria') {
                continue;
            }
            if ($b['spese'] !== [] && $b['totale_spese']['preventivo'] === null) {
                return null;
            }
            $totale += (int) $b['totale_spese']['preventivo'] - (int) $b['totale_entrate']['preventivo'];
        }

        return $totale;
    }

    private static function euro(int $c): string
    {
        return '€ '.number_format($c / 100, 2, ',', '.');
    }
}

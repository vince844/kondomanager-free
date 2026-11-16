<?php

namespace App\Services\Gestionale\Debiti;

use App\Services\Gestionale\Accessori\SuggerimentiDelRecupero;
use App\Services\Gestionale\Lettere\TestiLettere;
use Carbon\CarbonImmutable;

/**
 * La regola del passo proposto per una persona, una sola per il riquadro dei sei mesi e per la tesoreria: riceve i dati della persona e il giorno di oggi e
 * rende il caso, il passo, la frase a video e i passi possibili. È una funzione pura: non legge il database e non scrive niente. Il passo è un suggerimento,
 * mai un'azione: la regola non manda lettere e non dice mai che una persona «deve» o che l'amministratore è indietro; dice a che punto è e propone.
 *
 * Che cosa riceve (`$p`):
 * - `rate`: le rate aperte (scadute, non versate, non coperte da «hai agito»), ognuna con `chiave` («rata|unità»), `residuo`, `scadenza`, `giorni_ritardo`,
 *   `data` (la più vicina dei sei mesi, o null), `dispensa` (la dispensa in vigore sulla data scelta: la rata ha tutte le date coperte), `senza_data` (perché
 *   la data manca), `in_accordo` e `fuori_dalle_lettere` (la parte in un accordo di rientro in corso);
 * - `agito`: le righe di «hai agito» ancora valide (il giorno, la nota, chi), per R0; `tornati`: le frasi di chi torna dopo «hai agito» (R1);
 * - `credito`: il credito compensabile **nella stessa gestione** (`CreditoService`, `compensabile`);
 * - `accordi`: i segni degli accordi in corso ({@see AccordiRientro::segni()});
 * - `lettere`: l'ultima lettera di ogni tipo (`RegistroLettere::ultimePerPersone`, `per_tipo`), con il termine e le rate che chiedeva;
 * - `fascicolo`: il giorno del fascicolo per il legale preparato, se c'è; `tolleranza`: i giorni di tolleranza del condominio, se le regole li dicono;
 * - `salita`: che cosa fa il condominio dopo una lettera, quando scadono rate nuove (le impostazioni del condominio): {@see SALITA_AVANTI}, di serie, o
 *   {@see SALITA_RICOMINCIA}.
 *
 * I casi, in ordine (vale il primo che va bene): R0 «hai agito» su tutto; la dispensa su tutte le rate (nel riquadro nessun passo; in tesoreria toglie i passi
 * verso il legale, e con la scelta della dispensa anche il sollecito); R1 chi torna (una nota, e si va avanti); R2 il credito della stessa gestione che copre
 * tutto; R3 l'accordo in corso che copre tutto; R4 e R5 (note, e si va avanti); R6 il fascicolo dopo l'ultima lettera; R7 la lettera stampata da spedire; R8,
 * R9, R9b, R10 la diffida o messa in mora; R11 la data dei sei mesi entro 60 giorni o passata; R12, R13 il sollecito; R14 il sollecito per email non partito o
 * in coda; R15 il resto, con la tolleranza. Senza la data dei sei mesi la persona va come una data lontana, e la frase dice perché manca.
 *
 * Le rate scadute dopo una lettera (quelle che la lettera non chiedeva). Con «La salita va avanti» non riportano indietro il passo: la diffida resta il suo caso
 * (R8, R9, R9b; il termine passato propone il fascicolo) e il sollecito il suo (R12, R13; il termine passato propone la diffida), con una nota delle rate dopo.
 * Con «Per le rate nuove si ricomincia» riportano alla lettera di prima (R10 la diffida nuova, R15 il sollecito nuovo). In tutti e due i casi le rate ancora
 * nella tolleranza del condominio non tolgono alla lettera il suo caso, e una nota lo dice; e nel sollecito nuovo (R15) la tolleranza si guarda sulle sole rate
 * che il sollecito di prima non chiedeva.
 *
 * Il termine di una lettera: con la forma «giorni» si conta dalla spedizione registrata (o dall'invio per email), perché il programma non sa il giorno del
 * ricevimento; con la forma «data» è la data scritta; con «nessuno» non c'è. È passato dal giorno dopo: il giorno stesso no.
 */
final class PassoProposto
{
    public const RIQUADRO = 'riquadro';
    public const TESORERIA = 'tesoreria';

    /** Dopo una lettera, quando scadono rate nuove: la salita va avanti (di serie). */
    public const SALITA_AVANTI = 'avanti';

    /** Dopo una lettera, quando scadono rate nuove: per le rate nuove si ricomincia. */
    public const SALITA_RICOMINCIA = 'ricomincia';

    public const SOLLECITO = 'sollecito';
    public const DIFFIDA = 'diffida';
    public const SPEDIZIONE = 'spedizione';
    public const FASCICOLO = 'fascicolo';
    public const ACCORDO = 'accordo';
    public const COMPENSA = 'compensa';
    public const AGITO = 'agito';

    /** I passi nell'ordine del menu. */
    public const ORDINE = [self::SOLLECITO, self::DIFFIDA, self::SPEDIZIONE, self::FASCICOLO, self::ACCORDO, self::COMPENSA, self::AGITO];

    /** Il nome di ogni passo, come sul pulsante. */
    public const NOMI = [
        self::SOLLECITO => 'Manda un sollecito',
        self::DIFFIDA => 'Manda la diffida',
        self::SPEDIZIONE => 'Registra la spedizione',
        self::FASCICOLO => 'Prepara il fascicolo per il legale',
        self::ACCORDO => 'Vedi l\'accordo',
        self::COMPENSA => 'Compensa',
        self::AGITO => 'Registra che hai agito',
    ];

    /** I giorni prima della data dei sei mesi da cui la data è «vicina». */
    public const GIORNI_VICINA = 60;

    public const FRASE_NESSUNA_LETTERA = 'Nessuna lettera.';

    /** I passi verso il legale, che la dispensa toglie sempre. */
    private const LEGALI = [self::DIFFIDA, self::FASCICOLO];

    /** Gli stati di una lettera arrivata: partita per email o spedita. */
    private const ARRIVATA = ['inviata', 'spedita'];

    /** Gli stati di una lettera uscita dal programma: stampata, partita o spedita. */
    private const USCITA = ['stampata', 'inviata', 'spedita'];

    /**
     * @param array<string, mixed> $p
     * `rata_compensa` è la prima rata che il credito della stessa gestione copre, ogni volta che «Compensa» è fra i possibili (anche quando non è il passo
     * proposto): l'incasso si apre con quella rata. `frase_dei_fatti` è la frase senza la proposta, per «Scelgo io il passo»: di solito è la frase; nella
     * tolleranza dice fin quando dura, non da quando si propone il sollecito.
     *
     * @return array{caso: string, passo: ?string, frase: string, frase_dei_fatti: string, note: list<string>, possibili: list<string>, rata_compensa: ?int, termine: ?string}
     */
    public static function per(array $p, string $oggi, string $vista = self::RIQUADRO): array
    {
        $rate = array_values($p['rate'] ?? []);
        $lettere = ((array) ($p['lettere'] ?? [])) + ['diffida' => null, 'sollecito' => null, 'promemoria' => null];
        $note = array_values($p['tornati'] ?? []);
        $possibili = self::possibili($p, $lettere, $vista);
        $rataCompensa = in_array(self::COMPENSA, $possibili, true) ? (int) $p['credito']['rate_coperte'][0]['rata_id'] : null;
        $esito = fn (string $caso, ?string $passo, string $frase, array $altro = []) => $altro + ['caso' => $caso, 'passo' => $passo, 'frase' => $frase,
            'frase_dei_fatti' => $frase, 'note' => $note, 'possibili' => $possibili, 'rata_compensa' => $rataCompensa, 'termine' => null];

        if ($rate === []) {
            $agito = collect($p['agito'] ?? [])->sortBy('giorno')->last();

            return $agito === null ? $esito('nessuno', null, '') : $esito('R0', null, self::fraseAgito($agito));
        }

        $dispense = array_map(fn ($r) => $r['dispensa'] ?? null, $rate);
        $dispensa = in_array(null, $dispense, true) ? null : $dispense[0];
        if ($dispensa !== null && $vista === self::RIQUADRO) {
            return $esito('dispensa', null, 'Dispensa dell\'assemblea del ' . self::giorno($dispensa['data_delibera'])
                . (filled($dispensa['numero_verbale'] ?? null) ? ' (verbale n. ' . $dispensa['numero_verbale'] . ')' : '') . ': per queste rate il programma non suggerisce azioni.');
        }

        $r = self::tabella($p, $rate, $lettere, $oggi, $note);
        $r = $esito($r['caso'], $r['passo'], $r['frase'], ['note' => $r['note'], 'rata_compensa' => $r['rata_compensa'] ?? $rataCompensa, 'termine' => $r['termine'] ?? null,
            'frase_dei_fatti' => $r['frase_dei_fatti'] ?? $r['frase']]);

        if ($dispensa !== null) {
            $quando = 'Dispensa dell\'assemblea del ' . self::giorno($dispensa['data_delibera']);
            if (($dispensa['togli_sollecito'] ?? false) && in_array($r['passo'], [self::SOLLECITO, ...self::LEGALI], true)) {
                $frase = $quando . ': niente solleciti, come hai scelto.';

                return ['passo' => null, 'frase' => $frase, 'frase_dei_fatti' => $frase] + $r;
            }
            if (in_array($r['passo'], self::LEGALI, true)) {
                $frase = $quando . ': niente diffida né fascicolo.';

                return ['passo' => null, 'frase' => $frase, 'frase_dei_fatti' => $frase] + $r;
            }
        }

        return $r;
    }

    /** I casi da R1 a R15, sulle rate aperte. */
    private static function tabella(array $p, array $rate, array $lettere, string $oggi, array $note): array
    {
        // Le note si aggiungono strada facendo (R2 su un'altra gestione, R4, R5): il caso le legge quando si chiude.
        $caso = function (string $caso, ?string $passo, string $frase, array $altro = []) use (&$note) {
            return $altro + ['caso' => $caso, 'passo' => $passo, 'frase' => $frase, 'note' => $note];
        };

        // R2: il credito della stessa gestione che copre tutte le rate aperte. Su un'altra gestione la frase del credito è una nota, e si va avanti.
        $credito = $p['credito'] ?? null;
        if ($credito !== null) {
            $coperte = collect($credito['rate_coperte'] ?? [])->filter(fn ($c) => $c['copre_tutto'] ?? false)->pluck('rata_id')->map(fn ($id) => (int) $id)->all();
            $rateAperte = array_unique(array_map(fn ($r) => (int) explode('|', (string) $r['chiave'])[0], $rate));
            if ($coperte !== [] && array_diff($rateAperte, $coperte) === []) {
                return $caso('R2', self::COMPENSA, (string) $credito['frase'], ['rata_compensa' => (int) $credito['rate_coperte'][0]['rata_id']]);
            }
            if (($credito['rate_coperte'] ?? []) === [] && ($credito['debito_altrove_cents'] ?? 0) > 0) {
                $note[] = (string) $credito['frase'];
            }
        }

        // R3, R4, R5: l'accordo di rientro in corso.
        $accordi = array_values($p['accordi'] ?? []);
        if ($accordi !== []) {
            $copreTutto = collect($rate)->every(fn ($r) => ($r['fuori_dalle_lettere'] ?? false) && (int) ($r['in_accordo'] ?? 0) >= (int) $r['residuo']);
            $inCorso = collect($accordi)->first(fn ($s) => ! ($s['fino_al_passato'] ?? false)) ?? $accordi[0];
            if ($copreTutto) {
                return $caso('R3', null, self::fraseAccordo($inCorso));
            }
            $oltre = array_filter($rate, fn ($r) => (int) ($r['in_accordo'] ?? 0) > 0 && ! ($r['fuori_dalle_lettere'] ?? false));
            $passato = collect($accordi)->first(fn ($s) => $s['fino_al_passato'] ?? false);
            if ($oltre !== [] && $passato !== null) {
                $note[] = 'L\'accordo arrivava fino al ' . self::giorno($passato['fino_al']) . ': delle sue rate restano ' . self::euro((int) array_sum(array_column($oltre, 'residuo'))) . '.';
            }
            $coperta = fn ($r) => ($r['fuori_dalle_lettere'] ?? false) ? min((int) ($r['in_accordo'] ?? 0), (int) $r['residuo']) : 0;
            if (collect($rate)->contains(fn ($r) => $coperta($r) > 0)) {
                $fuori = array_values(array_filter(array_map(fn ($r) => ['residuo' => (int) $r['residuo'] - $coperta($r)] + $r, $rate), fn ($r) => $r['residuo'] > 0));
                $note[] = 'Fuori dall\'accordo: ' . self::euro((int) array_sum(array_column($fuori, 'residuo'))) . '.';
                $rate = $fuori;
            }
        }

        // La data dei sei mesi più vicina fra le rate aperte non dispensate (se sono tutte dispensate, fra tutte: la tesoreria toglie poi i passi verso il
        // legale, come `ElencoRateScadute::piuVicinaDellaPersona`); senza, il perché.
        $libere = array_values(array_filter(array_column(array_filter($rate, fn ($r) => ($r['dispensa'] ?? null) === null), 'data')));
        $libere = $libere !== [] ? $libere : array_values(array_filter(array_column($rate, 'data')));
        $data = $libere === [] ? null : min($libere);
        $perche = $data !== null ? null : collect($rate)->pluck('senza_data')->filter()->first();
        $conPerche = fn (string $frase) => $perche === null ? $frase : $frase . ' ' . $perche;
        $vicina = $data !== null && CarbonImmutable::parse($oggi, 'UTC')->diffInDays(CarbonImmutable::parse($data, 'UTC'), false) <= self::GIORNI_VICINA;

        $diffida = $lettere['diffida'];
        $sollecito = $lettere['sollecito'];
        $promemoria = $lettere['promemoria'];

        // R6: il fascicolo preparato dopo l'ultima lettera.
        $fascicolo = $p['fascicolo'] ?? null;
        $ultimaData = collect([$diffida, $sollecito, $promemoria])->filter()->map(fn ($l) => self::giornoDellaLettera($l))->max();
        if ($fascicolo !== null && ($ultimaData === null || $fascicolo >= $ultimaData)) {
            return $caso('R6', self::AGITO, 'Fascicolo preparato il ' . self::giorno($fascicolo) . '.');
        }

        // R7: una diffida stampata e non spedita, o l'ultima lettera che è un sollecito stampato.
        $ultima = collect([$diffida, $sollecito])->filter()->sortBy(fn ($l) => [$l['data'], $l['id'] ?? 0])->last();
        $stampata = ($diffida['stato'] ?? null) === 'stampata' ? $diffida : (($ultima['stato'] ?? null) === 'stampata' ? $ultima : null);
        if ($stampata !== null) {
            return $caso('R7', self::SPEDIZIONE, self::nome($stampata['tipo']) . ' ' . self::participio($stampata['tipo'], 'stampat') . ' il ' . self::giorno($stampata['data'])
                . ': la spedizione non è registrata.');
        }

        $salita = ($p['salita'] ?? null) === self::SALITA_RICOMINCIA ? self::SALITA_RICOMINCIA : self::SALITA_AVANTI;
        $tolleranza = isset($p['tolleranza']) ? (int) $p['tolleranza'] : null;

        // R8, R9, R9b, R10: la diffida o messa in mora partita o spedita. Una lettera che non chiede più nessuna delle rate aperte (le sue sono state versate)
        // non ha un caso suo: le rate aperte sono tutte dopo.
        $diffidaArrivata = $diffida !== null && in_array($diffida['stato'], self::ARRIVATA, true);
        $dopo = $diffidaArrivata ? self::rateDopo($diffida, $rate, $tolleranza) : null;
        if ($diffidaArrivata && ($dopo['chiede'] || $dopo['oltre'] !== [])) {
            $comeLaDiffida = mb_strtolower(self::nome($diffida['tipo']));
            $fraseDopo = 'La ' . $comeLaDiffida . ' del ' . self::giorno(self::giornoDellaLettera($diffida)) . ' non comprende ';
            if ($dopo['oltre'] !== [] && ($salita === self::SALITA_RICOMINCIA || ! $dopo['chiede'])) {
                return $caso('R10', self::DIFFIDA, $fraseDopo . self::euro(self::somma($dopo['tutte'])) . ' di rate scadute dopo.');
            }
            if ($dopo['oltre'] !== []) {
                $note[] = $fraseDopo . self::euro(self::somma($dopo['oltre'])) . ' di rate scadute dopo.';
            }
            if ($dopo['dentro'] !== []) {
                $note[] = self::notaDellaTolleranza($dopo['dentro'], 'nella ' . $comeLaDiffida);
            }
            $t = self::termine($diffida);
            $come = self::nome($diffida['tipo']) . ' ' . self::comeArrivata($diffida) . ' il ' . self::giorno(self::giornoDellaLettera($diffida));
            if ($t['giorno'] === null) {
                return $caso('R9b', null, 'La ' . mb_strtolower(self::nome($diffida['tipo'])) . ' ' . self::comeArrivata($diffida) . ' il ' . self::giorno(self::giornoDellaLettera($diffida))
                    . ' non aveva un termine: quando preparare il fascicolo lo decidi tu.');
            }
            if ($oggi > $t['giorno']) {
                return $caso('R9', self::FASCICOLO, $come . ': il termine del ' . self::giorno($t['giorno']) . ' è passato.', ['termine' => $t['nota']]);
            }

            return $caso('R8', null, $come . ': il termine è il ' . self::giorno($t['giorno']) . '.', ['termine' => $t['nota']]);
        }

        // R11: nessuna diffida, e la data dei sei mesi entro 60 giorni o passata.
        if ($vicina) {
            $prima = collect([$sollecito, $promemoria])->contains(fn ($l) => $l !== null && in_array($l['stato'], self::USCITA, true));

            return $caso('R11', self::DIFFIDA, ($prima ? 'Nessuna diffida' : 'Nessuna lettera') . '; la data dei sei mesi è il ' . self::giorno($data) . '.');
        }

        // R12, R13: il sollecito partito o spedito che chiede tutte le rate aperte, o che non chiede soltanto rate nella tolleranza; con la salita, anche quello
        // che non chiede le rate scadute dopo.
        $sollecitoArrivato = $sollecito !== null && in_array($sollecito['stato'], self::ARRIVATA, true);
        $dopoIlSollecito = $sollecitoArrivato ? self::rateDopo($sollecito, $rate, $tolleranza) : null;
        if ($sollecitoArrivato && $dopoIlSollecito['chiede'] && ($dopoIlSollecito['oltre'] === [] || $salita === self::SALITA_AVANTI)) {
            if ($dopoIlSollecito['oltre'] !== []) {
                $note[] = 'Il sollecito del ' . self::giorno(self::giornoDellaLettera($sollecito)) . ' non comprende ' . self::euro(self::somma($dopoIlSollecito['oltre']))
                    . ' di rate scadute dopo.';
            }
            if ($dopoIlSollecito['dentro'] !== []) {
                $note[] = self::notaDellaTolleranza($dopoIlSollecito['dentro'], 'nel sollecito');
            }
            $t = self::termine($sollecito);
            $come = 'Sollecito ' . self::comeArrivata($sollecito) . ' il ' . self::giorno(self::giornoDellaLettera($sollecito));
            if ($t['giorno'] === null) {
                return $caso('R12', null, $conPerche($come . ', senza un termine.'));
            }
            if ($oggi > $t['giorno']) {
                return $caso('R13', self::DIFFIDA, $conPerche($come . ': il termine del ' . self::giorno($t['giorno']) . ' è passato.'), ['termine' => $t['nota']]);
            }

            return $caso('R12', null, $conPerche($come . ': il termine è il ' . self::giorno($t['giorno']) . '.'), ['termine' => $t['nota']]);
        }

        // R14: il sollecito per email non partito, o in coda.
        if ($sollecito !== null && $sollecito['stato'] === 'non_partita') {
            return $caso('R14', self::SOLLECITO, $conPerche('Il sollecito del ' . self::giorno($sollecito['data']) . ' non è partito.'));
        }
        if ($sollecito !== null && $sollecito['stato'] === 'in_coda') {
            return $caso('R14', null, $conPerche('Il sollecito del ' . self::giorno($sollecito['data']) . ' è in coda per l\'email.'));
        }

        // R15: nessuna lettera che conta, con la tolleranza del condominio. Dopo un sollecito la tolleranza si guarda sulle sole rate che non chiedeva.
        if ($tolleranza !== null) {
            $daGuardare = $dopoIlSollecito !== null && $dopoIlSollecito['tutte'] !== [] ? $dopoIlSollecito['tutte'] : $rate;
            $oltre = SuggerimentiDelRecupero::scadenzeOltreLaTolleranza(array_map(fn ($r) => self::perLaTolleranza($r), $daGuardare), $tolleranza);
            if ($oltre === []) {
                $dal = CarbonImmutable::parse(min(array_column($daGuardare, 'scadenza')), 'UTC')->addDays($tolleranza + 1)->format('Y-m-d');

                $giorni = $tolleranza . ' ' . ($tolleranza === 1 ? 'giorno' : 'giorni');
                $fino = CarbonImmutable::parse($dal, 'UTC')->subDay()->format('Y-m-d');

                return $caso('R15', null, $conPerche('Nella tolleranza di ' . $giorni . ' del condominio: il sollecito si propone dal ' . self::giorno($dal) . '.'),
                    ['frase_dei_fatti' => $conPerche('Nella tolleranza di ' . $giorni . ' del condominio, fino al ' . self::giorno($fino) . '.')]);
            }
        }
        if ($sollecitoArrivato) {
            $frase = 'Il sollecito del ' . self::giorno(self::giornoDellaLettera($sollecito)) . ' non comprende ' . self::euro(self::somma($dopoIlSollecito['tutte'])) . ' di rate scadute dopo.';
        } elseif ($promemoria !== null && in_array($promemoria['stato'], self::USCITA, true)) {
            $frase = 'Solo un promemoria, il ' . self::giorno($promemoria['data']) . '.';
        } else {
            $frase = self::FRASE_NESSUNA_LETTERA;
        }

        return $caso('R15', self::SOLLECITO, $conPerche($frase));
    }

    /**
     * I passi possibili della persona, nell'ordine del menu: il sollecito e la diffida sempre (anche con la dispensa: li sceglie l'amministratore); la
     * spedizione con una lettera stampata da spedire; il fascicolo sempre; l'accordo con un accordo in corso; «Compensa» con un credito della stessa gestione
     * che copre qualcosa; «Registra che hai agito» nel riquadro.
     *
     * @return list<string>
     */
    private static function possibili(array $p, array $lettere, string $vista): array
    {
        $si = [self::SOLLECITO => true, self::DIFFIDA => true, self::FASCICOLO => true];
        if (collect([$lettere['diffida'], $lettere['sollecito']])->contains(fn ($l) => ($l['stato'] ?? null) === 'stampata')) {
            $si[self::SPEDIZIONE] = true;
        }
        if (($p['accordi'] ?? []) !== []) {
            $si[self::ACCORDO] = true;
        }
        if (($p['credito']['rate_coperte'] ?? []) !== []) {
            $si[self::COMPENSA] = true;
        }
        if ($vista === self::RIQUADRO) {
            $si[self::AGITO] = true;
        }

        return array_values(array_filter(self::ORDINE, fn ($x) => isset($si[$x])));
    }

    /**
     * Il termine di una lettera: il giorno (null senza termine) e, con la forma «giorni», da quando si conta. Le lettere vecchie hanno i giorni senza la forma, e
     * valgono «giorni».
     *
     * @return array{giorno: ?string, nota: ?string}
     */
    private static function termine(array $l): array
    {
        $forma = TestiLettere::formaDelTermine($l);
        if ($forma === 'data' && filled($l['termine_data'] ?? null)) {
            return ['giorno' => substr((string) $l['termine_data'], 0, 10), 'nota' => null];
        }
        if ($forma === 'giorni' && isset($l['termine'])) {
            $giorni = (int) $l['termine'];
            $base = self::giornoDellaLettera($l);
            $da = ($l['spedizione'] ?? null) !== null ? 'dalla spedizione del ' : 'dall\'invio del ';

            return ['giorno' => CarbonImmutable::parse($base, 'UTC')->addDays($giorni)->format('Y-m-d'),
                'nota' => ($giorni === 1 ? '1 giorno' : $giorni . ' giorni') . ' dal ricevimento: contati ' . $da . self::giorno($base) . '.'];
        }

        return ['giorno' => null, 'nota' => null];
    }

    /**
     * Le rate aperte che la lettera non chiedeva (le chiavi «rata|unità» non fra le sue righe): tutte, quelle ancora nella tolleranza del condominio e quelle
     * oltre (senza una tolleranza, tutte oltre).
     *
     * `chiede`: la lettera chiede ancora almeno una delle rate aperte.
     *
     * @return array{tutte: list<array<string, mixed>>, dentro: list<array<string, mixed>>, oltre: list<array<string, mixed>>, chiede: bool}
     */
    private static function rateDopo(array $lettera, array $rate, ?int $tolleranza): array
    {
        $chieste = array_flip(array_map('strval', $lettera['righe'] ?? []));
        $tutte = array_values(array_filter($rate, fn ($r) => ! isset($chieste[(string) $r['chiave']])));
        $nella = fn ($r) => $tolleranza !== null && SuggerimentiDelRecupero::scadenzeOltreLaTolleranza([self::perLaTolleranza($r)], $tolleranza) === [];

        return ['tutte' => $tutte, 'dentro' => array_values(array_filter($tutte, $nella)), 'oltre' => array_values(array_filter($tutte, fn ($r) => ! $nella($r))),
            'chiede' => count($tutte) < count($rate)];
    }

    /** Una rata nella forma della condizione della tolleranza ({@see SuggerimentiDelRecupero::scadenzeOltreLaTolleranza()}). */
    private static function perLaTolleranza(array $r): array
    {
        return ['scadenza' => $r['scadenza'], 'giorni_ritardo' => $r['giorni_ritardo'] ?? null, 'residuo' => $r['residuo'], 'accordo' => null];
    }

    private static function somma(array $rate): int
    {
        return (int) array_sum(array_column($rate, 'residuo'));
    }

    /**
     * «La rata del 10/11/2026, € 100,00, non è nel sollecito: è nella tolleranza.»; con più rate «Le rate del 08/11/2026 e del 10/11/2026, € 200,00, non sono
     * nel sollecito: sono nella tolleranza.» (con un giorno solo, «Le 2 rate del 10/11/2026»).
     *
     * @param list<array<string, mixed>> $rate
     */
    private static function notaDellaTolleranza(array $rate, string $dove): string
    {
        $giorni = array_values(array_unique(array_map(fn ($r) => substr((string) $r['scadenza'], 0, 10), $rate)));
        sort($giorni);
        $cifra = self::euro(self::somma($rate));
        if (count($rate) === 1) {
            return 'La rata del ' . self::giorno($giorni[0]) . ', ' . $cifra . ', non è ' . $dove . ': è nella tolleranza.';
        }
        $quali = count($giorni) === 1 ? 'Le ' . count($rate) . ' rate del ' . self::giorno($giorni[0])
            : 'Le rate del ' . implode(', del ', array_map(fn ($g) => self::giorno($g), array_slice($giorni, 0, -1))) . ' e del ' . self::giorno((string) end($giorni));

        return $quali . ', ' . $cifra . ', non sono ' . $dove . ': sono nella tolleranza.';
    }

    /** La frase dell'accordo che copre tutto: il calendario, se c'è; altrimenti la data e «fino al», con «nessun versamento» se il residuo non è cambiato. */
    private static function fraseAccordo(array $segno): string
    {
        $intestazione = 'Accordo di rientro del ' . self::giorno($segno['data_accordo']) . ', fino al ' . self::giorno($segno['fino_al']);
        $segnali = array_values(array_filter([$segno['segnali']['rate'] ?? null, $segno['segnali']['giorni'] ?? null], fn ($s) => $s !== null && ! ($s['smentito'] ?? false)));
        usort($segnali, fn ($a, $b) => strcmp($a['il'], $b['il']));
        $segnale = $segnali === [] ? '' : ' La condizione di decadenza scritta nell\'accordo si è verificata il ' . self::giorno($segnali[0]['il']) . '.';
        if (($segno['calendario'] ?? null) !== null) {
            $nonVersata = collect($segno['calendario'])->first(fn ($r) => $r['scaduta'] && ! $r['versata']);
            if ($nonVersata !== null) {
                return 'La rata dell\'accordo del ' . self::giorno($nonVersata['data']) . ' non è versata.' . $segnale;
            }

            return $intestazione . ': ' . AccordiRientro::statoDelCalendario($segno) . '.' . $segnale;
        }

        return $intestazione . '.' . (($segno['invariato'] ?? false) ? ' Dall\'accordo del ' . self::giorno($segno['data_accordo']) . ' nessun versamento sulle sue rate.' : '') . $segnale;
    }

    /** «Hai agito il 12/03/2026: «…» (Vincenzo).» */
    public static function fraseAgito(array $agito): string
    {
        $chi = filled($agito['utente'] ?? null) ? ' (' . $agito['utente'] . ')' : '';

        return 'Hai agito il ' . self::giorno($agito['giorno']) . ': «' . trim((string) ($agito['nota'] ?? '')) . '»' . $chi . '.';
    }

    /** Il giorno di una lettera che le frasi dicono: quello della spedizione registrata, o quello dell'uscita. */
    private static function giornoDellaLettera(array $l): string
    {
        return substr((string) ($l['spedizione']['data'] ?? $l['data']), 0, 10);
    }

    /** «spedita», «partita per email»: come è arrivata la lettera. */
    private static function comeArrivata(array $l): string
    {
        return $l['stato'] === 'inviata' ? self::participio($l['tipo'], 'partit') . ' per email' : self::participio($l['tipo'], 'spedit');
    }

    private static function nome(string $tipo): string
    {
        return ['diffida' => 'Diffida', 'messa_in_mora' => 'Messa in mora', 'sollecito' => 'Sollecito', 'promemoria' => 'Promemoria'][$tipo] ?? ucfirst($tipo);
    }

    /** Il participio accordato con il tipo: la diffida e la messa in mora sono femminili, il sollecito e il promemoria maschili. */
    private static function participio(string $tipo, string $radice): string
    {
        return $radice . (in_array($tipo, ['diffida', 'messa_in_mora'], true) ? 'a' : 'o');
    }

    private static function giorno(string $data): string
    {
        return CarbonImmutable::parse(substr($data, 0, 10), 'UTC')->format('d/m/Y');
    }

    private static function euro(int $centesimi): string
    {
        return '€ ' . number_format($centesimi / 100, 2, ',', '.');
    }
}

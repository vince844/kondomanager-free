<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Accessori;

use App\Models\Gestionale\Subentro;
use App\Models\ImpostazioniCondominio;

/**
 * Decisioni 493 e 494: le otto scelte dell'amministratore sugli interessi del debito ereditato. Stanno nelle regole del recupero del condominio
 * («Recupero dei crediti», sezione «Gli interessi degli eredi»: le colonne `eredi_*` di {@see ImpostazioniCondominio}) e valgono per ogni successione;
 * una successione le può cambiare per sé («Cambia per questa successione»: `subentri.interessi_eredi`, e il registro del passaggio), e tiene solo quelle
 * cambiate: le altre seguono il condominio anche se cambia dopo (seconda prova della beta.12).
 *
 * Le scelte, con il loro valore di serie:
 * 1. `interessi_sugli_interessi` (spento): anche sugli interessi e sulle spese già addebitati al defunto; di serie solo il capitale (art. 1283 c.c.);
 * 2. `dal_decesso` (acceso): gli interessi degli eredi partono dal giorno del decesso; spento, dal giorno dopo;
 * 3. `tolleranza_rata_nuova` (spento): il debito ereditato ha gli «Interessi dopo la scadenza» della rata nuova (si ferma alla scadenza); di serie conta
 *    anche quei giorni, perché era già in ritardo. È il campo `giorni_decorrenza` delle regole del recupero, non la «Tolleranza prima del sollecito»;
 * 4. `rata_per_rata` (acceso): su più rate il debito ereditato scende rata per rata; spento, si ferma tutto alla prima rata;
 * 5. `versamento_prima` (acceso): un versamento dell'erede copre prima il debito ereditato; spento, lo copre per ultimo;
 * 6. `anche_accordo` (acceso): anche il debito coperto dall'accordo di rientro del defunto in regola al decesso (decisione 454); spento, quella parte resta
 *    senza interessi finché non è in una rata degli eredi;
 * 7. `buco_continuano` (spento): con «continuano a suo nome» gli eredi contano i giorni fra l'emissione e la scadenza della loro rata nuova; di serie quei
 *    giorni restano senza interessi, e la card della successione lo dice;
 * 8. `interessi_defunto_agli_eredi` (acceso): gli interessi del defunto fino al decesso registrati dopo la successione passano agli eredi per quota, come
 *    quelli già addebitati prima; spento, restano a nome del defunto («eredi di …»).
 */
final class RegoleDegliEredi
{
    /** Le otto scelte: chiave => [colonna di `impostazioni_condominio`, valore di serie]. */
    public const SCELTE = [
        'interessi_sugli_interessi' => ['eredi_interessi_sugli_interessi', false],
        'dal_decesso' => ['eredi_dal_decesso', true],
        'tolleranza_rata_nuova' => ['eredi_tolleranza_rata_nuova', false],
        'rata_per_rata' => ['eredi_rata_per_rata', true],
        'versamento_prima' => ['eredi_versamento_prima', true],
        'anche_accordo' => ['eredi_anche_accordo', true],
        'buco_continuano' => ['eredi_buco_continuano', false],
        'interessi_defunto_agli_eredi' => ['eredi_interessi_defunto_agli_eredi', true],
    ];

    /** I valori di serie, con i nomi delle colonne: {@see ImpostazioniCondominio::DI_SERIE}. */
    public const DI_SERIE = [
        'eredi_interessi_sugli_interessi' => false,
        'eredi_dal_decesso' => true,
        'eredi_tolleranza_rata_nuova' => false,
        'eredi_rata_per_rata' => true,
        'eredi_versamento_prima' => true,
        'eredi_anche_accordo' => true,
        'eredi_buco_continuano' => false,
        'eredi_interessi_defunto_agli_eredi' => true,
    ];

    /** La descrizione della sezione, nella pagina «Recupero dei crediti» (decisione 494 (1)). */
    public const DESCRIZIONE = 'Quando un condòmino muore e il suo debito passa agli eredi. Valgono per ogni successione; nella successione le puoi cambiare per quella sola.';

    /**
     * Il titolo, la riga «Adesso: …» acceso e spento, e la «i» con il caso di Ettore Fabbri: la rata di aprile da € 300,00 scaduta il 30/04/2026, il decesso
     * il 17/11/2026, gli eredi Elena e Marco al 50 %, il 2 % l'anno.
     *
     * @var array<string, array{titolo: string, acceso: string, spento: string, esempio: string}>
     */
    public const TESTI = [
        'interessi_sugli_interessi' => [
            'titolo' => 'Interessi anche sugli interessi già addebitati al defunto',
            'acceso' => 'Adesso: gli eredi pagano gli interessi anche sugli interessi e sulle spese già addebitati al defunto.',
            'spento' => 'Adesso: gli eredi pagano gli interessi solo sul capitale delle rate (art. 1283 c.c.).',
            'esempio' => 'Per esempio: Ettore doveva la rata di aprile, € 300,00, e € 1,20 di interessi già addebitati. Spento (di serie) Elena e Marco contano gli '
                . 'interessi sui € 300,00, € 150,00 a testa; acceso anche sui € 1,20, finché non li versano.',
        ],
        'dal_decesso' => [
            'titolo' => 'Partono dal giorno del decesso',
            'acceso' => 'Adesso: gli interessi degli eredi partono dal giorno del decesso.',
            'spento' => 'Adesso: gli interessi degli eredi partono dal giorno dopo il decesso.',
            'esempio' => 'Per esempio: Ettore muore il 17/11/2026. Acceso (di serie) gli interessi di Elena e Marco partono il 17/11; spento il 18/11, e il 17/11 non li '
                . 'conta nessuno.',
        ],
        // E3 della seconda prova della beta.12: la scelta usa il campo «Interessi dopo la scadenza» delle regole del recupero (`giorni_decorrenza`), non la
        // «Tolleranza prima del sollecito»: le parole dicono il campo vero. Le cifre non cambiano.
        'tolleranza_rata_nuova' => [
            'titolo' => 'Il debito ereditato ha gli «Interessi dopo la scadenza» della rata nuova',
            'acceso' => 'Adesso: il debito ereditato si ferma alla scadenza della rata nuova che lo porta: i giorni di «Interessi dopo la scadenza» valgono anche per lui, come per una rata nuova.',
            'spento' => 'Adesso: il debito ereditato conta anche i giorni di «Interessi dopo la scadenza» della rata nuova, perché era già in ritardo.',
            'esempio' => 'Per esempio: la rata nuova di Elena e Marco scade il 31/03/2027, e nelle regole del recupero «Interessi dopo la scadenza» è 10 giorni. Spento (di '
                . 'serie) il debito ereditato conta fino al 10/04, € 1,19 a testa; acceso fino al 31/03, € 1,11 a testa. La «Tolleranza prima del sollecito» non c\'entra.',
        ],
        'rata_per_rata' => [
            'titolo' => 'Su più rate, scende rata per rata',
            'acceso' => 'Adesso: se il piano divide il debito ereditato su più rate, ogni parte smette di contare quando entra nella sua rata.',
            'spento' => 'Adesso: se il piano divide il debito ereditato su più rate, smette di contare tutto alla prima rata.',
            'esempio' => 'Per esempio: il piano 2027 divide i € 300,00 di Ettore su quattro rate. Acceso (di serie) ogni parte conta fino alla sua rata; spento tutto il '
                . 'debito smette di contare alla prima rata, e le parti delle rate dopo aspettano la loro.',
        ],
        'versamento_prima' => [
            'titolo' => 'Un versamento dell\'erede copre prima il debito ereditato',
            'acceso' => 'Adesso: un versamento dell\'erede copre prima il debito ereditato.',
            'spento' => 'Adesso: un versamento dell\'erede copre prima le sue rate, e il debito ereditato per ultimo.',
            'esempio' => 'Per esempio: Elena versa € 150,00 il 20/11/2026, prima che il debito sia in una sua rata. Acceso (di serie) il versamento copre prima il debito '
                . 'ereditato e i suoi interessi si fermano dal 21/11, € 0,03; spento copre prima le sue rate.',
        ],
        'anche_accordo' => [
            'titolo' => 'Anche il debito dell\'accordo di rientro in regola',
            'acceso' => 'Adesso: dal decesso gli eredi pagano gli interessi anche sul debito dell\'accordo di rientro del defunto, che era in regola.',
            'spento' => 'Adesso: il debito dell\'accordo di rientro del defunto resta senza interessi finché non è in una rata degli eredi.',
            'esempio' => 'Per esempio: Ettore aveva un accordo di rientro in regola sui € 300,00. Acceso (di serie) dal 17/11 Elena e Marco contano gli interessi anche '
                . 'su quel debito; spento quella parte resta senza interessi finché non entra in una loro rata.',
        ],
        'buco_continuano' => [
            'titolo' => 'Con «continuano a suo nome», gli eredi coprono il buco fino alla scadenza',
            'acceso' => 'Adesso: con «continuano a suo nome», dall\'emissione alla scadenza della rata nuova gli interessi li contano gli eredi.',
            'spento' => 'Adesso: con «continuano a suo nome», fra l\'emissione e la scadenza della rata nuova gli interessi non li conta nessuno, e la successione lo dice.',
            'esempio' => 'Per esempio: con «continuano a suo nome» Ettore conta fino all\'emissione della rata nuova, il 15/01/2027, che scade il 31/03/2027. Spento (di '
                . 'serie) dal 16/01 al 31/03 nessuno li conta, € 1,23 sui € 300,00; acceso li contano Elena e Marco.',
        ],
        'interessi_defunto_agli_eredi' => [
            'titolo' => 'Gli interessi del defunto registrati dopo vanno agli eredi',
            'acceso' => 'Adesso: gli interessi del defunto fino al decesso, registrati dopo la successione, passano agli eredi per quota.',
            'spento' => 'Adesso: gli interessi del defunto fino al decesso, registrati dopo la successione, restano a suo nome («eredi di …»).',
            'esempio' => 'Per esempio: gli interessi di Ettore dal 01/05 al 16/11/2026, € 3,29, registrati dopo la successione. Acceso (di serie) passano a Elena e Marco, '
                . 'metà ciascuno, come gli interessi già addebitati prima del decesso; spento restano a nome di Ettore, e la sua posizione chiusa si riapre.',
        ],
    ];

    /** @return array<string, bool> le scelte del condominio, con le chiavi brevi */
    public static function delCondominio(int $condominioId): array
    {
        return self::daImpostazioni(ImpostazioniCondominio::delCondominio($condominioId));
    }

    /** @return array<string, bool> */
    public static function daImpostazioni(ImpostazioniCondominio $impostazioni): array
    {
        $esito = [];
        foreach (self::SCELTE as $chiave => [$colonna, $diSerie]) {
            $valore = $impostazioni->getAttribute($colonna);
            $esito[$chiave] = $valore === null ? $diSerie : (bool) $valore;
        }

        return $esito;
    }

    /** @return array<string, bool> i valori di serie, con le chiavi brevi */
    public static function diSerie(): array
    {
        return array_map(fn (array $s) => $s[1], self::SCELTE);
    }

    /**
     * Le scelte che valgono per una successione: quelle cambiate per lei (`interessi_eredi`), altrimenti quelle del condominio.
     *
     * @param array<string, bool>|null $delCondominio le scelte del condominio, se chi chiama le ha già lette
     * @return array<string, bool>
     */
    public static function perSuccessione(Subentro $s, ?array $delCondominio = null): array
    {
        $delCondominio ??= self::delCondominio((int) $s->condominio_id);
        $proprie = is_array($s->interessi_eredi) ? $s->interessi_eredi : [];

        return self::unite($proprie, $delCondominio);
    }

    /** @return array<string, bool> le scelte date sopra quelle di base, solo le chiavi conosciute */
    public static function unite(array $proprie, array $base): array
    {
        $esito = [];
        foreach (array_keys(self::SCELTE) as $chiave) {
            $esito[$chiave] = array_key_exists($chiave, $proprie) ? (bool) $proprie[$chiave] : (bool) ($base[$chiave] ?? self::SCELTE[$chiave][1]);
        }

        return $esito;
    }

    /**
     * Le scelte che arrivano da un modulo: le otto chiavi, ognuna vera o falsa; null se manca qualcosa o c'è altro (il modulo manda tutte e otto).
     *
     * @return array<string, bool>|null
     */
    public static function normalizza(mixed $grezze): ?array
    {
        if (! is_array($grezze) || array_diff(array_keys($grezze), array_keys(self::SCELTE)) !== [] || count($grezze) !== count(self::SCELTE)) {
            return null;
        }
        $esito = [];
        foreach (array_keys(self::SCELTE) as $chiave) {
            $v = filter_var($grezze[$chiave], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($v === null) {
                return null;
            }
            $esito[$chiave] = $v;
        }

        return $esito;
    }

    /**
     * Le scelte cambiate per una successione, come arrivano dalla pagina del passaggio («Cambia per questa successione»): da una a otto chiavi conosciute,
     * ognuna vera o falsa; null se c'è altro, se un valore non è vero o falso, o se non c'è niente. Le otto insieme vanno bene lo stesso (le versioni di
     * prima della pagina le mandavano tutte).
     *
     * @return array<string, bool>|null
     */
    public static function normalizzaCambiate(mixed $grezze): ?array
    {
        if (! is_array($grezze) || $grezze === [] || array_diff(array_keys($grezze), array_keys(self::SCELTE)) !== []) {
            return null;
        }
        $esito = [];
        foreach (array_keys(self::SCELTE) as $chiave) {
            if (! array_key_exists($chiave, $grezze)) {
                continue;
            }
            $v = filter_var($grezze[$chiave], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($v === null) {
                return null;
            }
            $esito[$chiave] = $v;
        }

        return $esito;
    }

    /**
     * Correzione della seconda prova della beta.12: una successione tiene solo le scelte cambiate rispetto alle regole del condominio; le altre seguono il
     * condominio, anche se cambia dopo. Null se non ne resta nessuna.
     *
     * @param array<string, bool> $scelte
     * @param array<string, bool> $delCondominio
     * @return array<string, bool>|null
     */
    public static function soloCambiate(array $scelte, array $delCondominio): ?array
    {
        $esito = [];
        foreach (self::SCELTE as $chiave => [, $diSerie]) {
            if (array_key_exists($chiave, $scelte) && (bool) $scelte[$chiave] !== (bool) ($delCondominio[$chiave] ?? $diSerie)) {
                $esito[$chiave] = (bool) $scelte[$chiave];
            }
        }

        return $esito === [] ? null : $esito;
    }

    /**
     * Le scelte tenute da una successione, dette: «Partono dal giorno del decesso» spento. Per lo storico e per la card.
     *
     * @param array<string, bool> $proprie
     * @return list<string>
     */
    public static function dette(array $proprie): array
    {
        $esito = [];
        foreach (array_keys(self::SCELTE) as $chiave) {
            if (array_key_exists($chiave, $proprie)) {
                $esito[] = '«' . self::TESTI[$chiave]['titolo'] . '» ' . ($proprie[$chiave] ? 'acceso' : 'spento');
            }
        }

        return $esito;
    }

    /**
     * Le otto scelte per la pagina: chiave, titolo, valore, la riga «Adesso: …», la «i», il valore di serie.
     *
     * @param array<string, bool> $valori
     * @return list<array{chiave: string, titolo: string, valore: bool, adesso: string, acceso: string, spento: string, esempio: string, di_serie: bool}>
     */
    public static function perLaPagina(array $valori): array
    {
        $esito = [];
        foreach (self::SCELTE as $chiave => [, $diSerie]) {
            $t = self::TESTI[$chiave];
            $valore = (bool) ($valori[$chiave] ?? $diSerie);
            $esito[] = ['chiave' => $chiave, 'titolo' => $t['titolo'], 'valore' => $valore, 'adesso' => $valore ? $t['acceso'] : $t['spento'],
                'acceso' => $t['acceso'], 'spento' => $t['spento'], 'esempio' => $t['esempio'], 'di_serie' => $diSerie];
        }

        return $esito;
    }

    /**
     * Il riassunto della card «Gli interessi degli eredi» della successione, in due righe (decisione 494 (2)): chi paga gli interessi sul debito di chi, da
     * quando, su che cosa e fino a quando; e il versamento. Con «continuano a suo nome», il buco fra l'emissione e la scadenza della rata nuova.
     *
     * @param array<string, bool> $scelte
     * @param list<string> $eredi i nomi
     */
    public static function riassunto(array $scelte, string $defunto, array $eredi, string $decesso, string $interessiDelDefunto): string
    {
        $chi = self::elenco($eredi);
        $uno = count($eredi) === 1;
        $giorno = \Carbon\CarbonImmutable::parse($decesso, 'UTC');
        if ($interessiDelDefunto === Subentro::INTERESSI_CONTINUANO) {
            return $scelte['buco_continuano']
                ? sprintf('Gli interessi di %s continuano a suo nome fino a quando la rata nuova %s è emessa; dall\'emissione alla scadenza di quella rata li %s %s. '
                    . '%s', $defunto, $uno ? 'dell\'erede' : 'degli eredi', $uno ? 'conta' : 'contano', $chi, self::fraseVersamento($scelte, $uno))
                : sprintf('Gli interessi di %s continuano a suo nome fino a quando la rata nuova %s è emessa; fra l\'emissione e la scadenza di quella rata non li conta '
                    . 'nessuno.', $defunto, $uno ? 'dell\'erede' : 'degli eredi');
        }
        $dal = $scelte['dal_decesso'] ? $giorno->format('d/m/Y') : $giorno->addDay()->format('d/m/Y');
        $suChe = $scelte['interessi_sugli_interessi'] ? 'sulle rate e sugli interessi già addebitati' : 'solo sulle rate';
        if (! $scelte['anche_accordo']) {
            $suChe .= ', non sul debito dell\'accordo di rientro';
        }
        $fino = $scelte['rata_per_rata'] ? 'fino a quando entra in una loro rata' : 'fino alla prima loro rata che lo porta';
        if ($uno) {
            $fino = $scelte['rata_per_rata'] ? 'fino a quando entra in una sua rata' : 'fino alla prima sua rata che lo porta';
        }

        return sprintf('%s %s gli interessi sul debito di %s dal %s, %s, %s. %s', $chi, $uno ? 'paga' : 'pagano', $defunto, $dal, $suChe, $fino,
            self::fraseVersamento($scelte, $uno));
    }

    private static function fraseVersamento(array $scelte, bool $uno): string
    {
        return $scelte['versamento_prima']
            ? ($uno ? 'Un suo versamento copre prima questo debito.' : 'Un loro versamento copre prima questo debito.')
            : ($uno ? 'Un suo versamento copre prima le sue rate, e questo debito per ultimo.' : 'Un loro versamento copre prima le loro rate, e questo debito per ultimo.');
    }

    /** «Elena», «Elena e Marco», «Anna, Bruno e Carla». */
    public static function elenco(array $nomi): string
    {
        $nomi = array_values($nomi);
        if (count($nomi) <= 1) {
            return (string) ($nomi[0] ?? 'gli eredi');
        }
        $ultimo = array_pop($nomi);

        return implode(', ', $nomi) . ' e ' . $ultimo;
    }
}

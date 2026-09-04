<?php

namespace App\Enums\Fiscale;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;

/**
 * I regimi di ritenuta d'acconto rilevanti per un condominio. Ognuno ha un
 * comportamento distinto (aliquota, plafond, titolo in CU) — è per questo
 * che è un enum e non un campo testo libero come `fornitori.perc_ritenuta`
 * di oggi, che costringeva un solo numero a coprire regimi incompatibili.
 *
 * L'aliquota dipende dal REGIME (questo enum), il codice tributo dipende
 * dalla NATURA del percipiente (NaturaPercipiente) — due assi indipendenti,
 * mai confusi in un solo campo.
 *
 * Design: docs/design/f24_ritenute_design.md §2.2.
 */
enum TipoRitenuta: string
{
    /** Appalti di opere e servizi — art. 25-ter DPR 600/1973. 4%, plafond 500€/finestra. */
    case APPALTO_4 = 'appalto_4';

    /** Redditi di lavoro autonomo (professionisti) — art. 25. 20%, plafond 100€/anno. */
    case LAVORO_AUTONOMO_20 = 'lavoro_autonomo_20';

    /** Provvigioni con diritto alla riduzione (percipiente con dipendenti/collaboratori) — art. 25-bis. 23% su base 50% = 11,5%. */
    case PROVVIGIONI_BASE_50 = 'provvigioni_base_50';

    /** Provvigioni senza riduzione — art. 25-bis. 23% su base 20% = 4,6%. Richiede dichiarazione del percipiente. */
    case PROVVIGIONI_BASE_20 = 'provvigioni_base_20';

    /** Compensi a non residenti — 30% A TITOLO D'IMPOSTA (non acconto: valorizza il punto 10 CU, non il 9). */
    case NON_RESIDENTE_30 = 'non_residente_30';

    /**
     * Lavoro dipendente — art. 23, cod. 1001, plafond SEMPRE mensile.
     * Il motore paghe è fuori scope in v1.10: questo case esiste solo per
     * blindare la regola — la facoltà di rinvio dei 100€ (D.Lgs. 1/2024)
     * riguarda SOLO gli artt. 25 e 25-bis. Applicarla qui sarebbe un
     * versamento tardivo sanzionabile, se un domani qualcuno agganciasse
     * un motore paghe trovando per errore il plafond sbagliato.
     */
    case LAVORO_DIPENDENTE = 'lavoro_dipendente';

    public function label(): string
    {
        return match ($this) {
            self::APPALTO_4 => "Appalto di opere/servizi — 4% (art. 25-ter)",
            self::LAVORO_AUTONOMO_20 => 'Lavoro autonomo — 20% (art. 25)',
            self::PROVVIGIONI_BASE_50 => 'Provvigioni con riduzione — 11,5% (art. 25-bis)',
            self::PROVVIGIONI_BASE_20 => 'Provvigioni senza riduzione — 4,6% (art. 25-bis)',
            self::NON_RESIDENTE_30 => 'Compenso a non residente — 30% a titolo d\'imposta',
            self::LAVORO_DIPENDENTE => 'Lavoro dipendente — art. 23 (fuori scope: nessun motore paghe)',
        };
    }

    /**
     * Aliquota storicizzata alla data indicata. Nessun default silenzioso:
     * se config/fiscale.php non ha una riga valida, il calcolo si ferma.
     */
    public function aliquota(CarbonInterface $data): float
    {
        return (float) $this->rigaAliquotaVigente($data)['aliquota'];
    }

    /** Percentuale dell'imponibile che entra in base (es. provvigioni: 50 o 20). */
    public function percentualeBase(): float
    {
        $righe = Config::get("fiscale.aliquote.{$this->value}");
        if (empty($righe)) {
            throw new \DomainException(
                "Nessuna aliquota configurata per il regime «{$this->value}» in config/fiscale.php."
            );
        }

        return (float) $righe[0]['base'];
    }

    public function titolo(): TitoloRitenuta
    {
        return $this === self::NON_RESIDENTE_30 ? TitoloRitenuta::IMPOSTA : TitoloRitenuta::ACCONTO;
    }

    public function plafond(): PlafondRitenuta
    {
        return match ($this) {
            self::APPALTO_4 => PlafondRitenuta::SOGLIA_500_TRE_FINESTRE,
            self::LAVORO_AUTONOMO_20,
            self::PROVVIGIONI_BASE_50,
            self::PROVVIGIONI_BASE_20,
            self::NON_RESIDENTE_30 => PlafondRitenuta::SOGLIA_100_ANNUALE,
            self::LAVORO_DIPENDENTE => PlafondRitenuta::MENSILE_SEMPRE,
        };
    }

    /**
     * L'unico punto del sistema in cui si decide 1019 vs 1020 vs 1040 vs 1001.
     * Il campo `fornitori.codice_tributo` odierno diventa derivato da qui,
     * sola lettura salvo override motivato.
     */
    public function codiceTributo(?NaturaPercipiente $natura): string
    {
        // ⚠️ **La natura può mancare, e qui non si inventa.** La firma è diventata
        // nullable il 03/09/2026: fuori dall'appalto il codice è fisso e chiedere la
        // natura fermerebbe una delega per un dato che non decide niente, quindi il
        // chiamante ha il diritto di passare `null`. Sull'appalto invece il valore
        // decide fra 1019 e 1020, e allora l'assenza è un errore da dichiarare — non
        // un `?? PERSONA_FISICA_IRPEF` che manda all'Erario un codice a caso, che è
        // esattamente il difetto da cui nasce la Coda 119.
        if ($this === self::APPALTO_4 && $natura === null) {
            throw new \DomainException(
                'Il codice tributo dell\'appalto dipende dalla natura del percipiente '
                .'(1019 per IRPEF, 1020 per IRES) e la natura non è stata dichiarata.'
            );
        }

        return match ($this) {
            self::APPALTO_4 => $natura->ires() ? '1020' : '1019',
            self::LAVORO_AUTONOMO_20 => '1040',
            self::PROVVIGIONI_BASE_50, self::PROVVIGIONI_BASE_20 => '1040',
            self::NON_RESIDENTE_30 => '1040',
            self::LAVORO_DIPENDENTE => '1001',
        };
    }

    /**
     * Il regime che si applica, dato quel poco che si sa di un fornitore.
     *
     * ⚠️ **Esiste per non avere due deduzioni diverse della stessa cosa.** Fino al
     * 03/09/2026 questa logica viveva dentro `GeneraDelegheF24Action::tipoRitenuta()`, ed
     * era l'unico posto che ne avesse bisogno. Da quando anche la validazione
     * dell'anagrafica deve sapere se la natura serve — e la risposta dipende dal regime —
     * i posti sono due: riscriverla nel secondo avrebbe creato la condizione perché un
     * giorno rispondano diversamente, cioè un modulo che accetta un fornitore che poi
     * l'F24 rifiuta.
     *
     * Il ripiego su `APPALTO_4` non è una scorciatoia: è il regime della stragrande
     * maggioranza dei fornitori di un condominio, ed è quello che la 1.9 applicava
     * implicitamente con `perc_ritenuta = 4`. E soprattutto **non è tirare a indovinare**,
     * perché il ramo che conta lo decide un fatto registrato: l'aliquota davvero applicata.
     * Il `?? 4` finale è irraggiungibile dal modulo, dove `perc_ritenuta` è obbligatoria
     * quando `tipo_ritenuta` manca.
     */
    public static function dedotto(self|string|null $tipo, int|float|string|null $percRitenuta): self
    {
        if ($tipo instanceof self) {
            return $tipo;
        }

        if (! blank($tipo)) {
            return self::from($tipo);
        }

        return ((int) ($percRitenuta ?? 4)) === 20
            ? self::LAVORO_AUTONOMO_20
            : self::APPALTO_4;
    }

    /**
     * Se per questo regime la **natura del percipiente cambia il codice tributo**.
     *
     * Guardando `codiceTributo()` qui sopra si vede che la risposta è sì per un regime solo:
     * l'appalto, dove IRPEF dà 1019 e IRES dà 1020. Tutti gli altri hanno un codice fisso —
     * 1040 per lavoro autonomo, provvigioni e non residenti, 1001 per il lavoro dipendente —
     * e la natura non li sposta di una virgola.
     *
     * ⚠️ **Serve a non pretendere un dato che non decide niente.** Il blocco che impedisce di
     * preparare l'F24 senza la natura, nato il 03/09/2026 con la Coda 119, all'inizio non
     * guardava il regime: si fermava sempre. Un condominio che paga soltanto un professionista
     * al 20% si sarebbe visto rifiutare la delega per un campo che sul suo modello avrebbe
     * scritto 1040 in ogni caso — e il messaggio gli avrebbe pure detto una cosa falsa, cioè
     * che «il codice sarebbe 1019 o 1020 a caso».
     *
     * Non è la stessa cosa di «la natura non serve mai» per quei regimi: la Certificazione
     * Unica (Fase 5) la userà comunque. Serve a stabilire quando la sua assenza è un ostacolo
     * **al versamento**, che è l'unica cosa che il blocco dell'F24 protegge.
     */
    public function dipendeDallaNatura(): bool
    {
        return $this === self::APPALTO_4;
    }

    /** Causale CU associata al regime, dove applicabile (usata in Fase 5). */
    public function causaleCU(): ?string
    {
        return match ($this) {
            self::APPALTO_4 => 'W',
            self::LAVORO_AUTONOMO_20, self::PROVVIGIONI_BASE_50, self::PROVVIGIONI_BASE_20 => 'A',
            default => null,
        };
    }

    /** Riferimento normativo vigente alla data (cambia dal 2027 per gli appalti). */
    public function riferimentoNormativo(CarbonInterface $data): string
    {
        $righe = Config::get("fiscale.riferimenti_normativi.{$this->value}", []);

        foreach ($righe as $riga) {
            $dal = isset($riga['dal']) ? \Carbon\Carbon::parse($riga['dal']) : null;
            $finoAl = isset($riga['fino_al']) ? \Carbon\Carbon::parse($riga['fino_al']) : null;

            if (($dal === null || $data->greaterThanOrEqualTo($dal)) && ($finoAl === null || $data->lessThanOrEqualTo($finoAl))) {
                return $riga['testo'];
            }
        }

        throw new \DomainException(
            "Nessun riferimento normativo configurato per il regime «{$this->value}» alla data {$data->toDateString()}."
        );
    }

    /**
     * @return array{dal:string,al?:string,aliquota:float,base:float,titolo:string}
     */
    private function rigaAliquotaVigente(CarbonInterface $data): array
    {
        $righe = Config::get("fiscale.aliquote.{$this->value}", []);

        foreach (array_reverse($righe) as $riga) {
            $dal = \Carbon\Carbon::parse($riga['dal']);
            $al = isset($riga['al']) ? \Carbon\Carbon::parse($riga['al']) : null;

            if ($data->greaterThanOrEqualTo($dal) && ($al === null || $data->lessThanOrEqualTo($al))) {
                return $riga;
            }
        }

        throw new \DomainException(
            "Nessuna aliquota configurata per il regime «{$this->value}» alla data {$data->toDateString()}: "
            .'controlla config/fiscale.php. Il calcolo non applica mai un\'aliquota di fallback silenziosa.'
        );
    }
}

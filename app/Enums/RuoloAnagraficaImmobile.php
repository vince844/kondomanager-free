<?php

namespace App\Enums;

/**
 * Il ruolo con cui una persona è legata a un'unità immobiliare — la colonna
 * `anagrafica_immobile.tipologia`.
 *
 * Nasce nella beta.43 per due ragioni che vanno insieme:
 *
 * 1. **`nuda_proprietario` mancava**, e la sua assenza rendeva irraggiungibile un ramo di
 *    codice già scritto: `CalcoloQuoteService::distribuisciSuTabelle()` dichiara una catena
 *    capitale `['nuda_proprietario', 'proprietario']` che nessun valore poteva mai imboccare,
 *    perché l'`ENUM` MySQL non conteneva quel ruolo. Tre test in
 *    `CascataRuoloRipartoTest` erano `->skip('nuda_proprietario non ancora in enum')`.
 * 2. **La stessa regola veniva scritta in tre posti diversi**: la cascata delle spese, il
 *    riparto dei saldi solidali e `addebitaDiretto()` decidevano ognuno per conto proprio chi
 *    fosse il soggetto giusto, con tre risposte diverse. Qui la regola è una.
 *
 * ## Perché VARCHAR e non ENUM
 *
 * È il principio 10 della roadmap («Backed Enums + VARCHAR(50)»), che per queste due colonne
 * era dichiarato e non applicato. Un `ENUM` MySQL costringe a una migrazione per ogni ruolo
 * nuovo, e su SQLite diventa un `CHECK` che rende lo schema dei test **più severo** di quello
 * di produzione — la trappola pagata nella beta.34.
 */
enum RuoloAnagraficaImmobile: string
{
    case PROPRIETARIO = 'proprietario';
    case NUDA_PROPRIETA = 'nuda_proprietario';
    case USUFRUTTUARIO = 'usufruttuario';
    case INQUILINO = 'inquilino';

    public function label(): string
    {
        return match ($this) {
            self::PROPRIETARIO => 'Proprietario',
            self::NUDA_PROPRIETA => 'Nudo proprietario',
            self::USUFRUTTUARIO => 'Usufruttuario',
            self::INQUILINO => 'Inquilino',
        };
    }

    /**
     * I valori accettati, per le regole di validazione: `Rule::in(...)`.
     * Nessuna FormRequest deve più riscrivere l'elenco a mano.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Chi è titolare di un diritto reale sull'unità, e quindi **obbligato verso il
     * condominio**. L'inquilino non c'è: il suo rapporto è con il locatore (gli oneri
     * accessori dell'art. 9 L. 392/1978), non con il condominio, che non ha titolo per
     * escuterlo.
     */
    public static function titolariDiDirittoReale(): array
    {
        return [self::PROPRIETARIO, self::NUDA_PROPRIETA, self::USUFRUTTUARIO];
    }

    /**
     * La catena di risoluzione per un **saldo pregresso dell'unità** (art. 63 disp. att. c.c.),
     * ordinata dal soggetto giusto al ripiego.
     *
     * È la cascata del motore di riparto **meno l'inquilino**, e l'asse lo decide la natura
     * della gestione da cui il pregresso proviene:
     *
     * - **ordinaria** → le spese di ordinaria amministrazione sono dell'usufruttuario
     *   (art. 1004 c.c.): `usufruttuario → proprietario`
     * - **straordinaria** → le riparazioni straordinarie sono del proprietario
     *   (art. 1005 c.c.): `nuda_proprietario → proprietario`
     *
     * `proprietario` è il terminale di entrambe: su un'unità in piena proprietà — cioè la
     * quasi totalità dei casi — le due catene danno la stessa risposta di sempre.
     *
     * **Non è un obbligo di legge, è un default.** Verso il condominio usufruttuario e nudo
     * proprietario rispondono in **solido** (art. 67 co. 8 disp. att. c.c.): qualunque dei due
     * paghi, il condominio è a posto. Questa catena decide la ripartizione *interna*, che le
     * parti possono avere pattuito diversamente — e per quel caso esiste il riparto manuale.
     */
    public static function catenaSaldoSolidale(?string $tipoGestione): array
    {
        return $tipoGestione === 'straordinaria'
            ? [self::NUDA_PROPRIETA, self::PROPRIETARIO]
            : [self::USUFRUTTUARIO, self::PROPRIETARIO];
    }

    /**
     * La catena di risoluzione per una **spesa ripartita su tabella millesimale**, quando il
     * coefficiente punta a un ruolo che su quell'unità non c'è. Il ruolo richiesto è in testa,
     * i ripieghi seguono in ordine.
     *
     * Sostituisce le due liste che stavano dentro `CalcoloQuoteService::distribuisciSuTabelle()`
     * e **corregge un buco che si apriva proprio oggi**. Quelle liste erano
     * `['inquilino','usufruttuario','proprietario']` e `['nuda_proprietario','proprietario']`,
     * percorse con `array_slice($catena, $start + 1)`: una lista ordinata non può servire le
     * due direzioni dell'asse capitale. Da `nuda_proprietario` si scendeva a `proprietario`,
     * ma da `proprietario` non si saliva a `nuda_proprietario` — e finché il ruolo non era
     * registrabile non se ne accorgeva nessuno.
     *
     * Da oggi è registrabile, e senza questa correzione un'unità con nuda proprietà e usufrutto
     * — dove **nessuno** è `proprietario` — manderebbe a scoperto ogni coefficiente
     * straordinario, che è il modo tipico di ripartire i lavori. Un ruolo nuovo che rompe
     * silenziosamente il riparto di chi lo usa sarebbe stato un regalo avvelenato.
     *
     * `proprietario` e `nuda_proprietario` si fanno quindi da terminale a vicenda: sono lo
     * stesso soggetto economico visto con l'usufrutto staccato o attaccato.
     *
     * @return array<int, self>
     */
    public static function catenaRiparto(?string $soggettoRichiesto): array
    {
        return match ($soggettoRichiesto) {
            self::INQUILINO->value => [self::INQUILINO, self::USUFRUTTUARIO, self::PROPRIETARIO, self::NUDA_PROPRIETA],
            self::USUFRUTTUARIO->value => [self::USUFRUTTUARIO, self::PROPRIETARIO, self::NUDA_PROPRIETA],
            self::PROPRIETARIO->value => [self::PROPRIETARIO, self::NUDA_PROPRIETA],
            self::NUDA_PROPRIETA->value => [self::NUDA_PROPRIETA, self::PROPRIETARIO],
            // Vocabolario fuori catalogo: si ricade sul terminale legale invece di lasciare
            // la quota senza destinatario. Il `default` non è pigrizia — `soggetto` è una
            // colonna di testo e un dato sporco non deve far sparire un addebito.
            default => [self::PROPRIETARIO, self::NUDA_PROPRIETA],
        };
    }
}

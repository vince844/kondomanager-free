<?php

namespace App\Services\Gestionale\Debiti;

/**
 * L'origine di un pregresso e che cosa ne fa la lettura dei debiti: una regola sola, per l'elenco delle rate scadute, la sua stampa e la
 * Tesoreria.
 *
 * L'origine la dichiara l'amministratore sul saldo, dove il pregresso nasce (`saldi.origine_dichiarata`), mai il programma da solo. Le voci:
 * - `esercizio` (con l'esercizio): il debito è di quell'esercizio. Il programma non sa quando quei debiti sono scaduti: nessun numero di
 *   giorni, la fascia «oltre 90 giorni», le date dei sei mesi da quell'esercizio. Vale anche quando l'esercizio dichiarato è quello ancora
 *   aperto: resta un esercizio, non diventa «dovuto con questo piano».
 * - `con_il_piano` («dovuto con questo piano»): il conguaglio chiesto adesso. I giorni dalla scadenza della rata che lo porta, come una rata.
 * - `prima_del_primo`: più vecchio del primo esercizio che il programma conosce. Nessun numero, «oltre 90 giorni», nessuna data dei sei mesi.
 * - non dichiarata (il saldo senza la voce): come la voce precedente, con le sue parole.
 *
 * Più saldi nella stessa quota: vale l'origine più vecchia, e «non dichiarata» se anche uno solo non l'ha. È la cifra che non sottostima. Contano
 * i saldi a debito della quota: un credito (un saldo dell'unità ripartito sulla persona) non ha un'età da dire, e non si dichiara.
 */
final class OriginePregresso
{
    public const ESERCIZIO = 'esercizio';
    public const CON_IL_PIANO = 'con_il_piano';
    public const PRIMA_DEL_PRIMO = 'prima_del_primo';
    /** Solo nella lettura: il saldo non ha la voce. Non si scrive mai sul saldo (lì è il nullo). */
    public const NON_DICHIARATA = 'non_dichiarata';

    /** Le voci che si scrivono sul saldo. */
    public const VOCI = [self::ESERCIZIO, self::CON_IL_PIANO, self::PRIMA_DEL_PRIMO];

    /** Accanto al campo dell'origine: un saldo tiene un solo esercizio, e il modulo non ne accetta un secondo per la stessa persona. */
    public const FRASE_PIU_ESERCIZI = 'Se il saldo contiene debiti di più esercizi, dichiara il più vecchio: l\'elenco conta da quello.';

    public const FRASE_ANZIANITA_NON_NOTA = 'c\'è un pregresso di anzianità non nota';

    /** La voce ha un numero di giorni? Solo «dovuto con questo piano». */
    public static function haGiorni(string $origine): bool
    {
        return $origine === self::CON_IL_PIANO;
    }

    /**
     * Le parole di un pregresso, sulla riga, sulla stampa e in Tesoreria.
     *
     * @param  array{origine:string, esercizio?:array{nome?:string|null}|null}  $pregresso
     */
    public static function etichetta(array $pregresso): string
    {
        return match ($pregresso['origine']) {
            self::ESERCIZIO => 'pregresso dell\'esercizio ' . self::nomeEsercizio((string) ($pregresso['esercizio']['nome'] ?? '')),
            self::CON_IL_PIANO => 'pregresso dovuto con questo piano',
            self::PRIMA_DEL_PRIMO => 'pregresso di prima del primo esercizio',
            default => 'origine non dichiarata',
        };
    }

    /** «Esercizio 2025» si legge «2025»: «pregresso dell'esercizio 2025», non «dell'esercizio Esercizio 2025». */
    public static function nomeEsercizio(string $nome): string
    {
        $nome = trim($nome);
        $senza = preg_replace('/^esercizio\s+/iu', '', $nome);

        return $senza !== null && $senza !== '' ? $senza : $nome;
    }

    /**
     * Il ritardo di una persona, in parole: il ritardo più vecchio che il programma sa contare, e se c'è anche un pregresso di cui non sa
     * l'anzianità. Mai «in ritardo di N giorni» per un pregresso senza un numero.
     */
    public static function fraseRitardo(int $giorni, bool $anzianitaNonNota): string
    {
        $unita = $giorni === 1 ? 'giorno' : 'giorni';
        if (! $anzianitaNonNota) {
            return "in ritardo di {$giorni} {$unita}";
        }

        return $giorni > 0 ? "ritardo massimo noto: {$giorni} {$unita}; " . self::FRASE_ANZIANITA_NON_NOTA : self::FRASE_ANZIANITA_NON_NOTA;
    }

    /**
     * L'origine di una quota con più saldi: la più vecchia, «non dichiarata» se anche uno solo non l'ha (o il suo esercizio non c'è più).
     *
     * @param  list<array{origine:?string, esercizio:?array{id:int, nome:string, data_inizio:?string, data_fine:?string}}>  $saldi
     * @return array{origine:string, esercizio:?array{id:int, nome:string, data_inizio:?string, data_fine:?string}}
     */
    public static function piuVecchia(array $saldi): array
    {
        $nonDichiarata = ['origine' => self::NON_DICHIARATA, 'esercizio' => null];
        if ($saldi === []) {
            return $nonDichiarata;
        }
        $scelta = null;
        $rango = fn (array $s) => match ($s['origine']) {
            self::PRIMA_DEL_PRIMO => [0, ''],
            self::ESERCIZIO => [1, (string) ($s['esercizio']['data_fine'] ?? '')],
            default => [2, ''],
        };
        foreach ($saldi as $s) {
            if (! in_array($s['origine'], self::VOCI, true) || ($s['origine'] === self::ESERCIZIO && $s['esercizio'] === null)) {
                return $nonDichiarata;
            }
            if ($scelta === null || $rango($s) < $rango($scelta)) {
                $scelta = $s;
            }
        }

        return ['origine' => $scelta['origine'], 'esercizio' => $scelta['origine'] === self::ESERCIZIO ? $scelta['esercizio'] : null];
    }
}

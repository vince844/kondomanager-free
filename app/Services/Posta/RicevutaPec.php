<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use Carbon\CarbonImmutable;

/**
 * Una ricevuta PEC letta dal suo file `.eml` (decisione 577): il gestore la manda alla casella dello studio, l'amministratore la salva e la trascina sulla
 * riga dell'invio. Dentro c'è il `daticert.xml` con i dati di certificazione (D.P.R. 68/2005 e regole tecniche del D.M. 2 novembre 2005): il tipo
 * della ricevuta (l'attributo `tipo` di `postacert`: `accettazione`, `avvenuta-consegna`, `errore-consegna`…), l'errore (`nessuno` se non c'è), il mittente
 * e i destinatari, l'oggetto del messaggio, il gestore, il giorno e l'ora con la zona (`dati/data`), l'identificativo e, per la consegna, l'indirizzo a cui
 * il messaggio è stato consegnato (`dati/consegna`).
 *
 * Il programma legge i dati, non verifica la firma del gestore: la ricevuta resta archiviata così com'è, con la sua impronta, ed è lei la prova. Chi la usa
 * ({@see \App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione}, le lettere ai condòmini in ritardo) controlla che sia una consegna, o l'avviso di
 * mancata consegna, e che sia di quella persona.
 */
final class RicevutaPec
{
    public const CONSEGNA = 'avvenuta-consegna';

    /** L'avviso di mancata consegna: il gestore del destinatario non ha consegnato la PEC (definitivo; il preavviso delle dodici ore non lo è). */
    public const MANCATA_CONSEGNA = 'errore-consegna';

    public const NON_E_UNA_RICEVUTA = 'Il file non è una ricevuta PEC: dentro non c\'è il file dei dati del gestore (daticert.xml). Trascina la ricevuta di consegna '
        . 'così come l\'hai salvata dalla casella PEC, in formato .eml.';

    private function __construct(
        public readonly string $tipo,
        public readonly string $errore,
        public readonly ?string $mittente,
        public readonly ?string $oggetto,
        public readonly ?string $gestore,
        public readonly CarbonImmutable $quando,
        public readonly ?string $identificativo,
        public readonly ?string $consegnataA,
        /** @var list<string> i destinatari dell'intestazione: l'avviso di mancata consegna può non avere la consegna */
        public readonly array $destinatari = [],
    ) {}

    /**
     * Legge il file. Null se non è una ricevuta PEC (nessun `daticert.xml` leggibile, o senza il giorno e l'ora).
     */
    public static function leggi(string $eml): ?self
    {
        $xml = MessaggioEml::parteDiNome($eml, 'daticert.xml');
        if ($xml === null || trim($xml) === '') {
            return null;
        }

        return self::daiDati($xml);
    }

    /** Dal `daticert.xml`. Null se non è un `postacert` con il giorno e l'ora. */
    public static function daiDati(string $xml): ?self
    {
        $precedente = libxml_use_internal_errors(true);
        try {
            // Senza la rete e senza sostituire le entità: il file arriva da fuori.
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($precedente);
        }
        if ($doc === false || strtolower($doc->getName()) !== 'postacert') {
            return null;
        }
        $giorno = trim((string) ($doc->dati->data->giorno ?? ''));
        $ora = trim((string) ($doc->dati->data->ora ?? ''));
        $zona = trim((string) ($doc->dati->data['zona'] ?? ''));
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $giorno) !== 1 || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $ora) !== 1) {
            return null;
        }
        try {
            $fuso = preg_match('/^[+-]\d{4}$/', $zona) === 1 ? new \DateTimeZone($zona) : new \DateTimeZone((string) config('app.user_timezone', 'Europe/Rome'));
            $quando = CarbonImmutable::createFromFormat(strlen($ora) === 5 ? 'd/m/Y H:i' : 'd/m/Y H:i:s', $giorno . ' ' . $ora, $fuso);
        } catch (\Throwable) {
            return null;
        }
        if (! $quando instanceof CarbonImmutable) {
            return null;
        }
        $testo = fn ($nodo) => ($v = trim((string) ($nodo ?? ''))) === '' ? null : $v;

        return new self(
            strtolower(trim((string) ($doc['tipo'] ?? ''))),
            strtolower(trim((string) ($doc['errore'] ?? 'nessuno'))) ?: 'nessuno',
            $testo($doc->intestazione->mittente ?? null),
            $testo($doc->intestazione->oggetto ?? null),
            $testo($doc->dati->{'gestore-emittente'} ?? null),
            $quando->setTimezone((string) config('app.user_timezone', 'Europe/Rome')),
            $testo($doc->dati->identificativo ?? null),
            $testo($doc->dati->consegna ?? null),
            array_values(array_filter(array_map(fn ($d) => trim((string) $d), iterator_to_array($doc->intestazione->destinatari ?? [], false)))),
        );
    }

    public function eDiConsegna(): bool
    {
        return $this->tipo === self::CONSEGNA && $this->errore === 'nessuno' && $this->consegnataA !== null;
    }

    /** L'avviso di mancata consegna: la PEC non è arrivata nella casella del destinatario. */
    public function eMancataConsegna(): bool
    {
        return $this->tipo === self::MANCATA_CONSEGNA;
    }

    /** L'indirizzo a cui la consegna è riuscita o non è riuscita: quello della consegna, o il primo dei destinatari. */
    public function indirizzo(): ?string
    {
        return $this->consegnataA ?? ($this->destinatari[0] ?? null);
    }

    /** Il tipo della ricevuta in parole, per la frase che dice perché non vale. */
    public function tipoInParole(): string
    {
        return match ($this->tipo) {
            'accettazione' => 'la ricevuta di accettazione (il gestore ha preso la PEC, non l\'ha ancora consegnata)',
            'presa-in-carico' => 'la ricevuta di presa in carico',
            'errore-consegna' => 'l\'avviso di mancata consegna: la PEC non è arrivata',
            'preavviso-errore-consegna' => 'il preavviso di mancata consegna: la PEC non è ancora arrivata, e il gestore la sta ancora provando',
            'non-accettazione' => 'l\'avviso di non accettazione: la PEC non è partita',
            'rilevazione-virus' => 'l\'avviso di un virus nel messaggio',
            'posta-certificata' => 'un messaggio PEC, non una ricevuta',
            default => 'una ricevuta che non è di consegna',
        };
    }

    /** I dati che restano sulla riga dell'invio e nel registro delle modifiche. @return array<string, ?string> */
    public function perLaRiga(): array
    {
        return [
            'tipo' => $this->tipo,
            'consegnata_a' => $this->consegnataA,
            'il' => $this->quando->toIso8601String(),
            'gestore' => $this->gestore,
            'identificativo' => $this->identificativo,
        ];
    }

    /** Due indirizzi uguali, senza le maiuscole e gli spazi. */
    public static function stessoIndirizzo(?string $a, ?string $b): bool
    {
        $pulito = fn (?string $x) => strtolower(trim((string) preg_replace('/^.*<([^>]+)>.*$/', '$1', trim((string) $x))));

        return $pulito($a) !== '' && $pulito($a) === $pulito($b);
    }

    /** Due oggetti uguali, senza gli spazi in più. */
    public static function stessoOggetto(?string $a, ?string $b): bool
    {
        $pulito = fn (?string $x) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $x)));

        return $pulito($a) === $pulito($b);
    }
}

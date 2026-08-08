<?php

namespace App\Services\Import\Parser;

use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\EsitoVerifica;
use App\Services\Import\Foglio;
use App\Services\Import\Rilievo;
use Carbon\CarbonImmutable;

/**
 * Legge la testata di una stampa e ne ricava **condominio** ed **esercizio** — i livelli 1 e 2
 * del §5.
 *
 * Le stampe con banner portano in testa quattro informazioni che nel resto del file non
 * compaiono più:
 *
 * ```
 * Movimenti
 *
 * Condominio Rubino - C. Fisc. 00321760282        Esercizio ordinario "2026"
 * Via Roma, 15 - 35100 Padova (PD)                Periodo: 01/01/2026 - 31/12/2026
 * ```
 *
 * È il punto in cui l'importatore smette di essere un lettore di tabelle e comincia a capire
 * *di chi* sono i dati. E, non secondario, è la fetta che rende vera la frase «carichi un file
 * e vedi comparire il tuo condominio».
 *
 * ## Le due regole non negoziabili
 *
 * 1. **Le date dell'esercizio si leggono, non si deducono.** L'etichetta `"2024/2025"` non è
 *    una fonte: il periodo reale è dichiarato dalla riga `Periodo:` e va da novembre a ottobre.
 *    Dedurre l'anno solare da quell'etichetta sposterebbe l'esercizio di due mesi.
 * 2. **Un dato che non c'è non si inventa.** Senza la riga `Periodo:` l'esercizio non si
 *    importa e lo si dice; senza il codice fiscale il condominio entra lo stesso, con un avviso.
 *    Sono due severità diverse perché sono due situazioni diverse (§10.1).
 */
final class BannerParser
{
    /** Entro quante righe dall'alto vive la testata in tutti i report reali. */
    private const RIGHE_TESTATA = 6;

    /**
     * @return array{
     *     condominio: CanonicalCondominio|null,
     *     esercizio: CanonicalEsercizio|null,
     *     esito: EsitoVerifica
     * }
     */
    public function estrai(Foglio $foglio): array
    {
        $testo = $this->righeTestata($foglio);

        [$condominio, $rilieviCondominio] = $this->condominio($testo);
        [$esercizio, $rilieviEsercizio] = $this->esercizio($testo);

        // «Righe totali» qui vale 1: la testata descrive **un** condominio e **un** esercizio,
        // non un elenco. Il contatore resta a uno perché la schermata S3 è la stessa per tutti
        // i livelli, e un livello che ne importa uno solo deve dirlo con gli stessi numeri.
        $esito = new EsitoVerifica(1, [...$rilieviCondominio, ...$rilieviEsercizio]);

        return compact('condominio', 'esercizio', 'esito');
    }

    /**
     * @param  list<string>  $testo
     * @return array{0: CanonicalCondominio|null, 1: list<Rilievo>}
     */
    private function condominio(array $testo): array
    {
        // «Condominio <nome> - C. Fisc. <cf>». Il nome può contenere spazi, trattini e
        // maiuscole miste: si prende tutto ciò che sta fra la parola chiave e il separatore.
        $riga = $this->primaCheCombacia($testo, '/^condominio\s+(?<nome>.+?)\s*-\s*c\.?\s*fisc\.?\s*(?<cf>[A-Za-z0-9]*)\s*$/iu', $m);

        if ($riga === null) {
            return [null, [Rilievo::errore(
                'condominio.assente',
                'Non trovo il condominio nella testata del file.',
                'Verifica di aver esportato la stampa completa: la riga con il nome del condominio '
                .'e il codice fiscale è la prima cosa che Danea scrive in alto.',
            )]];
        }

        $rilievi = [];
        $nome = trim($m['nome']);
        $cf = strtoupper(trim($m['cf'])) ?: null;

        // Trappola 13: negli export reali certi accenti arrivano già corrotti in `?`. Non
        // possiamo ripararlo — non sappiamo quale lettera c'era — ma nasconderlo sarebbe
        // peggio: quel nome finisce sulle convocazioni.
        if (str_contains($nome, '?')) {
            $rilievi[] = Rilievo::avviso(
                'condominio.nome_corrotto',
                "Il nome «{$nome}» contiene un punto interrogativo: nel file di origine gli accenti sono già rovinati.",
                'Correggilo dopo l\'importazione dalla scheda del condominio: il file di partenza non contiene la lettera giusta.',
            );
        }

        if ($cf === null) {
            $rilievi[] = Rilievo::avviso(
                'condominio.cf_mancante',
                'Il condominio non ha un codice fiscale nella testata.',
                'Puoi importarlo lo stesso e aggiungerlo dopo, ma serve per gli adempimenti fiscali.',
            );
        } elseif (! preg_match('/^\d{11}$/', $cf) && ! preg_match('/^[A-Z0-9]{16}$/', $cf)) {
            $rilievi[] = Rilievo::avviso(
                'condominio.cf_malformato',
                "Il codice fiscale «{$cf}» non ha una forma valida (11 cifre per un condominio).",
                'Lo importo così com\'è: controllalo nella scheda del condominio dopo l\'importazione.',
            );
        }

        [$indirizzo, $cap, $comune, $provincia] = $this->indirizzo($testo);

        return [
            new CanonicalCondominio($nome, $cf, $indirizzo, $cap, $comune, $provincia),
            $rilievi,
        ];
    }

    /**
     * «Via Nazionale 92 - 99096 Venezia (VE)».
     *
     * @param  list<string>  $testo
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function indirizzo(array $testo): array
    {
        $riga = $this->primaCheCombacia(
            $testo,
            '/^(?<via>.+?)\s*-\s*(?<cap>\d{5})\s+(?<comune>.+?)\s*\((?<prov>[A-Za-z]{2})\)\s*$/u',
            $m,
        );

        if ($riga === null) {
            return [null, null, null, null];
        }

        // Gli export reali hanno doppi spazi dentro l'indirizzo («Via Nazionale  92»):
        // si compattano, perché lì non significano niente.
        return [
            preg_replace('/\s+/', ' ', trim($m['via'])),
            $m['cap'],
            preg_replace('/\s+/', ' ', trim($m['comune'])),
            strtoupper($m['prov']),
        ];
    }

    /**
     * @param  list<string>  $testo
     * @return array{0: CanonicalEsercizio|null, 1: list<Rilievo>}
     */
    private function esercizio(array $testo): array
    {
        // «Periodo: 01/11/2024 - 31/10/2025» — è **questa** la fonte delle date, non l'etichetta.
        $riga = $this->primaCheCombacia(
            $testo,
            '/periodo\s*:\s*(?<inizio>\d{1,2}\/\d{1,2}\/\d{4})\s*-\s*(?<fine>\d{1,2}\/\d{1,2}\/\d{4})/iu',
            $m,
        );

        if ($riga === null) {
            return [null, [Rilievo::errore(
                'esercizio.periodo_assente',
                'Non trovo il periodo dell\'esercizio nella testata del file.',
                'Senza le date di apertura e chiusura non posso creare l\'esercizio: dedurle '
                .'dall\'anno sarebbe sbagliato per tutti i condomìni con esercizio sfalsato. '
                .'Esporta di nuovo la stampa completa, oppure crea l\'esercizio a mano.',
            )]];
        }

        $inizio = $this->data($m['inizio']);
        $fine = $this->data($m['fine']);

        if ($inizio === null || $fine === null) {
            return [null, [Rilievo::errore(
                'esercizio.date_illeggibili',
                "Le date del periodo non si interpretano: «{$m['inizio']}» – «{$m['fine']}».",
                'Attese nel formato giorno/mese/anno.',
            )]];
        }

        if ($inizio >= $fine) {
            return [null, [Rilievo::errore(
                'esercizio.periodo_incoerente',
                sprintf(
                    'Il periodo dell\'esercizio finisce prima di cominciare: dal %s al %s.',
                    $inizio->format('d/m/Y'),
                    $fine->format('d/m/Y'),
                ),
                'Controlla la stampa: probabilmente è stata esportata con un filtro di date sbagliato.',
            )]];
        }

        // «Esercizio ordinario "2024/2025"» — etichetta e tipo. Sono contorno: se mancano si
        // ricava un'etichetta dalle date, che è l'unica fonte che conta.
        $etichetta = null;
        $tipo = null;

        if ($this->primaCheCombacia($testo, '/esercizio\s+(?<tipo>\w+)?\s*"(?<etichetta>[^"]+)"/iu', $me) !== null) {
            $etichetta = trim($me['etichetta']);
            $tipo = isset($me['tipo']) && $me['tipo'] !== '' ? mb_strtolower($me['tipo']) : null;
        }

        $etichetta ??= $inizio->year === $fine->year
            ? (string) $inizio->year
            : $inizio->year.'/'.$fine->year;

        return [new CanonicalEsercizio($etichetta, $inizio, $fine, $tipo), []];
    }

    private function data(string $valore): ?CarbonImmutable
    {
        try {
            $d = CarbonImmutable::createFromFormat('!d/m/Y', trim($valore));
        } catch (\Throwable) {
            return null;
        }

        return $d ?: null;
    }

    /**
     * Le celle non vuote delle prime righe, come stringhe.
     *
     * La testata occupa più celle sulla stessa riga — condominio a sinistra, esercizio a destra
     * — quindi si appiattisce tutto in un elenco di stringhe e si cerca lì dentro, invece di
     * assumere quale colonna contenga cosa.
     *
     * @return list<string>
     */
    private function righeTestata(Foglio $foglio): array
    {
        $testo = [];
        $limite = min($foglio->numeroRighe(), self::RIGHE_TESTATA);

        for ($i = 0; $i < $limite; $i++) {
            foreach ($foglio->riga($i) as $cella) {
                $valore = trim((string) ($cella ?? ''));

                if ($valore !== '') {
                    $testo[] = $valore;
                }
            }
        }

        return $testo;
    }

    /**
     * @param  list<string>  $testo
     */
    private function primaCheCombacia(array $testo, string $pattern, ?array &$match = null): ?string
    {
        foreach ($testo as $riga) {
            if (preg_match($pattern, $riga, $m)) {
                $match = $m;

                return $riga;
            }
        }

        $match = null;

        return null;
    }
}

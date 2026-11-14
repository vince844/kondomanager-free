<?php

namespace App\Services\Gestionale\Invii;

use Carbon\CarbonInterface;

/**
 * I pezzi comuni dei documenti che si mandano a una persona, uno o tanti: la ricevuta dell'incasso e le lettere (promemoria, sollecito,
 * diffida, messa in mora). Non sanno che cosa c'è dentro il documento: l'elenco degli indirizzi dell'archivio .zip ({@see ArchivioAPezzi}),
 * il nome di file non ancora usato, le persone che stanno nel tetto dei messaggi senza il servizio della coda, le parti di un PDF troppo
 * lungo a persone intere. Chi li chiama dà il titolo, le parole per contare i suoi documenti e l'eccezione con cui dice all'amministratore
 * che cosa non si è potuto fare.
 */
final class InviiPerPersona
{
    /** Il file dell'archivio con gli indirizzi email delle persone. */
    public const FILE_DEGLI_INDIRIZZI = 'Indirizzi email.txt';

    /** La frase quando l'archivio non si può scrivere sul server. */
    public const ARCHIVIO_NON_FATTO = 'Non è stato possibile preparare l\'archivio sul server. Riprova; se si ripete, avvisa chi gestisce l\'installazione.';

    /**
     * Un nome di file non ancora usato nell'archivio: due persone con lo stesso nome hanno «Anna Bruni» e «Anna Bruni (2)». Il confronto non
     * guarda le maiuscole, come i dischi di Windows e del Mac.
     *
     * @param array<int, string> $usati
     */
    public static function nomeLibero(string $base, array &$usati): string
    {
        $nome = $base;
        for ($n = 2; in_array(mb_strtolower($nome . '.pdf'), $usati, true); $n++) {
            $nome = $base . ' (' . $n . ')';
        }
        $usati[] = mb_strtolower($nome . '.pdf');

        return $nome;
    }

    /**
     * L'elenco degli indirizzi dell'archivio: per ogni persona quanti documenti, il nome del suo file e i suoi indirizzi per tipo (la PEC
     * compresa: l'email la manda l'amministratore dal suo programma, e sceglie lui); chi non ne ha nessuno lo dice. A capo come in Windows e con
     * il segno UTF-8 in testa, così il Blocco note legge anche gli accenti dei nomi.
     *
     * @param array<int, array{nome: string, indirizzi: array<string, string>, documenti: int, file: string}> $elenco
     * @param callable(int): string $quanti «1 ricevuta», «3 lettere»
     * @param array<string, string> $tipiIndirizzo il nome di ogni tipo d'indirizzo
     */
    public static function elencoDegliIndirizzi(string $titolo, string $condominio, array $elenco, CarbonInterface $adesso, callable $quanti, array $tipiIndirizzo,
        ?string $istruzione = null): string
    {
        $righe = [
            $titolo . ' — ' . $condominio . ' — ' . $adesso->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y'),
            // Le ricevute si mandano per email; chi chiama può dire come si spediscono i suoi PDF (le lettere).
            $istruzione ?? 'Un PDF per persona, da mandare dal tuo programma di posta.',
        ];
        foreach ($elenco as $p) {
            $righe[] = '';
            $righe[] = $p['nome'] . ' — ' . $quanti($p['documenti']) . ' — ' . $p['file'];
            if ($p['indirizzi'] === []) {
                $righe[] = '  nessun indirizzo email: da stampare per la posta';
            }
            foreach ($p['indirizzi'] as $tipo => $indirizzo) {
                $righe[] = '  ' . ($tipiIndirizzo[$tipo] ?? $tipo) . ': ' . $indirizzo;
            }
        }

        return "\u{FEFF}" . implode("\r\n", $righe) . "\r\n";
    }

    /**
     * Le persone di un invio che stanno nel tetto dei messaggi, nell'ordine dato, e quelle che restano: una persona intera o niente, così
     * nessuno riceve il documento a un indirizzo e non all'altro. La prima persona c'è sempre. Senza tetto (con il servizio della coda) tutte.
     *
     * @template T
     * @param array<int, T> $gruppi
     * @param callable(T): int $messaggiDi quanti messaggi fa una persona
     * @return array{0: array<int, T>, 1: ?array<int, T>} quelle che partono, e quelle che restano (null se nessuna)
     */
    public static function dentroIlTetto(array $gruppi, ?int $tetto, callable $messaggiDi): array
    {
        if ($tetto === null) {
            return [$gruppi, null];
        }

        $dentro = [];
        $contati = 0;
        foreach (array_values($gruppi) as $i => $g) {
            if ($dentro !== [] && $contati + $messaggiDi($g) > $tetto) {
                return [$dentro, array_slice(array_values($gruppi), $i)];
            }
            $dentro[] = $g;
            $contati += $messaggiDi($g);
        }

        return [$dentro, null];
    }

    /**
     * Le persone di un invio per email dentro i due tetti, a persone intere: prima i messaggi per volta (senza il servizio della coda, null con la
     * coda), poi i documenti per volta che si fanno nella richiesta (un PDF ciascuno, con qualunque coda; null se non ce n'è uno). Quelle che restano
     * sono quelle oltre i documenti, poi quelle oltre i messaggi. È la regola delle lettere che conservano il PDF e dei fogli del conguaglio.
     *
     * @template T
     * @param array<int, T> $gruppi
     * @param callable(T): int $messaggiDi
     * @return array{0: array<int, T>, 1: ?array<int, T>}
     */
    public static function dentroIDueTetti(array $gruppi, ?int $tettoMessaggi, ?int $tettoDocumenti, callable $messaggiDi): array
    {
        [$dentro, $fuori] = self::dentroIlTetto($gruppi, $tettoMessaggi, $messaggiDi);
        if ($tettoDocumenti === null) {
            return [$dentro, $fuori];
        }
        [$dentro, $oltre] = self::dentroIlTetto($dentro, $tettoDocumenti, fn ($g) => 1);

        return [$dentro, $oltre === null ? $fuori : [...$oltre, ...($fuori ?? [])]];
    }

    /**
     * I documenti in più parti quando sono più del tetto di un PDF: le persone nell'ordine dato, ognuna intera in una parte (una sola persona
     * con più documenti del tetto si divide da sé). Ogni parte sta nel tetto; la prima parte porta le prime persone, l'ultima le ultime.
     *
     * @param array<int, array<int, int>> $perPersona gli identificativi dei documenti di ogni persona, nell'ordine della stampa
     * @return array<int, array<int, int>>
     */
    public static function partiPerPersone(array $perPersona, int $tetto): array
    {
        $parti = [];
        $corrente = [];
        foreach ($perPersona as $ids) {
            foreach (array_chunk(array_values($ids), $tetto) as $pezzo) {
                if ($corrente !== [] && count($corrente) + count($pezzo) > $tetto) {
                    $parti[] = $corrente;
                    $corrente = [];
                }
                $corrente = array_merge($corrente, $pezzo);
            }
        }
        if ($corrente !== []) {
            $parti[] = $corrente;
        }

        return $parti;
    }
}

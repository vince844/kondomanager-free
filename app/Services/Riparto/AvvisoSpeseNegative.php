<?php

namespace App\Services\Riparto;

use App\Enums\StatoPianoRate;
use App\Models\Gestionale\PianoRate;

/**
 * L'avviso della pagina del piano: il piano contiene una voce di spesa con un importo negativo, che il motore addebita in positivo.
 *
 * Con incassi registrati, rate emesse o un passaggio che ha conguagliato il piano, «Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» rifiutano già
 * per le loro ragioni: il fermo di `SpeseNegativeNelPiano` non si raggiunge e nessuno direbbe che la voce è stata addebitata in positivo. L'avviso
 * usa lo stesso rilevamento del fermo, in ogni stato del piano, nomina la voce con la cifra e dice le strade, tutte: la scelta è dell'amministratore.
 * Sono sola lettura: non cambia niente e non spegne nessuna via d'uscita (il cestino dipende da `fermo_del_piano`, che è un'altra proprietà).
 *
 * Dove nessuna voce è negativa, l'avviso dice di un capitolo con la copia a zero o negativa e dei sottoconti con degli importi (`SpeseNegativeNelPiano::capitoliConLaCopiaRimasta`):
 * corretto il sottoconto che il fermo nominava, «Ricalcola» passa e non segnala più niente, e il capitolo resta com'era. Non è un fermo, e la copia può essere voluta.
 */
final class AvvisoSpeseNegative
{
    /**
     * La strada del saldo dice dove entra la differenza e che cosa fare se la persona ha già un saldo. Il piano ha già letto i saldi quando è stato generato: un saldo
     * scritto dopo non lo cambia, entra nel prossimo piano della gestione che applica i saldi, o nell'esercizio dopo. Il Wallet accetta un solo saldo per persona,
     * gestione, unità ed esercizio, senza contare quelli scritti da un passaggio (`CreateSaldoRequest`): dove la persona ne ha già uno, il saldo nuovo si rifiuta e
     * si corregge quello che c'è, finché il piano che l'ha assorbito si può riscrivere: niente a giornale, nessun movimento sulle quote, nessun conguaglio (`UpdateSaldoRequest`, con `Saldo::eBloccato()`).
     * Se l'ha assorbito un piano che non si riscrive più non si fa né l'uno né l'altro, e la differenza va nell'esercizio dopo; un saldo da cui una successione ha
     * calcolato l'arretrato agli eredi non si corregge, e il Wallet dice la strada (`Saldo::fraseFonteDellArretrato()`).
     */
    public const STRADA_DEL_SALDO = 'Se il piano non si può più riscrivere (incassi o crediti usati da tenere, o un passaggio di titolarità che l\'ha conguagliato), regola la differenza con un saldo sulle unità, da «Saldi iniziali»: '
        . 'questo piano non lo legge più, entra nel prossimo piano della gestione che applica i saldi, o nell\'esercizio dopo. '
        . 'Il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio: dove la persona ne ha già uno, si corregge quello, se nessun piano l\'ha ancora assorbito o se il piano che l\'ha assorbito si può ancora riscrivere; se l\'ha già assorbito un piano che non si riscrive più, il Wallet non accetta né un secondo saldo né la correzione, e per quella persona la differenza va nell\'esercizio dopo. Se il saldo è fra quelli da cui una successione ha calcolato l\'arretrato agli eredi, il Wallet lo dice e indica la strada.';

    public function __construct(private SpeseNegativeNelPiano $spese) {}

    /**
     * @return array{titolo: string, voci: list<string>, situazione: string, strade: list<string>}|null null se il piano non ha voci di spesa negative né capitoli con la copia rimasta
     */
    public function perLaPagina(PianoRate $piano): ?array
    {
        $voci = $this->spese->trova($piano);
        // Con una voce negativa il fermo nomina già i capitoli da ricreare: la copia rimasta si dice solo dove il fermo non c'è.
        $capitoli = $voci === [] ? $this->spese->capitoliConLaCopiaRimasta($piano) : [];
        if ($voci === [] && $capitoli === []) {
            return null;
        }

        // Le ragioni per cui il piano non si riscrive (rate a giornale, incassi, crediti usati, conguaglio di un passaggio), calcolate una volta sola.
        return $piano->conIPassaggiCalcolati(function (PianoRate $piano) use ($voci, $capitoli) {
            $ragioni = $piano->ragioniDelFermo();
            $approvato = $piano->stato === StatoPianoRate::APPROVATO;

            if ($voci === []) {
                return $this->perLaCopiaRimasta($piano, $capitoli, $ragioni, $approvato);
            }

            $correzioni = SpeseNegativeNelPiano::frasiDiCorrezione($voci);
            $generica = $this->haUnSottocontoDiUnaCopiaNonPositiva($voci);
            $cosa = $generica ? 'una voce con un importo negativo che il motore ripartisce male' : 'una voce di spesa negativa';

            if ($ragioni === []) {
                return [
                    'titolo' => $this->titolo(count($voci), $generica),
                    'voci' => array_map($this->voce(...), $voci),
                    'situazione' => $approvato
                        ? "Il piano è approvato: «Ricalcola» si ferma finché nel piano c'è {$cosa}, e per correggerlo va prima riportato in bozza."
                        : "«Ricalcola» si ferma finché nel piano c'è {$cosa}: il piano resta com'è, con la cifra sbagliata.",
                    'strade' => array_merge($approvato ? ['Riporta il piano in bozza (' . SpeseNegativeNelPiano::COSTO_DELLA_BOZZA . ').'] : [], $correzioni),
                ];
            }

            return [
                'titolo' => $this->titolo(count($voci), $generica),
                'voci' => array_map($this->voce(...), $voci),
                'situazione' => 'Il piano ' . $piano->fraseDelFermo() . ': «Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» lo rifiutano, quindi nessuno dei tre arriva a togliere la voce negativa dalle quote.',
                'strade' => array_merge(
                    [$this->perRiscriverlo($ragioni, $approvato)],
                    $correzioni,
                    [self::STRADA_DEL_SALDO],
                ),
            ];
        });
    }

    /**
     * Il capitolo con la copia a zero o negativa che il piano ripartisce fra sottoconti con degli importi: l'unica strada è ricreare il piano, e se non si può riscrivere si regola con un saldo.
     *
     * @param  list<array{conto_id: int, nome: string, copia: int, sottoconti: list<string>}>  $capitoli
     * @param  list<string>  $ragioni  `PianoRate::ragioniDelFermo()`
     * @return array{titolo: string, voci: list<string>, situazione: string, strade: list<string>}
     */
    private function perLaCopiaRimasta(PianoRate $piano, array $capitoli, array $ragioni, bool $approvato): array
    {
        $strada = 'Se non è la cifra che volevi (per esempio hai corretto i sottoconti dopo aver creato il piano), elimina il piano e ricrealo: la copia di un capitolo si scrive solo quando il piano nasce, '
            . 'e «Ricalcola» non la cambia. ' . SpeseNegativeNelPiano::COSTO_DEL_PIANO_NUOVO;
        $titolo = count($capitoli) === 1
            ? 'Il piano contiene un capitolo con la copia a zero o negativa'
            : 'Il piano contiene ' . count($capitoli) . ' capitoli con la copia a zero o negativa';
        $voci = array_map($this->capitolo(...), $capitoli);

        if ($ragioni === []) {
            return [
                'titolo' => $titolo,
                'voci' => $voci,
                'situazione' => $approvato
                    ? 'Il piano è approvato: «Ricalcola» non cambia la copia del capitolo, e per ricrearlo va prima riportato in bozza.'
                    : '«Ricalcola» non segnala questo capitolo e non ne cambia la copia: se è la cifra che volevi, non c\'è niente da correggere.',
                'strade' => array_merge($approvato ? ['Riporta il piano in bozza (' . SpeseNegativeNelPiano::COSTO_DELLA_BOZZA . ').'] : [], [$strada]),
            ];
        }

        return [
            'titolo' => $titolo,
            'voci' => $voci,
            'situazione' => 'Il piano ' . $piano->fraseDelFermo() . ': «Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» lo rifiutano, quindi nessuno dei tre arriva a cambiare la copia del capitolo.',
            'strade' => [$this->perRiscriverlo($ragioni, $approvato, 'decidi come segue'), $strada, self::STRADA_DEL_SALDO],
        ];
    }

    /** @param  array{conto_id: int, nome: string, copia: int, sottoconti: list<string>}  $c */
    private function capitolo(array $c): string
    {
        $nomi = array_map(fn (string $nome) => "«{$nome}»", $c['sottoconti']);
        $chi = match (true) {
            count($nomi) === 1 => "il suo sottoconto {$nomi[0]} ha un importo",
            count($nomi) <= 3 => 'i suoi sottoconti ' . SpeseNegativeNelPiano::elenco($nomi) . ' hanno degli importi',
            default => count($nomi) . ' dei suoi sottoconti hanno degli importi',
        };

        return "Il capitolo «{$c['nome']}» ha nel piano una copia " . SpeseNegativeNelPiano::descriviLaCopia($c['copia']) . " e {$chi}: il piano ripartisce la copia fra i sottoconti, non i loro importi.";
    }

    /** @param  bool  $generica  fra le voci c'è un sottoconto di un capitolo con la copia non positiva, che può essere anche di entrata */
    private function titolo(int $quante, bool $generica = false): string
    {
        $di = $generica ? '' : ' di spesa';

        return $quante === 1
            ? "Il piano contiene una voce{$di} con un importo negativo"
            : "Il piano contiene {$quante} voci{$di} con un importo negativo";
    }

    /** @param  list<array<string, mixed>>  $voci */
    private function haUnSottocontoDiUnaCopiaNonPositiva(array $voci): bool
    {
        foreach ($voci as $v) {
            if ($v['origine'] === SpeseNegativeNelPiano::ORIGINE_FIGLIO && $v['copia_del_capitolo'] !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $v */
    private function voce(array $v): string
    {
        $cifra = SpeseNegativeNelPiano::cifra($v['importo']);

        $misto = (bool) ($v['misto'] ?? false);
        $divisione = 'la divide fra i suoi sottoconti di spesa e di entrata: il totale del piano non è quello del capitolo.';

        return match (true) {
            $v['origine'] === SpeseNegativeNelPiano::ORIGINE_FIGLIO && $v['copia_del_capitolo'] !== null && ($v['fuori_dal_piano'] ?? false) => "Il sottoconto «{$v['nome']}», sotto il capitolo «{$v['padre']}», ha un importo negativo (meno {$cifra}) ed è nato dopo il piano: il piano non lo ripartisce, e il capitolo, che ha nel piano una copia " . SpeseNegativeNelPiano::descriviLaCopia($v['copia_del_capitolo']) . ', non chiede niente.',
            $v['origine'] === SpeseNegativeNelPiano::ORIGINE_FIGLIO && $v['copia_del_capitolo'] !== null => "Il sottoconto «{$v['nome']}», sotto il capitolo «{$v['padre']}», ha un importo negativo (meno {$cifra}) e il capitolo ha nel piano una copia " . SpeseNegativeNelPiano::descriviLaCopia($v['copia_del_capitolo']) . ': il piano ripartisce la copia anche su di lui, e il totale non è quello del capitolo.',
            $v['origine'] === SpeseNegativeNelPiano::ORIGINE_FIGLIO => "Il sottoconto «{$v['nome']}», sotto il capitolo «{$v['padre']}», ha un importo negativo (meno {$cifra}): il piano ripartisce l'importo del capitolo anche su di lui, e il totale supera quello del capitolo.",
            SpeseNegativeNelPiano::giaCorretta($v) => "La voce «{$v['nome']}» è già corretta, ma il piano ne tiene ancora la copia negativa (meno {$cifra}) e " . ($misto ? $divisione : "la addebita in positivo, per {$cifra}."),
            SpeseNegativeNelPiano::soloLaCopia($v) => "La voce «{$v['nome']}» ha nel piano una copia negativa (meno {$cifra}) e il piano " . ($misto ? $divisione : "la addebita in positivo, per {$cifra}."),
            default => "La voce «{$v['nome']}» ha un importo negativo (meno {$cifra}) e il piano la addebita in positivo, per {$cifra}.",
        };
    }

    /**
     * Che cosa annullare per poter riscrivere il piano, nell'ordine che funziona: i movimenti sulle quote prima (un'emissione con incassi non si annulla),
     * poi le emissioni; un passaggio che ha conguagliato il piano si annulla dal suo storico, con i passi che il pulsante «Ricalcola» già elenca.
     *
     * @param  list<string>  $ragioni  `PianoRate::ragioniDelFermo()`
     * @param  string  $poi  che cosa si fa dopo aver annullato: «correggi come segue» per una voce negativa, un'altra chiusa per la copia di un capitolo
     */
    private function perRiscriverlo(array $ragioni, bool $approvato, string $poi = 'correggi come segue'): string
    {
        $movimenti = array_values(array_filter([
            in_array('incasso', $ragioni, true) ? 'gli incassi registrati' : null,
            in_array('credito', $ragioni, true) ? 'i crediti usati o rimborsati' : null,
        ]));
        $emissioni = in_array('scrittura', $ragioni, true);

        $passi = match (true) {
            $movimenti !== [] && $emissioni => 'annulla prima ' . implode(' e ', $movimenti) . ', poi le emissioni delle rate',
            $movimenti !== [] => 'annulla ' . implode(' e ', $movimenti),
            $emissioni => 'annulla le emissioni delle rate',
            default => '',
        };
        if (in_array('conguaglio', $ragioni, true)) {
            $passaggio = 'annulla il passaggio che ha conguagliato il piano e registralo di nuovo (i passi sono sul pulsante «Ricalcola»)';
            $passi = $passi === '' ? $passaggio : $passi . ' e ' . $passaggio;
        }

        return 'Per riscrivere il piano ' . $passi . ($approvato ? ' e riportalo in bozza (' . SpeseNegativeNelPiano::COSTO_DELLA_BOZZA . ')' : '') . ", poi {$poi}.";
    }
}

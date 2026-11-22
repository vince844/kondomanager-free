<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;

/**
 * Le cifre del verbale (V3, scena 2 della bozza approvata): per ogni parte «Dati della sala» del testo corretto, le cifre del testo confrontate con quelle che
 * la sala dà adesso. Una cifra che non torna è l'avviso «Nel punto 4 il verbale dice «496,40», i voti registrati dicono 506,40», con «Rimetti 506,40» (la
 * pagina cambia quella cifra nell'editor) e «Vai al voto del punto 4» (il voto si corregge in sala fino alla registrazione); **finché c'è, «Stampa la bozza da
 * firmare» chiede prima**. Una parte segnata tolta dal testo è un avviso anche lei.
 *
 * Se l'impronta dei dati letti alla generazione non è più quella di adesso (un voto corretto dopo), la pagina dice «I dati della sala sono cambiati alle 21:20:
 * aggiorna le parti segnate» ({@see ComposizioneDelVerbale::aggiornaLeParti()}).
 *
 * Una parte segnata cambiata a mano senza toccare le cifre (un nome al posto di un altro: «Gallo Enzo» al posto di «Colombo Rita» fra i contrari) è un avviso
 * leggero, `a_mano`: «Nel punto 4 la parte dei voti è cambiata a mano: i nomi non sono quelli registrati.». Non ferma la stampa (la correzione può essere
 * voluta), ma la bozza da firmare non dice più in silenzio una cosa diversa dai voti.
 *
 * @phpstan-type Controllo array{avvisi: list<array<string, mixed>>, sala_cambiata: ?array{alle: ?string}, a_mano: list<array<string, mixed>>}
 */
final class CifreDellaSala
{
    /** Le cifre di un testo: «11», «26», «484,60», «1.000», «14.500,00», nell'ordine. */
    private const CIFRA = '/\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:,\d+)?/u';

    public function __construct(private readonly ComposizioneDelVerbale $composizione) {}

    /**
     * Gli avvisi delle cifre e il cambio dei dati della sala, per la pagina.
     *
     * @return array{avvisi: list<array<string, mixed>>, sala_cambiata: ?array{alle: ?string}, a_mano: list<array<string, mixed>>}
     */
    public function controlla(VerbaleDellAssemblea $verbale): array
    {
        $parte = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->first();
        if ($parte === null || ! $verbale->siScrive()) {
            return ['avvisi' => [], 'sala_cambiata' => null, 'a_mano' => []];
        }
        $adesso = $this->composizione->blocchiDiAdesso($verbale);
        $nelTesto = StrutturaDelVerbale::blocchi((array) $parte->documento);
        $avvisi = [];
        $conCifre = [];
        foreach ($nelTesto as $chiave => $testo) {
            if (! isset($adesso[$chiave])) {
                continue;
            }
            $cifreDelTesto = self::cifre($testo);
            $cifreDiAdesso = self::cifre(StrutturaDelVerbale::testo($adesso[$chiave]));
            $quante = max(count($cifreDelTesto), count($cifreDiAdesso));
            for ($i = 0; $i < $quante; $i++) {
                $qui = $cifreDelTesto[$i] ?? null;
                $la = $cifreDiAdesso[$i] ?? null;
                if ($qui === $la) {
                    continue;
                }
                $dove = $this->dove($chiave);
                $conCifre[$chiave] = true;
                $avvisi[] = ['chiave' => $chiave, 'punto' => $dove['punto'], 'votazione_id' => $dove['votazione_id'], 'occorrenza' => $i, 'nel_testo' => $qui,
                    'adesso' => $la, 'frase' => ($dove['punto'] !== null ? 'Nel punto ' . $dove['punto'] : 'Nella costituzione') . ' il verbale dice «'
                        . ($qui ?? '—') . '», ' . ($dove['votazione_id'] !== null ? 'i voti registrati dicono ' : 'la sala dice ') . ($la ?? '—') . '.',
                    'rimetti' => $la !== null ? 'Rimetti ' . $la : null, 'vai_al_voto' => $dove['punto'] !== null ? 'Vai al voto del punto ' . $dove['punto'] : null];
                break;
            }
        }
        foreach (array_keys($adesso) as $chiave) {
            if (str_starts_with($chiave, 'voto:') && ! isset($nelTesto[$chiave])) {
                $dove = $this->dove($chiave);
                $avvisi[] = ['chiave' => $chiave, 'punto' => $dove['punto'], 'votazione_id' => $dove['votazione_id'], 'occorrenza' => null, 'nel_testo' => null,
                    'adesso' => null, 'frase' => 'Nel punto ' . $dove['punto'] . ' manca la parte con i voti: «Aggiorna le parti segnate» la rimette.',
                    'rimetti' => null, 'vai_al_voto' => null];
            }
        }
        $cambiata = $verbale->impronta_sala !== null && $this->composizione->improntaDiAdesso($verbale) !== $verbale->impronta_sala;
        // Le parti dei voti cambiate a mano senza toccare le cifre: i nomi, le parole.
        $aMano = [];
        foreach ($nelTesto as $chiave => $testo) {
            if (isset($conCifre[$chiave]) || ! isset($adesso[$chiave]) || ! str_starts_with($chiave, 'voto:')) {
                continue;
            }
            if (self::normale($testo) !== self::normale(StrutturaDelVerbale::testo($adesso[$chiave]))) {
                $dove = $this->dove($chiave);
                $aMano[] = ['chiave' => $chiave, 'punto' => $dove['punto'], 'frase' => 'Nel punto ' . $dove['punto'] . ' la parte dei voti è cambiata a mano: i nomi '
                    . 'non sono quelli registrati. «Aggiorna le parti segnate» la rifà dai voti.'];
            }
        }

        return ['avvisi' => $avvisi, 'sala_cambiata' => $cambiata ? ['alle' => $this->ultimoCambioDellaSala($verbale)] : null, 'a_mano' => $aMano];
    }

    /** Il testo senza le differenze che non contano: gli spazi in più. */
    private static function normale(string $testo): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $testo));
    }

    /** @return list<string> */
    public static function cifre(string $testo): array
    {
        preg_match_all(self::CIFRA, $testo, $m);

        return $m[0];
    }

    /** Il punto e la votazione di una parte segnata. @return array{punto: ?int, votazione_id: ?int} */
    private function dove(string $chiave): array
    {
        [$tipo, $id] = array_pad(explode(':', $chiave, 2), 2, null);
        if ($tipo === 'voto' && is_numeric($id)) {
            $v = VotazioneDelPunto::query()->with('punto:id,numero')->find((int) $id);

            return ['punto' => $v?->punto?->numero === null ? null : (int) $v->punto->numero, 'votazione_id' => $v?->id === null ? null : (int) $v->id];
        }
        if ($tipo === 'rinvio_punto' && is_numeric($id)) {
            return ['punto' => (int) (PuntoDellOrdineDelGiorno::query()->whereKey((int) $id)->value('numero') ?? 0) ?: null, 'votazione_id' => null];
        }

        return ['punto' => null, 'votazione_id' => null];
    }

    /** L'ora dell'ultimo voto cambiato nella riunione del verbale, come si legge: «21:20». */
    private function ultimoCambioDellaSala(VerbaleDellAssemblea $verbale): ?string
    {
        $quando = VotazioneDelPunto::query()->where('seduta_id', $verbale->seduta_id)->max('updated_at');

        return $quando === null ? null : ParoleDelVerbale::ora((string) $quando);
    }
}

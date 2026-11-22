<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\EsitoInvio;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\RegistroModifica;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Mpdf\Output\Destination;

/**
 * «Stampa la prova della convocazione» (§4.4 del progetto della tappa 2): per ogni convocato il mezzo, la spedizione, l'esito, il giorno che conta, se è
 * dentro i cinque giorni, il nome e l'impronta della prova dell'esito (quella che il registro ha scritto: se il file di oggi è diverso, lo dice) **e
 * l'impronta della riga del registro delle modifiche** del suo esito; in fondo chi manca e
 * l'impronta della stampa (SHA-256 delle righe stampate, che si rifà dagli stessi dati). È il documento che si porta in sala per il controllo
 * dell'art. 1136 c. 6 («non consta che tutti gli aventi diritto sono stati regolarmente convocati»).
 */
final class ProvaDellaConvocazione
{
    public function __construct(
        private readonly ElencoDeiConvocati $elenco,
        private readonly TerminiDellaConvocazione $termini,
        private readonly ArchivioDocumenti $archivio,
        private readonly PdfService $pdf,
    ) {}

    /** I dati della stampa. @return array<string, mixed> */
    public function dati(Assemblea $assemblea): array
    {
        $assemblea->loadMissing(['condominio', 'sedute']);
        $voci = $this->elenco->per($assemblea);
        $ultimo = $this->termini->ultimoGiorno($assemblea);
        $prima = $this->termini->primaConvocazione($assemblea);
        $ids = collect($voci)->flatMap(fn ($v) => array_map(fn ($i) => (int) $i->id, $v['invii']))->all();
        $documenti = Documento::query()->where('documentable_type', RegistroInvio::class)->whereIn('documentable_id', $ids ?: [0])->get();
        $perInvio = $documenti->groupBy(fn (Documento $d) => (int) $d->documentable_id);
        $fogli = $documenti->keyBy('id');
        $registro = RegistroModifica::query()->where('oggetto_tipo', 'assemblea')->where('oggetto_id', $assemblea->id)->whereNotNull('lotto')->orderBy('id')->get();
        // La riga del registro dell'esito di ogni invio (l'ultima), la prova che quella riga ha registrato, e l'impronta registrata di ogni prova.
        $rigaDi = [];
        $provaDi = [];
        $registrata = [];
        foreach ($registro as $r) {
            $lotto = (array) $r->lotto;
            $prova = is_array($lotto['prova'] ?? null) ? $lotto['prova'] : null;
            if ($prova !== null && isset($prova['documento_id'])) {
                $registrata[(int) $prova['documento_id']] = (string) ($prova['impronta'] ?? '');
            }
            if (isset($lotto['invio_id']) && array_key_exists('esito', $lotto)) {
                $rigaDi[(int) $lotto['invio_id']] = $r;
                $provaDi[(int) $lotto['invio_id']] = $prova !== null ? (int) $prova['documento_id'] : ($provaDi[(int) $lotto['invio_id']] ?? null);
            }
            foreach ((array) ($lotto['consegne'] ?? []) as $c) {
                $rigaDi[(int) ($c['invio_id'] ?? 0)] = $r;
                if ($prova !== null) {
                    $provaDi[(int) ($c['invio_id'] ?? 0)] = (int) $prova['documento_id'];
                }
            }
        }

        $righe = [];
        $mancano = [];
        foreach ($voci as $v) {
            /** @var ?RegistroInvio $invio */
            $invio = $v['invio'];
            $mezzo = $invio?->dati['mezzo'] ?? $v['mezzo']['mezzo'];
            $spedizione = match (true) {
                $invio === null => '—',
                $invio->canale === RegistroInvio::CANALE_PEC && $invio->stato === RegistroInvio::STATO_IN_CODA && $invio->esito === null => 'non si sa se è partita: il '
                    . $invio->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y \a\l\l\e H:i')
                    . ' il collegamento con il gestore non si è chiuso',
                $invio->canale === RegistroInvio::CANALE_PEC => 'partita il ' . $invio->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y \a\l\l\e H:i')
                    . ($invio->stato === RegistroInvio::STATO_NON_PARTITA ? ', non accettata' : ''),
                filled($invio->dati['spedizione']['data'] ?? null) => Carbon::parse((string) $invio->dati['spedizione']['data'])->format('d/m/Y')
                    . (filled($invio->dati['spedizione']['numero'] ?? null) ? ', n. ' . $invio->dati['spedizione']['numero'] : ''),
                default => 'stampata il ' . $invio->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y') . ', non ancora spedita',
            };
            $foglio = $invio !== null && isset($invio->dati['foglio_documento_id']) ? $fogli->get((int) $invio->dati['foglio_documento_id']) : null;
            // La prova stampata è quella dell'esito, come il registro l'ha scritta; senza, l'ultima allegata.
            $dellEsito = $invio !== null && isset($provaDi[(int) $invio->id]) ? $documenti->firstWhere('id', $provaDi[(int) $invio->id]) : null;
            $prova = $invio === null ? null : ($dellEsito ?? ConsegneDellaConvocazione::prova($invio, $perInvio->get((int) $invio->id) ?? collect(), $foglio));
            $ricevuta = $invio?->ricevuta_il?->format('Y-m-d');
            $inTempo = $this->termini->inTempo($assemblea, $ricevuta);
            $rigaDelRegistro = $invio === null ? null : ($rigaDi[(int) $invio->id] ?? null);
            $esito = $invio?->esito === null ? ($invio === null ? 'nessun avviso' : 'non ancora registrato')
                : mb_strtolower((string) EsitoInvio::parolaDi($invio->esito)) . ($invio->esito_il !== null ? ' il ' . $invio->esito_il->format('d/m/Y') : '');
            $riga = [
                'persona' => (string) $v['nome'] . ($v['ruolo'] !== null ? ' (' . $v['ruolo'] . ')' : ''),
                'unita' => (string) $v['unita_parole'],
                'mezzo' => MezzoDiConvocazione::parola($mezzo),
                'spedizione' => $spedizione,
                'esito' => $esito,
                'conta' => $ricevuta === null ? '—' : Carbon::parse($ricevuta)->format('d/m/Y'),
                'in_tempo' => $inTempo === null ? '—' : ($inTempo ? 'sì' : 'no'),
                'prova' => $prova === null ? '—' : $this->provaInParole($prova, $registrata[(int) $prova->id] ?? null),
                'registro' => $rigaDelRegistro === null ? '—' : 'n. ' . $rigaDelRegistro->id . ' · ' . mb_substr((string) ($rigaDelRegistro->impronta ?? $rigaDelRegistro->impronta_valori), 0, 12),
            ];
            $righe[] = $riga;
            if ($inTempo !== true) {
                $mancano[] = $riga['persona'] . ': ' . ($invio === null ? ($v['mezzo']['valido'] ? 'avviso non spedito' : 'senza un mezzo valido')
                    : ($inTempo === false ? 'ricevuto il ' . $riga['conta'] . ', dopo il termine' : $esito));
            }
        }
        $impronta = hash('sha256', json_encode($righe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'condominio' => $assemblea->condominio,
            'titolo' => 'Prova della convocazione',
            'assemblea' => 'Assemblea ' . mb_strtolower($assemblea->nome()) . ($prima !== null ? ', prima convocazione ' . $prima->format('d/m/Y \a\l\l\e H:i') : ''),
            'termine' => $ultimo === null ? null : 'Gli avvisi dovevano arrivare ' . ConsegneDellaConvocazione::entro($ultimo, 'd/m/Y') . ' (' . ($this->termini->giorniPrima($assemblea) === 20
                ? 'venti' : 'cinque') . ' giorni prima della prima convocazione, dalla ricezione: il giorno in cui arrivano conta, quello dell\'assemblea no).',
            'righe' => $righe,
            'mancano' => $mancano,
            'impronta' => $impronta,
            'stampata_il' => DateHelper::adessoUtente()->format('d/m/Y \a\l\l\e H:i'),
            'nota' => 'La raccomandata non ritirata conta dal giorno dell\'avviso di giacenza (Cass. 23396/2017). «Rifiutata» non conta da sola come ricevuta: '
                . 'decide il presidente. Ogni esito è anche una riga del registro delle modifiche dell\'assemblea, con la sua impronta.',
        ];
    }

    public function pdf(Assemblea $assemblea): string
    {
        return $this->pdf->generate('pdf.gestionale.assemblea_prova_convocazione', ['gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA]
            + $this->dati($assemblea), ['orientation' => 'L'])->Output('', Destination::STRING_RETURN);
    }

    /**
     * «avviso-giacenza.pdf · 7f3a9c…»: il nome e l'impronta registrata (o quella di oggi, per una prova di prima); se il file di oggi ha un'altra impronta, lo
     * dice: il file è stato sostituito dopo la registrazione.
     */
    private function provaInParole(Documento $documento, ?string $registrata): string
    {
        $oggi = $this->impronta($documento);
        $registrata = $registrata === '' ? null : $registrata;
        $testo = $documento->name . ' · ' . mb_substr((string) ($registrata ?? $oggi), 0, 12);

        return $registrata !== null && $oggi !== $registrata ? $testo . ' · il file di oggi ha un\'impronta diversa da quella registrata' : $testo;
    }

    private function impronta(Documento $documento): ?string
    {
        $contenuto = $this->archivio->leggi($documento->path);

        return $contenuto === null ? null : hash('sha256', $contenuto);
    }
}

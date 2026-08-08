<?php

namespace App\Services\Import\Controlli;

use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;

/**
 * «Cosa mi restava da sistemare dopo l'importazione?» — la domanda che l'amministratore si fa
 * tre giorni dopo, quando i consigli della schermata di esito non ce li ha più davanti.
 *
 * ## Perché è una vista e non una tabella
 *
 * Gli avvisi sono già persistiti nel JSON di `import_batches.rapporto`: creare una tabella
 * significherebbe copiarli e da quel momento avere **due verità**, l'archivio e la riga, di cui
 * quella stantia vince sempre. Qui invece lo stato verificabile **non si scrive mai**: si
 * ricalcola a ogni lettura sugli id annotati in `import_batch_items`. Una tabella scollegata da
 * un capitolo riapre la sua voce nello stesso istante, senza cron e senza riconciliazione.
 *
 * L'unica cosa che finisce su disco è la spunta manuale — l'unica informazione che nessuna query
 * può produrre.
 *
 * ## Perché si raggruppa per codice
 *
 * Gli avvisi di lettura nascono per riga: quaranta unità senza codice fiscale sono quaranta
 * rilievi. Senza raggruppamento la lista è inutilizzabile alla prima apertura, ed è la stessa
 * disciplina che i livelli applicano già dentro sé — il rumore fa saltare anche gli avvisi che
 * contano.
 */
final class ControlliPostImport
{
    /**
     * Le voci di tutti i lotti di questo condominio, dal più recente.
     *
     * @return list<array<string, mixed>>
     */
    public function perCondominio(Condominio $condominio): array
    {
        $voci = [];

        $lotti = ImportBatch::query()
            ->where('condominio_id', $condominio->getKey())
            ->whereIn('stato', [ImportBatch::STATO_COMPLETATO, ImportBatch::STATO_PARZIALE])
            ->whereNotNull('rapporto')
            ->latest()
            ->get();

        foreach ($lotti as $lotto) {
            array_push($voci, ...$this->perLotto($lotto));
        }

        return $voci;
    }

    /**
     * Le voci di un lotto solo.
     *
     * @return list<array<string, mixed>>
     */
    public function perLotto(ImportBatch $lotto): array
    {
        $livelli = $lotto->rapporto['livelli'] ?? [];

        if ($livelli === []) {
            return [];
        }

        $gruppi = $this->raggruppa($livelli);

        if ($gruppi === []) {
            return [];
        }

        $idPerTipo = $this->idCreati($lotto);
        $spunte = $lotto->rapporto['controlli'] ?? [];
        $voci = [];

        foreach ($gruppi as $chiave => $g) {
            $catalogo = CatalogoControlli::per($g['codice']);
            $esito = $this->verifica($catalogo, $lotto, $idPerTipo);
            $spunta = $spunte[$chiave] ?? null;

            // L'ordine conta: una spunta dell'amministratore vince sul verificatore, perché
            // «l'ho guardato io» è un'informazione più forte di «la query non trova più niente».
            $stato = match (true) {
                $spunta !== null => StatoControllo::from($spunta['stato']),
                $esito !== null => $esito->stato,
                default => StatoControllo::Aperto,
            };

            $voci[] = [
                'chiave' => $chiave,
                'lotto' => $lotto->uuid,
                'livello' => $g['livello'],
                'codice' => $g['codice'],
                // La frase viva del verificatore batte il messaggio congelato: quello dice com'era
                // il giorno dell'importazione, questa dice cosa manca adesso.
                'titolo' => $esito?->frase ?? $g['messaggio'],
                'messaggio_originale' => $g['messaggio'],
                'rimedio' => $g['rimedio'],
                'perche' => $catalogo->perche,
                'quante' => $g['quante'],
                'righe' => $g['righe'],
                'origine' => $g['origine'],
                'importata_il' => $lotto->completato_at?->format('d/m/Y'),
                'stato' => $stato->value,
                'verificabile' => $catalogo->verificabile(),
                'superata' => $esito?->stato === StatoControllo::Superato,
                'nota' => $spunta['nota'] ?? null,
                'destinazione' => $lotto->condominio_id === null
                    ? null
                    : CatalogoControlli::destinazione($g['codice'], $lotto->condominio_id),
            ];
        }

        // Le aperte in cima: sono l'unica ragione per cui si apre questa pagina.
        usort($voci, fn ($a, $b) => ($a['stato'] === StatoControllo::Aperto->value ? 0 : 1)
            <=> ($b['stato'] === StatoControllo::Aperto->value ? 0 : 1));

        return $voci;
    }

    /**
     * La guardia economica del cruscotto: una query indicizzata, e nel 99% dei casi si esce.
     */
    public function esistonoAperti(int $condominioId): bool
    {
        $lotti = ImportBatch::query()
            ->where('condominio_id', $condominioId)
            ->whereIn('stato', [ImportBatch::STATO_COMPLETATO, ImportBatch::STATO_PARZIALE])
            ->whereNotNull('rapporto')
            ->latest()
            ->get();

        foreach ($lotti as $lotto) {
            foreach ($this->perLotto($lotto) as $voce) {
                if ($voce['stato'] === StatoControllo::Aperto->value) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Da `livelli[].avvisi[]` a un gruppo per `{livello}:{codice}`.
     *
     * @param  list<array<string, mixed>>  $livelli
     * @return array<string, array<string, mixed>>
     */
    private function raggruppa(array $livelli): array
    {
        $gruppi = [];

        foreach ($livelli as $riga) {
            $livello = $riga['livello'] ?? '';

            foreach ($riga['avvisi'] ?? [] as $avviso) {
                $codice = $avviso['codice'] ?? null;

                if ($codice === null) {
                    continue;
                }

                $chiave = $livello.':'.$codice;

                if (! isset($gruppi[$chiave])) {
                    $gruppi[$chiave] = [
                        'livello' => $livello,
                        'codice' => $codice,
                        'messaggio' => $avviso['messaggio'] ?? '',
                        'rimedio' => $avviso['rimedio'] ?? null,
                        'origine' => $avviso['origine'] ?? 'scrittura',
                        'quante' => 0,
                        'righe' => [],
                    ];
                }

                $gruppi[$chiave]['quante']++;

                if ($avviso['riga'] ?? null) {
                    $gruppi[$chiave]['righe'][] = $avviso['riga'];
                }
            }
        }

        return $gruppi;
    }

    /**
     * Gli id che **questo lotto** ha creato, per classe.
     *
     * Solo `creato`: un'entità unita o saltata esisteva già, e chiedere all'amministratore di
     * sistemare qualcosa che non abbiamo scritto noi sarebbe scaricargli addosso il suo archivio.
     *
     * @return array<string, list<int>>
     */
    private function idCreati(ImportBatch $lotto): array
    {
        return ImportBatchItem::query()
            ->where('import_batch_id', $lotto->getKey())
            ->where('azione', ImportBatchItem::AZIONE_CREATO)
            ->whereNotNull('importabile_type')
            ->get(['importabile_type', 'importabile_id'])
            ->groupBy('importabile_type')
            ->map(fn ($righe) => $righe->pluck('importabile_id')->all())
            ->all();
    }

    /**
     * @param  array<string, list<int>>  $idPerTipo
     */
    private function verifica(VoceCatalogo $catalogo, ImportBatch $lotto, array $idPerTipo): ?EsitoControllo
    {
        if ($catalogo->verificatore === null) {
            return null;
        }

        /** @var VerificatoreControllo $verificatore */
        $verificatore = app($catalogo->verificatore);

        return $verificatore->esegui($lotto, $idPerTipo);
    }
}

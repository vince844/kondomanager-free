<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * L'avviso della conferma dello storno di un incasso (Coda 209, decisione 75.5, voce P8 della 1.11.0-beta.49). Sola lettura.
 *
 * Lo storno toglie da ogni quota tutto ciò che l'incasso le aveva dato, anche la parte in più. Se quella parte è già stata usata
 * in una compensazione o rimborsata, l'uso non si annulla da solo, e la quota torna da pagare anche per quella parte: un debito
 * che ricompare senza che nessuno l'abbia detto. La conferma dello storno lo dice prima, con la rata, la persona, quanto è già
 * stato usato, dove (protocollo e data) e quanto tornerà da pagare. Le strade per scegliere che cosa fare di quell'uso restano
 * fuori (punto 2.26 del rapporto del 01/10/2026): qui solo l'avviso.
 *
 * Il calcolo, quota per quota, sulle quote a debito che l'incasso ha toccato: pagato dopo lo storno = pagato oggi − quello che
 * l'incasso (e la sua compensazione) le aveva dato; da pagare dopo = importo − pagato dopo; senza l'uso sarebbe importo −
 * (pagato dopo + usi). La differenza è la parte già usata che torna da pagare. Così la frase è giusta anche quando l'incasso
 * stornato una parte in più non l'aveva, e quella usata veniva da un altro incasso sulla stessa quota (sonda SC1). Un uso a sua
 * volta stornato non conta più.
 */
final class AvvisoStornoIncasso
{
    /**
     * @return array{voci: list<array{rata: int, persona: string, importo_rata: int, da_pagare_dopo: int, gia_usato: int, usi: list<array{tipo: string, protocollo: ?string, data: ?string}>}>, frase: ?string}
     */
    public function perIncasso(ScritturaContabile $incasso): array
    {
        $famiglia = collect([$incasso])->merge($incasso->figlie)->filter(fn (ScritturaContabile $s) => $s->stato !== 'annullata');
        $idsFamiglia = $famiglia->pluck('id')->all();
        $tolto = [];
        $quote = [];
        foreach ($famiglia as $scrittura) {
            foreach ($scrittura->quotePagate as $quota) {
                $tolto[$quota->id] = ($tolto[$quota->id] ?? 0) + (int) $quota->pivot->importo_pagato;
                $quote[$quota->id] = $quota;
            }
        }
        $aDebito = collect($quote)->filter(fn ($q) => (int) $q->importo > 0);
        if ($aDebito->isEmpty()) {
            return ['voci' => [], 'frase' => null];
        }

        $usi = DB::table('quota_scrittura')
            ->join('scritture_contabili', 'scritture_contabili.id', '=', 'quota_scrittura.scrittura_contabile_id')
            ->whereIn('quota_scrittura.rate_quota_id', $aDebito->keys())
            ->where('quota_scrittura.importo_pagato', '<', 0)
            ->whereNotIn('scritture_contabili.id', $idsFamiglia)
            ->where('scritture_contabili.stato', '!=', 'annullata')
            ->whereNull('scritture_contabili.deleted_at')
            ->whereIn('scritture_contabili.tipo_movimento', [TipoMovimentoContabile::STORNO_CREDITO->value, TipoMovimentoContabile::RIMBORSO_CONDOMINO->value])
            ->orderBy('scritture_contabili.data_competenza')
            ->orderBy('scritture_contabili.id')
            ->get(['quota_scrittura.rate_quota_id', 'quota_scrittura.importo_pagato', 'scritture_contabili.tipo_movimento',
                'scritture_contabili.numero_protocollo', 'scritture_contabili.data_competenza', 'scritture_contabili.scrittura_padre_id'])
            ->groupBy('rate_quota_id');

        $voci = [];
        foreach ($aDebito as $id => $quota) {
            $usate = $usi->get($id, collect());
            $usato = -(int) $usate->sum('importo_pagato');
            if ($usato <= 0) {
                continue;
            }
            $pagatoDopo = (int) $quota->importo_pagato - $tolto[$id];
            $daPagareDopo = max(0, (int) $quota->importo - $pagatoDopo);
            $senzaUso = max(0, (int) $quota->importo - ($pagatoDopo + $usato));
            if ($daPagareDopo - $senzaUso <= 0) {
                continue;
            }
            $quota->loadMissing('rata', 'anagrafica');
            $voci[] = [
                'rata'           => (int) $quota->rata?->numero_rata,
                'persona'        => (string) $quota->anagrafica?->nome,
                'importo_rata'   => (int) $quota->importo,
                'da_pagare_dopo' => $daPagareDopo,
                'gia_usato'      => $daPagareDopo - $senzaUso,
                'usi'            => $usate->map(fn ($uso) => [
                    'tipo'       => $uso->tipo_movimento === TipoMovimentoContabile::RIMBORSO_CONDOMINO->value ? 'rimborso' : 'compensazione',
                    // La compensazione è la figlia di un incasso: il protocollo che l'amministratore trova nell'elenco è quello del padre.
                    'protocollo' => $uso->tipo_movimento === TipoMovimentoContabile::STORNO_CREDITO->value && $uso->scrittura_padre_id
                        ? DB::table('scritture_contabili')->where('id', $uso->scrittura_padre_id)->value('numero_protocollo')
                        : $uso->numero_protocollo,
                    'data'       => $uso->data_competenza ? Carbon::parse($uso->data_competenza)->format('d/m/Y') : null,
                ])->values()->all(),
            ];
        }

        return ['voci' => $voci, 'frase' => $voci === [] ? null : implode(' ', array_map(fn (array $voce) => $this->frase($voce), $voci))];
    }

    private function frase(array $voce): string
    {
        $usi = collect($voce['usi'])->map(fn (array $uso) => $uso['tipo'] === 'rimborso'
            ? sprintf('rimborsati il %s (prot. %s)', $uso['data'], $uso['protocollo'])
            : sprintf('usati nell\'incasso prot. %s del %s', $uso['protocollo'], $uso['data']))->join(', ', ' e ');

        return sprintf('Sulla rata n.%d di %s %s pagati in più sono già stati %s: lo storno non annulla quell\'uso, e la rata n.%d, da %s, tornerà da pagare per %s.',
            $voce['rata'], $voce['persona'], MoneyHelper::format($voce['gia_usato']), $usi, $voce['rata'],
            MoneyHelper::format($voce['importo_rata']), MoneyHelper::format($voce['da_pagare_dopo']));
    }
}

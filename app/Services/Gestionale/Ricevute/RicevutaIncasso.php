<?php

namespace App\Services\Gestionale\Ricevute;

use App\Enums\TipoMovimentoContabile;
use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Support\Facades\DB;

/**
 * Il contenuto della ricevuta di un incasso (decisioni 197 e 200), in sola lettura e in centesimi: che cosa la ricevuta dice, letto da ciò
 * che l'incasso ha scritto. Non scrive niente.
 *
 * ## Da dove viene ogni riga
 *
 * - **Le quote coperte**: per ogni quota, quanto ci hanno messo l'incasso (il denaro, compresa la parte in più che il programma vi ha
 *   attaccato) e le sue compensazioni (`storno_credito`, le figlie che usano il credito), dalla pivot `quota_scrittura`. L'**imputato** è
 *   il minore fra quella somma e il residuo che la quota aveva prima dell'incasso; quello che avanza è la parte in più rimasta a credito
 *   su quella quota. Così una parte in più che riduce una rata ancora da pagare è imputata alla rata, come la legge l'estratto conto.
 * - **Il già versato prima** è la somma della pivot di quella quota per le scritture registrate prima di questo incasso: l'ordine di
 *   registrazione, quello con cui il programma ha imputato il denaro (decisione 202). Un incasso annullato non conta, né la sua rettifica,
 *   né le compensazioni di un incasso annullato. Se la somma è negativa (il credito della quota usato e poi annullato il versamento che lo
 *   aveva prodotto) la ricevuta mostra zero e dice quanto era tornato a debito. **Il residuo dopo** non scende sotto zero.
 * - **Il credito usato** dalle quote che le compensazioni dell'incasso hanno svuotato (la pivot in negativo); per ogni quota coperta, la
 *   parte che viene dal credito.
 * - **La parte in più**: quello che il denaro ha messo oltre i debiti della posizione. Sulla rata di chi ha versato per un altro la ricevuta
 *   dice quanto la riduce e quanto resta a credito, senza il residuo di quella rata, che è un dato di chi ha versato.
 * - **Chi ha versato e per conto di chi** (decisione 30): la regola di `PersoneDegliIncassi`. Una compensazione che copre solo le rate di
 *   un'altra persona (il credito della posizione «si usa adesso» e il denaro va tutto sulla rata di chi l'ha versato) non è un versamento di
 *   questa ricevuta: non entra nelle quote né nel credito usato, e la ricevuta la nomina solo in `compensazioni_escluse`.
 *
 * Non c'è il metodo di pagamento (bonifico, contanti, assegno): l'incasso non lo registra.
 */
class RicevutaIncasso
{
    /** Sopra questa somma la ricevuta è «sopra € 77,47» per la finestra e per i filtri: il programma mostra il dato, non dice se il bollo è dovuto. */
    public const SOGLIA_BOLLO_CENTS = 7747;

    private const ETICHETTE_CASSA = [
        'banca'    => 'Conto corrente',
        'contanti' => 'Cassa contanti',
        'postale'  => 'Conto postale',
        'virtuale' => 'Cassa virtuale',
    ];

    /**
     * @return array<string, mixed>
     */
    public function per(ScritturaContabile $incasso): array
    {
        $incasso->loadMissing([
            'condominio',
            'gestione',
            'righe.cassa',
            'righe.anagrafica',
            'quotePagate.rata.pianoRate.gestione',
            'quotePagate.immobile',
            'quotePagate.anagrafica',
            'figlie.quotePagate.rata.pianoRate.gestione',
            'figlie.quotePagate.immobile',
            'figlie.quotePagate.anagrafica',
        ]);

        [$posizione, $versatoDa, $piuPersone] = $this->persone($incasso);
        $compensazioni = $incasso->figlie->filter(fn (ScritturaContabile $f) => $f->tipo_movimento === TipoMovimentoContabile::STORNO_CREDITO)->values();

        // Quanto l'incasso ha messo su ogni quota con il denaro, e quanto le sue compensazioni con il credito.
        $quote = [];
        $denaro = [];
        foreach ($incasso->quotePagate as $quota) {
            $denaro[$quota->id] = ($denaro[$quota->id] ?? 0) + (int) $quota->pivot->importo_pagato;
            $quote[$quota->id] = $quota;
        }
        $credito = [];
        $escluse = [];
        foreach ($compensazioni as $compensazione) {
            $netto = [];
            foreach ($compensazione->quotePagate as $quota) {
                $netto[$quota->id] = ($netto[$quota->id] ?? 0) + (int) $quota->pivot->importo_pagato;
                $quote[$quota->id] ??= $quota;
            }
            $coperte = collect($netto)->filter(fn (int $v, int $id) => $v > 0 && (int) $quote[$id]->importo > 0);
            $persone = $coperte->keys()->map(fn (int $id) => (int) $quote[$id]->anagrafica_id)->unique()->values();
            if ($posizione !== null && $persone->isNotEmpty() && ! $persone->contains((int) $posizione->id)) {
                $escluse[] = [
                    'persona' => $coperte->keys()->map(fn (int $id) => $quote[$id]->anagrafica?->nome)->filter()->unique()->implode(', '),
                    'importo' => (int) $coperte->sum(),
                ];

                continue;
            }
            foreach ($netto as $id => $v) {
                $credito[$id] = ($credito[$id] ?? 0) + $v;
            }
        }

        $ids = array_keys($denaro + $credito);
        $prima = $this->giaVersatoPrima($incasso, $ids);
        $daChiHaVersato = fn (RataQuote $q) => $versatoDa !== null && (int) $q->anagrafica_id === (int) $versatoDa->id;

        $righeQuote = [];
        $crediti = [];
        $parteInPiu = [];
        foreach ($ids as $id) {
            $q = $quote[$id];
            $messo = ($denaro[$id] ?? 0) + ($credito[$id] ?? 0);
            if ($messo < 0) {
                // Il credito di un'altra persona (un comproprietario) usato per le rate della posizione: la ricevuta dice di chi è.
                $crediti[] = [
                    'importo' => -$messo,
                    'rata'    => $this->nomeRataEPiano($q),
                    'persona' => $posizione !== null && (int) $q->anagrafica_id !== (int) $posizione->id ? $q->anagrafica?->nome : null,
                ];

                continue;
            }
            if ($messo === 0 || (int) $q->importo <= 0) {
                continue;
            }

            $versatoPrima = $prima[$id] ?? 0;
            $residuoPrima = (int) $q->importo - $versatoPrima;
            $imputato = min($messo, max(0, $residuoPrima));
            $avanza = $messo - $imputato;

            if ($daChiHaVersato($q)) {
                $parteInPiu[] = $this->parteInPiu($messo, $q, $imputato, $avanza, true);

                continue;
            }
            if ($imputato > 0) {
                $righeQuote[] = [
                    'quota_id'    => $q->id,
                    'rata'        => $this->nomeRata($q),
                    'piano'       => $q->rata?->pianoRate?->nome,
                    'gestione'    => $q->rata?->pianoRate?->gestione?->nome,
                    'unita'       => $q->immobile?->etichetta,
                    'scadenza'    => $q->rata?->data_scadenza?->format('Y-m-d'),
                    'importo'     => (int) $q->importo,
                    'prima'       => min(max(0, $versatoPrima), (int) $q->importo),
                    'riaddebito'  => max(0, -$versatoPrima),
                    'imputato'    => $imputato,
                    'dal_credito' => min(max(0, $credito[$id] ?? 0), $imputato),
                    'dopo'        => max(0, $residuoPrima - $imputato),
                ];
            }
            if ($avanza > 0) {
                $parteInPiu[] = $this->parteInPiu($avanza, $q, 0, $avanza, false);
            }
        }
        usort($righeQuote, fn (array $a, array $b) => [$a['scadenza'] ?? '', $quote[$a['quota_id']]->rata?->numero_rata, $a['quota_id']]
            <=> [$b['scadenza'] ?? '', $quote[$b['quota_id']]->rata?->numero_rata, $b['quota_id']]);

        // La parte in più senza una quota su cui stare (incassi di versioni vecchie): un anticipo, tutto a credito.
        foreach ($incasso->righe as $riga) {
            if ($riga->tipo_riga === 'avere' && ! $riga->rata_id && str_starts_with((string) $riga->note, 'Anticipo / Eccedenza')) {
                $parteInPiu[] = ['importo' => (int) $riga->importo, 'persona' => $riga->anagrafica?->nome, 'rata' => null, 'riduce' => 0,
                    'a_credito' => (int) $riga->importo, 'di_chi_ha_versato' => false];
            }
        }

        $rigaCassa = $incasso->righe->first(fn (RigaScrittura $r) => $r->tipo_riga === 'dare' && $r->cassa_id);
        $importoVersato = (int) ($rigaCassa?->importo ?? 0);
        $condominio = $incasso->condominio;

        return [
            'incasso_id'      => $incasso->id,
            'numero'          => $incasso->numero_protocollo,
            'data_versamento' => $incasso->data_competenza?->format('Y-m-d'),
            'causale'         => $incasso->causale,
            'annullato'       => $incasso->stato === 'annullata',
            'storno'          => $incasso->stato === 'annullata' ? $this->storno($incasso) : null,
            'condominio'      => [
                'nome'           => $condominio?->nome,
                'indirizzo'      => trim(implode(' ', array_filter([
                    $condominio?->indirizzo,
                    trim(implode(' ', array_filter([$condominio?->cap, $condominio?->comune, $condominio?->provincia ? "({$condominio->provincia})" : null]))),
                ])), ' '),
                'codice_fiscale' => $condominio?->codice_fiscale,
            ],
            'posizione'       => $posizione ? [
                'id'             => $posizione->id,
                'nome'           => $posizione->nome,
                'indirizzo'      => $posizione->indirizzo,
                'codice_fiscale' => $posizione->codice_fiscale,
            ] : null,
            'versato_da'      => $versatoDa ? ['id' => $versatoDa->id, 'nome' => $versatoDa->nome] : null,
            'importo_versato' => $importoVersato,
            'cassa'           => $rigaCassa?->cassa ? [
                'nome' => $rigaCassa->cassa->nome,
                'tipo' => self::ETICHETTE_CASSA[$rigaCassa->cassa->tipo] ?? ucfirst((string) $rigaCassa->cassa->tipo),
            ] : null,
            'gestione'        => $incasso->gestione?->nome,
            // A che cosa va il versamento, con quale criterio (decisione 218, punto 4): una riga, o niente.
            'imputazione'     => self::fraseImputazione($incasso->imputazione, $incasso->imputazione_nota),
            'quote'           => $righeQuote,
            'totali'          => [
                'importo'  => array_sum(array_column($righeQuote, 'importo')),
                'prima'    => array_sum(array_column($righeQuote, 'prima')),
                'imputato' => array_sum(array_column($righeQuote, 'imputato')),
                'dopo'     => array_sum(array_column($righeQuote, 'dopo')),
            ],
            'credito_usato'   => array_sum(array_column($crediti, 'importo')),
            'crediti'         => $crediti,
            'eccedenza'       => array_sum(array_column($parteInPiu, 'importo')),
            'eccedenze'       => $parteInPiu,
            'compensazioni_escluse' => $escluse,
            // Le persone dell'incasso quando sono più d'una e nessuno ha versato per conto di altri: non c'è una ricevuta sola da fare.
            'piu_persone'     => $piuPersone,
            'sopra_soglia'    => self::sopraSoglia($importoVersato),
        ];
    }

    /**
     * La ricevuta com'è stata emessa la prima volta (il contenuto salvato nella riga del registro dell'originale), con quello che è di oggi:
     * se l'incasso è stato annullato dopo (la copia lo dice in testa, sopra le cifre dell'originale) e l'indirizzo postale della persona,
     * che serve alla busta di oggi. Le cifre restano quelle dell'originale: una copia non è una ricevuta nuova.
     *
     * @param array<string, mixed> $istantanea
     * @return array<string, mixed>
     */
    public function daIstantanea(array $istantanea, ScritturaContabile $incasso): array
    {
        $istantanea['annullato'] = $incasso->stato === 'annullata';
        $istantanea['storno'] = $istantanea['annullato'] ? $this->storno($incasso) : null;
        if (! empty($istantanea['posizione']['id']) && ($persona = Anagrafica::find($istantanea['posizione']['id'])) !== null) {
            $istantanea['posizione']['indirizzo'] = $persona->indirizzo;
        }

        return $istantanea;
    }

    /**
     * Le cifre della ricevuta, senza ciò che cambia con lo stato dell'incasso o con l'anagrafica: per sapere se la situazione delle quote è
     * cambiata dopo l'originale.
     *
     * @param array<string, mixed> $contenuto
     * @return array<string, mixed>
     */
    public static function cifre(array $contenuto): array
    {
        return [
            'quote'      => array_map(fn (array $q) => [$q['quota_id'] ?? null, $q['importo'] ?? 0, $q['prima'] ?? 0, $q['imputato'] ?? 0, $q['dopo'] ?? 0], $contenuto['quote'] ?? []),
            'credito'    => $contenuto['credito_usato'] ?? 0,
            'eccedenze'  => array_map(fn (array $e) => [$e['importo'] ?? 0, $e['riduce'] ?? 0, $e['a_credito'] ?? 0], $contenuto['eccedenze'] ?? []),
            'posizione'  => $contenuto['posizione']['id'] ?? null,
            'versato_da' => $contenuto['versato_da']['id'] ?? null,
        ];
    }

    /** @return array{importo: int, persona: ?string, rata: string, riduce: int, a_credito: int, di_chi_ha_versato: bool} */
    private function parteInPiu(int $importo, RataQuote $q, int $riduce, int $aCredito, bool $diChiHaVersato): array
    {
        return [
            'importo'           => $importo,
            'persona'           => $q->anagrafica?->nome,
            'rata'              => $this->nomeRataEPiano($q),
            'riduce'            => $riduce,
            'a_credito'         => $aCredito,
            'di_chi_ha_versato' => $diChiHaVersato,
        ];
    }

    /**
     * La riga del criterio dell'imputazione sotto «A che cosa va il versamento» (decisione 218, punto 4): l'ordine delle scadenze, la
     * dichiarazione di chi ha versato con la sua nota (è sua), la scelta dell'amministratore **senza** la sua nota (può essere interna, come
     * la causale: decisione 202.1), la compensazione. Niente per «fuori ordine» e per gli incassi di prima. Nessuna frase di effetto legale.
     */
    public static function fraseImputazione(?string $imputazione, ?string $nota): ?string
    {
        $nota = trim((string) $nota);

        return match ($imputazione) {
            \App\Services\Gestionale\Incassi\OrdineImputazione::ORDINE_SCADENZE => 'Imputazione: secondo l\'ordine delle scadenze.',
            \App\Services\Gestionale\Incassi\OrdineImputazione::INDICATA_DAL_VERSANTE => 'Imputazione: indicata da chi ha versato' . ($nota !== '' ? ": «{$nota}»." : '.'),
            \App\Services\Gestionale\Incassi\OrdineImputazione::SCELTA_AMMINISTRATORE => 'Imputazione: scelta dall\'amministratore.',
            \App\Services\Gestionale\Incassi\OrdineImputazione::COMPENSAZIONE => 'Imputazione: compensazione con il credito.',
            default => null,
        };
    }

    public static function sopraSoglia(int $importoCents): bool
    {
        return $importoCents > self::SOGLIA_BOLLO_CENTS;
    }

    /**
     * Il già versato prima di questo incasso su ogni quota: la pivot delle scritture registrate prima (identificativo minore: l'ordine con cui
     * il programma ha imputato il denaro, decisione 202). Le compensazioni di questo incasso sono registrate dopo di lui e non ci sono; una
     * scrittura annullata, una compensazione di un incasso annullato e la rettifica di uno storno non contano.
     *
     * @param array<int, int> $quoteIds
     * @return array<int, int> quota => centesimi
     */
    private function giaVersatoPrima(ScritturaContabile $incasso, array $quoteIds): array
    {
        if ($quoteIds === []) {
            return [];
        }

        $righe = DB::table('quota_scrittura')
            ->join('scritture_contabili as s', 's.id', '=', 'quota_scrittura.scrittura_contabile_id')
            ->leftJoin('scritture_contabili as padre', 'padre.id', '=', 's.scrittura_padre_id')
            ->whereIn('quota_scrittura.rate_quota_id', $quoteIds)
            ->where('s.id', '<', $incasso->id)
            ->whereNull('s.deleted_at')
            ->where('s.stato', '!=', 'annullata')
            ->where('s.tipo_movimento', '!=', TipoMovimentoContabile::RETTIFICA->value)
            ->get([
                'quota_scrittura.rate_quota_id as quota_id',
                'quota_scrittura.importo_pagato as importo',
                's.tipo_movimento as tipo',
                'padre.stato as padre_stato',
            ]);

        $prima = [];
        foreach ($righe as $r) {
            if ($r->tipo === TipoMovimentoContabile::STORNO_CREDITO->value && $r->padre_stato === 'annullata') {
                continue;
            }
            $prima[$r->quota_id] = ($prima[$r->quota_id] ?? 0) + (int) $r->importo;
        }

        return $prima;
    }

    /**
     * La posizione e chi ha versato, con la regola di `PersoneDegliIncassi`: la stessa dell'elenco delle ricevute e dell'invio.
     *
     * @return array{0: ?Anagrafica, 1: ?Anagrafica, 2: array<int, array{id: int, nome: string}>}
     */
    private function persone(ScritturaContabile $incasso): array
    {
        $persone = app(PersoneDegliIncassi::class)->di([$incasso->id])[$incasso->id];
        $trovate = Anagrafica::whereIn('id', array_merge(array_filter([$persone['posizione'], $persone['versato_da']]), $persone['piu_persone']))->get()->keyBy('id');

        return [
            $persone['posizione'] ? $trovate->get($persone['posizione']) : null,
            $persone['versato_da'] ? $trovate->get($persone['versato_da']) : null,
            collect($persone['piu_persone'])->map(fn (int $id) => $trovate->get($id))->filter()->map(fn (Anagrafica $a) => ['id' => $a->id, 'nome' => $a->nome])->values()->all(),
        ];
    }

    /** La rettifica che ha annullato l'incasso: il suo protocollo e il giorno in cui è stata scritta, nel calendario dell'amministratore. */
    private function storno(ScritturaContabile $incasso): ?array
    {
        $rettifica = ScritturaContabile::where('scrittura_padre_id', $incasso->id)
            ->where('tipo_movimento', TipoMovimentoContabile::RETTIFICA->value)
            ->orderBy('id')
            ->first();

        if ($rettifica === null) {
            return null;
        }

        return [
            'protocollo' => $rettifica->numero_protocollo,
            'data'       => ($rettifica->created_at ?? $rettifica->data_registrazione)?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'),
        ];
    }

    private function nomeRata(RataQuote $q): string
    {
        $rata = $q->rata;
        $numero = $rata?->numero_rata;
        $descrizione = trim((string) ($rata?->descrizione ?? ''));

        return self::nomeBreve(trim('Rata ' . ($numero ?? '?') . ($descrizione !== '' ? ' — ' . $descrizione : '')), $rata?->pianoRate?->nome);
    }

    /**
     * Il nome della rata senza il nome del piano ripetuto (decisione 206.1): il programma scrive la descrizione di una rata come «Rata n.1 -
     * <piano>», e la ricevuta, che dice il piano in testa al gruppo, leggeva «Rata 1 — Rata n.1 - <piano>». Toglie il nome del piano in fondo
     * alla descrizione e il «Rata n.N» che ripete il numero; lascia quello che l'amministratore ha scritto. Vale anche per il nome salvato
     * nel contenuto di un originale emesso prima, perché la copia si legga allo stesso modo.
     */
    public static function nomeBreve(string $rata, ?string $piano): string
    {
        if (! preg_match('/^(Rata\s+(\S+))(?:\s+—\s+(.*))?$/su', trim($rata), $m)) {
            return $rata;
        }
        [$testa, $numero, $resto] = [$m[1], $m[2], trim($m[3] ?? '')];
        $piano = trim((string) $piano);
        if ($piano !== '') {
            $resto = trim((string) preg_replace('/(?:^|\s*[-–—,]\s*)' . preg_quote($piano, '/') . '$/iu', '', $resto));
        }
        if ($resto === '' || preg_match('/^Rata\s*n\.?\s*' . preg_quote($numero, '/') . '$/iu', $resto)) {
            return $testa;
        }

        return $testa . ' — ' . $resto;
    }

    private function nomeRataEPiano(RataQuote $q): string
    {
        $piano = $q->rata?->pianoRate?->nome;

        return $this->nomeRata($q) . ($piano ? ', ' . $piano : '');
    }
}

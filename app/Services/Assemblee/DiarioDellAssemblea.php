<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Models\Assemblee\Assemblea;
use App\Models\RegistroModifica;
use App\Services\Assemblee\Convocazione\DiarioDegliInvii;
use App\Services\RegistroModifiche\LetturaDelRegistro;
use App\Services\RegistroModifiche\RegistroPerLaPagina;
use Illuminate\Support\Carbon;

/**
 * Il «Diario» dell'assemblea (§6.4 del progetto della tappa 2, decisione 518, la scena 3 della bozza del registro): il registro delle modifiche sulle cose
 * dell'assemblea insieme agli invii, per istante, dalla più recente. Tre fonti:
 *
 * 1. la «Storia» dell'assemblea (l'assemblea, le sedute, i punti, le delibere, e gli esiti della convocazione);
 * 2. le righe di altre schede **con il rimando** a una delibera o all'assemblea (i collegamenti: «Delibera: nessuna → …»), una lettura per rimando;
 * 3. gli invii ({@see DiarioDegliInvii}), fuori dalla catena firmata, senza ripetere gli esiti che stanno già in (1). Senza badge: la frase dice già che
 *    cosa è («Raccomandata a Neri Carla: spedita il 02/12»), e il filtro «Invii» li separa.
 *
 * Le righe (1) e (2) passano da {@see RegistroPerLaPagina::righe()}, con il badge delle annullate dal ripristino e di quelle senza firma. Le righe
 * dell'assemblea stessa non hanno il suo nome davanti: nel suo diario non serve («Convocata», «Delega di Neri Carla a Paolo Ferraris, ricevuta su carta il
 * 01/12/2026»); le righe di un'altra cosa (un punto, una delibera) tengono il loro. Un documento aggiunto o tolto da un punto (il campo «Parla di») si legge
 * con il suo nome: «Documento aggiunto al punto 2: «Preventivo portone.pdf»». «Mostra altre» trenta per volta, con il cursore dell'istante. Il diario dura
 * quanto il registro, dieci anni di serie (decisione 567).
 */
final class DiarioDellAssemblea
{
    public const PER_PAGINA = 30;

    public const FILTRI = ['tutto', 'modifiche', 'invii'];

    /**
     * La forma di una riga del diario, comune alle tre fonti: `fonte` (`modifica` o `invio`), `istante` (ISO 8601, il cursore), `giorno` («15/12/2026»),
     * `ora` («08:30»), `chi`, `frase`, e per le modifiche la riga intera del registro (`registro`).
     */
    public const RIGA = ['fonte', 'istante', 'giorno', 'ora', 'chi', 'frase', 'registro'];

    /**
     * @return array{righe: list<array<string, mixed>>, dopo: ?string, altre: bool}
     */
    public static function righe(Assemblea $assemblea, string $filtro = 'tutto', ?string $dopo = null): array
    {
        $filtro = in_array($filtro, self::FILTRI, true) ? $filtro : 'tutto';
        $prima = $dopo === null ? null : Carbon::parse($dopo);
        $righe = [];
        if ($filtro !== 'invii') {
            $righe = self::modifiche($assemblea, $prima);
        }
        if ($filtro !== 'modifiche') {
            foreach (app(DiarioDegliInvii::class)->righe($assemblea, $dopo, self::PER_PAGINA + 1) as $r) {
                $righe[] = $r + ['fonte' => 'invio', 'registro' => null];
            }
        }
        usort($righe, fn ($a, $b) => strcmp((string) $b['istante'], (string) $a['istante']));
        $altre = count($righe) > self::PER_PAGINA;
        $righe = array_slice($righe, 0, self::PER_PAGINA);

        return ['righe' => $righe, 'dopo' => $altre && $righe !== [] ? (string) end($righe)['istante'] : null, 'altre' => $altre];
    }

    /** Le fonti (1) e (2), prima dell'istante del cursore. @return list<array<string, mixed>> */
    private static function modifiche(Assemblea $assemblea, ?Carbon $prima): array
    {
        $delibere = $assemblea->delibere()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $limita = fn ($q) => $prima === null ? $q : $q->where('avvenuta_il', '<', $prima);
        $storia = $limita(LetturaDelRegistro::storia('assemblea', (int) $assemblea->id))->limit(self::PER_PAGINA + 1)->get();
        $rimandi = $limita(LetturaDelRegistro::perRimando('delibera', $delibere))->limit(self::PER_PAGINA + 1)->get();
        $all = $limita(LetturaDelRegistro::perRimando('assemblea', [(int) $assemblea->id]))->limit(self::PER_PAGINA + 1)->get();
        $modelli = $storia->concat($rimandi)->concat($all)->unique('id')->values();
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $perId = $modelli->keyBy('id');
        $esito = [];
        foreach (RegistroPerLaPagina::righe($modelli) as $r) {
            /** @var RegistroModifica $m */
            $m = $perId[$r['id']];
            $locale = $m->avvenuta_il->copy()->setTimezone($fuso);
            $propria = $m->oggetto_tipo === 'assemblea' && (int) $m->oggetto_id === (int) $assemblea->id;
            $esito[] = [
                'fonte' => 'modifica',
                'istante' => $m->avvenuta_il->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'giorno' => $locale->format('d/m/Y'),
                'ora' => $locale->format('H:i'),
                'chi' => $r['autore']['nome'] ?? 'Il programma',
                'frase' => self::fraseDeiDocumenti($m) ?? ($propria ? self::senzaIlNome((string) $r['cambiato'], (string) ($r['oggetto']['nome'] ?? ''))
                    : trim(($r['oggetto']['nome'] ?? '') . ' · ' . $r['cambiato'], ' ·')),
                'registro' => $r,
            ];
        }

        return $esito;
    }

    /**
     * La frase di una riga dell'assemblea stessa, senza il suo nome: il registro la scrive con il nome davanti («Assemblea ordinaria del 22/11/2026:
     * delega di …», «Assemblea ordinaria del 22/11/2026 convocata», «Creata · Assemblea ordinaria del 22/11/2026 preparata: …»), e dentro il suo diario
     * il nome non serve. Resta il fatto, con la maiuscola: «Delega di …», «Convocata», «Preparata: …». Una frase che non comincia con il nome resta com'è.
     */
    private static function senzaIlNome(string $cambiato, string $nome): string
    {
        $frase = $nome === '' ? $cambiato : (string) preg_replace('/^(?:[^·]+ · )?' . preg_quote($nome, '/') . '(?::\s*|\s+)/u', '', $cambiato, 1);
        $frase = trim($frase);

        return $frase === '' ? $cambiato : mb_strtoupper(mb_substr($frase, 0, 1)) . mb_substr($frase, 1);
    }

    /**
     * La riga del campo «Parla di» di un punto quando cambiano solo i suoi documenti: «Documento aggiunto al punto 2: «…»», «Documenti aggiunti al punto 2:
     * «…», «…»», «Documento tolto dal punto 2: «…»». Il numero è quello del punto al momento della riga. Altrimenti `null`, e la riga resta com'è.
     */
    private static function fraseDeiDocumenti(RegistroModifica $m): ?string
    {
        $campi = (array) $m->campi;
        if ($m->oggetto_tipo !== 'punto' || $m->azione !== 'modificata' || count($campi) !== 1 || ($campi[0]['chiave'] ?? null) !== 'oggetti') {
            return null;
        }
        $prima = self::vociDelCampo($campi[0]['prima'] ?? null, (array) ($campi[0]['valore_prima'] ?? []));
        $dopo = self::vociDelCampo($campi[0]['dopo'] ?? null, (array) ($campi[0]['valore_dopo'] ?? []));
        if ($prima === null || $dopo === null || preg_match('/^Punto (\d+)/u', (string) $m->oggetto_nome, $n) !== 1) {
            return null;
        }
        $aggiunti = array_diff_key($dopo, $prima);
        $tolti = array_diff_key($prima, $dopo);
        $soloDocumenti = fn (array $voci) => $voci !== [] && array_filter(array_keys($voci), fn ($k) => ! str_starts_with((string) $k, 'documento:')) === [];
        $nomi = fn (array $voci) => implode(', ', array_map(fn ($t) => (string) preg_replace('/^[^:]+: /u', '', (string) $t), array_values($voci)));
        if ($tolti === [] && $soloDocumenti($aggiunti)) {
            return (count($aggiunti) === 1 ? 'Documento aggiunto' : 'Documenti aggiunti') . ' al punto ' . $n[1] . ': ' . $nomi($aggiunti);
        }
        if ($aggiunti === [] && $soloDocumenti($tolti)) {
            return (count($tolti) === 1 ? 'Documento tolto' : 'Documenti tolti') . ' dal punto ' . $n[1] . ': ' . $nomi($tolti);
        }

        return null;
    }

    /** Le voci del campo, chiave => testo; `null` se il testo e le chiavi non si accoppiano (un nome con «; » dentro). @return array<string, string>|null */
    private static function vociDelCampo(?string $testo, array $chiavi): ?array
    {
        if ($chiavi === []) {
            return [];
        }
        $pezzi = $testo === null ? [] : explode('; ', $testo);

        return count($pezzi) === count($chiavi) ? array_combine(array_map('strval', $chiavi), $pezzi) : null;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\StatoVerbale;
use App\Models\Condominio;
use App\Models\ModelloTesto;
use App\Models\User;
use App\Models\Assemblee\VerbaleDellAssemblea;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * I modelli del verbale (V12): il testo del programma, nel codice ({@see DI_SERIE}: non si modifica, si duplica), e i modelli dello studio nella pagina dei
 * modelli, con le regole dei modelli delle lettere (il nome unico per «vale per», tutti i condomini o uno solo; li cambia chi gestisce le impostazioni di
 * stampa). Un modello ha due testi, **prima dei punti** e **dopo i punti**, nella struttura dell'editor; in mezzo il programma mette i dati della sala (la
 * costituzione, i punti con la discussione e i voti, le dichiarazioni, le note del presidente), che nel modello non si scrivono. I campi si riempiono
 * dall'assemblea ({@see CAMPI}). Cambiare un modello non cambia i verbali già generati.
 *
 * Nel database: `modelli_testo` con l'ambito `verbale` e il tipo `verbale`; il testo è il JSON `{prima, dopo}`.
 */
final class ModelliDelVerbale
{
    public const TIPO = 'verbale';

    public const NOME_DOPPIO = 'C\'è già un modello del verbale con questo nome e con lo stesso «vale per»: scegline un altro.';

    /** I campi, con che cosa dicono. */
    public const CAMPI = [
        '[condominio]' => 'il nome del condominio',
        '[tipo]' => 'ordinaria o straordinaria',
        '[giorno]' => 'il giorno della riunione, «15 dicembre 2026»',
        '[ora]' => 'l\'ora dell\'apertura, «18:42»',
        '[luogo]' => 'dove si tiene, o la piattaforma della videoconferenza',
        '[convocazione]' => '«prima convocazione», o «seconda convocazione, essendo andata deserta la prima del 14 dicembre 2026»',
        '[presidente]' => 'chi presiede',
        '[segretario]' => 'chi fa da segretario',
        '[ora_di_chiusura]' => 'l\'ora della chiusura, «20:55»',
    ];

    /** Il nome del testo del programma, come lo dice il menu. */
    public const NOME_DEL_PROGRAMMA = 'Testo del programma';

    /** @return array{prima: array<string, mixed>, dopo: array<string, mixed>} */
    public static function diSerie(): array
    {
        return [
            'prima' => StrutturaDelVerbale::documento([
                StrutturaDelVerbale::paragrafo('Il giorno [giorno], alle ore [ora], presso [luogo], si è riunita in [convocazione] l\'assemblea [tipo] dei condòmini del [condominio].'),
                StrutturaDelVerbale::paragrafo('Assume la presidenza [presidente]; funge da segretario [segretario].'),
            ]),
            'dopo' => StrutturaDelVerbale::documento([
                StrutturaDelVerbale::paragrafo('Esauriti gli argomenti all\'ordine del giorno, il presidente dichiara chiusa l\'assemblea alle ore [ora_di_chiusura].'),
                StrutturaDelVerbale::paragrafo('Letto, approvato e sottoscritto.'),
            ]),
        ];
    }

    /** I modelli del verbale. */
    public static function query()
    {
        return ModelloTesto::query()->where('ambito', self::TIPO)->where('tipo', self::TIPO);
    }

    /**
     * Il menu di «Genera il verbale dal modello»: i modelli del condominio, quelli per tutti, il testo del programma; con il proposto (quello del condominio, se
     * c'è; se no l'ultimo usato dallo studio; se no il testo del programma) e l'ultimo usato.
     *
     * @return array{modelli: list<array<string, mixed>>, proposto: ?int, ultimo_usato: ?int}
     */
    public static function perIlMenu(Condominio $condominio): array
    {
        $validi = self::query()->where(fn ($q) => $q->whereNull('condominio_id')->orWhere('condominio_id', $condominio->id))->with('cambiatoDa:id,name')->get();
        $ultimo = VerbaleDellAssemblea::query()->whereNotNull('modello_id')->where('stato', '!=', StatoVerbale::IN_SALA->value)->orderByDesc('generato_il')
            ->orderByDesc('id')->value('modello_id');
        $ultimo = $ultimo !== null && $validi->contains('id', (int) $ultimo) ? (int) $ultimo : null;
        $delCondominio = $validi->where('condominio_id', $condominio->id)->sortBy(fn ($m) => mb_strtolower($m->nome))->values();
        $perTutti = $validi->whereNull('condominio_id')->sortBy(fn ($m) => mb_strtolower($m->nome))->values();
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $voce = fn (ModelloTesto $m, string $gruppo) => ['id' => (int) $m->id, 'nome' => (string) $m->nome, 'gruppo' => $gruppo,
            'ultimo_usato' => $ultimo === (int) $m->id, 'cambiato_da' => $m->cambiatoDa?->name,
            'cambiato_il' => $m->updated_at?->copy()->setTimezone($fuso)->format('Y-m-d')];

        return [
            'modelli' => [
                ...$delCondominio->map(fn ($m) => $voce($m, 'condominio'))->all(),
                ...$perTutti->map(fn ($m) => $voce($m, 'tutti'))->all(),
                ['id' => null, 'nome' => self::NOME_DEL_PROGRAMMA, 'gruppo' => 'programma', 'ultimo_usato' => false, 'cambiato_da' => null, 'cambiato_il' => null],
            ],
            'proposto' => $delCondominio->first()?->id ?? $ultimo,
            'ultimo_usato' => $ultimo,
        ];
    }

    /**
     * Il modello scelto: uno dello studio che vale nel condominio (altrimenti 404), o il testo del programma con `null`.
     *
     * @return array{id: ?int, nome: string, prima: array<string, mixed>, dopo: array<string, mixed>}
     */
    public static function scelto(Condominio $condominio, mixed $id): array
    {
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return ['id' => null, 'nome' => self::NOME_DEL_PROGRAMMA] + self::diSerie();
        }
        abort_unless(is_numeric($id), 404);
        $modello = self::query()->find((int) $id);
        abort_if($modello === null || ($modello->condominio_id !== null && (int) $modello->condominio_id !== (int) $condominio->id), 404);

        return ['id' => (int) $modello->id, 'nome' => (string) $modello->nome] + self::testi($modello);
    }

    /** I due testi di un modello salvato: se il JSON non c'è più, il testo del programma. @return array{prima: array<string, mixed>, dopo: array<string, mixed>} */
    public static function testi(ModelloTesto $modello): array
    {
        $dati = json_decode((string) $modello->testo, true);
        $serie = self::diSerie();

        return [
            'prima' => is_array($dati['prima'] ?? null) && StrutturaDelVerbale::errore($dati['prima']) === null ? StrutturaDelVerbale::valida($dati['prima']) : $serie['prima'],
            'dopo' => is_array($dati['dopo'] ?? null) && StrutturaDelVerbale::errore($dati['dopo']) === null ? StrutturaDelVerbale::valida($dati['dopo']) : $serie['dopo'],
        ];
    }

    /** I modelli per la pagina dei modelli. @return list<array<string, mixed>> */
    public static function perLaPagina(): array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');

        return self::query()->with(['condominio:id,nome', 'cambiatoDa:id,name'])->get()
            ->sort(fn ($a, $b) => [$a->condominio_id === null ? 1 : 0, mb_strtolower($a->nome), $a->id] <=> [$b->condominio_id === null ? 1 : 0, mb_strtolower($b->nome), $b->id])
            ->map(fn (ModelloTesto $m) => ['id' => (int) $m->id, 'nome' => (string) $m->nome] + self::testi($m) + [
                'condominio' => $m->condominio === null ? null : ['id' => (int) $m->condominio->id, 'nome' => (string) $m->condominio->nome],
                'cambiato_da' => $m->cambiatoDa?->name, 'cambiato_il' => $m->updated_at?->copy()->setTimezone($fuso)->format('Y-m-d'),
            ])->values()->all();
    }

    /**
     * Crea o cambia un modello: il nome unico per «vale per» (senza le maiuscole e gli spazi), i due testi nella struttura dell'editor, senza parti «Dati della
     * sala» (le mette il programma).
     *
     * @param array{nome: string, prima: mixed, dopo: mixed, condominio_id: ?int} $dati
     */
    public function salva(?ModelloTesto $modello, array $dati, ?User $utente): ModelloTesto
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', (string) $dati['nome']));
        if ($nome === '') {
            throw ValidationException::withMessages(['nome' => 'Scrivi il nome del modello.']);
        }
        $testi = [];
        foreach (['prima', 'dopo'] as $parte) {
            $errore = StrutturaDelVerbale::errore($dati[$parte] ?? null);
            if ($errore !== null) {
                throw ValidationException::withMessages([$parte => $errore]);
            }
            $doc = StrutturaDelVerbale::valida($dati[$parte]);
            if (StrutturaDelVerbale::blocchi($doc) !== []) {
                throw ValidationException::withMessages([$parte => 'Le parti «Dati della sala» le mette il programma: nel modello non si scrivono.']);
            }
            $testi[$parte] = $doc;
        }
        $condominio = isset($dati['condominio_id']) && $dati['condominio_id'] !== null ? (int) $dati['condominio_id'] : null;
        $doppio = self::query()
            ->when($condominio === null, fn ($q) => $q->whereNull('condominio_id'), fn ($q) => $q->where('condominio_id', $condominio))
            ->when($modello !== null, fn ($q) => $q->whereKeyNot($modello->id))
            ->get(['id', 'nome'])->contains(fn (ModelloTesto $m) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $m->nome))) === mb_strtolower($nome));
        if ($doppio) {
            throw ValidationException::withMessages(['nome' => self::NOME_DOPPIO]);
        }
        $campi = ['nome' => mb_substr($nome, 0, ModelloTesto::LUNGHEZZA_NOME), 'testo' => json_encode($testi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'condominio_id' => $condominio, 'updated_by' => $utente?->id];
        if ($modello === null) {
            return ModelloTesto::create($campi + ['ambito' => self::TIPO, 'tipo' => self::TIPO, 'created_by' => $utente?->id]);
        }
        $modello->update($campi);

        return $modello;
    }

    /** Un modello del verbale, altrimenti 404. */
    public static function oppure404(string|int $id): ModelloTesto
    {
        abort_unless(is_numeric($id), 404);

        return self::query()->findOrFail((int) $id);
    }

    /**
     * I campi riempiti in un testo del modello: ogni pezzo di testo con i suoi campi (un campo spezzato fra due formati non si riempie, e resta scritto).
     *
     * @param array<string, mixed> $documento
     * @param array<string, string> $valori campo => valore
     * @return array<string, mixed>
     */
    public static function riempi(array $documento, array $valori): array
    {
        if (($documento['type'] ?? null) === 'text') {
            $documento['text'] = strtr((string) $documento['text'], $valori);

            return $documento;
        }
        if (isset($documento['content'])) {
            $documento['content'] = array_map(fn ($n) => self::riempi((array) $n, $valori), (array) $documento['content']);
        }

        return $documento;
    }

    /** @return Collection<int, ModelloTesto> */
    public static function tutti(): Collection
    {
        return self::query()->get();
    }
}

<?php

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\ModelloTesto;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * I testi dello studio per la nota sintetica: le frasi che l'amministratore riusa da un rendiconto all'altro (il criterio del riscaldamento, la frase sui
 * documenti in studio, la dichiarazione di sempre), nella stessa libreria dei modelli delle lettere ({@see ModelloTesto}, con il loro ambito), per tutti i
 * condomini o per uno solo. Chi usa un testo ne copia le parole nella nota: cambiarlo o cancellarlo dopo non cambia le note già scritte.
 *
 * Il tipo dice per quale campo è il testo: una delle cinque parti, la causa di uno scostamento, la spiegazione di un controllo, una riga della
 * dichiarazione. Il nome è unico per tipo e per «vale per», senza badare alle maiuscole e agli spazi, come nei modelli delle lettere.
 */
final class TestiDellaNota
{
    public const TIPI = ['criteri', 'gestione', 'rapporti', 'questioni', 'altre', 'causa', 'spiegazione', 'dichiarazione'];

    public const NOME_DOPPIO = 'C\'è già un testo dello studio con questo nome per questo campo e con lo stesso «vale per»: scegline un altro.';

    /**
     * I testi validi nel condominio: prima quelli del condominio, poi quelli per tutti, per nome.
     *
     * @return list<array{id:int, tipo:string, nome:string, testo:string, per_tutti:bool}>
     */
    public static function delCondominio(Condominio $condominio): array
    {
        return ModelloTesto::where('ambito', ModelloTesto::AMBITO_NOTA)->validiNel((int) $condominio->id)->get()
            ->sortBy(fn (ModelloTesto $m) => [array_search($m->tipo, self::TIPI, true), $m->condominio_id === null ? 1 : 0, mb_strtolower($m->nome), $m->id])
            ->map(fn (ModelloTesto $m) => self::perLaPagina($m))->values()->all();
    }

    /** @return array{id:int, tipo:string, nome:string, testo:string, per_tutti:bool} */
    public static function perLaPagina(ModelloTesto $m): array
    {
        return ['id' => (int) $m->id, 'tipo' => (string) $m->tipo, 'nome' => (string) $m->nome, 'testo' => (string) $m->testo, 'per_tutti' => $m->condominio_id === null];
    }

    /** @return array<string, array<int, mixed>> */
    public static function regole(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(self::TIPI)],
            'nome' => ['required', 'string', 'max:'.ModelloTesto::LUNGHEZZA_NOME],
            'testo' => ['required', 'string', 'max:'.NotaSinteticaDelRendiconto::LUNGHEZZA],
            'per_tutti' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messaggi(): array
    {
        return [
            'tipo.*' => 'Il testo non ha un campo della nota.',
            'nome.required' => 'Scrivi il nome del testo.',
            'nome.max' => 'Il nome è al massimo di '.ModelloTesto::LUNGHEZZA_NOME.' caratteri.',
            'testo.required' => 'Il testo è vuoto: scrivilo prima di salvarlo.',
            'testo.max' => 'Il testo è troppo lungo: al massimo '.NotaSinteticaDelRendiconto::LUNGHEZZA.' caratteri.',
            'per_tutti.required' => 'Scegli per quali condomini vale il testo: tutti, o solo questo.',
        ];
    }

    /** Un testo dello studio della nota valido nel condominio (suo o per tutti), o 404: un modello delle lettere o di un altro condominio non si tocca da qui. */
    public static function oppure404(string|int $id, Condominio $condominio): ModelloTesto
    {
        abort_unless(is_numeric($id), 404);

        return ModelloTesto::where('ambito', ModelloTesto::AMBITO_NOTA)->validiNel((int) $condominio->id)->findOrFail((int) $id);
    }

    /** Cambia il nome di un testo, con la stessa regola del nome unico per campo e per «vale per». */
    public static function rinomina(ModelloTesto $testo, string $nome, ?User $utente): ModelloTesto
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        if ($nome === '') {
            throw ValidationException::withMessages(['nome' => 'Scrivi il nome del testo.']);
        }
        if (self::doppio((string) $testo->tipo, $nome, $testo->condominio_id === null ? null : (int) $testo->condominio_id, (int) $testo->id)) {
            throw ValidationException::withMessages(['nome' => self::NOME_DOPPIO]);
        }
        $testo->update(['nome' => $nome, 'updated_by' => $utente?->id]);

        return $testo;
    }

    /** Salva un testo nuovo, con il nome senza spazi doppi e il testo con gli a capo «\n». */
    public static function salva(string $tipo, string $nome, string $testo, ?int $condominioId, ?User $utente): ModelloTesto
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        $testo = str_replace("\r\n", "\n", trim($testo));
        if ($nome === '') {
            throw ValidationException::withMessages(['nome' => 'Scrivi il nome del testo.']);
        }
        if ($testo === '') {
            throw ValidationException::withMessages(['testo' => 'Il testo è vuoto: scrivilo prima di salvarlo.']);
        }
        if (self::doppio($tipo, $nome, $condominioId)) {
            throw ValidationException::withMessages(['nome' => self::NOME_DOPPIO]);
        }

        return ModelloTesto::create(['ambito' => ModelloTesto::AMBITO_NOTA, 'tipo' => $tipo, 'nome' => $nome, 'testo' => $testo, 'condominio_id' => $condominioId,
            'created_by' => $utente?->id, 'updated_by' => $utente?->id]);
    }

    /** C'è già un altro testo con questo nome per il campo e per «vale per»? Senza badare alle maiuscole e agli spazi. */
    private static function doppio(string $tipo, string $nome, ?int $condominioId, ?int $tranne = null): bool
    {
        return ModelloTesto::where('ambito', ModelloTesto::AMBITO_NOTA)->where('tipo', $tipo)
            ->when($condominioId === null, fn ($q) => $q->whereNull('condominio_id'), fn ($q) => $q->where('condominio_id', $condominioId))
            ->when($tranne !== null, fn ($q) => $q->whereKeyNot($tranne))
            ->get(['id', 'nome'])->contains(fn (ModelloTesto $m) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $m->nome))) === mb_strtolower($nome));
    }
}

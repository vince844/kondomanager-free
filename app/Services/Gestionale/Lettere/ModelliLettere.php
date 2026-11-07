<?php

namespace App\Services\Gestionale\Lettere;

use App\Models\Condominio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\ModelloTesto;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * I modelli delle lettere dello studio: per ogni tipo di lettera, quanti ne servono, con un nome, per tutti i condomini o per uno solo. I testi
 * del programma ({@see TestiLettere::DI_SERIE}) non sono modelli: non si cambiano e non stanno nel database.
 *
 * - **Nella finestra della lettera** il menu porta, per il tipo scelto, il testo del programma e poi i modelli validi nel condominio: prima
 *   quelli del condominio, poi quelli per tutti, per nome. Un modello di un altro condominio non si vede e non si usa (404 per tutta la richiesta).
 * - **Si ricorda** l'ultimo modello scelto di ogni tipo nel condominio, dalla riga dell'ultima lettera di quel tipo scritta dal blocco
 *   (`dati.modello_id`): nessun posto nuovo dove scriverlo. Non contano le lettere rifatte («Riprova le non partite», «Invia di nuovo»:
 *   `dati.rifatta_da`), che ripetono il modello di allora senza che nessuno lo scelga, né la lettera di una persona (`dati.singola`), che ha un
 *   testo per lei. Se quel modello non c'è più, o non vale più nel condominio, la finestra parte dal testo del programma e lo dice.
 * - **La lettera copia il testo**: la riga del registro tiene il testo con i campi (`dati.modello`), il testo riempito, e il numero e il nome del
 *   modello scelto (`dati.modello_id`, `dati.modello_nome`). Cambiare o cancellare il modello dopo non cambia le lettere già scritte.
 * - **Il nome** è unico per tipo e per «vale per» (tutti, o quel condominio), senza badare alle maiuscole e agli spazi; lo controlla il
 *   programma, perché su MySQL un indice unico non vede due nulli come uguali.
 * - Creare, cambiare ed eliminare un modello lo fa chi gestisce le impostazioni di stampa; vederli e usarli, chi prepara le lettere.
 */
final class ModelliLettere
{
    public const NOME_DOPPIO = 'C\'è già un modello con questo nome per questo tipo di lettera e con lo stesso «vale per»: scegline un altro.';

    /**
     * I modelli validi in un condominio, per la finestra: per ogni tipo (nell'ordine dei tipi) prima quelli del condominio, poi quelli per tutti,
     * per nome.
     *
     * @return list<array{id: int, tipo: string, nome: string, testo: string, per_tutti: bool}>
     */
    public function delCondominio(Condominio $condominio): array
    {
        return self::ordinati(ModelloTesto::perLettere()->validiNel((int) $condominio->id)->whereIn('tipo', EmissioneLettere::TIPI)->get())
            ->map(fn (ModelloTesto $m) => self::perLaFinestra($m))->values()->all();
    }

    /** @return array{id: int, tipo: string, nome: string, testo: string, per_tutti: bool} */
    public static function perLaFinestra(ModelloTesto $m): array
    {
        return ['id' => (int) $m->id, 'tipo' => (string) $m->tipo, 'nome' => (string) $m->nome, 'testo' => (string) $m->testo, 'per_tutti' => $m->condominio_id === null];
    }

    /**
     * I modelli nell'ordine della finestra e della pagina: il tipo, poi quelli di un condominio prima di quelli per tutti, poi il nome.
     *
     * @param Collection<int, ModelloTesto> $modelli
     * @return Collection<int, ModelloTesto>
     */
    public static function ordinati(Collection $modelli): Collection
    {
        $ordine = array_flip(EmissioneLettere::TIPI);

        return $modelli->sort(fn (ModelloTesto $a, ModelloTesto $b) => [$ordine[$a->tipo] ?? 99, $a->condominio_id === null ? 1 : 0, mb_strtolower($a->nome), $a->id]
            <=> [$ordine[$b->tipo] ?? 99, $b->condominio_id === null ? 1 : 0, mb_strtolower($b->nome), $b->id])->values();
    }

    /**
     * L'ultimo modello scelto di ogni tipo nel condominio, dalla riga dell'ultima lettera di quel tipo scritta dal blocco (non rifatta, non di una
     * persona sola): null se quella lettera partiva dal testo del programma (o non c'è una lettera); `mancante` se il modello non c'è più o non
     * vale più nel condominio (con il nome scritto nella lettera).
     *
     * @return array<string, array{id: ?int, nome: string, mancante: bool}|null>
     */
    public function ricordati(Condominio $condominio): array
    {
        $validi = ModelloTesto::perLettere()->validiNel((int) $condominio->id)->get()->keyBy('id');
        $ricordati = [];
        foreach (EmissioneLettere::TIPI as $tipo) {
            $dati = (array) (RegistroInvio::where('condominio_id', $condominio->id)->where('tipo', $tipo)
                ->whereNull('dati->rifatta_da')->whereNull('dati->singola')->orderByDesc('id')->first()?->dati ?? []);
            $id = is_numeric($dati['modello_id'] ?? null) ? (int) $dati['modello_id'] : null;
            if ($id === null) {
                $ricordati[$tipo] = null;

                continue;
            }
            $modello = $validi->get($id);
            $ricordati[$tipo] = $modello !== null && $modello->tipo === $tipo
                ? ['id' => $id, 'nome' => (string) $modello->nome, 'mancante' => false]
                : ['id' => null, 'nome' => (string) ($dati['modello_nome'] ?? ''), 'mancante' => true];
        }

        return $ricordati;
    }

    /**
     * Il modello scelto nella finestra per una lettera (`modello_id`), per scriverne il numero e il nome nella riga del registro. Un modello di
     * un altro condominio è un 404 per tutta la richiesta. Uno che non c'è più (cancellato dopo la scelta) o di un altro tipo non è il modello di
     * questa lettera: la lettera parte con il suo testo, senza il nome di un modello.
     *
     * @return array{id: int, nome: string}|null
     */
    public static function scelto(Condominio $condominio, string $tipo, mixed $id): ?array
    {
        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }
        $modello = ModelloTesto::perLettere()->find((int) $id);
        if ($modello === null) {
            return null;
        }
        abort_if($modello->condominio_id !== null && (int) $modello->condominio_id !== (int) $condominio->id, 404);

        return $modello->tipo === $tipo ? ['id' => (int) $modello->id, 'nome' => (string) $modello->nome] : null;
    }

    /** Un modello delle lettere che vale in questo condominio (suo o per tutti), altrimenti 404. */
    public static function delCondominioOppure404(Condominio $condominio, string|int $id): ModelloTesto
    {
        abort_unless(is_numeric($id), 404);
        $modello = ModelloTesto::perLettere()->find((int) $id);
        abort_if($modello === null || ($modello->condominio_id !== null && (int) $modello->condominio_id !== (int) $condominio->id), 404);

        return $modello;
    }

    /** Un modello delle lettere, altrimenti 404. */
    public static function oppure404(string|int $id): ModelloTesto
    {
        abort_unless(is_numeric($id), 404);

        return ModelloTesto::perLettere()->findOrFail((int) $id);
    }

    /**
     * Le regole dei campi di un modello. Il tipo si sceglie solo per un modello nuovo: un modello non cambia tipo (si duplica).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function regole(bool $nuovo): array
    {
        return array_filter([
            'tipo'  => $nuovo ? ['required', 'string', \Illuminate\Validation\Rule::in(EmissioneLettere::TIPI)] : null,
            'nome'  => ['required', 'string', 'max:' . ModelloTesto::LUNGHEZZA_NOME],
            'testo' => ['required', 'string', 'max:' . TestiLettere::LUNGHEZZA_MASSIMA],
        ]);
    }

    /** I messaggi delle regole, nella lingua delle pagine. */
    public static function messaggi(): array
    {
        return [
            'tipo.required'  => 'Scegli il tipo della lettera.',
            'tipo.in'        => EmissioneLettere::TIPO_CHE_NON_C_E,
            'nome.required'  => 'Scrivi il nome del modello.',
            'nome.max'       => 'Il nome del modello è al massimo di ' . ModelloTesto::LUNGHEZZA_NOME . ' caratteri.',
            'testo.required' => 'Scrivi il testo del modello.',
            'testo.max'      => 'Il testo è troppo lungo: al massimo ' . TestiLettere::LUNGHEZZA_MASSIMA . ' caratteri.',
        ];
    }

    /**
     * Crea o cambia un modello, con il nome e il testo come li salva una lettera (senza gli spazi in testa e in coda, gli a capo «\n») e il nome
     * unico per tipo e «vale per». Il promemoria non ha un termine: un suo modello con `[termine]` si rifiuta, come la lettera.
     *
     * @param array{tipo?: string, nome: string, testo: string, condominio_id: ?int} $dati
     */
    public function salva(?ModelloTesto $modello, array $dati, ?User $utente): ModelloTesto
    {
        $tipo = $modello?->tipo ?? (string) $dati['tipo'];
        $nome = trim((string) preg_replace('/\s+/u', ' ', (string) $dati['nome']));
        $testo = str_replace("\r\n", "\n", trim((string) $dati['testo']));
        $condominio = isset($dati['condominio_id']) ? (int) $dati['condominio_id'] : null;
        if ($nome === '') {
            throw ValidationException::withMessages(['nome' => 'Scrivi il nome del modello.']);
        }
        if ($testo === '') {
            throw ValidationException::withMessages(['testo' => 'Scrivi il testo del modello.']);
        }
        if ($tipo === 'promemoria' && str_contains($testo, '[termine]')) {
            throw ValidationException::withMessages(['testo' => EmissioneLettere::PROMEMORIA_SENZA_TERMINE]);
        }
        $doppio = ModelloTesto::perLettere()->where('tipo', $tipo)
            ->when($condominio === null, fn ($q) => $q->whereNull('condominio_id'), fn ($q) => $q->where('condominio_id', $condominio))
            ->when($modello !== null, fn ($q) => $q->whereKeyNot($modello->id))
            ->get(['id', 'nome'])
            ->contains(fn (ModelloTesto $m) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $m->nome))) === mb_strtolower($nome));
        if ($doppio) {
            throw ValidationException::withMessages(['nome' => self::NOME_DOPPIO]);
        }

        $campi = ['nome' => $nome, 'testo' => $testo, 'condominio_id' => $condominio, 'updated_by' => $utente?->id];
        if ($modello === null) {
            return ModelloTesto::create($campi + ['ambito' => ModelloTesto::AMBITO_LETTERA, 'tipo' => $tipo, 'created_by' => $utente?->id]);
        }
        $modello->update($campi);

        return $modello;
    }

    /** «Aggiorna» dalla finestra: il testo nuovo di un modello, con il nome e il «vale per» di prima. */
    public function aggiornaIlTesto(ModelloTesto $modello, string $testo, ?User $utente): ModelloTesto
    {
        return $this->salva($modello, ['nome' => $modello->nome, 'testo' => $testo, 'condominio_id' => $modello->condominio_id], $utente);
    }
}

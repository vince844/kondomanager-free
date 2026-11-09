<?php

namespace App\Services\PDF;

use App\Models\CartaStampa;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le regole delle carte dello studio, dalla pagina delle impostazioni delle stampe.
 *
 * - Una carta ha un nome (unico, senza badare alle maiuscole e agli spazi in più), il logo sì o no, il colore dei titoli e delle righe (`#rrggbb`, o nullo
 *   per i colori di sempre di ogni stampa). Un colore che sul bianco della carta ha un contrasto sotto 4,5 a 1 si rifiuta: i titoli non si
 *   leggerebbero.
 * - L'aspetto: a campi (lo studio lo scrive il programma) o con la carta intestata (lo studio è stampato sulla carta: un PDF o un'immagine, i cui
 *   controlli stanno in {@see CartaIntestata}), con i quattro margini in mm: l'alto e il basso fino a 150 insieme, il sinistro e il destro fino a 50.
 *   Una carta tornata a campi tiene il file, per tornare alla carta intestata senza caricarlo di nuovo; con «Togli la carta intestata» il file
 *   (e i margini) si tolgono, e il file si cancella dal disco dopo il salvataggio.
 * - Una sola carta è la predefinita: segnarne un'altra toglie il segno alla prima; la predefinita non perde il segno da sola, perché i gruppi
 *   senza una carta scelta resterebbero senza.
 * - La predefinita non si elimina, e quindi nemmeno l'ultima carta (l'ultima è sempre la predefinita); una carta scelta per un gruppo nemmeno, e
 *   il rifiuto dice quali gruppi.
 * - La carta per gruppo: per ognuno dei cinque gruppi una carta che c'è, o nullo per la predefinita.
 */
final class CarteDelloStudio
{
    public const NOME_PREDEFINITA = 'Carta dello studio';

    /** Il contrasto minimo del colore con il bianco della carta, come per il testo nelle linee guida dell'accessibilità (WCAG, livello AA). */
    public const CONTRASTO_MINIMO = 4.5;

    /** I gruppi che hanno una carta: tutti, tranne il modello F24. */
    public static function gruppi(): array
    {
        return array_values(array_filter(GruppoCarta::cases(), fn (GruppoCarta $g) => $g !== GruppoCarta::NESSUNO));
    }

    /** La «Carta dello studio» quando la tabella è vuota: a campi, con il logo, il colore nullo, predefinita. */
    public static function laPredefinitaSeManca(): void
    {
        if (! CartaStampa::query()->exists()) {
            CartaStampa::create(['nome' => self::NOME_PREDEFINITA, 'aspetto' => CartaStampa::ASPETTO_CAMPI, 'mostra_logo' => true, 'colore' => null, 'predefinita' => true]);
        }
    }

    /** @return array<string, mixed> */
    public static function regole(?CartaStampa $carta): array
    {
        return [
            'nome' => ['required', 'string', 'max:100', function (string $campo, mixed $valore, \Closure $rifiuta) use ($carta) {
                if (is_string($valore) && self::nomeGiaUsato($valore, $carta)) {
                    $rifiuta('C\'è già una carta con questo nome.');
                }
            }],
            'mostra_logo' => ['required', 'boolean'],
            'colore' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D', function (string $campo, mixed $valore, \Closure $rifiuta) {
                if (is_string($valore) && preg_match('/^#[0-9a-fA-F]{6}$/D', $valore) === 1 && ($contrasto = self::contrasto($valore)) < self::CONTRASTO_MINIMO) {
                    $rifiuta(sprintf('Il colore %s sul bianco della carta ha un contrasto di %s a 1: i titoli si leggerebbero male. Scegli un colore più scuro (il contrasto deve essere almeno 4,5 a 1).',
                        strtolower($valore), number_format(floor($contrasto * 100) / 100, 2, ',', '')));
                }
            }],
            'predefinita' => ['required', 'boolean'],
            'aspetto' => ['sometimes', 'string', 'in:'.CartaStampa::ASPETTO_CAMPI.','.CartaStampa::ASPETTO_CARTA],
            // Un PNG passa la regola per sentirsi dire la sua frase, «Carica la carta come JPG…» ({@see CartaIntestata::rifiutoDellImmagine()}).
            'carta' => ['nullable', 'bail', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.\App\Support\LimiteCaricamento::regolaMax(self::MB_CARTA)],
            'margine_alto' => self::regolaMargine(self::MARGINE_ALTO_BASSO),
            'margine_basso' => [...self::regolaMargine(self::MARGINE_ALTO_BASSO), function (string $campo, mixed $valore, \Closure $rifiuta) {
                $alto = request()->input('margine_alto');
                if (is_numeric($valore) && is_numeric($alto) && (float) $valore + (float) $alto > self::MARGINE_ALTO_BASSO) {
                    $rifiuta('Il margine alto e quello basso insieme sono al massimo 150 mm: sotto, il testo non avrebbe spazio.');
                }
            }],
            'margine_sinistro' => self::regolaMargine(self::MARGINE_LATI),
            'margine_destro' => self::regolaMargine(self::MARGINE_LATI),
            // Il file della carta intestata si toglie solo da una carta che va a campi: con la carta intestata serve.
            'togli_carta_intestata' => ['exclude_unless:aspetto,'.CartaStampa::ASPETTO_CAMPI, 'boolean'],
        ];
    }

    /** La carta intestata più grande che si carica, in MB (o il limite del server, se è più basso). */
    public const MB_CARTA = 10.0;

    /** Il margine alto e il basso, ognuno e insieme; il sinistro e il destro: in mm. */
    public const MARGINE_ALTO_BASSO = 150;

    public const MARGINE_LATI = 50;

    /** Un margine della carta intestata: solo con la carta intestata, un numero di millimetri fino a `$massimo`. */
    private static function regolaMargine(int $massimo): array
    {
        return ['exclude_unless:aspetto,'.CartaStampa::ASPETTO_CARTA, 'required', 'numeric', 'between:0,'.$massimo];
    }

    /** Il nome come si salva: senza spazi ai lati, e gli spazi in mezzo (anche quelli che non vanno a capo) uno solo. */
    public static function nomePulito(string $nome): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $nome));
    }

    /**
     * Un'altra carta ha già questo nome, senza badare alle maiuscole e agli spazi in più? Il confronto è nel codice, non nel database: la collazione del
     * database direbbe cose diverse su MySQL e su SQLite, e nella tendina due nomi che differiscono solo per le maiuscole non si distinguono.
     */
    public static function nomeGiaUsato(string $nome, ?CartaStampa $carta): bool
    {
        $cercato = mb_strtolower(self::nomePulito($nome));

        return CartaStampa::query()->when($carta !== null, fn ($q) => $q->whereKeyNot($carta->id))->pluck('nome')
            ->contains(fn (string $altro) => mb_strtolower(self::nomePulito($altro)) === $cercato);
    }

    /** @return array<string, string> */
    public static function messaggi(): array
    {
        return [
            'nome.required' => 'La carta ha bisogno di un nome.',
            'nome.max' => 'Il nome della carta può avere al massimo 100 caratteri.',
            'colore.regex' => 'Il colore si scrive come #rrggbb, per esempio #1e3a5f.',
            'carta.file' => 'La carta intestata va caricata come PDF, o come immagine JPG.',
            'carta.mimes' => 'La carta intestata va caricata come PDF, o come immagine JPG.',
            'carta.max' => 'La carta intestata può essere grande al massimo '.\App\Support\LimiteCaricamento::etichetta(self::MB_CARTA).'.',
            'carta.uploaded' => 'La carta intestata può essere grande al massimo '.\App\Support\LimiteCaricamento::etichetta(self::MB_CARTA).'.',
        ] + self::messaggiDeiMargini();
    }

    /** @return array<string, string> */
    private static function messaggiDeiMargini(): array
    {
        $messaggi = [];
        foreach (['alto' => self::MARGINE_ALTO_BASSO, 'basso' => self::MARGINE_ALTO_BASSO, 'sinistro' => self::MARGINE_LATI, 'destro' => self::MARGINE_LATI] as $lato => $massimo) {
            $messaggi["margine_{$lato}.required"] = "Scrivi il margine {$lato} della carta, in millimetri.";
            $messaggi["margine_{$lato}.numeric"] = "Il margine {$lato} è un numero di millimetri.";
            $messaggi["margine_{$lato}.between"] = "Il margine {$lato} va da 0 a {$massimo} mm.";
        }

        return $messaggi;
    }

    /** Il contrasto di un colore `#rrggbb` con il bianco: (1 + 0,05) / (luminanza + 0,05), con la luminanza relativa del colore. */
    public static function contrasto(string $colore): float
    {
        $lineare = function (string $due): float {
            $v = hexdec($due) / 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        $luminanza = 0.2126 * $lineare(substr($colore, 1, 2)) + 0.7152 * $lineare(substr($colore, 3, 2)) + 0.0722 * $lineare(substr($colore, 5, 2));

        return 1.05 / ($luminanza + 0.05);
    }

    /**
     * Salva una carta nuova (`$carta` nullo) o cambiata, con i dati già validati. Una carta segnata come predefinita toglie il segno alle altre. Con la
     * carta intestata, i margini; `$file`: il file nuovo della carta intestata già salvato sul disco (il percorso, il tipo, le pagine), o null per
     * tenere quello che c'è. Una carta a campi con `togli_carta_intestata` perde il file e i margini (il file sul disco lo cancella chi chiama, dopo).
     *
     * @param  array{percorso: string, tipo: string, pagine: int}|null  $file
     *
     * @throws ValidationException se la predefinita perde il segno
     */
    public function salva(?CartaStampa $carta, array $dati, ?array $file = null): CartaStampa
    {
        if ($carta?->predefinita && ! $dati['predefinita']) {
            throw ValidationException::withMessages(['predefinita' => 'C\'è sempre una carta predefinita: per cambiarla, segna come predefinita un\'altra carta.']);
        }
        $aspetto = ($dati['aspetto'] ?? null) === CartaStampa::ASPETTO_CARTA ? CartaStampa::ASPETTO_CARTA : CartaStampa::ASPETTO_CAMPI;
        $campi = [
            'nome' => self::nomePulito((string) $dati['nome']),
            'mostra_logo' => (bool) $dati['mostra_logo'],
            'colore' => ($dati['colore'] ?? null) === null ? null : strtolower((string) $dati['colore']),
            'predefinita' => (bool) $dati['predefinita'],
            'aspetto' => $aspetto,
        ];
        if ($aspetto === CartaStampa::ASPETTO_CARTA) {
            foreach (['alto', 'basso', 'sinistro', 'destro'] as $lato) {
                $campi["margine_{$lato}"] = round((float) $dati["margine_{$lato}"], 1);
            }
        }
        if ($file !== null) {
            $campi += ['carta_path' => $file['percorso'], 'carta_tipo' => $file['tipo'], 'carta_pagine' => $file['pagine']];
        } elseif ($aspetto === CartaStampa::ASPETTO_CAMPI && ! empty($dati['togli_carta_intestata'])) {
            $campi += ['carta_path' => null, 'carta_tipo' => null, 'carta_pagine' => null,
                'margine_alto' => null, 'margine_basso' => null, 'margine_sinistro' => null, 'margine_destro' => null];
        }

        return DB::transaction(function () use ($carta, $campi) {
            if ($campi['predefinita']) {
                // Le predefinite si leggono bloccate fino alla fine: due richieste insieme che segnano due carte diverse si mettono in fila, e
                // la seconda trova la predefinita della prima (senza il blocco tutte e due toglierebbero il segno alla vecchia e ne resterebbero due).
                CartaStampa::where('predefinita', true)->when($carta !== null, fn ($q) => $q->whereKeyNot($carta->id))->lockForUpdate()->get()
                    ->each(fn (CartaStampa $altra) => $altra->update(['predefinita' => false]));
            }
            if ($carta === null) {
                return CartaStampa::create($campi);
            }
            $carta->update($campi);

            return $carta;
        });
    }

    /** I gruppi che hanno scelto la carta. */
    public static function gruppiDi(CartaStampa $carta): array
    {
        $scelte = app(PrintSettings::class)->carta_per_gruppo;

        return array_values(array_filter(self::gruppi(), fn (GruppoCarta $g) => (int) ($scelte[$g->value] ?? 0) === $carta->id));
    }

    /** Perché la carta non si elimina, o null se si elimina. */
    public static function rifiutoEliminazione(CartaStampa $carta): ?string
    {
        if ($carta->predefinita) {
            return "La carta «{$carta->nome}» è la predefinita: non si elimina. Per eliminarla, segna prima come predefinita un'altra carta.";
        }
        $gruppi = self::gruppiDi($carta);
        if ($gruppi !== []) {
            return "La carta «{$carta->nome}» si usa per: ".implode(', ', array_map(fn (GruppoCarta $g) => $g->nome(), $gruppi))
                .'. Scegli un\'altra carta per questi documenti, poi eliminala.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function regolePerGruppo(): array
    {
        $chiavi = array_map(fn (GruppoCarta $g) => $g->value, self::gruppi());
        $regole = ['carta_per_gruppo' => ['required', 'array:'.implode(',', $chiavi)]];
        foreach ($chiavi as $chiave) {
            $regole["carta_per_gruppo.{$chiave}"] = ['present', 'nullable', 'integer', 'exists:carte_stampa,id'];
        }

        return $regole;
    }

    /** @return array<string, string> */
    public static function messaggiPerGruppo(): array
    {
        $messaggi = ['carta_per_gruppo.array' => 'La scelta delle carte vale per i cinque gruppi di documenti, e solo per loro.'];
        foreach (self::gruppi() as $g) {
            $messaggi["carta_per_gruppo.{$g->value}.exists"] = "La carta scelta per «{$g->nome()}» non c'è più: scegline un'altra.";
            $messaggi["carta_per_gruppo.{$g->value}.present"] = "Scegli la carta per «{$g->nome()}».";
        }

        return $messaggi;
    }

    /** Salva la carta per gruppo, già validata: per ognuno dei cinque gruppi l'id, o nullo per la predefinita. */
    public function assegna(array $perGruppo): void
    {
        $s = app(PrintSettings::class);
        $scelte = [];
        foreach (self::gruppi() as $g) {
            $scelte[$g->value] = isset($perGruppo[$g->value]) ? (int) $perGruppo[$g->value] : null;
        }
        $s->carta_per_gruppo = $scelte;
        $s->save();
    }

    /** La scelta per gruppo per la pagina: i cinque gruppi, e un id che non c'è più come nullo (la predefinita). */
    public static function sceltePerLaPagina(): array
    {
        $scelte = app(PrintSettings::class)->carta_per_gruppo;
        $ci = CartaStampa::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $pagina = [];
        foreach (self::gruppi() as $g) {
            $id = isset($scelte[$g->value]) ? (int) $scelte[$g->value] : null;
            $pagina[$g->value] = $id !== null && in_array($id, $ci, true) ? $id : null;
        }

        return $pagina;
    }
}

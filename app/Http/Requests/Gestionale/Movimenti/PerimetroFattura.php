<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Saldo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ogni id che una fattura riceve dal browser sta nel condominio dell'indirizzo, e il capitolo di una riga è una voce di spesa.
 *
 * Registrazione e modifica condividono le regole degli id (la voce, l'unità, il conto di addebito, il fondo di riserva, il debito, la tabella
 * millesimale dell'imprevisto) e il controllo sul tipo della voce. Un id di un altro condominio cambierebbe il libro di quel condominio: la
 * scrittura della fattura porta il mastro, la voce e l'unità che la riga indica. Un elenco al posto di un id è un errore sul campo, non il
 * testo della query. I messaggi sono propri perché «non è valido» non dice dove sta l'errore.
 */
final class PerimetroFattura
{
    /** I messaggi delle regole di id, per chiave di regola: si uniscono a quelli della Request. */
    public static function messaggi(): array
    {
        return [
            'righe.*.conto_id.integer' => 'La voce scelta non è valida.',
            'righe.*.conto_id.exists' => 'La voce scelta non appartiene a questo condominio.',
            'righe.*.immobile_id.integer' => 'L\'unità scelta non è valida.',
            'righe.*.immobile_id.exists' => 'L\'unità scelta non appartiene a questo condominio.',
            'conto_corrente_id.integer' => 'Il conto di addebito scelto non è valido.',
            'conto_corrente_id.exists' => 'Il conto di addebito scelto non appartiene a questo condominio.',
            'dati_extra.override_budget.fondo_patrimoniale_id.integer' => 'Il fondo di riserva scelto non è valido.',
            'dati_extra.override_budget.fondo_patrimoniale_id.exists' => 'Il fondo di riserva scelto non appartiene a questo condominio.',
            'saldo_patrimoniale_id.integer' => 'Il debito scelto non è valido.',
            'saldo_patrimoniale_id.exists' => 'Il debito scelto non appartiene a questo condominio.',
            'coperture.*.fonte_id.integer' => 'La fonte scelta per la copertura non è valida.',
            'dati_extra.log_legale_sopravvenienza.tabella_millesimale_id.integer' => 'La tabella millesimale scelta non è valida.',
            'dati_extra.log_legale_sopravvenienza.tabella_millesimale_id.exists' => 'La tabella millesimale scelta non appartiene a questo condominio.',
        ];
    }

    /** @return list<mixed> */
    public static function regolaVoce(int $condominioId): array
    {
        return ['bail', 'nullable', 'integer', Rule::exists('conti', 'id')->whereIn('piano_conto_id', self::pianiDeiConti($condominioId))];
    }

    /** @return list<mixed> */
    public static function regolaUnita(int $condominioId): array
    {
        return ['bail', 'nullable', 'integer', Rule::exists('immobili', 'id')->where('condominio_id', $condominioId)];
    }

    /** Il conto di addebito è un mastro del condominio. @return list<mixed> */
    public static function regolaContoDiAddebito(int $condominioId): array
    {
        return ['bail', 'nullable', 'integer', Rule::exists('conti_contabili', 'id')->where('condominio_id', $condominioId)];
    }

    /** Il fondo di riserva è il mastro di una cassa di tipo fondo del condominio, la stessa selezione che il modulo offre. @return list<mixed> */
    public static function regolaFondo(int $condominioId): array
    {
        return ['bail', 'nullable', 'integer', Rule::exists('casse', 'conto_contabile_id')->where('condominio_id', $condominioId)->where('tipo', 'fondo')];
    }

    /** @return list<mixed> */
    public static function regolaDebito(int $condominioId): array
    {
        return ['bail', 'nullable', 'integer', Rule::exists('saldi', 'id')->where('condominio_id', $condominioId)];
    }

    /** @return list<mixed> */
    public static function regolaTabella(int $condominioId, string $obbligataSe): array
    {
        return ['bail', 'nullable', 'integer', $obbligataSe, Rule::exists('tabelle', 'id')->where('condominio_id', $condominioId)];
    }

    /**
     * L'id come lo legge la regola `integer`: «+5» e 5.0 valgono 5 come 5, e il perimetro li controlla come l'id intero. Un valore che la regola rifiuta (una
     * lista, un testo, un decimale scritto 5.5) non è un id: ha già il suo errore sul campo e qui non si legge.
     */
    private static function comeUnId(mixed $valore): ?int
    {
        $id = filter_var($valore, FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }

    /** @return \Closure(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder */
    private static function pianiDeiConti(int $condominioId): \Closure
    {
        return fn ($q) => $q->select('id')->from('piani_conti')->where('condominio_id', $condominioId);
    }

    /**
     * Le righe con un capitolo e un importo non possono stare su una voce di entrata: sull'entrata la fattura crea uno sforo e il piano che lo
     * recupera accredita ai condòmini invece di chiedere. Restano fuori le righe con l'unità (il capitolo si scarta), quelle a € 0,00 (testo del
     * documento) e quelle di sopravvenienza (il capitolo lo scarta il servizio). Una riga negativa conta come una positiva.
     *
     * Il tipo si legge solo per gli id interi (anche scritti «+5», come li accetta la regola), non già in errore e dentro il condominio: una voce di un
     * altro condominio prende il rifiuto del perimetro e non si nomina.
     *
     * @param  mixed  $righe  le righe della richiesta
     * @param  list<int>  $vociGiaNelleRighe  le voci che la fattura in modifica ha già: la frase dice che la riga è già lì
     */
    public static function rifiutaLeEntrate(Validator $validator, mixed $righe, int $condominioId, array $vociGiaNelleRighe = []): void
    {
        if (! is_array($righe)) {
            return;
        }

        $candidate = [];
        foreach ($righe as $idx => $riga) {
            if (! is_array($riga) || ! empty($riga['immobile_id']) || (float) ($riga['importo_imponibile'] ?? 0) === 0.0
                || filter_var($riga['is_sopravvenienza'] ?? false, FILTER_VALIDATE_BOOLEAN) || $validator->errors()->has("righe.{$idx}.conto_id")) {
                continue;
            }
            $id = self::comeUnId($riga['conto_id'] ?? null);
            if ($id !== null) {
                $candidate[$idx] = $id;
            }
        }
        if ($candidate === []) {
            return;
        }

        $entrate = Conto::query()
            ->whereIn('id', array_unique($candidate))
            ->whereIn('piano_conto_id', PianoConto::query()->where('condominio_id', $condominioId)->select('id'))
            ->whereNotIn('tipo', Conto::TIPI_DI_SPESA)
            ->pluck('nome', 'id');

        foreach ($candidate as $idx => $contoId) {
            if (! $entrate->has($contoId)) {
                continue;
            }
            $nome = $entrate->get($contoId);
            $validator->errors()->add("righe.{$idx}.conto_id", in_array($contoId, $vociGiaNelleRighe, true)
                ? "Questa riga è sulla voce di entrata «{$nome}»: il capitolo di una fattura è una voce di spesa. Scegli una voce di spesa per salvare la modifica."
                : "«{$nome}» è una voce di entrata: il capitolo di una fattura è una voce di spesa. Scegli un'altra voce.");
        }
    }

    /**
     * Il perimetro della fonte di una copertura, che dipende dal tipo della sua riga: un debito del condominio per `rata_0` e `saldo_patrimoniale`,
     * un fondo per `fondo_riserva`, una voce di spesa del condominio per `sopravvenienza` (lo storno scrive costo e voce sul mastro che la fonte
     * indica, e su un'entrata li scriverebbe come un costo).
     *
     * @param  mixed  $coperture  le coperture della richiesta
     */
    public static function controllaLeFonti(Validator $validator, mixed $coperture, int $condominioId): void
    {
        if (! is_array($coperture)) {
            return;
        }

        foreach ($coperture as $i => $copertura) {
            $campo = "coperture.{$i}.fonte_id";
            $fonte = is_array($copertura) ? self::comeUnId($copertura['fonte_id'] ?? null) : null;
            if ($fonte === null || $validator->errors()->has($campo)) {
                continue;
            }

            switch ($copertura['tipo_copertura'] ?? null) {
                case 'rata_0':
                case 'saldo_patrimoniale':
                    if (! Saldo::query()->where('condominio_id', $condominioId)->whereKey($fonte)->exists()) {
                        $validator->errors()->add($campo, 'Il debito scelto non appartiene a questo condominio.');
                    }
                    break;
                case 'fondo_riserva':
                    if (! Cassa::query()->where('condominio_id', $condominioId)->where('tipo', 'fondo')->where('conto_contabile_id', $fonte)->exists()) {
                        $validator->errors()->add($campo, 'Il fondo di riserva scelto non appartiene a questo condominio.');
                    }
                    break;
                case 'sopravvenienza':
                    $voce = Conto::query()->whereKey($fonte)
                        ->whereIn('piano_conto_id', PianoConto::query()->where('condominio_id', $condominioId)->select('id'))
                        ->first(['id', 'nome', 'tipo']);
                    if ($voce === null) {
                        $validator->errors()->add($campo, 'La voce scelta non appartiene a questo condominio.');
                    } elseif (Conto::segnoDelTipo((string) $voce->tipo) !== 1) {
                        $validator->errors()->add($campo, "«{$voce->nome}» è una voce di entrata: una copertura per sopravvenienza si appoggia a una voce di spesa.");
                    }
                    break;
            }
        }
    }
}

<?php

namespace App\Http\Controllers\Impostazioni;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\ModelloTesto;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\ModelliLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pagina «Modelli delle lettere», da «Impostazioni → Stampe»: per ogni tipo il testo del programma (che non si cambia) e i modelli dello
 * studio, con il nome, «vale per», chi l'ha cambiato e il giorno. Vederli è di chi entra nel pannello (la rotta lo chiede), come usarli nella
 * finestra delle lettere; creare, cambiare ed eliminare è di chi gestisce le impostazioni di stampa. Un modello non cambia tipo: si duplica.
 * Eliminare un modello non tocca le lettere già scritte, che hanno il loro testo.
 */
class ModelliLettereController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private readonly ModelliLettere $modelli) {}

    public function index(Request $request): Response
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $modelli = ModelliLettere::ordinati(ModelloTesto::perLettere()->whereIn('tipo', EmissioneLettere::TIPI)->with(['condominio:id,nome', 'cambiatoDa:id,name'])->get());

        return Inertia::render('impostazioni/ModelliLettere', [
            'tipi'                => collect(EmissioneLettere::NOMI)->map(fn ($nome, $tipo) => ['tipo' => $tipo, 'nome' => $nome])->values()->all(),
            'testi_del_programma' => TestiLettere::DI_SERIE,
            'modelli'             => $modelli->map(fn (ModelloTesto $m) => [
                'id'          => (int) $m->id,
                'tipo'        => (string) $m->tipo,
                'nome'        => (string) $m->nome,
                'testo'       => (string) $m->testo,
                'condominio'  => $m->condominio === null ? null : ['id' => (int) $m->condominio->id, 'nome' => (string) $m->condominio->nome],
                'cambiato_da' => $m->cambiatoDa?->name,
                // Il giorno nel calendario dell'amministratore: il browser lo formatta e basta.
                'cambiato_il' => $m->updated_at?->copy()->setTimezone($fuso)->format('Y-m-d'),
            ])->all(),
            'condomini'           => Condominio::orderBy('nome')->get(['id', 'nome'])->map(fn (Condominio $c) => ['id' => (int) $c->id, 'nome' => (string) $c->nome])->all(),
            'campi'               => TestiLettere::CAMPI,
            // I valori con cui la pagina mostra l'anteprima di un testo: scritti come li scrive la lettera.
            'esempio'             => ['nome' => 'Maria Bianchi', 'totale' => TestiLettere::euro(18000), 'termine' => TestiLettere::termine(15), 'condominio' => 'Le Rose'],
            'puo_modificare'      => self::puoModificare($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::puoModificare($request), 403);
        $dati = $request->validate(ModelliLettere::regole(true) + self::regolaCondominio(), ModelliLettere::messaggi() + self::messaggiCondominio());
        $modello = $this->modelli->salva(null, $dati, $request->user());

        return back()->with($this->flashSuccess('Modello «' . $modello->nome . '» salvato.'));
    }

    /** Il nome, il testo e «vale per»; il tipo no. */
    public function update(Request $request, string|int $modello): RedirectResponse
    {
        $esistente = ModelliLettere::oppure404($modello);
        abort_unless(self::puoModificare($request), 403);
        $dati = $request->validate(ModelliLettere::regole(false) + self::regolaCondominio(), ModelliLettere::messaggi() + self::messaggiCondominio());
        $this->modelli->salva($esistente, $dati, $request->user());

        return back()->with($this->flashSuccess('Modello «' . $esistente->nome . '» salvato. Le lettere già scritte non cambiano.'));
    }

    public function destroy(Request $request, string|int $modello): RedirectResponse
    {
        $esistente = ModelliLettere::oppure404($modello);
        abort_unless(self::puoModificare($request), 403);
        $nome = $esistente->nome;
        $esistente->delete();

        return back()->with($this->flashSuccess('Modello «' . $nome . '» eliminato. Le lettere già scritte non cambiano.'));
    }

    /** @return array<string, array<int, string>> */
    private static function regolaCondominio(): array
    {
        // `present`: «vale per» lo sceglie l'amministratore; una richiesta senza la chiave non diventa un modello per tutti.
        return ['condominio_id' => ['present', 'nullable', 'integer', 'exists:condomini,id']];
    }

    /** @return array<string, string> */
    private static function messaggiCondominio(): array
    {
        return ['condominio_id.present' => 'Scegli per quali condomini vale il modello: tutti, o uno.',
            'condominio_id.exists' => 'Il condominio scelto non c\'è più: scegli di nuovo per quali condomini vale il modello.'];
    }

    /**
     * Creare, cambiare ed eliminare i modelli: chi gestisce le impostazioni di stampa (il permesso di `PrintSettingsPolicy::manage`). Con `can()`,
     * che risponde no quando il permesso non è nel database, invece della policy, che solleverebbe un'eccezione.
     */
    private static function puoModificare(Request $request): bool
    {
        return $request->user()?->can(Permission::MANAGE_GENERAL_SETTINGS->value) === true;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Services\Posta\CasellaPec;
use App\Services\Posta\ConfigurazionePec;
use App\Services\Posta\ErroreDellaPec;
use App\Services\Posta\MotivoDellaProva;
use App\Services\Posta\ProvaDellaPec;
use App\Services\Posta\ProveDelleCaselle;
use App\Settings\PecSettings;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * La casella PEC dello studio nella pagina «Configurazione email» (decisioni 564, 572 e 577): «Salva la casella PEC», «Prova l'invio» e «Togli la
 * casella PEC». Le rotte stanno con
 * quelle della posta e passano dagli stessi controlli (proposta 17): il permesso delle impostazioni (403, prima di tutto), la password ridigitata da poco
 * (423) e, per la prova, sei prove al minuto (429); poi i campi (422).
 *
 * Come la posta (Coda 311): la password salvata parte solo verso il server, la porta e la cifratura per cui è scritta; con il campo vuoto e un'altra
 * destinazione, la prova e il salvataggio chiedono di riscriverla. Non torna mai alla pagina. La cifratura è solo SSL o TLS: la PEC non parte in chiaro.
 */
class CasellaPecController extends Controller
{
    use HandleFlashMessages;

    public const PASSWORD_DA_RISCRIVERE = 'Hai cambiato server, porta o cifratura: riscrivi la password della casella PEC. La password salvata vale solo per il '
        . 'server per cui è scritta.';

    public const PASSWORD_MANCANTE = 'Scrivi la password della casella PEC.';

    /** I nomi dei campi nei messaggi, con le parole della pagina. */
    private const NOMI = ['indirizzo' => 'indirizzo PEC', 'server' => 'server di posta in uscita', 'porta' => 'porta', 'cifratura' => 'cifratura',
        'utente' => 'utente', 'password' => 'password', 'test_email' => 'indirizzo della prova'];

    /** «Salva la casella PEC»: vale da quando si salva. Un cambio di server, porta, cifratura, utente o password riporta la casella a «Da provare». */
    public function update(Request $request, PecSettings $pec): RedirectResponse
    {
        $dati = $request->validate($this->regole() + [
            'password' => [filled($pec->password) ? 'nullable' : 'required', 'string', 'max:255'],
        ], [], self::NOMI);

        if (! $request->filled('password') && filled($pec->password) && ! $this->stessaDestinazione($dati, $pec)) {
            throw ValidationException::withMessages(['password' => self::PASSWORD_DA_RISCRIVERE]);
        }

        $prima = $this->impronta($pec);
        $pec->indirizzo = trim((string) $dati['indirizzo']);
        $pec->server = trim((string) $dati['server']);
        $pec->porta = (int) $dati['porta'];
        $pec->cifratura = (string) $dati['cifratura'];
        $pec->utente = trim((string) $dati['utente']);
        if ($request->filled('password')) {
            $pec->password = Crypt::encryptString((string) $request->input('password'));
        }
        $dopo = $this->impronta($pec);
        if ($dopo !== null && ($dopo !== $prima || $pec->impostata_il === null)) {
            $pec->impostata_il = now()->toIso8601String();
        }
        $pec->save();

        return back()->with($this->flashSuccess('La casella PEC dello studio è salvata: le convocazioni per PEC partono da qui.'));
    }

    /**
     * «Togli la casella PEC» (decisione 577): per un cambio di gestore, o per fermare le PEC. La casella torna «Da impostare», le PEC non partono e «Manda le
     * PEC» lo dice; il salvataggio è una riga del registro delle modifiche come gli altri (i campi che si vuotano, la password «cambiata»).
     */
    public function destroy(PecSettings $pec): RedirectResponse
    {
        $pec->indirizzo = null;
        $pec->server = null;
        $pec->porta = 465;
        $pec->cifratura = 'ssl';
        $pec->utente = null;
        $pec->password = null;
        $pec->impostata_il = null;
        $pec->ultima_prova = null;
        $pec->save();

        return back()->with($this->flashSuccess('La casella PEC dello studio è tolta: le convocazioni per PEC non partono finché non la imposti di nuovo.'));
    }

    /**
     * «Prova l'invio»: con i valori scritti nella pagina, anche se non sono salvati, e senza salvarli; di serie verso la stessa casella PEC (proposta 8).
     * Il programma ricorda la prova con l'impronta della casella provata.
     */
    public function prova(Request $request, PecSettings $pec, ProvaDellaPec $prova): JsonResponse
    {
        $dati = $request->validate($this->regole() + [
            'password' => ['nullable', 'string', 'max:255'],
            'test_email' => ['required', 'email', 'max:150'],
        ], [], self::NOMI);

        $salvata = ! $request->filled('password') ? CasellaPec::decifra($pec->password) : null;
        if (! $request->filled('password') && $salvata === null) {
            return response()->json(['success' => false, 'message' => self::PASSWORD_MANCANTE, 'campo' => 'password'], 422);
        }
        if ($salvata !== null && ! $this->stessaDestinazione($dati, $pec)) {
            return response()->json(['success' => false, 'message' => self::PASSWORD_DA_RISCRIVERE, 'campo' => 'password'], 422);
        }

        $casella = new ConfigurazionePec(trim((string) $dati['indirizzo']), trim((string) $dati['server']), (int) $dati['porta'], trim((string) $dati['utente']),
            $salvata ?? (string) $request->input('password'), (string) $dati['cifratura']);
        $destinatario = trim((string) $dati['test_email']);
        $errore = $prova->manda($casella, $destinatario);
        ProveDelleCaselle::ricordaPec($errore === null, $errore, $destinatario, CasellaPec::impronta($casella));

        $quando = ProveDelleCaselle::quando(now()->toIso8601String());
        if ($errore === null) {
            return response()->json(['success' => true, 'prova' => ['quando' => $quando, 'andata' => true, 'destinatario' => $destinatario]]);
        }

        return response()->json([
            'success' => false,
            'message' => $errore,
            'motivo' => MotivoDellaProva::per(ErroreDellaPec::motivo($errore), 'pec'),
            'prova' => ['quando' => $quando, 'andata' => false, 'destinatario' => $destinatario],
        ], 500);
    }

    /** @return array<string, array<int, mixed>> */
    private function regole(): array
    {
        return [
            'indirizzo' => ['required', 'email', 'max:150'],
            'server' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'porta' => ['required', 'integer', 'between:1,65535'],
            'cifratura' => ['required', Rule::in(['ssl', 'tls'])],
            'utente' => ['required', 'string', 'max:255'],
        ];
    }

    /** Server (senza maiuscole e spazi), porta e cifratura della richiesta sono quelli salvati? L'utente non conta: la password andrebbe allo stesso server. */
    private function stessaDestinazione(array $dati, PecSettings $pec): bool
    {
        return strtolower(trim((string) $dati['server'])) === strtolower(trim((string) $pec->server))
            && (int) $dati['porta'] === (int) $pec->porta
            && (string) $dati['cifratura'] === (string) $pec->cifratura;
    }

    /** L'impronta della casella salvata; `null` finché manca qualcosa. */
    private function impronta(PecSettings $pec): ?string
    {
        $password = CasellaPec::decifra($pec->password);
        if ($password === null || ! filled($pec->server) || ! filled($pec->utente)) {
            return null;
        }

        return CasellaPec::impronta(new ConfigurazionePec((string) $pec->indirizzo, (string) $pec->server, (int) $pec->porta, (string) $pec->utente, $password,
            $pec->cifratura));
    }
}

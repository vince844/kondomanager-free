<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace Tests\Support\RegistroModifiche;

/**
 * Il lettore della guardia del registro delle modifiche (§13 (2) del progetto, P5): legge il testo del codice e trova le scritture in blocco, quelle che
 * gli eventi dei modelli non vedono. Per ognuna dice il file, la riga, il metodo che la contiene e la tabella che tocca (quando il testo la dice).
 *
 * Le forme: `DB::table('t')` con un verbo di scrittura; `DB::statement`/`DB::update`/`DB::delete`/`DB::insert`/`DB::unprepared`; `Modello::where…->update`/
 * `->delete`/`->forceDelete`/`->increment`/`->decrement`; `Modello::insert`/`insertGetId`/`insertOrIgnore`/`upsert`/`updateOrInsert`/`truncate`; le query
 * su una relazione (`$gestione->pianiRate()->delete()`); `sync`/`attach`/`detach`/`syncWithoutDetaching`/`updateExistingPivot`/`toggle`; i ruoli e i
 * permessi di Spatie (`syncRoles`, `assignRole`, …); `forceDelete`; `increment`/`decrement` su un'istanza.
 *
 * Che cosa non vede (lo dichiara il test): le catene di chiamate (un metodo che scrive in blocco chiamato da un altro); una scrittura su una variabile che
 * contiene una query (`$query->delete()`); una scrittura costruita in un modo che le espressioni non riconoscono.
 */
final class LettoreDelleScritture
{
    private const VERBI_DI_QUERY = 'update|delete|forceDelete|increment|decrement|incrementEach|decrementEach|insert|insertGetId|insertOrIgnore|insertUsing|upsert|updateOrInsert|truncate';

    private const VERBI_DEI_LEGAMI = 'sync|attach|detach|syncWithoutDetaching|updateExistingPivot|syncWithPivotValues|toggle';

    private const VERBI_DI_SPATIE = 'syncRoles|assignRole|removeRole|syncPermissions|givePermissionTo|revokePermissionTo';

    /**
     * Le chiamate del registro che **avvolgono** il lavoro (la funzione passata scrive, e la riga dice ciò che ha fatto): una scrittura dentro la funzione
     * passata a una di loro è coperta. Le altre chiamate del registro (`gesto()`, `scrivi()`, gli atti sulle utenze, gli aiutanti dei gesti) scrivono una
     * riga accanto alla scrittura, non intorno: quella scrittura va fra le eccezioni, con quante sono.
     */
    public const AVVOLGONO = '/\bRegistroModifiche::(durante|durantePiuTransazioni|legame|campoDellaScheda|comeRipristino)\(/';

    /** Chiamate che portano i modelli in memoria: dopo di loro un `->delete()` è sull'istanza, con i suoi eventi. */
    private const IN_MEMORIA = '->(get|first|firstOrFail|firstOrCreate|firstOrNew|find|findOrFail|sole|each|cursor|lazy|lazyById|chunk|chunkById|map|pluck|all|fresh|refresh)\b';

    /**
     * @param array<string, string> $tabelleDeiModelli nome corto del modello => tabella
     * @param array<string, list<string>> $tabelleDelleRelazioni nome della relazione => tabelle (la relazione e la sua pivot)
     */
    public function __construct(private array $tabelleDeiModelli, private array $tabelleDelleRelazioni) {}

    /** Il lettore con le tabelle dei modelli di `app/Models` e le relazioni lette dai loro sorgenti (con le pivot dei `belongsToMany`). */
    public static function daiModelli(): self
    {
        $tabelle = [];
        $classi = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Models'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $classe = 'App\\Models\\' . str_replace('/', '\\', substr($file->getPathname(), strlen(app_path('Models')) + 1, -4));
            if (class_exists($classe) && is_subclass_of($classe, \Illuminate\Database\Eloquent\Model::class)) {
                $tabelle[class_basename($classe)] = (new $classe())->getTable();
                $classi[class_basename($classe)] = $file->getPathname();
            }
        }

        $relazioni = ['roles' => ['model_has_roles'], 'permissions' => ['role_has_permissions', 'model_has_permissions']];
        foreach ($classi as $base => $percorso) {
            $sorgente = (string) file_get_contents($percorso);
            preg_match_all('/function\s+(\w+)\s*\(\s*\)[^{]*\{(.*?)(?=\n\s*(?:public|protected|private)\s+(?:static\s+)?function|\z)/s', $sorgente, $metodi, PREG_SET_ORDER);
            foreach ($metodi as [, $nome, $corpo]) {
                if (! preg_match('/\$this->(hasMany|hasOne|belongsTo|belongsToMany|morphMany|morphOne|morphToMany|morphedByMany|hasManyThrough|hasOneThrough)\(\s*\\\\?([\\\\\w]+)::class(?:\s*,\s*[\'"](\w+)[\'"])?(?:\s*,\s*[\'"](\w+)[\'"])?/', $corpo, $r)) {
                    continue;
                }
                $arrivo = $tabelle[class_basename(str_replace('\\', '/', $r[2]))] ?? null;
                $arrivo ??= $tabelle[basename(str_replace('\\', '/', $r[2]))] ?? null;
                $suo = [];
                if ($arrivo !== null) {
                    $suo[] = $arrivo;
                }
                if ($r[1] === 'belongsToMany') {
                    $pivot = $r[3] ?? '';
                    if ($pivot === '') {
                        $nomi = [\Illuminate\Support\Str::snake($base), \Illuminate\Support\Str::snake(basename(str_replace('\\', '/', $r[2])))];
                        sort($nomi);
                        $pivot = implode('_', $nomi);
                    }
                    $suo[] = $pivot;
                } elseif (in_array($r[1], ['morphToMany', 'morphedByMany'], true) && isset($r[4]) && $r[4] !== '') {
                    $suo[] = $r[4];
                }
                $relazioni[$nome] = array_values(array_unique([...($relazioni[$nome] ?? []), ...$suo]));
            }
        }

        return new self($tabelle, $relazioni);
    }

    /**
     * Le scritture in blocco di un sorgente.
     *
     * `coperta`: la scrittura sta **dentro** il lavoro di una chiamata del registro che lo avvolge ({@see AVVOLGONO}: nella funzione passata, o nella stessa
     * istruzione con una funzione freccia), o fra `RegistroModifiche::apriGesto(` e `chiudiGesto(` dello stesso metodo. `chiamaIlRegistro`: il metodo
     * chiama il registro in un punto qualunque (non basta per coprire una scrittura: la guardia conta le scritture, non il metodo).
     *
     * @return list<array{riga: int, metodo: ?string, tabelle: list<string>|null, testo: string, chiamaIlRegistro: bool, coperta: bool}>
     */
    public function scritture(string $sorgente): array
    {
        $trovate = [];
        foreach ($this->istruzioni($sorgente) as $istruzione) {
            $tabelle = $this->tabelleScritte($istruzione['testo']);
            if ($tabelle === false) {
                continue;
            }
            $trovate[] = [
                'riga' => $istruzione['riga'],
                'metodo' => $istruzione['metodo'],
                'tabelle' => $tabelle,
                'testo' => mb_substr($istruzione['testo'], 0, 200),
                // Il metodo scrive il registro: un gesto, un atto sulle utenze, un campo della scheda scritto da un aiutante dei gesti.
                'chiamaIlRegistro' => $istruzione['corpoDelMetodo'] !== null
                    && (bool) preg_match('/\b(RegistroModifiche|AttiSulleUtenze)::|\bGestiDelRegistro::scrivi\w*\(/', $istruzione['corpoDelMetodo']),
                'coperta' => $this->coperta($istruzione),
            ];
        }

        return $trovate;
    }

    /** @param array{riga: int, testo: string, contesto: string, gesto: bool} $istruzione */
    private function coperta(array $istruzione): bool
    {
        if ($istruzione['gesto'] || preg_match(self::AVVOLGONO, $istruzione['contesto']) === 1) {
            return true;
        }
        // Nella stessa istruzione, con una funzione freccia: la chiamata che avvolge viene prima della scrittura.
        if (preg_match(self::AVVOLGONO, $istruzione['testo'], $m, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }
        $prima = substr($istruzione['testo'], 0, $m[0][1]);

        return $this->tabelleScritte($prima) === false;
    }

    /**
     * Le tabelle che un'istruzione scrive in blocco: `false` se non è una scrittura in blocco, `null` se lo è ma il testo non dice quale tabella.
     *
     * @return list<string>|null|false
     */
    public function tabelleScritte(string $testo): array|null|false
    {
        $v = self::VERBI_DI_QUERY;

        // DB::table('t') … ->verbo(
        if (preg_match('/DB::table\(\s*[\'"](\w+)[\'"]/', $testo, $m) && preg_match('/->(' . $v . ')\(/', $testo)) {
            return $this->dopoLaMemoria($testo, 'DB::table') ? false : [$m[1]];
        }
        // SQL scritto a mano.
        if (preg_match('/DB::(statement|unprepared|update|delete|insert)\(\s*[\'"]([^\'"]*)/', $testo, $m)) {
            if (preg_match_all('/\b(?:update|into|from|table)\s+`?(\w+)`?/i', $m[2], $t) && $t[1] !== []) {
                return array_values(array_unique(array_map('strtolower', $t[1])));
            }

            return null;
        }
        // Modello::insert(…), Modello::upsert(…), …
        if (preg_match('/\b([A-Z]\w*)::(insert|insertGetId|insertOrIgnore|upsert|updateOrInsert|truncate)\(/', $testo, $m)
            && isset($this->tabelleDeiModelli[$m[1]])) {
            return [$this->tabelleDeiModelli[$m[1]]];
        }
        // Modello::where…(…)->verbo(  /  Modello::query()…->verbo(
        if (preg_match('/\b([A-Z]\w*)::(where\w*|query|withTrashed|onlyTrashed|whereKey|whereIn|whereNotIn|has|whereHas|doesntHave|withoutGlobalScopes?)\(/',
            $testo, $m) && isset($this->tabelleDeiModelli[$m[1]]) && preg_match('/->(' . $v . ')\(/', $testo)) {
            return $this->dopoLaMemoria($testo, $m[1] . '::') ? false : [$this->tabelleDeiModelli[$m[1]]];
        }
        // I legami: ->relazione()->sync(…)
        if (preg_match('/->(\w+)\(\)\s*->(' . self::VERBI_DEI_LEGAMI . ')\(/', $testo, $m)) {
            return $this->tabelleDiRelazione($m[1]);
        }
        if (preg_match('/->(' . self::VERBI_DEI_LEGAMI . ')\(/', $testo) && preg_match('/->(\w+)\(\)/', $testo, $m)) {
            return $this->tabelleDiRelazione($m[1]);
        }
        // I ruoli e i permessi.
        if (preg_match('/->(' . self::VERBI_DI_SPATIE . ')\(/', $testo)) {
            return ['model_has_roles', 'model_has_permissions', 'role_has_permissions'];
        }
        // Le query su una relazione: ->relazione()…->verbo(
        if (preg_match('/->(\w+)\(\)\s*(->\w+\([^;]*?\)\s*)*->(' . $v . ')\(/', $testo, $m) && ! $this->dopoLaMemoria($testo, '->' . $m[1] . '()')) {
            if (preg_match('/->(' . $v . ')\(/', $testo, $verbo) && in_array($verbo[1], ['increment', 'decrement'], true)
                && preg_match('/^\s*\$\w+->(increment|decrement)\(/', $testo)) {
                return null;
            }

            // Un nome che non è una relazione di un modello (`$this->disco()->delete($path)`) non è una scrittura su una tabella.
            return $this->tabelleDiRelazione($m[1]) ?? false;
        }
        // Una query con un filtro su una variabile: $q->where(…)->delete().
        if (preg_match('/->(where\w*|whereIn|whereKey)\(.*->(' . $v . ')\(/', $testo) && ! preg_match('/' . self::IN_MEMORIA . '/', $testo)) {
            return null;
        }
        // forceDelete e i contatori su un'istanza.
        if (preg_match('/->(forceDelete|increment|decrement)\(/', $testo)) {
            return null;
        }

        return false;
    }

    /** Se fra la testa della query e il verbo c'è una chiamata che porta i modelli in memoria (allora il verbo è sull'istanza). */
    private function dopoLaMemoria(string $testo, string $testa): bool
    {
        $da = strpos($testo, $testa);
        $dopo = $da === false ? $testo : substr($testo, $da);
        if (! preg_match('/->(' . self::VERBI_DI_QUERY . ')\(/', $dopo, $verbo, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        return (bool) preg_match('/' . self::IN_MEMORIA . '/', substr($dopo, 0, $verbo[0][1]));
    }

    /** @return list<string>|null */
    private function tabelleDiRelazione(string $relazione): ?array
    {
        return $this->tabelleDelleRelazioni[$relazione] ?? null;
    }

    /**
     * Le istruzioni del sorgente, con la riga d'inizio, il metodo che le contiene e il suo corpo. Un `{` dentro una parentesi (una funzione passata a una
     * query) apre un livello: l'istruzione di fuori riprende dopo la `}`; un `{` fuori dalle parentesi (un blocco) la chiude.
     *
     * `contesto`: i testi delle espressioni aperte intorno all'istruzione (una funzione passata a una chiamata: «RegistroModifiche::durante($gesto, function
     * () {»); `gesto`: l'istruzione sta fra `RegistroModifiche::apriGesto(` e `chiudiGesto(` del suo metodo.
     *
     * @return list<array{riga: int, metodo: ?string, testo: string, corpoDelMetodo: ?string, contesto: string, gesto: bool}>
     */
    public function istruzioni(string $sorgente): array
    {
        $gettoni = token_get_all($sorgente);
        $metodi = $this->metodi($gettoni);
        $righeDelSorgente = explode("\n", $sorgente);
        $istruzioni = [];
        $pila = [];
        $testo = '';
        $riga = null;
        $tonde = 0;
        $rigaCorrente = 1;
        $interpolazioni = 0;

        $chiudi = function () use (&$testo, &$riga, &$istruzioni, &$pila, $metodi, $righeDelSorgente) {
            $t = trim(preg_replace('/\s+/', ' ', $testo));
            if ($t !== '' && $riga !== null) {
                $metodo = null;
                $corpo = null;
                $gesto = false;
                foreach ($metodi as $m) {
                    if ($riga >= $m['da'] && $riga <= $m['a']) {
                        $metodo = $m['nome'];
                        $corpo = $m['corpo'];
                        $gesto = $this->fraApriEChiudi(array_slice($righeDelSorgente, $m['da'] - 1, $riga - $m['da']),
                            array_slice($righeDelSorgente, $riga, $m['a'] - $riga));
                    }
                }
                $contesto = implode(' ', array_map(fn ($p) => $p[2] > 0 ? (string) $p[0] : '', $pila));
                $istruzioni[] = ['riga' => $riga, 'metodo' => $metodo, 'testo' => $t, 'corpoDelMetodo' => $corpo,
                    'contesto' => trim(preg_replace('/\s+/', ' ', $contesto)), 'gesto' => $gesto];
            }
            $testo = '';
            $riga = null;
        };

        foreach ($gettoni as $g) {
            if (is_array($g)) {
                $rigaCorrente = $g[2];
                if (in_array($g[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($g[0] !== T_WHITESPACE && $riga === null) {
                    $riga = $g[2];
                }
                if (in_array($g[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $interpolazioni++;
                }
                $testo .= $g[1];
                continue;
            }
            if ($g === '}' && $interpolazioni > 0) {
                // La graffa che chiude un «{$…}» dentro una stringa.
                $interpolazioni--;
                $testo .= $g;
                continue;
            }
            if ($g === '{' || $g === '}') {
                if ($g === '{') {
                    $pila[] = [$testo, $riga, $tonde];
                    $testo = '';
                    $riga = null;
                    $tonde = 0;
                } else {
                    $chiudi();
                    [$prima, $rigaPrima, $tondePrima] = array_pop($pila) ?? ['', null, 0];
                    if ($tondePrima > 0) {
                        $testo = $prima . '{…}';
                        $riga = $rigaPrima;
                        $tonde = $tondePrima;
                    } else {
                        $testo = '';
                        $riga = null;
                        $tonde = 0;
                    }
                }
                continue;
            }
            if ($riga === null && trim($g) !== '') {
                $riga = $rigaCorrente;
            }
            if ($g === '(') {
                $tonde++;
            } elseif ($g === ')') {
                $tonde--;
            }
            if ($g === ';' && $tonde <= 0) {
                $testo .= ';';
                $chiudi();
                $tonde = 0;
                continue;
            }
            $testo .= $g;
        }

        return $istruzioni;
    }

    /**
     * Se un'istruzione sta fra un `RegistroModifiche::apriGesto(` prima di lei e un `chiudiGesto(` dopo, nel suo metodo (la transazione aperta e chiusa a
     * mano).
     *
     * @param list<string> $prima le righe del metodo prima dell'istruzione
     * @param list<string> $dopo le righe del metodo dopo
     */
    private function fraApriEChiudi(array $prima, array $dopo): bool
    {
        $aperto = false;
        foreach ($prima as $r) {
            if (str_contains($r, 'RegistroModifiche::apriGesto(')) {
                $aperto = true;
            } elseif (str_contains($r, 'RegistroModifiche::chiudiGesto(')) {
                $aperto = false;
            }
        }

        return $aperto && str_contains(implode("\n", $dopo), 'RegistroModifiche::chiudiGesto(');
    }

    /** I metodi con nome: dalla riga di `function` alla `}` che li chiude, con il corpo. @return list<array{nome: string, da: int, a: int, corpo: string}> */
    private function metodi(array $gettoni): array
    {
        $metodi = [];
        $n = count($gettoni);
        for ($i = 0; $i < $n; $i++) {
            if (! is_array($gettoni[$i]) || $gettoni[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && is_array($gettoni[$j]) && $gettoni[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $n && $gettoni[$j] === '&') {
                $j++;
                while ($j < $n && is_array($gettoni[$j]) && $gettoni[$j][0] === T_WHITESPACE) {
                    $j++;
                }
            }
            if (! is_array($gettoni[$j] ?? null) || $gettoni[$j][0] !== T_STRING) {
                continue;
            }
            $nome = $gettoni[$j][1];
            $da = $gettoni[$i][2];
            $k = $j;
            while ($k < $n && $gettoni[$k] !== '{' && $gettoni[$k] !== ';') {
                $k++;
            }
            if (($gettoni[$k] ?? null) !== '{') {
                continue;
            }
            $profondita = 0;
            $corpo = '';
            $a = $da;
            for (; $k < $n; $k++) {
                $t = $gettoni[$k];
                $corpo .= is_array($t) ? $t[1] : $t;
                if (is_array($t)) {
                    $a = $t[2] + substr_count($t[1], "\n");
                } elseif ($t === '{') {
                    $profondita++;
                } elseif ($t === '}') {
                    $profondita--;
                    if ($profondita === 0) {
                        break;
                    }
                }
                if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $profondita++;
                }
            }
            $metodi[] = ['nome' => $nome, 'da' => $da, 'a' => $a, 'corpo' => $corpo];
        }

        return $metodi;
    }
}

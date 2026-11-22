<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

/**
 * Le parti di un messaggio di posta salvato come file `.eml` (MIME, RFC 2045–2046): le intestazioni, e per ogni parte il tipo, il nome del file e il
 * contenuto già decodificato (base64, quoted-printable). Serve a trovare il `daticert.xml` dentro una ricevuta PEC ({@see RicevutaPec}).
 *
 * Una lettura stretta, senza librerie: le parti annidate (`multipart/*`) si seguono fino a dieci livelli e al più duecento parti; un messaggio allegato
 * (`message/rfc822`, il `postacert.eml` della ricevuta completa) resta una parte sola, non si apre. Un messaggio firmato in forma opaca
 * (`application/pkcs7-mime`) si apre con OpenSSL senza controllare il certificato del gestore: il programma legge i dati, non certifica la firma.
 */
final class MessaggioEml
{
    private const LIVELLI = 10;

    private const PARTI = 200;

    /** @var list<array{tipo: string, nome: ?string, contenuto: string}> */
    private array $parti = [];

    private function __construct() {}

    /** Le parti del messaggio, foglie dell'albero MIME. @return list<array{tipo: string, nome: ?string, contenuto: string}> */
    public static function parti(string $eml): array
    {
        $m = new self();
        $m->leggi($eml, 0);

        return $m->parti;
    }

    /** La prima parte con questo nome di file (senza maiuscole), o null. */
    public static function parteDiNome(string $eml, string $nome): ?string
    {
        foreach (self::parti($eml) as $p) {
            if ($p['nome'] !== null && strtolower($p['nome']) === strtolower($nome)) {
                return $p['contenuto'];
            }
        }

        return null;
    }

    private function leggi(string $testo, int $livello): void
    {
        if ($livello > self::LIVELLI || count($this->parti) >= self::PARTI) {
            return;
        }
        [$intestazioni, $corpo] = self::separa($testo);
        $tipo = self::valore($intestazioni['content-type'] ?? 'text/plain');
        $parametri = self::parametri($intestazioni['content-type'] ?? '');
        if (str_starts_with($tipo, 'multipart/') && isset($parametri['boundary'])) {
            foreach (self::pezzi($corpo, $parametri['boundary']) as $pezzo) {
                $this->leggi($pezzo, $livello + 1);
            }

            return;
        }
        $contenuto = self::decodifica($corpo, strtolower(trim($intestazioni['content-transfer-encoding'] ?? '')));
        if (in_array($tipo, ['application/pkcs7-mime', 'application/x-pkcs7-mime'], true) && $livello < self::LIVELLI) {
            $dentro = self::apriLaBusta($testo);
            if ($dentro !== null) {
                $this->leggi($dentro, $livello + 1);

                return;
            }
        }
        $disposizione = self::parametri($intestazioni['content-disposition'] ?? '');
        $nome = $disposizione['filename'] ?? $parametri['name'] ?? null;
        $this->parti[] = ['tipo' => $tipo, 'nome' => $nome === null ? null : self::parolaCodificata($nome), 'contenuto' => $contenuto];
    }

    /** Le intestazioni (in minuscolo, con le righe di continuazione unite) e il corpo. @return array{0: array<string, string>, 1: string} */
    private static function separa(string $testo): array
    {
        $testo = ltrim($testo, "\r\n");
        $pos = preg_match('/\r?\n\r?\n/', $testo, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : null;
        $testa = $pos === null ? $testo : substr($testo, 0, $pos);
        $corpo = $pos === null ? '' : substr($testo, $pos + strlen($m[0][0]));
        $intestazioni = [];
        $ultima = null;
        foreach (preg_split('/\r?\n/', $testa) ?: [] as $riga) {
            if ($riga !== '' && ($riga[0] === ' ' || $riga[0] === "\t") && $ultima !== null) {
                $intestazioni[$ultima] .= ' ' . trim($riga);
                continue;
            }
            if (str_contains($riga, ':')) {
                [$chiave, $valore] = explode(':', $riga, 2);
                $ultima = strtolower(trim($chiave));
                $intestazioni[$ultima] = trim($valore);
            }
        }

        return [$intestazioni, $corpo];
    }

    /** «multipart/mixed; boundary=…» → «multipart/mixed». */
    private static function valore(string $intestazione): string
    {
        return strtolower(trim(explode(';', $intestazione, 2)[0]));
    }

    /** I parametri di un'intestazione: `boundary`, `name`, `filename`, con o senza virgolette. @return array<string, string> */
    private static function parametri(string $intestazione): array
    {
        $parametri = [];
        if (preg_match_all('/;\s*([A-Za-z0-9*\-]+)\s*=\s*("([^"]*)"|[^;\s]+)/', $intestazione, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $p) {
                // Senza le virgolette il terzo gruppo non c'è: `charset=UTF-8`, la forma più comune.
                $parametri[strtolower(rtrim($p[1], '*'))] = ($p[3] ?? '') !== '' ? $p[3] : trim($p[2], '"');
            }
        }

        return $parametri;
    }

    /** I pezzi di un corpo `multipart` fra le righe «--confine». @return list<string> */
    private static function pezzi(string $corpo, string $confine): array
    {
        $righe = preg_split('/\r?\n/', $corpo) ?: [];
        $pezzi = [];
        $corrente = null;
        foreach ($righe as $riga) {
            $pulita = rtrim($riga);
            if ($pulita === '--' . $confine . '--') {
                break;
            }
            if ($pulita === '--' . $confine) {
                if ($corrente !== null) {
                    $pezzi[] = implode("\n", $corrente);
                }
                $corrente = [];
                continue;
            }
            if ($corrente !== null) {
                $corrente[] = $riga;
            }
        }
        if ($corrente !== null && $corrente !== []) {
            $pezzi[] = implode("\n", $corrente);
        }

        return $pezzi;
    }

    private static function decodifica(string $corpo, string $codifica): string
    {
        return match ($codifica) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $corpo) ?? '', false),
            'quoted-printable' => quoted_printable_decode($corpo),
            default => $corpo,
        };
    }

    /** Un nome di file scritto come «=?UTF-8?B?…?=». */
    private static function parolaCodificata(string $nome): string
    {
        $decodificato = function_exists('iconv_mime_decode') ? @iconv_mime_decode($nome, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;

        return is_string($decodificato) && $decodificato !== '' ? $decodificato : $nome;
    }

    /** Il contenuto di un messaggio firmato in forma opaca, senza controllare il certificato; null se non si apre. */
    private static function apriLaBusta(string $messaggio): ?string
    {
        if (! function_exists('openssl_pkcs7_verify')) {
            return null;
        }
        $entrata = tempnam(sys_get_temp_dir(), 'eml');
        $certificati = tempnam(sys_get_temp_dir(), 'crt');
        $uscita = tempnam(sys_get_temp_dir(), 'out');
        try {
            file_put_contents($entrata, $messaggio);
            $esito = @openssl_pkcs7_verify($entrata, PKCS7_NOVERIFY, $certificati, [], null, $uscita);

            return $esito === true && is_file($uscita) && filesize($uscita) > 0 ? (string) file_get_contents($uscita) : null;
        } finally {
            foreach ([$entrata, $certificati, $uscita] as $f) {
                if (is_string($f) && is_file($f)) {
                    @unlink($f);
                }
            }
        }
    }
}

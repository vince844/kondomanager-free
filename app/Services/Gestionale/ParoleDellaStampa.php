<?php

namespace App\Services\Gestionale;

/**
 * Le frasi che le stampe del rendiconto dicono con altre parole. Una pagina parla all'amministratore (il suo pulsante, la sua scheda, il controllo che
 * tocca a lui, il modulo che arriverà); il foglio va ai condòmini, che quei pulsanti non li hanno: lì la stessa cosa si dice in terza persona e senza
 * promesse. Ogni chiave è la frase della pagina presa dalla sua costante, così una frase della pagina riscritta resta una chiave; le cifre restano quelle.
 * Le frasi si cambiano dentro il testo che le contiene (una nota, un rimedio, un'etichetta), con `strtr`: il resto del testo non si tocca.
 */
final class ParoleDellaStampa
{
    public const VARIANTI = [
        SintesiDelRendiconto::FRASE_LIQUIDITA => 'Secondo la contabilità: non è il saldo dell\'estratto conto della banca, che si confronta a parte.',
        StatoPatrimonialePaginaService::FRASE_BANCA => 'Il confronto con l\'estratto conto della banca si fa a parte: il programma non può farlo.',
        StatoPatrimonialePaginaService::NOTA_VINCOLI_A_OGGI => 'Per un esercizio finito questo confronto non si fa: i vincoli si leggono a oggi, non alla data di fine.',
        AnnoACavallo::FRASE_NORMA => 'Una registrazione va nell\'esercizio aperto quando la si scrive, non in quello della sua data '
            .'(un versamento F24 va in quello della sua delega, lo storno di un incasso in quello dell\'incasso): '
            .'per questo alcune stanno oltre il confine quasi ogni anno, e l\'elenco dice dove.',
        AnnoACavallo::RIMEDIO_QUI_APERTO => 'Da quando questo esercizio si chiude e si apre il successivo, le nuove vanno lì.',
        AnnoACavallo::RIMEDIO_FUORI => 'Se le date dell\'esercizio sono sbagliate, si correggono nei dati dell\'esercizio. Se sono registrazioni di un esercizio che nel programma '
            .'non c\'è, restano qui; se è l\'esercizio dopo, le nuove vanno lì da quando questo si chiude e si apre il successivo.',
        AnnoACavallo::CATEGORIE['regolazione'] => 'Spese pagate subito, senza fattura',
        RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO => 'Per i piani delle rate approvati prima che il programma conservasse il dettaglio del riparto, il '
            .'deliberato con le rate è ricalcolato con i dati di oggi e torna al centesimo con le quote chieste a ogni unità.',
        RiepilogoPerCompetenza::FRASE_DELIBERATO_DIVISO => '* Divisa fra le voci con gli importi di oggi: il piano delle rate ha fissato il capitolo, o nessuna voce, e non quanto '
            .'chiedeva ogni voce.',
        RiepilogoPerCompetenza::FRASE_DELIBERATO_SPOSTATO => 'Un piano delle rate cambiato spostando una spesa da una voce a un\'altra non si ricalcola: '
            .'le sue voci di oggi possono non dire quanto chiedevano le rate.',
    ];

    /** La frase com'è nella stampa: le parti che hanno una variante cambiano, il resto resta. */
    public static function frase(?string $testo): ?string
    {
        return $testo === null ? null : strtr($testo, self::VARIANTI);
    }

    /** Il sottotipo e il vincolo di un fondo, come li legge chi ha il foglio: se il sottotipo non c'è, non si scrive niente al suo posto. */
    public static function fondo(array $fondo): string
    {
        $sottotipo = $fondo['sottotipo_label'] === LiquiditaVincolataService::SOTTOTIPO_NON_INDICATO ? null : $fondo['sottotipo_label'];

        return implode(', ', array_filter([$sottotipo, $fondo['vincolato'] ? 'vincolato' : 'liberamente utilizzabile']));
    }
}

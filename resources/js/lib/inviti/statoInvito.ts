export type StatoInvito = 'accettato' | 'scaduto' | 'in_attesa';

/**
 * Lo stato di un invito nell'elenco degli inviti. L'accettazione viene prima della scadenza: un
 * invito accettato resta «Accettato» anche dopo che il suo tempo è passato. Fino alla
 * 1.11.0-beta.44 l'ordine era rovesciato, e un invito accettato diventava «Scaduto» un'ora dopo
 * l'invio.
 */
export function statoInvito(
    acceptedAt: string | null | undefined,
    expiresAt: string | null | undefined,
    adesso: Date = new Date(),
): StatoInvito {
    if (acceptedAt) {
        return 'accettato';
    }

    if (expiresAt && new Date(expiresAt) < adesso) {
        return 'scaduto';
    }

    return 'in_attesa';
}

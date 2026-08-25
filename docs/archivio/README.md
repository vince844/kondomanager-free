# Archivio

Documenti **chiusi**: descrivono lavoro concluso, o sono stati sostituiti da una fonte più recente.
Restano leggibili perché la motivazione di una scelta vale anche quando la scelta è già stata fatta,
ma **non sono più fonti attendibili sullo stato del prodotto** e non vanno usati per decidere.

Non è un cestino. Ci si arriva con tre condizioni, tutte e tre verificate prima di spostare:

1. **Ciò che il documento descrive è concluso**, e la conclusione è scritta altrove — una voce di
   changelog, una guida viva, un documento che lo contiene per intero.
2. **Non contiene niente di unico**: se una parte del ragionamento serviva ancora, quella parte si
   porta nella fonte nuova *prima* di archiviare, invece di lasciarla qui a marcire.
3. **I documenti che lo citavano sono stati ripuntati** alla fonte che lo sostituisce.

`kondomanager:verifica-documentazione` guarda `docs/*.md` e non entra qui: un documento archiviato
smette di comparire nella misura dell'età, che è il punto — l'età serve a decidere cosa riguardare,
e questi sono già stati guardati.

## Cosa c'è dentro

| Documento | Archiviato il | Perché | Cosa leggere al suo posto |
| :--- | :--- | :--- | :--- |
| `registrazione_incasso_rata.md` | 16/08/2026 | Descrive uno schema dati **mai esistito** (`pagamenti`, `pagamento_rata`: verificato, le tabelle non ci sono). In più il §3.2 contiene due ricette da non seguire, fra cui `round($val * 100)` sugli importi — cioè il bug del ×100 che è costato la beta.32 | La guida in-app `IncassoRateGuide.vue` e la guida del sito «Registrare un incasso» |
| `logica_piani_rate.md` | 16/08/2026 | Copia più vecchia e incompleta di un altro documento: su 78 frasi lunghe, 74 sono già nella guida e le altre quattro sono la sua intestazione | `guida_preventivi_rate_capitoli.md`, che lo contiene per intero |
| `fix_eccedenza_rate_non_emesse.md` | 16/08/2026 | Il caso è stato risolto nella beta.9 con una terza via che il documento non contemplava. Nessun altro documento lo citava | La voce `[1.10.0-beta.9]` di `docs/changelog.md` |

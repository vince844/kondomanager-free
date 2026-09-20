<?php

/**
 * B2 — gli invarianti di `docs/subentro_e_competenza_temporale.md` §5 che S4 e S5 devono rendere verdi.
 *
 * Scritti in S1 come `todo`, di proposito senza corpo: un test con un corpo finto passerebbe verde per
 * la ragione sbagliata, mentre un `todo` compare nel rapporto finché qualcuno non lo scrive davvero. Ogni
 * voce porta il numero dell'invariante del progetto e il passo del piano esecutivo che lo chiude. Quando
 * il test vero esiste (in questo file o in uno più adatto), la voce qui si cancella.
 *
 * Gli invarianti già coperti da S1 (5, 24 e la parte pura di D7/D8) stanno in `InsiemePeriodiTest`,
 * `RisolutoreCompetenzaTest` e in coda a `RisolutoreTitolariTest`: non sono ripetuti qui.
 *
 * I tre test di B1 da **rovesciare** (non da aggiungere), marcati nei loro file:
 * - `RisolutoreTitolariTest` «un periodo di competenza passato al risolutore non cambia la risposta»
 *   → ✅ S4: «D7 nel risolutore» (chiuso prima del periodo escluso, «apre nel futuro» senza predecessore dentro);
 * - `RigheRipartoScritturaTest` «il motore con un periodo esplicito dà lo stesso riparto» → ✅ S4: **resta
 *   vero** quando nessun titolare cambia (D8), con il gemello del subentro in `MotoreTemporaleTest`;
 * - `RisolutoreTitolariTest` «updateExistingPivot ne tocca una» → le rotte lavorano per `id`
 *   (decisione 13, S5).
 */

// --- S4: il motore ---------------------------------------------------------------------------------
// ➕ 19/09/2026: i quindici todo di S4 sono diventati test veri in `MotoreTemporaleTest` (inv. 1, 2, 9,
// 10, 13, 18, 22, 24, cancello (2), decisioni 11, 12, 15, 16, 17) e in `RisolutoreTitolariTest` (inv. 7 e 8,
// D7 dentro attiviAlla/vincolaQuery con l'invariante 4 esteso al periodo). Le voci sono state cancellate
// come questo file prescrive.

// --- S5: la registrazione — tutti i todo sono diventati test veri (`RegistraSubentroTest`, `PassaggioTitolaritaTest`). ---


// --- S2: le migrazioni -------------------------------------------------------------------------------

it('inv. 23 [S2] — rieseguire le sei migrazioni di B2 e il drop è un no-op strutturale, senza doppio travaso (dataset di UpgradeMigrationsRerunTest)')->todo();

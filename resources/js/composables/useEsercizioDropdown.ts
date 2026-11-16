// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// composables/useEsercizioDropdown.ts
import { router, usePage } from "@inertiajs/vue3";
import type { Building } from "@/types/buildings";

export function useEsercizioDropdown() {
  const page = usePage<{ condominio: Building }>();

  const selectEsercizio = (condominioId: number | string, esercizioId: number | string) => {
    const currentUrl = page.url;
    
    // Sostituisci l'ID esercizio nell'URL
    const newUrl = currentUrl.replace(
      /\/esercizi\/\d+/,
      `/esercizi/${esercizioId}`
    );

    // FORZA il reload completo con preserveState: false
    router.visit(newUrl, {
      preserveState: false, 
      preserveScroll: true,
    });
  };

  return { selectEsercizio };
}
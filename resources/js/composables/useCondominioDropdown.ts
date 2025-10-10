import { usePage, router } from "@inertiajs/vue3";
import type { Building } from "@/types/buildings";

export function useCondominioDropdown() {
  const page = usePage<{
    condominio: Building;
    condomini: (Building & { esercizio_aperto?: { id: number } | null })[];
  }>();

  const selectCondominio = (id: string | number) => {
    const currentUrl = page.url; // es: /admin/gestionale/1/esercizi/6/gestioni
    const segments = currentUrl.split("/");

    // Trova il condominio selezionato tra quelli disponibili
    const selected = page.props.condomini.find((c) => String(c.id) === String(id));

    // Trova l’indice del condominio attuale nella URL
    const condIndex = segments.findIndex(
      (s) => s === page.props.condominio.id.toString()
    );

    // Sostituisci il condominio se trovato
    if (condIndex !== -1) segments[condIndex] = id.toString();

    // Se siamo nella pagina delle gestioni, aggiorna anche l’esercizio aperto
    const isGestionePage = segments.includes("gestioni");

    if (isGestionePage && selected?.esercizio_aperto?.id) {
      const esercizioIndex = segments.findIndex(
        (s, i) => segments[i - 1] === "esercizi"
      );
      if (esercizioIndex !== -1) {
        segments[esercizioIndex] = selected.esercizio_aperto.id.toString();
      }
    }

    // Naviga verso la nuova URL
    const newUrl = segments.join("/");
    router.visit(newUrl, { preserveState: false, preserveScroll: true });
  };

  return { selectCondominio };
}

import { usePage, router } from "@inertiajs/vue3";
import { usePermission } from "@/composables/permissions";
import type { Building } from "@/types/buildings";

/**
 * Composable per cambiare il condominio mantenendo la stessa pagina
 */
export function useCondominioDropdown() {

  const page = usePage<{ condominio: Building }>(); 

  const selectCondominio = (id: string | number) => {
    // Prendi la URL corrente come array
    const segments = page.url.split('/'); // es: ["admin","gestionale","1","tabelle"]

    // Trova l'indice del condominio corrente
    const currentCondIndex = segments.findIndex(
      (s) => s === page.props.condominio.id.toString()
    );

    if (currentCondIndex !== -1) {
      segments[currentCondIndex] = id.toString(); // sostituisci solo il condominio
    }

    const newUrl = segments.join('/');
    router.visit(newUrl);
  };

  return { selectCondominio };
}

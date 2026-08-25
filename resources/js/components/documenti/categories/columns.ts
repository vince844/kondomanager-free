import { h } from 'vue';
import DropdownAction from '@/components/documenti/categories/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/documenti/categories/DataTableColumnHeader.vue';
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Categoria } from '@/types/categorie';

const { generateRoute } = usePermission();

export const columns: ColumnDef<Categoria>[] = [
  {
    accessorKey: 'name',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('documenti.table.categories.name') }),

    cell: ({ row, table }) => {

      const categoria = row.original;
    
      /*
       * Il nome porta ai **documenti di questa categoria**, non a una scheda della categoria.
       *
       * ⚠️ Fino alla beta.62 puntava a `categorie.show`, una rotta che `Route::resource`
       * registrava e che il controller non implementava: chi cliccava riceveva un **500**. È
       * arrivato dal forum insieme alla domanda giusta — *«non so neanche cosa dovrebbe fare il
       * software cliccando su una categoria»* — e la risposta era già nel prodotto, dall'altro
       * lato: il condòmino sfoglia l'archivio per categoria e vede i documenti di quella.
       *
       * Non serve un metodo nuovo: l'elenco documenti accetta già `category_id`. La barra dei
       * filtri si reidrata da `filters` (vedi `DataTableToolbar.vue`), così la pagina che si apre
       * **dichiara** di essere filtrata invece di esserlo in silenzio.
       */
      return h('div', { class: 'flex items-center space-x-2' }, [
        h('a', {
          href: route(generateRoute('documenti.index'), { category_id: [categoria.id] }),
          class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
        }, categoria.name)
      ]);
    }
  },
  {
    accessorKey: 'description',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('documenti.table.categories.description') }),

    cell: ({ row, table }) => {

      const categoria = row.original;
    
      return h('div', { class: 'flex items-center space-x-2' }, [
        h('div', categoria.description)
      ]);
    }
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const categoria = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { categoria }))
    },
  },
]
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
    
      return h('div', { class: 'flex items-center space-x-2' }, [
        h('a', {
           href: route(generateRoute('categorie.show'), { id: categoria.id }),
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
import { h } from 'vue';
import { router } from '@inertiajs/vue3';
import DropdownAction from '@/components/fornitori/documenti/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/fornitori/documenti/DataTableColumnHeader.vue';
import { publishedConstants } from '@/lib/documenti/constants';
import { usePermission } from "@/composables/permissions";
import { ShieldCheck } from 'lucide-vue-next';
import { Permission }  from "@/enums/Permission";
import type { ColumnDef } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';
import type { Fornitore } from '@/types/fornitori';

const { hasPermission,  generateRoute } = usePermission();

export const createColumns = (fornitore: Fornitore): ColumnDef<Documento>[] => [
  {
    accessorKey: 'name',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Titolo' }),

    cell: ({ row, table }) => {

      const documento = row.original;

      const toggleApproval = () => {
      
          router.put(route(generateRoute('documenti.toggle-approval'), { id: documento.id }), {}, {
            preserveScroll: true,
            only: ['stats', 'documenti'],
            onSuccess: () => {
              // Manually update the specific item
              documento.is_approved = !documento.is_approved;
              documento.is_published = documento.is_approved;

              // Update the row data in the table
              table.options.meta?.updateData(row.index, {
                ...documento,
                is_published: documento.is_approved
              });

            }
          });

      };

      const tooltip = documento.is_approved
        ? 'Approvato - clicca per rimuovere approvazione'
        : 'Non approvato - clicca per approvare';
    
      const shieldIcon = hasPermission([Permission.APPROVE_ARCHIVE_DOCUMENTS])
        ? h('div', {
            class: 'cursor-pointer',
            title: tooltip,
            onClick: toggleApproval,
          }, [
            h(ShieldCheck, {
              class: documento.is_approved ? 'w-4 h-4 text-green-500' : 'w-4 h-4 text-red-500',
            }),
          ])
        : null;
    
      return h('div', { class: 'flex items-center space-x-2' }, [
        shieldIcon,
        h('a', {
          href: route(generateRoute('documenti.download'), { documento: documento.id }),
          class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
        }, documento.name)
      ]);
    }
  },
  {
    accessorKey: 'is_published',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
    cell: ({ row }) => {

      const value = Boolean(row.getValue('is_published'));
      const stato = publishedConstants.find(p => p.value === value);
  
      if (!stato) return h('span', '–');
  
      return h('div', { class: 'flex items-center gap-2' }, [
        h(stato.icon, { class: `h-4 w-4 ${stato.colorClass}` }),
        h('span', stato.label)
      ]);
    },
    filterFn: (row, id, value) =>
      value.includes(Boolean(row.getValue(id))),
  },
  {
    id: 'actions',
    enableHiding: false,

    cell: ({ row }) => {
      const documento = row.original as Documento
      return h(DropdownAction, {
        documento, 
        fornitore
      })
    },
  },
]
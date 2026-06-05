import { h } from 'vue';
import { router } from '@inertiajs/vue3';
import DropdownAction from '@/components/fornitori/documenti/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/fornitori/documenti/DataTableColumnHeader.vue';
import { publishedConstants } from '@/lib/documenti/constants';
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import { ShieldCheck, FileText } from 'lucide-vue-next';
import { formatBytes } from '@/utils/formatBytes';
import { Permission }  from "@/enums/Permission";
import type { ColumnDef } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';
import type { Fornitore } from '@/types/fornitori';

const { hasPermission,  generateRoute } = usePermission();

export const createColumns = (fornitore: Fornitore): ColumnDef<Documento>[] => [
  {
    accessorKey: 'name',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Documento' }),

    cell: ({ row, table }) => {
      const documento = row.original;

      const toggleApproval = () => {
          router.put(route(generateRoute('documenti.toggle-approval'), { id: documento.id }), {}, {
            preserveScroll: true,
            only: ['stats', 'documenti'],
            onSuccess: () => {
              documento.is_approved = !documento.is_approved;
              documento.is_published = documento.is_approved;
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
            class: 'cursor-pointer mt-3 mr-1',
            title: tooltip,
            onClick: toggleApproval,
          }, [
            h(ShieldCheck, {
              class: documento.is_approved ? 'w-4 h-4 text-emerald-500' : 'w-4 h-4 text-slate-300 hover:text-slate-500 transition-colors',
            }),
          ])
        : null;

      const fileIcon = h('div', { class: 'w-10 h-10 flex items-center justify-center rounded-lg bg-indigo-50/80 dark:bg-indigo-900/30 shrink-0 border border-indigo-100 dark:border-indigo-800/50 mt-0.5' }, [
        h(FileText, { class: 'w-5 h-5 text-indigo-600 dark:text-indigo-400' })
      ]);

      const titleAndDesc = h('div', { class: 'flex flex-col justify-center' }, [
        h('a', {
          href: route(generateRoute('documenti.download'), { documento: documento.id }),
          class: 'text-sm font-bold text-slate-800 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors line-clamp-1',
          title: documento.name
        }, documento.name),
        
        documento.description 
          ? h('span', { class: 'text-xs text-slate-500 dark:text-slate-400 line-clamp-1 max-w-[280px] mt-0.5', title: documento.description }, documento.description)
          : null
      ]);
    
      return h('div', { class: 'flex items-start gap-3 py-1.5' }, [
        shieldIcon,
        fileIcon,
        titleAndDesc
      ]);
    }
  },
  {
    id: 'size',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Dimensione' }),
    cell: ({ row }) => {
      const size = row.original.file_size;
      return h('span', { class: 'text-xs text-slate-500 font-medium' }, size ? formatBytes(size, 1, true) : 'N/D');
    }
  },
  {
    id: 'created_at',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Caricato il' }),
    cell: ({ row }) => {
      const date = row.original.created_at;
      // The API returns diffForHumans() e.g., '2 ore fa'
      // If it's a valid date string we format it, otherwise we just print the string as is.
      const isValid = date && !isNaN(new Date(date).getTime());
      return h('span', { class: 'text-xs text-slate-500 font-medium' }, 
        isValid 
          ? new Intl.DateTimeFormat('it-IT', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date)) 
          : (date || 'N/D')
      );
    }
  },
  {
    accessorKey: 'is_published',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Visibilità' }),
    cell: ({ row }) => {
      const value = Boolean(row.getValue('is_published'));
      const stato = publishedConstants.find(p => p.value === value);
  
      if (!stato) return h('span', '–');
  
      return h('div', { class: 'flex items-center gap-2' }, [
        h(stato.icon, { class: `h-4 w-4 ${stato.colorClass}` }),
        h('span', { class: 'text-sm font-medium text-slate-700 dark:text-slate-300' }, trans(stato.label))
      ]);
    },
    filterFn: (row, id, value) => value.includes(Boolean(row.getValue(id))),
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
];
import { h } from 'vue'
import DropdownAction from '@/components/fornitori/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/fornitori/DataTableColumnHeader.vue';
import type { ColumnDef } from '@tanstack/vue-table'
import type { Fornitore } from '@/types/fornitori';

export const columns: ColumnDef<Fornitore>[] = [
  {
    accessorKey: 'ragione_sociale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ragione sociale' }), 
    cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('ragione_sociale')),
  },
/*   {
    accessorKey: 'indirizzo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Indirizzo' }), 
    cell: ({ row }) => h('div', { class: 'capitalize' }, row.getValue('indirizzo')),
  }, */
/*   {
    accessorKey: 'condomini',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Condomini' }),
    
    cell: ({ row }) => {
      const condomini = row.original.condomini ?? [];
  
      if (!Array.isArray(condomini) || condomini.length === 0) {
        return '—';
      }
  
      const maxAvatars = 3;
      const visibleCondomini = condomini.slice(0, maxAvatars);
      const remainingCount = condomini.length - maxAvatars;
  
      const createAvatar = (text: string, index: number, tooltip?: string) => h('div', {
        key: `${text}-${index}`,
        title: tooltip ?? text,
        class: 'absolute w-8 h-8 rounded-full bg-gray-200 text-gray-800 text-xs font-bold flex items-center justify-center border border-white shadow',
        style: `z-index: ${10 + index}; left: ${index * 18}px; top: 50%; transform: translateY(-50%);`
      }, text);
  
      const avatars = visibleCondomini.map((option, index) => {
        const initials = (option.nome || '?')
          .split(' ')
          .map(word => word[0]?.toUpperCase())
          .join('')
          .slice(0, 2);
        return createAvatar(initials, index, option.nome);
      });
  
      if (remainingCount > 0) {
        avatars.push(createAvatar(`+${remainingCount}`, visibleCondomini.length, `+${remainingCount} altri condomini`));
      }
  
      return h('div', { class: 'relative flex items-center h-10' }, avatars);
    },
  
    filterFn: (row, id, value) => {
      const condomini = row.original.condomini ?? [];
      return condomini.some((condominio: Building) => value.includes(condominio.id));
    }
  }, */
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const fornitore = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, {
        fornitore,
      }))
    },
  }
]
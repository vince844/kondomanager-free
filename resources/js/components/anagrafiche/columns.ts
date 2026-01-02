import { h } from 'vue'
import DropdownAction from '@/components/anagrafiche/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/anagrafiche/DataTableColumnHeader.vue';
import { trans } from 'laravel-vue-i18n';
import type { ColumnDef } from '@tanstack/vue-table'
import type { Anagrafica } from '@/types/anagrafiche';
import type { Building } from '@/types/buildings';

export const columns: ColumnDef<Anagrafica>[] = [
  {
    accessorKey: 'nome',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('anagrafiche.table.name') }), 
    cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('nome')),
  },
  {
    accessorKey: 'indirizzo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('anagrafiche.table.address') }), 
    cell: ({ row }) => h('div', { class: 'capitalize' }, row.getValue('indirizzo')),
  },
  {
    accessorKey: 'condomini',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('anagrafiche.table.buildings') }),
    
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
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const anagrafica = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, {
        anagrafica,
      }))
    },
  }
]
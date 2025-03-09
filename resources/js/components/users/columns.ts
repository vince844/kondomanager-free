import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { User } from '@/types/users';
import DropdownAction from './DataTableRowActions.vue';
import DataTableColumnHeader from './DataTableColumnHeader.vue';

const roleClasses: Record<string, string> = {
  amministratore: "bg-red-400 text-white",
  collaboratore: "bg-purple-400 text-white",
  fornitore: "bg-green-400 text-white",
  utente: "bg-blue-400 text-white",
};

export const columns: ColumnDef<User>[] = [
    {
        accessorKey: 'name',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Nome e cognome' }), 
        cell: ({ row }) => h('div', { class: 'capitalize' }, row.getValue('name')),
      },
      {
        accessorKey: 'email',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Indirizzo email' }), 
        cell: ({ row }) => h('div', { class: 'lowercase' }, row.getValue('email')),
      },
      {
        accessorKey: 'roles',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ruolo utente' }), 
        cell: ({ getValue }) => {
          const roles = getValue() as string[];
          return h(
            "div",
            { class: "flex gap-2" }, 
            roles.map((role) =>
              h("span", { class: `px-2 py-1 rounded text-xs font-medium capitalize ${roleClasses[role] || "bg-gray-400 text-white"}` }, role)
            )
          );
        }
        
      },
    
      {
        id: 'actions',
        enableHiding: false,
        cell: ({ row }) => {
          const user = row.original
          return h('div', { class: 'relative' }, h(DropdownAction, {
            user,
          }))
        },
      }
]
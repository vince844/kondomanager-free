import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { User } from '@/types/users';
import { Button } from '@/components/ui/button';
import DropdownAction from '@/components/DataTableRowActions.vue';
import { ArrowUpDown } from 'lucide-vue-next';

const roleClasses: Record<string, string> = {
  amministratore: "bg-red-400 text-white",
  collaboratore: "bg-blue-500 text-white",
  fornitore: "bg-green-500 text-white",
  utente: "bg-yellow-500 text-black",
};

export const columns: ColumnDef<User>[] = [
    {
        accessorKey: 'name',
        header: 'Nome e cognome',
        cell: ({ row }) => h('div', { class: 'capitalize' }, row.getValue('name')),
      },
      {
        accessorKey: 'email',
        header: ({ column }) => {
          return h(Button, {
            variant: 'ghost',
            class: 'p-0',
            onClick: () => column.toggleSorting(column.getIsSorted() === 'asc'),
          }, () => ['Indirizzo email', h(ArrowUpDown, { class: 'ml-2 h-4 w-4' })])
        }, 
        cell: ({ row }) => h('div', { class: 'lowercase' }, row.getValue('email')),
      },
      {
        accessorKey: 'roles',
        header: 'Ruolo utente',
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
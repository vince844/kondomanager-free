import { h } from 'vue'
import DropdownAction from '@/components/roles/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/roles/DataTableColumnHeader.vue'
import PermissionsDialog from '@/components/PermissionsDialog.vue'
import { statusClasses } from '@/composables/useBadges'
import { useBadges } from '@/composables/useBadges'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Role } from '@/types/roles'

// Dialog per visualizzare i permessi del ruolo
const RolePermissionsDialog = {
  props: ['role'],
  setup(props: { role: Role }) {
    const role = props.role
    const { createUserCountBadge } = useBadges()
    
    const permissions = role.permissions || []
    const badges = role.users_count !== undefined ? [createUserCountBadge(role.users_count)] : []
    
    return () => h(PermissionsDialog, {
      permissions,
      title: 'Permessi del ruolo',
      entityName: role.name,
      entityDescription: role.description,
      badges
    })
  }
}

export const columns: ColumnDef<Role>[] = [
  {
    accessorKey: 'name',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ruolo' }), 
    cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('name')),
  },
  {
    accessorKey: 'description',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }), 
    cell: ({ row }) => h('div', row.getValue('description')),
  },
  {
    accessorKey: 'permissions',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Permessi' }), 
    cell: ({ getValue, row }) => {
      const permissions = getValue() as any[]
      const role = row.original
      const total = permissions?.length || 0
      
      if (total === 0) {
        return h('span', { 
          class: statusClasses.none
        }, 'Nessuno')
      }
      
      return h(RolePermissionsDialog, { role })
    }
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const role = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, {
        role,
      }))
    },
  }
]
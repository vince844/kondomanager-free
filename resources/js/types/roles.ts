import { Permission } from './permissions';

export interface Role {
    id: string
    name: string
    description: string
    users_count: number
    permissions: Permission[]
}
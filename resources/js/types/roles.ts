import { Permission } from './permissions';

export interface Role {
    id: string
    name: string
    description: string
    users_count: number
    is_protected: boolean
    permissions: Permission[]
}
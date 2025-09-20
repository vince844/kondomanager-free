import type { LucideIcon } from 'lucide-vue-next';
import type { PageProps } from '@inertiajs/core';
import { Role } from '@/enums/Role';
import { Permission } from '@/enums/Permission';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    external?: boolean;
    roles?: Role[];
    permissions?: Permission[];
}

export interface SharedData extends PageProps {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
}

export interface User {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

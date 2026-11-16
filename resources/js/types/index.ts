// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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

// Base properties comuni a tutti i NavItem
interface NavItemBase {
    title: string;
    icon?: LucideIcon;
    isActive?: boolean;
    external?: boolean;
    roles?: Role[];
    permissions?: Permission[];
}

// NavItem con link diretto
export interface LinkItem extends NavItemBase {
    type: 'link';
    href: string;
}

// NavItem parent con children
export interface ParentItem extends NavItemBase {
    type: 'parent';
    children: LinkItem[];
}

// Union type - un NavItem può essere SOLO link O parent
export type NavItem = LinkItem | ParentItem;

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
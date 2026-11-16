// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { Config, RouteParams } from 'ziggy-js';

declare global {
    function route(): Config;
    function route(name: string, params?: RouteParams<typeof name> | undefined, absolute?: boolean): string;
}

declare module '@vue/runtime-core' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}

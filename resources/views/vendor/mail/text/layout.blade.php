{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{!! strip_tags($header ?? '') !!}

{!! strip_tags($slot) !!}
@isset($subcopy)

{!! strip_tags($subcopy) !!}
@endisset

{!! strip_tags($footer ?? '') !!}

{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@props(['url'])
<tr>
<td align="center" style="padding: 30px 0 0 0; background-color: #edf2f7;">
<table width="570" cellpadding="0" cellspacing="0" role="presentation" style="width: 570px;">
<tr>
<td class="header" style="border-radius: 8px 8px 0 0; text-align: center;">
<a href="{{ $url }}" style="display: inline-block; text-align: center;">
{{ $slot }}
</a>
</td>
</tr>
</table>
</td>
</tr>

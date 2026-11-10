<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LogsIndexRequest;
use App\Models\MailLog;
use Carbon\Carbon;
use Inertia\Inertia;

class LogsController extends Controller
{
    public function index(LogsIndexRequest $request)
    {
        $search = $request->validated('search');
        $status = $request->validated('status');
        $mailer = $request->validated('mailer');
        $dateFrom = $request->validated('date_from');
        $dateTo = $request->validated('date_to');
        $perPage = (int) $request->validated('per_page', 10);
        if (! in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 10;
        }

        // TAB 1: Mail Logs
        $mailLogs = MailLog::query()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('recipient', 'like', "%{$search}%")
                      ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->when($status && $status !== 'tutti', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($mailer && $mailer !== 'tutti', function ($query) use ($mailer) {
                $query->where('mailer', $mailer);
            })
            ->when($dateFrom, function ($query, $from) {
                try {
                    $query->whereDate('sent_at', '>=', Carbon::parse($from)->startOfDay());
                } catch (\Throwable) {}
            })
            ->when($dateTo, function ($query, $to) {
                try {
                    $query->whereDate('sent_at', '<=', Carbon::parse($to)->endOfDay());
                } catch (\Throwable) {}
            })
            ->latest('sent_at')
            // 'mail_page' è la chiave per non confondere la paginazione con l'altro tab
            ->paginate($perPage, ['*'], 'mail_page')
            ->withQueryString();

        $availableMailers = MailLog::query()
            ->distinct()
            ->whereNotNull('mailer')
            ->pluck('mailer')
            ->filter()
            ->values();

        return Inertia::render('impostazioni/impostazioniLogs', [
            'filters' => [
                'search' => is_string($search) ? $search : '',
                'status' => $status ?? 'tutti',
                'mailer' => $mailer ?? 'tutti',
                'date_from' => is_string($dateFrom) ? $dateFrom : '',
                'date_to' => is_string($dateTo) ? $dateTo : '',
                'per_page' => $perPage,
            ],
            'availableMailers' => $availableMailers,
            'mailLogs' => $mailLogs,

            // TAB 2: Activity Logs (Spatie). La scheda «Attività Sistema» non c'è finché la query qui sotto non ha dati da mostrare: non si manda una
            // proprietà vuota, che la pagina leggerebbe come un elenco. Quando la query torna, torna con la scheda in `impostazioniLogs.vue`.
           /*  'activityLogs' => Activity::query()
                ->with('causer') // Carica l'utente che ha fatto l'azione
                ->when($search, function ($query, $search) {
                    $query->where('description', 'like', "%{$search}%")
                          ->orWhereHas('causer', function ($q) use ($search) {
                              $q->where('name', 'like', "%{$search}%"); // Assumendo che User abbia 'name'
                          });
                })
                ->latest()
                ->paginate(10, ['*'], 'activity_page')
                ->withQueryString(), */
        ]);
    }
}

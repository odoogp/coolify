<?php

namespace App\Livewire;

use App\Models\GpshNotice;
use App\Models\GpshNoticeSetting;
use App\Support\GpshNotices;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class GpshNoticeBell extends Component
{
    public bool $announce = false;

    public function openNotice(int $noticeId)
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        $notice = GpshNotices::forUser($user)->with('service.environment.project')->whereKey($noticeId)->first();
        if (! $notice instanceof GpshNotice) {
            return null;
        }

        $notice->reads()->firstOrCreate(['user_id' => $user->id]);
        $href = $notice->href();
        if ($href === null || $href === '') {
            return null;
        }

        if (str_starts_with($href, url('/'))) {
            return redirect()->to($href);
        }

        return redirect()->away($href);
    }

    public function markRead(int $noticeId): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $notice = GpshNotices::forUser($user)->whereKey($noticeId)->first();
        if ($notice instanceof GpshNotice) {
            $notice->reads()->firstOrCreate(['user_id' => $user->id]);
        }
    }

    public function markAllRead(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        GpshNotices::forUser($user)->each(function (GpshNotice $notice) use ($user): void {
            $notice->reads()->firstOrCreate(['user_id' => $user->id]);
        });
    }

    public function deleteAll(): void
    {
        // Clears the bell for this user only; hard delete is Notifications\Center::deleteAll.
        $this->markAllRead();
    }

    public function render()
    {
        $user = Auth::user();
        $notices = collect();
        $unread = 0;
        if ($user !== null) {
            $unread = GpshNotices::forUser($user)
                ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $user->id))
                ->count();
            $notices = GpshNotices::forUser($user)
                ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $user->id))
                ->with('service.environment.project')
                ->limit(15)
                ->get();
        }
        $latest = $notices->first();
        $settings = GpshNoticeSetting::current();
        if ($this->announce && $user !== null && $latest instanceof GpshNotice && $settings->toast) {
            $seen = session('gpsh_notice_seen');
            if ($seen !== null && (int) $seen !== (int) $latest->id) {
                $this->dispatch(
                    'gpsh-toast',
                    title: $latest->title,
                    body: (string) str($latest->body)->limit(180),
                    seconds: max(3, (int) $settings->toast_seconds),
                );
            }
            session(['gpsh_notice_seen' => $latest->id]);
        }

        return view('livewire.gpsh-notice-bell', [
            'notices' => $notices,
            'unread' => $unread,
        ]);
    }
}

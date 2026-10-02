<?php

namespace App\Livewire;

use App\Models\GpshNotice;
use App\Support\GpshNotices;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class GpshNoticeBell extends Component
{
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

    public function render()
    {
        $user = Auth::user();
        $notices = $user === null ? collect() : GpshNotices::forUser($user)->limit(15)->get();
        $unread = 0;
        if ($user !== null) {
            $unread = GpshNotices::forUser($user)
                ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $user->id))
                ->count();
        }

        return view('livewire.gpsh-notice-bell', [
            'notices' => $notices,
            'unread' => $unread,
        ]);
    }
}

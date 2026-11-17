<div class="flex h-full min-h-[22rem] flex-col gap-3">
    <div class="flex items-center justify-between gap-2">
        <div>
            <p class="text-sm font-semibold">{{ $agentName }}</p>
            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                {{ __('Scoped to this project only.') }}
            </p>
        </div>
        @unless ($agentReady)
            <span class="rounded-full border border-amber-500/30 px-2 py-0.5 text-[11px] text-amber-300">
                {{ __('Not configured') }}
            </span>
        @endunless
    </div>

    <div class="gc-agent-chat-log flex-1 space-y-2 overflow-y-auto rounded-xl border border-white/10 bg-black/20 p-3">
        @forelse ($messages as $message)
            <div wire:key="agent-msg-{{ $message['id'] ?? $loop->index }}"
                @class([
                    'rounded-lg px-3 py-2 text-[13px] leading-5',
                    'ml-6 bg-indigo-500/20' => ($message['role'] ?? '') === 'user',
                    'mr-6 bg-white/5' => ($message['role'] ?? '') !== 'user',
                ])>
                <p class="mb-0.5 text-[10px] uppercase tracking-wide text-neutral-400">
                    {{ ($message['role'] ?? '') === 'user' ? __('You') : __('Agent') }}
                </p>
                <div class="whitespace-pre-wrap break-words">{{ $message['content'] }}</div>
            </div>
        @empty
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Ask the agent to inspect connected services, explain a deploy, plan a rollback, or draft an Odoo module.') }}
            </p>
        @endforelse
    </div>

    <form wire:submit="send" class="flex flex-col gap-2">
        <textarea wire:model="draft" rows="3"
            class="w-full rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-[13px] text-fg"
            placeholder="{{ __('Tell the agent what to do in this project…') }}"
            @disabled(! $agentReady || ! auth()->user()?->can('update', $project))></textarea>
        <x-forms.button type="submit" canGate="update" :canResource="$project" isHighlighted
            class="w-full justify-center" :disabled="! $agentReady">
            {{ __('Send') }}
        </x-forms.button>
    </form>
</div>

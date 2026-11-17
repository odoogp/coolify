<div class="space-y-4">
    <div>
        <p class="text-sm font-semibold">{{ __('AI agent') }}</p>
        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ __('This agent only works inside this project. It can read connected nodes, help with deploys and rollbacks, and draft Odoo modules — never other projects.') }}
        </p>
    </div>

    <form wire:submit="save" class="space-y-3">
        <x-forms.input canGate="update" :canResource="$project" id="name" label="{{ __('Name') }}" />
        <x-forms.searchable-listbox canGate="update" :canResource="$project" id="provider"
            label="{{ __('Provider') }}" :options="$providers" live />
        <x-forms.input canGate="update" :canResource="$project" id="model" label="{{ __('Model') }}"
            helper="{{ __('Example: gpt-4o-mini, claude-3-5-sonnet, gemini-2.0-flash') }}" />
        <x-forms.input canGate="update" :canResource="$project" id="apiKey" type="password"
            label="{{ __('API key') }}"
            helper="{{ $apiKeySet ? __('Leave blank to keep the saved key.') : __('Required to enable the agent.') }}" />
        <x-forms.input canGate="update" :canResource="$project" id="baseUrl" label="{{ __('Base URL (optional)') }}"
            helper="{{ __('Only for custom OpenAI-compatible endpoints.') }}" />
        <x-forms.checkbox canGate="update" :canResource="$project" id="isEnabled" label="{{ __('Enable agent') }}"
            live />
        <x-forms.checkbox canGate="update" :canResource="$project" id="followInCloud"
            label="{{ __('Follow me in the chat cloud') }}"
            helper="{{ __('Keeps a floating chat available while you browse this app.') }}" live />
        <x-forms.button type="submit" canGate="update" :canResource="$project" isHighlighted>
            {{ __('Save agent') }}
        </x-forms.button>
    </form>
</div>

<x-auth.shell :title="product_name()" :description="$signup?->plan?->name">
    <x-auth.alert type="warning">
        <p>{{ $message }}</p>
    </x-auth.alert>
    @if ($signup?->plan)
        <a href="{{ $signup->plan->publicUrl() }}" class="button mt-4 w-full justify-center">
            {{ __('Back to the plan') }}
        </a>
    @endif
</x-auth.shell>

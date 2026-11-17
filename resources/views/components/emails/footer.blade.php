{{ Illuminate\Mail\Markdown::parse('---') }}

Thank you,<br>
{{ product_name() }}

{{ Illuminate\Mail\Markdown::parse('[Contact Support]('.config('constants.urls.contact').')') }}

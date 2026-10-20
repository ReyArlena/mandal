{{-- Inlined so the white fill can follow Filament's light/dark logo text color --}}
{!! str_replace(
    ['<svg ', 'fill="white"'],
    ['<svg style="height: 100%; width: auto" ', 'fill="currentColor"'],
    file_get_contents(public_path('logo.svg')),
) !!}

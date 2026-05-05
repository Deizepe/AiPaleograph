@php
$width = $width ?? '25';
@endphp

<span class="text-primary">
    <svg width="{{ $width }}" viewBox="0 0 24 24" version="1.1" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <g fill="none" fill-rule="evenodd">
            <circle cx="10.5" cy="10.5" r="6.5" stroke="currentColor" stroke-width="2.5"></circle>
            <line x1="15.2" y1="15.2" x2="21" y2="21" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"></line>
        </g>
    </svg>
</span>
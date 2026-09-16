@php /** @see \HiEvents\Assistant\Mail\EventSalesAlertMail */ @endphp
<x-mail::message>
# {{ $title }} viene lento

@if($daysLeft <= 0)
El evento es **hoy** y vendiste **{{ $sold }} de {{ $capacity }}** entradas ({{ $soldPct }}%).
@else
Faltan **{{ $daysLeft }} {{ $daysLeft === 1 ? 'día' : 'días' }}** y vendiste **{{ $sold }} de {{ $capacity }}** entradas ({{ $soldPct }}%).
@endif

@if($benchmarkPct !== null)
Tus últimos eventos cerraron en promedio al **{{ $benchmarkPct }}%** de su capacidad.
@endif

Entrá al panel para mover ventas: compartí el link del evento, creá un código de descuento o mandá un mensaje a tu público.

<x-mail::button :url="$panelUrl">
Ver el evento en el panel
</x-mail::button>

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>

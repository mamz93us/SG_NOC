@php($s = $c->last_health_status)
@if($s === 'healthy')
    <span class="badge bg-success">healthy</span>
@elseif($s === 'unreachable')
    <span class="badge bg-danger" title="{{ $c->last_error }}">unreachable</span>
@elseif($s === 'unauthorized')
    <span class="badge bg-warning text-dark">unauthorized</span>
@elseif($s === 'error')
    <span class="badge bg-danger" title="{{ $c->last_error }}">error</span>
@else
    <span class="badge bg-secondary">untested</span>
@endif

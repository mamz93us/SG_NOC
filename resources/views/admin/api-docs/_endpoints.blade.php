{{--
    One API's endpoint reference, rendered from its spec
    (App\Support\HrApiDocs, App\Support\AttendanceApiDocs).
    Expects $endpoints, $accordionId and $baseUrl. An endpoint may carry
    curl_path (a concrete path for the example) and curl_query.
--}}
<div class="accordion mb-4 shadow-sm" id="{{ $accordionId }}">
@foreach($endpoints as $ep)
    @php
        $methodClass = $ep['method'] === 'GET' ? 'bg-info text-dark' : 'bg-success';
        $bodyId      = 'endpoint-'.$ep['id'];
    @endphp
    <div class="accordion-item border">
        <h2 class="accordion-header">
            <button class="accordion-button {{ $loop->first ? '' : 'collapsed' }} fw-semibold" type="button"
                    data-bs-toggle="collapse" data-bs-target="#{{ $bodyId }}"
                    aria-expanded="{{ $loop->first ? 'true' : 'false' }}" aria-controls="{{ $bodyId }}">
                <span class="badge {{ $methodClass }} me-3 px-2 py-1" style="font-size:.75rem">{{ $ep['method'] }}</span>
                <code>{{ $ep['path'] }}</code>
                <span class="ms-3 text-muted fw-normal small d-none d-md-inline">{{ $ep['summary'] }}</span>
            </button>
        </h2>
        <div id="{{ $bodyId }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}" data-bs-parent="#{{ $accordionId }}">
            <div class="accordion-body">

                @if($ep['mirrors'])
                <div class="alert alert-primary py-2 small mb-3">
                    <i class="bi bi-arrow-left-right me-1"></i>
                    <strong>Same behaviour as {{ $ep['mirrors'] }}.</strong>
                    Both go through the same service, so a request raised here is indistinguishable from one a person filled in.
                </div>
                @endif

                <p class="text-muted">{{ $ep['description'] }}</p>

                @if(! empty($ep['notes']))
                <ul class="small text-muted ps-3 mb-3">
                    @foreach($ep['notes'] as $note)
                    <li class="mb-1">{{ $note }}</li>
                    @endforeach
                </ul>
                @endif

                @if(! empty($ep['fields']))
                <h6 class="fw-semibold mb-2"><i class="bi bi-list-columns me-1 text-primary"></i>{{ $ep['method'] === 'GET' ? 'Query parameters' : 'Request body' }}</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:200px">Field</th>
                                <th style="width:150px">Type</th>
                                <th style="width:110px">Required</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ep['fields'] as [$name, $type, $req, $desc])
                            <tr>
                                <td><code>{{ $name }}</code></td>
                                <td class="small text-muted">{{ $type }}</td>
                                <td>
                                    @if($req === 'required')
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">required</span>
                                    @elseif($req === 'one-of')
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">one of</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">optional</span>
                                    @endif
                                </td>
                                <td class="small">{{ $desc }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(collect($ep['fields'])->contains(fn ($f) => $f[2] === 'one-of'))
                <p class="small text-muted"><i class="bi bi-info-circle me-1"></i><strong>one of</strong> — send any single field from that group; the first one that resolves wins.</p>
                @endif
                @endif

                <div class="row g-4">
                    @if($ep['request'])
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-arrow-up-circle me-1 text-primary"></i>Example request</h6>
                        <pre class="bg-dark text-light p-3 rounded small mb-0"><code>{{ json_encode($ep['request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                    </div>
                    @endif
                    <div class="col-lg-{{ $ep['request'] ? 6 : 12 }}">
                        <h6 class="fw-semibold mb-2">
                            <i class="bi bi-arrow-down-circle me-1 text-primary"></i>Example response
                            <span class="badge {{ $ep['method'] === 'GET' ? 'bg-primary' : 'bg-success' }} ms-1">{{ $ep['method'] === 'GET' ? '200 OK' : '201 Created' }}</span>
                        </h6>
                        <pre class="bg-dark text-light p-3 rounded small mb-0"><code>{{ json_encode($ep['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                    </div>
                </div>

                <h6 class="fw-semibold mb-2 mt-3"><i class="bi bi-terminal me-1 text-secondary"></i>cURL</h6>
                @php
                    $curlPath  = $ep['curl_path'] ?? str_replace('{workflow_id}', '812', $ep['path']);
                    $curlQuery = $ep['curl_query'] ?? (! empty($ep['fields']) ? $ep['fields'][0][0].'=VALUE' : '');
                @endphp
                @if($ep['method'] === 'GET')
                <pre class="bg-dark text-light p-3 rounded small mb-0"><code>curl -s "{{ $baseUrl }}{{ $curlPath }}{{ $curlQuery !== '' ? '?'.$curlQuery : '' }}" \
  -H "X-HR-Api-Key: YOUR_KEY" \
  -H "Accept: application/json"</code></pre>
                @else
                <pre class="bg-dark text-light p-3 rounded small mb-0"><code>curl -s -X POST "{{ $baseUrl }}{{ $curlPath }}" \
  -H "X-HR-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{{ json_encode($ep['request'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}'</code></pre>
                @endif
            </div>
        </div>
    </div>
@endforeach
</div>{{-- /accordion --}}

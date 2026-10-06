{{-- The two ways of reading Saudization. $tab is 'groups' or 'departments'. --}}
<ul class="nav nav-pills mb-3">
    <li class="nav-item">
        <a class="nav-link py-1 {{ $tab === 'groups' ? 'active' : '' }}" href="{{ route('admin.people.saudization') }}">By professional group</a>
    </li>
    <li class="nav-item">
        <a class="nav-link py-1 {{ $tab === 'departments' ? 'active' : '' }}" href="{{ route('admin.people.saudization.departments') }}">By department</a>
    </li>
</ul>

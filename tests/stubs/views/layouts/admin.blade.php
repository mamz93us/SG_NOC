{{--
    Stand-in for layouts.admin in render tests.

    The real layout needs an authenticated user, roles, permissions and the
    settings singleton. Rendering it would test the chrome rather than the page,
    so tests prepend this directory to the view finder and get just the section
    under test.
--}}
<!DOCTYPE html>
<html><body>@yield('content')</body></html>

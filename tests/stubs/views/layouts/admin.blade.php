{{--
    Stand-in for layouts.admin in render tests.

    The real layout needs an authenticated user, roles, permissions and the
    settings singleton. Rendering it would test the chrome rather than the page,
    so tests prepend this directory to the view finder and get just the section
    under test.

    It must keep the same @yield/@stack hooks the real layout offers, or pages
    that push their styles and scripts would render half-empty and the tests
    would quietly assert against markup the browser never sees.
--}}
<!DOCTYPE html>
<html>
<head>@stack('head')</head>
<body>
@yield('content')
@stack('scripts')
</body>
</html>

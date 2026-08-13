{{--
    Stub of layouts.admin for view-render tests.

    The real admin layout pulls in the authenticated user, the settings
    singleton, the notification bell and the whole nav tree — none of which the
    voice mesh views are being tested for. Prepending this location in the view
    finder lets a test render a page's own markup, and so catch undefined
    variables in it, without standing up the entire admin chrome.
--}}
@yield('content')

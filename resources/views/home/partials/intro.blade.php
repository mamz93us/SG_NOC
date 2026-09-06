{{--
    The brand intro that plays over the employee portal.

    THE WHOLE DESIGN CONSTRAINT IS RESTRAINT. This page is the browser home page
    on every company PC — people land here dozens of times a day, on every new
    tab and every launch. An intro that played every time would be the single
    most hated thing in the building within a week. So:

      * it plays AT MOST ONCE A DAY per device (localStorage date stamp),
      * it is under two seconds,
      * any click, tap or key kills it instantly,
      * `prefers-reduced-motion` skips it entirely,
      * and it CANNOT strand anyone: the overlay is `display:none` in CSS and is
        only ever revealed by the script in the <head>, so a browser with JS off
        or a script that throws leaves the page perfectly usable rather than
        hidden behind a black screen that never lifts.

    The decision runs in the <head> (see layouts/home.blade.php) so the overlay
    is painted or not painted on the first frame — deciding here, in the body,
    would flash the page before covering it.
--}}
<div class="sg-intro" id="sgIntro" aria-hidden="true" role="presentation">
    <div class="sg-intro-inner">
        <div class="sg-intro-mark">
            <span class="sg-intro-glow" aria-hidden="true"></span>
            <img src="{{ asset('images/brand/samir-mark.png') }}" alt="" aria-hidden="true">
        </div>

        <img class="sg-intro-wordmark" src="{{ asset('images/brand/samir-logo.png') }}" alt="" aria-hidden="true">

        <span class="sg-intro-rule" aria-hidden="true"></span>

        <p class="sg-intro-greeting">{{ $introGreeting ?? 'Welcome' }}</p>
        <p class="sg-intro-sub">بوابة الموظفين &middot; Employee Portal</p>
    </div>

    <p class="sg-intro-skip">Click anywhere to skip</p>
</div>

<script>
(function () {
  'use strict';

  var intro = document.getElementById('sgIntro');
  if (!intro) return;

  // The <head> script decides whether to play. If it did not turn the intro on,
  // this element is display:none and there is nothing to do but let the page
  // reveal itself immediately.
  function reveal() {
    document.body.classList.add('sg-reveal');
    document.dispatchEvent(new CustomEvent('sg:reveal'));
  }

  if (document.documentElement.getAttribute('data-intro') !== 'on') {
    reveal();
    return;
  }

  var HOLD = {{ (int) ($introHoldMs ?? 1750) }};   // how long the brand holds
  var FADE = 480;                                  // overlay fade-out
  var done = false;

  document.body.classList.add('sg-intro-lock');

  function finish() {
    if (done) return;
    done = true;

    intro.classList.add('sg-intro-out');
    document.body.classList.remove('sg-intro-lock');
    // Content starts arriving while the overlay is still fading, so the two
    // read as one movement rather than a splash followed by a page.
    reveal();

    window.setTimeout(function () {
      if (intro.parentNode) intro.parentNode.removeChild(intro);
    }, FADE + 60);

    document.removeEventListener('keydown', finish, true);
    intro.removeEventListener('click', finish);
  }

  intro.addEventListener('click', finish);
  document.addEventListener('keydown', finish, true);
  window.setTimeout(finish, HOLD);

  // A tab restored from the back/forward cache, or one opened in the background
  // and looked at ten minutes later, must not still be sitting on the splash.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) finish();
  });
})();
</script>

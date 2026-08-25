/*
 * =====================================================================
 *  Keeping the auction board current
 * =====================================================================
 *
 *  The auction is called aloud in a room and each result typed into the
 *  administrator's sheet. Everyone else is watching this board, often on
 *  a screen at the side of the hall that nobody is standing next to. So
 *  it refreshes itself.
 *
 *  It refetches its own page and swaps in the new <main>. Not a JSON
 *  API and not a rebuild in JavaScript: the server already knows how to
 *  render this board, and one renderer means the live view and a plain
 *  reload can never drift apart.
 *
 *  An enhancement, never a requirement. With scripting off, blocked, or
 *  broken, the page is exactly what it was — a board you reload. Nothing
 *  here is the only way to see anything.
 *
 *  Three things it is careful about:
 *
 *    An open team card must survive a refresh, and read as up to date
 *    afterwards. Which card is open lives in the address bar, so nothing
 *    needs remembering — but a card is shown by :target, and replacing
 *    the panel underneath it removes the element the browser holds as
 *    the target. It does not re-match the new one, so the card vanishes
 *    mid-refresh, in front of whoever was reading it. syncOpen() marks
 *    the card named by the address after every swap; the stylesheet
 *    accepts that mark as well as :target.
 *
 *    A hidden tab must stop asking. A board left open on a laptop for a
 *    week should not spend the week polling.
 *
 *    A failure must be quiet and must not give up. A hall's wifi drops;
 *    the board should catch up when it returns, not sit there stale and
 *    confident, and not shout about it either.
 */

(function () {
    'use strict';

    var EVERY   = 12000;   /* a lot takes minutes to call; this is plenty */
    var BACKOFF = 60000;   /* after a failure, ask less often for a while */

    var main = document.getElementById('board');

    if (!main || typeof fetch !== 'function' || !window.DOMParser) {
        return;
    }

    var timer   = null;
    var failing = false;

    function status(text) {
        var el = document.querySelector('[data-board-status]');

        if (el) {
            el.textContent = text;
        }
    }

    /**
     * Keep the shown card in step with the address. Called after every
     * swap, and whenever the address changes — the Close link points at
     * an id that matches nothing, so this is also what closes a card
     * that a swap has marked open.
     */
    function syncOpen() {
        var id    = window.location.hash.slice(1);
        var open  = id ? document.getElementById(id) : null;
        var marked = document.querySelectorAll('.tc-modal.is-open');

        for (var i = 0; i < marked.length; i++) {
            if (marked[i] !== open) {
                marked[i].classList.remove('is-open');
            }
        }

        if (open && open.classList.contains('tc-modal')) {
            open.classList.add('is-open');
        }
    }

    function stamp() {
        var now = new Date();

        return now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function schedule(delay) {
        window.clearTimeout(timer);

        if (!document.hidden) {
            timer = window.setTimeout(refresh, delay);
        }
    }

    function refresh() {
        fetch(window.location.href, {
            headers: { 'X-Requested-With': 'board-live' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            })
            .then(function (html) {
                var fresh = new DOMParser()
                    .parseFromString(html, 'text/html')
                    .getElementById('board');

                /* Whatever came back is not this board any more — the
                   auction ended, the session changed, something was
                   redirected. Leave what is on screen alone. */
                if (!fresh) {
                    throw new Error('no board in the response');
                }

                if (fresh.innerHTML !== main.innerHTML) {
                    main.innerHTML = fresh.innerHTML;
                    syncOpen();
                }

                failing = false;
                status('Updating on its own · last checked ' + stamp());
                schedule(EVERY);
            })
            .catch(function () {
                /* Say only what is true and useful: the figures on screen
                   are the last ones that arrived. */
                if (!failing) {
                    failing = true;
                    status('Cannot reach the server — showing the last figures received.');
                }

                schedule(BACKOFF);
            });
    }

    window.addEventListener('hashchange', syncOpen);

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            window.clearTimeout(timer);
        } else {
            refresh();
        }
    });

    status('Updating on its own — no need to reload.');
    schedule(EVERY);
}());

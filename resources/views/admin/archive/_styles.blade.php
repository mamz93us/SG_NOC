{{--
    The handful of helper classes the archive settings pages use.

    These pages were written for the archive portal's own layout and moved here
    whole. Rewriting ~1,500 lines of markup onto the admin layout's conventions
    would change how every one of them looks and reads for no benefit a person
    could see, so the six classes they lean on are defined here instead, scoped
    to these pages by being pushed only from them.

    Colours come from the admin layout's own Bootstrap variables rather than the
    portal's palette, so the pages belong to the NOC visually: a card here is a
    Bootstrap card, not a 18px-radius portal tile.

    @once, because every one of these pages includes this partial and the admin
    layout has one head stack.
--}}
@once
    @push('head')
        <style>
            .arc-card {
                background: var(--bs-body-bg, #fff);
                border: 1px solid var(--bs-border-color, #dee2e6);
                border-radius: .5rem;
            }

            .arc-muted { color: var(--bs-secondary-color, #6c757d); }

            .arc-empty {
                padding: 2.5rem 1rem;
                text-align: center;
                color: var(--bs-secondary-color, #6c757d);
            }

            .arc-table th {
                font-size: .74rem;
                text-transform: uppercase;
                letter-spacing: .4px;
                color: var(--bs-secondary-color, #6c757d);
                font-weight: 700;
            }

            .arc-table td { vertical-align: middle; }

            /* The Samir red, the one portal colour worth keeping: these pages
               spend money and delete things, and their primary action should not
               look like an ordinary link. */
            .btn-brand { background: #EC2024; border-color: #EC2024; color: #fff; font-weight: 600; }
            .btn-brand:hover { background: #C81A1E; border-color: #C81A1E; color: #fff; }
            .btn-brand:focus-visible { outline: 2px solid #F13337; outline-offset: 2px; }

            .badge-soft { background: #FCE6E6; color: #C81A1E; font-weight: 600; }
        </style>
    @endpush
@endonce

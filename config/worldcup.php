<?php

return [

    /*
    |--------------------------------------------------------------------------
    | World Cup contest — flag image source
    |--------------------------------------------------------------------------
    |
    | Where the public form/email reads flag images from. Flags are downloaded
    | once into public/images/flags/{code}.png by `php artisan worldcup:fetch-flags`
    | so they are served from NOC itself (works offline, renders on Windows/Outlook).
    |
    | "remote_template" is only used by the fetch command to download the images;
    | it is NOT hit at render time. {code} is the team code below.
    |
    */
    'flag_path'        => 'images/flags',            // under public/
    'remote_template'  => 'https://flagcdn.com/w160/{code}.png',

    /*
    |--------------------------------------------------------------------------
    | Participating teams (FIFA World Cup 2026)
    |--------------------------------------------------------------------------
    |
    | code = ISO 3166-1 alpha-2 (lowercase) as used by flagcdn. UK home nations
    | use flagcdn's special codes (gb-eng, gb-sct, gb-wls). Edit this list freely;
    | after adding/removing a team re-run `php artisan worldcup:fetch-flags`.
    | Source: https://www.fifa.com/en/tournaments/mens/worldcup/canadamexicousa2026
    |
    */
    'teams' => [
        ['code' => 'dz',     'name' => 'Algeria'],
        ['code' => 'ar',     'name' => 'Argentina'],
        ['code' => 'au',     'name' => 'Australia'],
        ['code' => 'at',     'name' => 'Austria'],
        ['code' => 'be',     'name' => 'Belgium'],
        ['code' => 'ba',     'name' => 'Bosnia and Herzegovina'],
        ['code' => 'br',     'name' => 'Brazil'],
        ['code' => 'cv',     'name' => 'Cape Verde'],
        ['code' => 'ca',     'name' => 'Canada'],
        ['code' => 'co',     'name' => 'Colombia'],
        ['code' => 'cd',     'name' => 'Congo DR'],
        ['code' => 'ci',     'name' => "Côte d'Ivoire"],
        ['code' => 'hr',     'name' => 'Croatia'],
        ['code' => 'cw',     'name' => 'Curaçao'],
        ['code' => 'cz',     'name' => 'Czechia'],
        ['code' => 'ec',     'name' => 'Ecuador'],
        ['code' => 'eg',     'name' => 'Egypt'],
        ['code' => 'gb-eng', 'name' => 'England'],
        ['code' => 'fr',     'name' => 'France'],
        ['code' => 'de',     'name' => 'Germany'],
        ['code' => 'gh',     'name' => 'Ghana'],
        ['code' => 'ht',     'name' => 'Haiti'],
        ['code' => 'ir',     'name' => 'IR Iran'],
        ['code' => 'iq',     'name' => 'Iraq'],
        ['code' => 'jp',     'name' => 'Japan'],
        ['code' => 'jo',     'name' => 'Jordan'],
        ['code' => 'kr',     'name' => 'Korea Republic'],
        ['code' => 'mx',     'name' => 'Mexico'],
        ['code' => 'ma',     'name' => 'Morocco'],
        ['code' => 'nl',     'name' => 'Netherlands'],
        ['code' => 'nz',     'name' => 'New Zealand'],
        ['code' => 'no',     'name' => 'Norway'],
        ['code' => 'pa',     'name' => 'Panama'],
        ['code' => 'py',     'name' => 'Paraguay'],
        ['code' => 'pt',     'name' => 'Portugal'],
        ['code' => 'qa',     'name' => 'Qatar'],
        ['code' => 'sa',     'name' => 'Saudi Arabia'],
        ['code' => 'gb-sct', 'name' => 'Scotland'],
        ['code' => 'sn',     'name' => 'Senegal'],
        ['code' => 'za',     'name' => 'South Africa'],
        ['code' => 'es',     'name' => 'Spain'],
        ['code' => 'se',     'name' => 'Sweden'],
        ['code' => 'ch',     'name' => 'Switzerland'],
        ['code' => 'tn',     'name' => 'Tunisia'],
        ['code' => 'tr',     'name' => 'Türkiye'],
        ['code' => 'us',     'name' => 'United States'],
        ['code' => 'uy',     'name' => 'Uruguay'],
        ['code' => 'uz',     'name' => 'Uzbekistan'],
    ],

];

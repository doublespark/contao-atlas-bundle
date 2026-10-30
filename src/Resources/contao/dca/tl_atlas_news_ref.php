<?php

declare(strict_types=1);

// Records which news entries this API created, keyed by the caller's atlas_ref. Own table, so tl_news itself is not altered.
// Created by contao:migrate (or the install tool) after the bundle is installed.
$GLOBALS['TL_DCA']['tl_atlas_news_ref'] = [
    'config' => [
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'atlas_ref' => 'unique',
                'news_id' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'atlas_ref' => ['sql' => "varchar(190) NOT NULL default ''"],
        'news_id' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'content_id' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        // v0.3: every tl_content id of an element list, in order, comma-separated. content_id stays the first, for v0.2 readers.
        'content_ids' => ['sql' => "varchar(1000) NOT NULL default ''"],
    ],
];

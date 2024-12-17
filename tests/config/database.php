<?php

return [

    'default' => 'default',

    'connections' => [

        'neo4j' => [
            'scheme'   => 'http',
            'driver'   => 'neo4j',
            'host'     => 'localhost',
            'port'     => 7474,
            'database' => 'neo4j',
            'username' => 'neo4j',
            'password' => 'test',
        ],

        'default' => [
            'scheme'   => 'http',
            'driver'   => 'neo4j',
            'host'     => 'localhost',
            'port'     => 7474,
            'database' => 'neo4j',
            'username' => 'neo4j',
            'password' => 'test',
        ],
    ],
];

<?php

return [

    "groups" => [
        "public" => ["login", "logout", "auth.*"],
        "admin" => ["admin.*"],
        // Everything except admin routes. Emitted by @routes('player') in
        // app.blade.php for non-admin visitors (an all-negated pattern list
        // makes Ziggy reject matches instead of keeping them).
        "player" => ["!admin.*"],
    ],
];

<?php

/*
|--------------------------------------------------------------------------
| Sanctum (mobile app bearer tokens)
|--------------------------------------------------------------------------
| Only the keys the app changes. Laravel merges this over the package's own
| config/sanctum.php, so every other Sanctum setting keeps its default.
|
| expiration: minutes a token works after it is issued (a fixed lifetime —
| using the token does not extend it). After that the API answers 401 and
| the mobile app asks the visitor to sign in again. Default 30 days; a
| missing, empty, zero or non-numeric SANCTUM_EXPIRATION also gets 30 days,
| so a blank .env line can never turn expiry off.
*/

$expiration = (int) env('SANCTUM_EXPIRATION');

return [

    'expiration' => $expiration > 0 ? $expiration : 43200,

];

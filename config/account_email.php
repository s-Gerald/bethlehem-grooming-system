<?php

// Explicit operational exception domains belong in the shared source, never request input.
return json_decode(file_get_contents(__DIR__.'/../resources/data/account-email-policy.json'), true, flags: JSON_THROW_ON_ERROR);
